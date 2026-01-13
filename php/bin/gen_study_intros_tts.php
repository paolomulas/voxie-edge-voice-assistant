<?php
declare(strict_types=1);

/**
 * gen_study_intros_tts.php
 * Reads data/phrases/study_intros.json and generates MP3 files via OpenAI TTS.
 */

require_once __DIR__ . '/../core/config.php';
bv_env_load(bv_base_dir() . '/.env');

$apiKey = getenv('OPENAI_API_KEY') ?: (getenv('LLM_API_KEY') ?: '');
if ($apiKey === '') { fwrite(STDERR, "Missing OPENAI_API_KEY\n"); exit(1); }

$model = getenv('OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
$voice = getenv('OPENAI_TTS_VOICE') ?: 'alloy';

$base = bv_base_dir();
$list = json_decode(file_get_contents($base . '/data/phrases/study_intros.json') ?: '[]', true);

function tts_mp3(string $text, string $out, string $apiKey, string $model, string $voice): void {
  $ch = curl_init("https://api.openai.com/v1/audio/speech");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer $apiKey",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode([
      "model"  => $model,
      "voice"  => $voice,
      "format" => "mp3",
      "input"  => $text
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60
  ]);
  $bin = curl_exec($ch);
  curl_close($ch);
  file_put_contents($out, $bin);
}

$i = 1;
foreach ($list as $t) {
  $out = sprintf("%s/assets/intros_study_mp3/study_%02d.mp3", $base, $i++);
  echo "TTS study intro -> $out\n";
  tts_mp3((string)$t, $out, $apiKey, $model, $voice);
}
echo "DONE\n";
