<?php
declare(strict_types=1);

/**
 * VOXIE - Gemini Live Agent Challenge Edition
 * Migrazione da OpenAI a Google Gemini per massimizzare il punteggio della challenge.
 */

function llm_key(): string {
    // Cerchiamo la chiave specifica per Gemini (GEMINI_API_KEY)
    $k = (string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '');
    return trim($k);
}

function llm_model(): string {
    // Usiamo gemini-2.0-flash per la massima reattività sulla Raspberry 2011
    $m = trim((string)(getenv('LLM_MODEL') ?: 'gemini-2.0-flash'));
    fwrite(STDERR, "[GEMINI_MODEL_USED] $m\n");
    return $m;
}

function llm_endpoint(): string {
    $key = llm_key();
    $model = llm_model();
    // Endpoint nativo di Google AI
    return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
}

// Funzioni helper invariate per mantenere la compatibilità
function env_int(string $k, int $def): int {
    $v = getenv($k);
    return ($v === false || $v === '') ? $def : max(1, (int)$v);
}

function env_float(string $k, float $def): float {
    $v = getenv($k);
    return ($v === false || $v === '') ? $def : (float)$v;
}

function llm_max_tokens(): int      { return env_int('LLM_MAX_TOKENS', 150); }
function llm_temperature(): float { return env_float('LLM_TEMPERATURE', 0.7); }
function llm_timeout(): int        { return env_int('LLM_TIMEOUT', 15); }

function llm_call(string $system, string $userText): array {
    $key = llm_key();
    if ($key === '') return ['ok' => false, 'err' => 'NO_GEMINI_KEY'];

    $t0 = microtime(true);

    // Mappatura del payload dal formato OpenAI al formato Google Gemini
    $payload = [
        'contents' => [
            [
                'role' => 'user', 
                'parts' => [
                    ['text' => "SYSTEM INSTRUCTIONS: {$system}\n\nUSER QUESTION: {$userText}"]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => llm_max_tokens(),
            'temperature' => llm_temperature(),
        ]
    ];

    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['ok' => false, 'err' => 'JSON_ENCODE_FAIL', 'msg' => $e->getMessage()];
    }

    $ch = curl_init(llm_endpoint());
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => llm_timeout(),
    ]);

    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ms = (int)((microtime(true) - $t0) * 1000);

    if ($raw === false || $code !== 200) {
        return [
            'ok'   => false,
            'err'  => 'GEMINI_HTTP_FAIL',
            'code' => $code,
            'body' => is_string($raw) ? mb_substr($raw, 0, 500) : $err,
            'ms'   => $ms,
        ];
    }

    $j = json_decode($raw, true);
    
    // Estrazione del testo dal formato risposta di Gemini
    $text = (string)($j['candidates'][0]['content']['parts'][0]['text'] ?? '');

    return [
        'ok'    => true,
        'text'  => trim($text),
        'ms'    => $ms,
        'usage' => $j['usageMetadata'] ?? [], // Gemini usa usageMetadata invece di usage
    ];
}
