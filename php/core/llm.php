<?php
declare(strict_types=1);

function llm_endpoint(): string {
  return trim((string)(getenv('LLM_ENDPOINT') ?: 'https://api.openai.com/v1/chat/completions'));
}

function llm_key(): string {
  // Simple key resolution: OPENAI_API_KEY preferred, fallback to LLM_API_KEY
  $k = (string)(getenv('OPENAI_API_KEY') ?: getenv('LLM_API_KEY') ?: '');
  return trim($k);
}

function llm_model(): string {
  $m = trim((string)(getenv('LLM_MODEL') ?: 'gpt-4o-mini'));
  // Keep this stderr trace: useful on-device with minimal tooling
  fwrite(STDERR, "[LLM_MODEL_USED] $m\n");
  return $m !== '' ? $m : 'gpt-4o-mini';
}

function env_int(string $k, int $def): int {
  $v = getenv($k);
  return ($v === false || $v === '') ? $def : max(1, (int)$v);
}

function env_float(string $k, float $def): float {
  $v = getenv($k);
  return ($v === false || $v === '') ? $def : (float)$v;
}

function llm_max_tokens(): int     { return env_int('LLM_MAX_TOKENS', 120); }
function llm_temperature(): float { return env_float('LLM_TEMPERATURE', 0.4); }
function llm_timeout(): int       { return env_int('LLM_TIMEOUT', 15); }

function llm_call(string $system, string $userText): array {
  $key = llm_key();
  if ($key === '') return ['ok' => false, 'err' => 'NO_LLM_KEY'];

  $t0 = microtime(true);

  $payload = [
    'model' => llm_model(),
    'messages' => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $userText],
    ],
    'max_tokens'  => llm_max_tokens(),
    'temperature' => llm_temperature(),
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
      "Authorization: Bearer {$key}",
      "Content-Type: application/json",
    ],
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => llm_timeout(),
    // Note: SSL verify is left to PHP/cURL defaults (recommended).
  ]);

  $raw  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  $ms = (int)((microtime(true) - $t0) * 1000);

  if ($raw === false || $code < 200 || $code >= 300) {
    $body = is_string($raw) ? mb_substr($raw, 0, 500) : '';
    return [
      'ok'   => false,
      'err'  => 'LLM_HTTP_FAIL',
      'code' => $code,
      'curl' => $err,
      'body' => $body,
      'ms'   => $ms,
    ];
  }

  $j = json_decode($raw, true);
  $text = (string)($j['choices'][0]['message']['content'] ?? '');

  return [
    'ok'    => true,
    'text'  => $text,
    'ms'    => $ms,
    'usage' => $j['usage'] ?? [],
  ];
}
