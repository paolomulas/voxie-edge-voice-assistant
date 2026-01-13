<?php
declare(strict_types=1);

/**
 * gen_intros_tts.php
 * 01) Reads data/phrases/latency_intros.json + latency_fillers.json
 * 02) Calls OpenAI TTS and writes mp3 files into assets/*_mp3/
 *
 * Env:
 * - OPENAI_API_KEY (or LLM_API_KEY)
 * - OPENAI_TTS_MODEL (e.g. gpt-4o-mini-tts)
 * - OPENAI_TTS_VOICE (e.g. alloy)
 */

require_once __DIR__ . '/../core/config.php';
bv_env_load(bv_base_dir() . '/.env');

$apiKey = getenv('OPENAI_API_KEY') ?: (getenv('LLM_API_KEY') ?: '');
if ($apiKey === '') { fwrite(STDERR, "Missing OPENAI_API_KEY (or LLM_API_KEY)\n"); exit(1); }

$model = getenv('OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
$voice = getenv('OPENAI_TTS_VOICE') ?: 'alloy';

$base = bv_base_dir();
$intros  = json_decode(file_get_contents($base . '/data/phrases/latency_intros.json') ?: '[]', true) ?: [];
$fillers = json_decode(file_get_contents($base . '/data/phrases/latency_fillers.json') ?: '[]', true) ?: [];

function tts_mp3(string $text, string $outPath, string $apiKey, string $model, string $voice): bool {
  $url = "https://api.openai.com/v1/audio/speech";
  $payload = json_encode([
    "model" => $model,
    "voice" => $voice,
    "format" => "mp3",
    "input" => $text
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$apiKey}",
      "Content-Type: application/json"
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
    fwrite(STDERR, "TTS FAIL code={$code} err={$err}\n");
    return false;
  }

  @file_put_contents($outPath, $bin);
  return is_file($outPath) && filesize($outPath) > 1000;
}

@mkdir($base . '/assets/intros_mp3', 0777, true);
@mkdir($base . '/assets/fillers_mp3', 0777, true);

$i = 1;
foreach ($intros as $t) {
  $out = sprintf("%s/assets/intros_mp3/intro_%02d.mp3", $base, $i++);
  echo "TTS intro -> $out\n";
  tts_mp3((string)$t, $out, $apiKey, $model, $voice);
}

$i = 1;
foreach ($fillers as $t) {
  $out = sprintf("%s/assets/fillers_mp3/filler_%02d.mp3", $base, $i++);
  echo "TTS filler -> $out\n";
  tts_mp3((string)$t, $out, $apiKey, $model, $voice);
}

echo "DONE\n";
