<?php
declare(strict_types=1);

/**
 * BitVox / Voxie — ASR (OpenAI) — single file, zero deps
 *
 * API:
 *   asr_transcribe_wav($wavPath, $lang='it'): ['ok'=>bool, 'text'=>string] or ['ok'=>false,'err'=>...]
 *
 * CLI:
 *   php asr.php /path/file.wav [lang]
 */

/* ------------------------------------------------------------
 * 0) Tiny .env loader (no external libs)
 * ------------------------------------------------------------ */
function asr_load_env(string $envFile): void {
  if (!is_file($envFile)) return;

  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) return;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;

    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);

    // Remove surrounding quotes
    $v = trim($v, "\"'");

    if ($k === '') continue;

    putenv($k . '=' . $v);
    $_ENV[$k] = $v;
  }
}

/**
 * Load env with a conservative search order:
 * 1) VOXIE_ROOT/.env (explicit)
 * 2) repo root: __DIR__/../../.env (typical layout)
 * 3) legacy hardcoded path (kept to avoid breaking existing installs)
 */
(function (): void {
  $voxieRoot = trim((string)(getenv('VOXIE_ROOT') ?: ''));
  if ($voxieRoot !== '') {
    asr_load_env($voxieRoot . '/.env');
  }

  $repoEnv = realpath(__DIR__ . '/../../.env');
  if ($repoEnv) {
    asr_load_env($repoEnv);
  }

  // Legacy fallback (kept intentionally)
  asr_load_env('/home/paolo/bitvox/2.8/.env');
})();

/* ------------------------------------------------------------
 * 1) Config helpers
 * ------------------------------------------------------------ */
function asr_debug_enabled(): bool {
  return (getenv('ASR_DEBUG') ?: '') === '1';
}

function asr_openai_key(): string {
  return getenv('OPENAI_API_KEY') ?: (getenv('LLM_API_KEY') ?: '');
}

function asr_model(): string {
  return getenv('OPENAI_ASR_MODEL') ?: 'gpt-4o-mini-transcribe';
}

function asr_endpoint(): string {
  return getenv('OPENAI_ASR_ENDPOINT') ?: 'https://api.openai.com/v1/audio/transcriptions';
}

/* ------------------------------------------------------------
 * 2) Main API
 * ------------------------------------------------------------ */
function asr_transcribe_wav(string $wavPath, string $lang='it'): array {
  $key = asr_openai_key();
  if ($key === '') return ['ok' => false, 'err' => 'NO_ASR_KEY'];

  if (!is_file($wavPath)) return ['ok' => false, 'err' => 'WAV_NOT_FOUND', 'path' => $wavPath];
  $size = filesize($wavPath);
  if ($size === false || $size < 1000) return ['ok' => false, 'err' => 'BAD_WAV', 'size' => $size ?: 0];

  $url = asr_endpoint();
  $model = asr_model();

  $ch = curl_init($url);
  if ($ch === false) return ['ok' => false, 'err' => 'CURL_INIT_FAIL'];

  $post = [
    'model' => $model,
    'file'  => new CURLFile($wavPath, 'audio/wav', basename($wavPath)),
    'language' => $lang,
    // Lower temperature reduces hallucinations
    'temperature' => '0',
    'prompt' => 'Trascrivi fedelmente in italiano. Domande tipiche: meteo, orari, comandi vocali BitVox.',
  ];

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$key}",
    ],
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90,
  ]);

  $raw  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($raw === false || $code < 200 || $code >= 300) {
    return [
      'ok' => false,
      'err' => 'ASR_HTTP_FAIL',
      'code' => $code,
      'curl' => $err,
      'body' => mb_substr((string)$raw, 0, 800),
      'model' => $model,
      'url' => $url,
    ];
  }

  $j = json_decode((string)$raw, true);
  if (!is_array($j)) {
    return ['ok' => false, 'err' => 'BAD_JSON', 'raw' => mb_substr((string)$raw, 0, 800)];
  }

  $text = trim((string)($j['text'] ?? ''));
  if ($text === '') {
    return ['ok' => false, 'err' => 'EMPTY_TRANSCRIPT', 'raw' => mb_substr((string)$raw, 0, 800)];
  }

  if (asr_debug_enabled()) {
    return ['ok' => true, 'text' => $text, 'raw' => $j];
  }

  return ['ok' => true, 'text' => $text];
}

/* ------------------------------------------------------------
 * 3) CLI entrypoint
 * ------------------------------------------------------------ */
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === __FILE__) {
  $wav  = $argv[1] ?? '';
  $lang = $argv[2] ?? 'it';

  if ($wav === '') {
    fwrite(STDERR, "Usage: php asr.php /path/audio.wav [lang]\n");
    exit(1);
  }

  $r = asr_transcribe_wav($wav, $lang);

  if (empty($r['ok'])) {
    fwrite(STDERR, json_encode($r, JSON_UNESCAPED_SLASHES) . "\n");
    exit(2);
  }

  // Print only transcript text (easy to capture from Python)
  echo $r['text'];
  exit(0);
}
