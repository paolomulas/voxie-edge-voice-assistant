#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/core/config.php';
bv_env_load(bv_base_dir() . '/.env');

$apiKey = getenv('ELEVENLABS_API_KEY');
$voice  = getenv('ELEVEN_VOICE_NEUTRAL');
$model  = getenv('ELEVENLABS_MODEL') ?: 'eleven_multilingual_v2';
$format = getenv('ELEVENLABS_OUTPUT_FORMAT') ?: 'mp3_44100_128';

if (!$apiKey || !$voice) {
  fwrite(STDERR, "Missing ELEVENLABS_API_KEY or ELEVEN_VOICE_NEUTRAL\n");
  exit(1);
}

$text = "Ascolta.";

$payload = json_encode([
  'text' => $text,
  'model_id' => $model,
  'voice_settings' => [
    'stability' => 0.6,
    'similarity_boost' => 0.8,
    'style' => 0.1,
    'use_speaker_boost' => false
  ]
], JSON_UNESCAPED_UNICODE);

$url = "https://api.elevenlabs.io/v1/text-to-speech/$voice/stream?output_format=$format";

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_HTTPHEADER => [
    "xi-api-key: $apiKey",
    "Content-Type: application/json",
    "Accept: audio/mpeg"
  ]
]);

$bin = curl_exec($ch);
curl_close($ch);

$out = __DIR__ . '/../assets/vox_romana_mp3/intros/intro.mp3';
file_put_contents($out, $bin);

echo "Intro generated: $out\n";
