<?php
declare(strict_types=1);

require_once __DIR__ . "/../php/core/config.php";
bv_env_load(bv_base_dir() . "/.env");
date_default_timezone_set(config_get("TZ","Europe/Rome") ?: "Europe/Rome");

require_once __DIR__ . "/../php/core/llm.php";
require_once __DIR__ . "/../php/core/tts.php";
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$ROOT = dirname(__DIR__);
$city = $argv[1] ?? 'cagliari';

$inJson = "$ROOT/data/events/cache/$city/weekend.latest.json";
if (!is_file($inJson)) { fwrite(STDERR, "Missing: $inJson\n"); exit(2); }

$feed = json_decode(file_get_contents($inJson), true);
if (!is_array($feed) || !($feed['ok'] ?? false)) { fwrite(STDERR, "Bad feed JSON\n"); exit(2); }

$sys = file_get_contents("$ROOT/data/events/prompts/timeout_v1_system.txt");
$schema = json_decode(file_get_contents("$ROOT/data/events/prompts/timeout_v1_schema.json"), true);
if (!$sys || !is_array($schema)) { fwrite(STDERR, "Missing prompts/schema\n"); exit(2); }

$openaiKey = getenv('OPENAI_API_KEY') ?: '';
if ($openaiKey === '') { fwrite(STDERR, "ERR: OPENAI_API_KEY not set\n"); exit(2); }

$model    = getenv('VOXIE_LLM_MODEL') ?: (getenv('LLM_MODEL') ?: 'gpt-4o-mini');

/**
 * TTS selection (events/timeout only)
 */
$eventsTtsEngine = strtolower((string)(getenv('VOXIE_EVENTS_TTS_ENGINE') ?: getenv('TTS_ENGINE') ?: 'openai'));

/**
 * OpenAI TTS defaults (fallback)
 */
$ttsModel = getenv('VOXIE_TTS_MODEL') ?: (getenv('TTS_MODEL') ?: 'tts-1');
$ttsVoice = getenv('VOXIE_TTS_VOICE') ?: (getenv('TTS_VOICE') ?: 'alloy');

/**
 * ElevenLabs config (preferred for timeout)
 */
$elevenKey     = getenv('ELEVENLABS_API_KEY') ?: '';
$elevenModel   = getenv('ELEVENLABS_MODEL') ?: 'eleven_multilingual_v2';
$elevenFormat  = getenv('ELEVENLABS_OUTPUT_FORMAT') ?: 'mp3_44100_128';
$elevenVoiceId = getenv('VOXIE_EVENTS_ELEVEN_VOICE_ID') ?: (getenv('ELEVEN_VOICE_NEUTRAL') ?: '');

$elevenStability      = (float)(getenv('ELEVEN_STABILITY') ?: '0.65');
$elevenSimilarity     = (float)(getenv('ELEVEN_SIMILARITY') ?: '0.85');
$elevenStyle          = (float)(getenv('ELEVEN_STYLE') ?: '0.25');
$elevenSpeakerBoost   = (int)(getenv('ELEVEN_SPEAKER_BOOST') ?: '1');

function post_json(string $url, array $payload, array $headers, int $timeout=45): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => $timeout
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("curl: $e"); }
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  $j = json_decode($raw, true);
  if ($code >= 400) {
    $msg = is_array($j) ? ($j['error']['message'] ?? $raw) : $raw;
    throw new RuntimeException("HTTP $code: " . $msg);
  }
  if (!is_array($j)) throw new RuntimeException("Bad JSON response");
  return $j;
}

function post_bin(string $url, array $payload, array $headers, int $timeout=60): string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => $timeout
  ]);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $e = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException("curl: $e");
  }

  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($code >= 400) {
    $msg = $resp;
    $j = json_decode($resp, true);
    if (is_array($j)) {
      // Eleven spesso risponde con { "detail": { "message": "..."} } oppure con altri formati
      $msg =
        $j['detail']['message'] ??
        $j['detail'] ??
        $j['message'] ??
        $j['error'] ??
        json_encode($j, JSON_UNESCAPED_UNICODE);
    }
    throw new RuntimeException("HTTP $code (binary): " . (is_string($msg) ? $msg : ''));
  }

  return $resp;
}


/**
 * Estrae testo dall'output della Responses API in modo robusto.
 */
function extract_output_text(array $resp): string {
  if (isset($resp['output_text']) && is_string($resp['output_text']) && $resp['output_text'] !== '') {
    return $resp['output_text'];
  }
  $chunks = [];
  $out = $resp['output'] ?? [];
  if (is_array($out)) {
    foreach ($out as $msg) {
      $content = $msg['content'] ?? [];
      if (!is_array($content)) continue;
      foreach ($content as $c) {
        if (isset($c['text']) && is_string($c['text'])) $chunks[] = $c['text'];
      }
    }
  }
  return trim(implode("\n", $chunks));
}

/**
 * Converte script JSON in testo parlato evitando doppioni e stile "giornale".
 */
function compose_spoken(array $script, array $feed): string {
  $period = (string)($feed['period_label'] ?? 'Questo weekend');
  $parts = [];

  $intro = trim((string)($script['intro'] ?? ''));
  $parts[] = $intro !== '' ? $intro : "Ehi. $period. Ti va di uscire un attimo e fare qualcosa di carino?";

  foreach (($script['picks'] ?? []) as $p) {
    $t  = trim((string)($p['title'] ?? ''));
    $wh = trim((string)($p['where'] ?? ''));
    $pitch = trim((string)($p['pitch'] ?? ($p['why'] ?? '')));

    if ($t === '') continue;

    // Titolo + (location solo se non è già nel titolo)
    $line = $t;
    if ($wh !== '') {
      $t_l = mb_strtolower($t);
      $w_l = mb_strtolower($wh);
      if (mb_strpos($t_l, $w_l) === false) {
        $line .= " — $wh";
      }
      $line .= ".";
    } else {
      $line .= ".";
    }

    if ($pitch !== '') $line .= " " . $pitch;
    $parts[] = trim($line);
  }

  $outro = trim((string)($script['outro'] ?? ''));
  $parts[] = $outro !== '' ? $outro : "Dimmi cosa ti ispira e si va. Anche solo per due passi, eh.";

  $txt = preg_replace('/\s+/u', ' ', implode(" ", $parts));
  return trim((string)$txt);
}

try {
  // ---------- 1) LLM → JSON script (Structured Outputs via Responses API) ----------
  $payload = [
    "model" => $model,
    "input" => [
      ["role" => "system", "content" => $sys],
      ["role" => "user", "content" => json_encode([
        "feed" => $feed,
        "instructions" => [
          "Return JSON matching provided schema.",
          "Max 4 picks.",
          "Do not invent details."
        ]
      ], JSON_UNESCAPED_UNICODE)]
    ],
    "text" => [
      "format" => [
        "type" => "json_schema",
        "name" => "timeout_script_v1",
        "schema" => $schema,
        "strict" => true
      ]
    ]
  ];

  $resp = post_json(
    "https://api.openai.com/v1/responses",
    $payload,
    [
      "Authorization: Bearer $openaiKey",
      "Content-Type: application/json"
    ],
    45
  );

  $outText = extract_output_text($resp);
  if ($outText === '') throw new RuntimeException("No text in Responses output");

  $script = json_decode($outText, true);
  if (!is_array($script)) throw new RuntimeException("LLM output not JSON");

  if (empty($script['picks']) || !is_array($script['picks'])) {
    throw new RuntimeException("LLM returned empty picks (bad prompt or schema not applied)");
  }

  $spoken = compose_spoken($script, $feed);

  // ---------- 2) Save artifacts ----------
  $cacheDir = "$ROOT/data/events/cache/$city";
  if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);

  $outMp3    = "$cacheDir/timeout.latest.mp3";
  $outScript = "$cacheDir/timeout.latest.script.json";
  $outTxt    = "$cacheDir/timeout.latest.txt";

  file_put_contents($outScript, json_encode($script, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  file_put_contents($outTxt, $spoken);


fwrite(STDOUT, "[EVENTS_TTS] engine=$eventsTtsEngine voice_id=$elevenVoiceId eleven_key=" . ($elevenKey !== '' ? 'SET' : 'MISSING') . "\n");

  // ---------- 3) TTS ----------
  if ($eventsTtsEngine === 'elevenlabs') {
    if ($elevenKey === '' || $elevenVoiceId === '') {
      throw new RuntimeException("ElevenLabs selected but ELEVENLABS_API_KEY or VOXIE_EVENTS_ELEVEN_VOICE_ID missing");
    }

    $url = "https://api.elevenlabs.io/v1/text-to-speech/" . rawurlencode($elevenVoiceId) .
           "?output_format=" . rawurlencode($elevenFormat);

    $mp3 = post_bin($url, [
      "text" => $spoken,
      "model_id" => $elevenModel,
      "voice_settings" => [
        "stability" => $elevenStability,
        "similarity_boost" => $elevenSimilarity,
        "style" => $elevenStyle,
        "use_speaker_boost" => ($elevenSpeakerBoost ? true : false),
      ]
    ], [
      "xi-api-key: $elevenKey",
      "Content-Type: application/json",
      "Accept: audio/mpeg"
    ], 90);

    file_put_contents($outMp3, $mp3);
  } else {
    // OpenAI TTS fallback
    $mp3 = post_bin("https://api.openai.com/v1/audio/speech", [
      "model"  => $ttsModel,
      "voice"  => $ttsVoice,
      "format" => "mp3",
      "input"  => $spoken
    ], [
      "Authorization: Bearer $openaiKey",
      "Content-Type: application/json"
    ], 60);

    file_put_contents($outMp3, $mp3);
  }

  fwrite(STDOUT, "OK saved:\n- $outMp3\n- $outTxt\n- $outScript\n");
} catch (Throwable $e) {
  fwrite(STDERR, "ERR: ".$e->getMessage()."\n");
  exit(1);
}
