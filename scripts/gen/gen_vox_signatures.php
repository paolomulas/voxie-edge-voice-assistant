#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/core/config.php';
bv_env_load(bv_base_dir() . '/.env');

function envv(string $k, string $d=''): string {
  $v = getenv($k);
  return ($v === false || $v === '') ? $d : $v;
}

$apiKey = envv('ELEVENLABS_API_KEY');
if ($apiKey === '') {
  fwrite(STDERR, "ERROR: ELEVENLABS_API_KEY missing\n");
  exit(1);
}

$model  = envv('ELEVENLABS_MODEL', 'eleven_multilingual_v2');
$format = envv('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128');

$voices = [
  'seneca'  => envv('ELEVEN_VOICE_SENECA'),
  'aurelio' => envv('ELEVEN_VOICE_AURELIUS'),
  'cicerone'=> envv('ELEVEN_VOICE_CICERO'),
];

foreach ($voices as $k=>$vid) {
  if ($vid === '') {
    fwrite(STDERR, "ERROR: missing voice id for $k (check .env)\n");
    exit(1);
  }
}

function eleven_tts(string $apiKey, string $voiceId, string $text, string $model, string $format): string {
  $url = "https://api.elevenlabs.io/v1/text-to-speech/$voiceId/stream?output_format=$format";
  $payload = json_encode([
    'text' => $text,
    'model_id' => $model,
    'voice_settings' => [
      // firme = secche e “stabili”
      'stability' => 0.90,
      'similarity_boost' => 0.55,
      'style' => 0.00,
      'use_speaker_boost' => false
    ]
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
      "xi-api-key: $apiKey",
      "Content-Type: application/json",
      "Accept: audio/mpeg"
    ],
    CURLOPT_TIMEOUT => 60
  ]);

  $bin = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($bin === false || $code < 200 || $code >= 300) {
    throw new RuntimeException("ElevenLabs error HTTP $code");
  }
  return $bin;
}

$base = bv_base_dir();
$outBase = $base . '/assets/vox_romana_mp3/signatures';

$lines = [
  'seneca' => [
    "Seneca.",
    "Voce di Seneca.",
    "Seneca, stoico."
  ],
  'aurelio' => [
    "Marco Aurelio.",
    "Parla Marco Aurelio.",
    "Marco Aurelio, imperatore."
  ],
  'cicerone' => [
    "Cicerone.",
    "Voce di Cicerone.",
    "Cicerone, oratore."
  ],
];

$ok=0; $fail=0;

foreach ($lines as $who => $variants) {
  $voiceId = $voices[$who];
  $dir = $outBase . "/$who";
  @mkdir($dir, 0777, true);

  foreach ($variants as $i => $txt) {
    $n = $i + 1;
    $out = "$dir/sig_$n.mp3";
    echo "[GEN] $who sig_$n: $txt\n";
    try {
      $bin = eleven_tts($apiKey, $voiceId, $txt, $model, $format);
      file_put_contents($out, $bin);
      $ok++;
    } catch (Throwable $e) {
      echo "[FAIL] $who sig_$n ".$e->getMessage()."\n";
      @unlink($out);
      $fail++;
    }
  }
}

echo "DONE signatures ok=$ok fail=$fail\n";
exit($fail>0 ? 1 : 0);
