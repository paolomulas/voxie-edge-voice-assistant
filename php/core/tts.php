<?php
declare(strict_types=1);

/**
 * tts.php (OpenAI default)
 * - tts_mp3_cached($text) -> returns mp3 path
 * - cache key = sha1(model|voice|text)
 * - writes to data/cache/tts/
 */

function tts_openai_key(): string {
  return getenv('OPENAI_API_KEY') ?: getenv('LLM_API_KEY') ?: '';
}

function tts_openai_model(): string {
  return getenv('OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
}

function tts_openai_voice(): string {
  return getenv('OPENAI_TTS_VOICE') ?: 'alloy';
}

function tts_cache_dir(): string {
  return path_data() . '/cache/tts';
}

function tts_normalize_text(string $t): string {
  // Keep responses short/stable for voice output
  $t = trim((string)preg_replace('/\s+/u', ' ', $t));
  return mb_substr($t, 0, 900);
}

function tts_mp3_cached(string $text): array {
  $key = tts_openai_key();
  if ($key === '') return ['ok' => false, 'err' => 'NO_TTS_KEY'];

  $model = tts_openai_model();
  $voice = tts_openai_voice();

  $text = tts_normalize_text($text);
  if ($text === '') return ['ok' => false, 'err' => 'EMPTY_TEXT'];

  @mkdir(tts_cache_dir(), 0777, true);

  $hash = sha1($model . '|' . $voice . '|' . $text);
  $out  = tts_cache_dir() . "/tts_{$hash}.mp3";

  // Cache hit
  if (is_file($out) && filesize($out) > 1000) {
    return ['ok' => true, 'path' => $out, 'cached' => true];
  }

  // OpenAI TTS request
  $payload = json_encode([
    'model'  => $model,
    'voice'  => $voice,
    'format' => 'mp3',
    'input'  => $text,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init('https://api.openai.com/v1/audio/speech');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$key}",
      "Content-Type: application/json",
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
  ]);

  $bin  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($bin === false || $code < 200 || $code >= 300) {
    return ['ok' => false, 'err' => 'TTS_HTTP_FAIL', 'code' => $code, 'curl' => $err];
  }

  @file_put_contents($out, $bin);

  if (!is_file($out) || filesize($out) < 1000) {
    return ['ok' => false, 'err' => 'TTS_WRITE_FAIL'];
  }

  return ['ok' => true, 'path' => $out, 'cached' => false];
}
