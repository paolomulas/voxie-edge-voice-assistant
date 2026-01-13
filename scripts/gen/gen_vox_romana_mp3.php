#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Vox Romana MP3 Generator (build-time only)
 * - Usa ElevenLabs
 * - Genera MP3 da JSON (latino + italiano)
 * - NON viene usato a runtime
 */

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

$jsonPath = $argv[1] ?? (__DIR__ . '/../data/vox_romana/vox_romana_demo.json');
$outDir   = __DIR__ . '/../assets/vox_romana_mp3';
@mkdir($outDir, 0777, true);

$data = json_decode(file_get_contents($jsonPath) ?: '', true);
if (!is_array($data)) {
  fwrite(STDERR, "ERROR: invalid JSON: $jsonPath\n");
  exit(1);
}

$model  = envv('ELEVENLABS_MODEL', 'eleven_multilingual_v2');
$format = envv('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128');

$voiceMap = [
  'seneca'        => envv('ELEVEN_VOICE_SENECA'),
  'marco aurelio' => envv('ELEVEN_VOICE_AURELIUS'),
  'aurelio'       => envv('ELEVEN_VOICE_AURELIUS'),
  'cicerone'      => envv('ELEVEN_VOICE_CICERO'),
  'cicero'        => envv('ELEVEN_VOICE_CICERO'),
];

$stability  = (float)envv('ELEVEN_STABILITY', '0.65');
$similarity = (float)envv('ELEVEN_SIMILARITY', '0.85');
$style      = (float)envv('ELEVEN_STYLE', '0.25');
$boost      = envv('ELEVEN_SPEAKER_BOOST', '1') === '1';

function eleven_tts(string $apiKey, string $voiceId, string $text, array $opt): string {
  $url = "https://api.elevenlabs.io/v1/text-to-speech/$voiceId/stream?output_format=".$opt['format'];

  $payload = json_encode([
    'text' => $text,
    'model_id' => $opt['model'],
    'voice_settings' => [
      'stability' => $opt['stability'],
      'similarity_boost' => $opt['similarity'],
      'style' => $opt['style'],
      'use_speaker_boost' => $opt['boost']
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

$ok = 0; $skip = 0; $fail = 0;

foreach ($data as $item) {
  $file = $item['file'] ?? '';
  $latin = trim($item['latin'] ?? '');
  $italian = trim($item['italian'] ?? '');
  $ph = strtolower(trim($item['philosopher'] ?? ''));

  if ($file === '' || $latin === '' || $italian === '') {
    $skip++; continue;
  }

  $out = "$outDir/$file";
  if (is_file($out) && filesize($out) > 2000) {
    echo "[SKIP] $file exists\n";
    $skip++; continue;
  }

  $voiceId = $voiceMap[$ph] ?? '';
  if ($voiceId === '') {
    echo "[FAIL] no voice for $ph\n";
    $fail++; continue;
  }

  $script = $latin . "\n\n" . $italian;

  echo "[GEN] $file\n";
  try {
    $bin = eleven_tts($apiKey, $voiceId, $script, [
      'model'=>$model,
      'format'=>$format,
      'stability'=>$stability,
      'similarity'=>$similarity,
      'style'=>$style,
      'boost'=>$boost
    ]);
    file_put_contents($out, $bin);
    $ok++;
  } catch (Throwable $e) {
    echo "[FAIL] $file ".$e->getMessage()."\n";
    @unlink($out);
    $fail++;
  }
}

echo "DONE ok=$ok skip=$skip fail=$fail\n";
exit($fail > 0 ? 1 : 0);
