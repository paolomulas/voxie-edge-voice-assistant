<?php
/**
 * build_vec_store.php (one-shot)
 * Reads data/vec/intents_source.json, calls OpenAI Embeddings API,
 * writes data/vec/intents_vectors.json (centroids).
 *
 * Usage:
 *   php php/bin/build_vec_store.php
 *
 * Expects OPENAI_API_KEY in environment OR in ../../.env (supports quoted values).
 */

// -----------------------
// Minimal .env loader
// -----------------------
$envPath = __DIR__ . '/../../.env';
if (file_exists($envPath)) {
  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $k = trim(substr($line, 0, $pos));
    $v = trim(substr($line, $pos + 1));

    // Strip optional quotes "..." or '...'
    if (strlen($v) >= 2) {
      $first = $v[0];
      $last  = $v[strlen($v) - 1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        $v = substr($v, 1, -1);
      }
    }
    if ($k !== '') putenv($k . '=' . $v);
  }
}

// -----------------------
// Config
// -----------------------
$apiKey = getenv('OPENAI_API_KEY');
$apiKey = trim((string)$apiKey);
// Ultra-safe: strip quotes in case the loader left them
$apiKey = trim($apiKey, "\"'");

if ($apiKey === '') {
  fwrite(STDERR, "ERROR: OPENAI_API_KEY not set\n");
  exit(1);
}

$src = __DIR__ . '/../../data/vec/intents_source.json';
$out = __DIR__ . '/../../data/vec/intents_vectors.json';

if (!file_exists($src)) {
  fwrite(STDERR, "ERROR: missing $src\n");
  exit(1);
}

$doc = json_decode(file_get_contents($src), true);
if (!is_array($doc) || empty($doc['intents']) || !is_array($doc['intents'])) {
  fwrite(STDERR, "ERROR: invalid intents_source.json\n");
  exit(1);
}

$model = getenv('VOXIE_EMBED_MODEL') ?: 'text-embedding-3-small';
$dimensions = getenv('VOXIE_EMBED_DIM') ? (int)getenv('VOXIE_EMBED_DIM') : null;

// -----------------------
// HTTP helper
// -----------------------
function http_post_json(string $url, array $payload, string $apiKey): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
  ]);
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException("curl error: $err");
  }
  curl_close($ch);

  $j = json_decode($raw, true);
  if ($code < 200 || $code >= 300) {
    $msg = $j['error']['message'] ?? $raw;
    throw new RuntimeException("HTTP $code: $msg");
  }
  return $j;
}

// -----------------------
// Vector ops
// -----------------------
function vec_add(array &$acc, array $v): void {
  $n = count($v);
  if (!$acc) $acc = array_fill(0, $n, 0.0);
  for ($i = 0; $i < $n; $i++) $acc[$i] += (float)$v[$i];
}
function vec_div(array &$v, float $d): void {
  $n = count($v);
  for ($i = 0; $i < $n; $i++) $v[$i] = $v[$i] / $d;
}

// -----------------------
// Build
// -----------------------
$url = 'https://api.openai.com/v1/embeddings';

$intentsOut = [];
$total = 0;

try {
  foreach ($doc['intents'] as $item) {
    $intent = (string)($item['intent'] ?? '');
    $examples = $item['examples'] ?? [];
    if ($intent === '' || !is_array($examples) || count($examples) === 0) continue;

    fwrite(STDERR, "[VEC] intent=$intent examples=" . count($examples) . "\n");

    $payload = [
      'model' => $model,
      'input' => array_values($examples),
    ];
    if ($dimensions) $payload['dimensions'] = $dimensions;

    $res = http_post_json($url, $payload, $apiKey);

    $data = $res['data'] ?? [];
    if (count($data) !== count($examples)) {
      throw new RuntimeException("Embedding count mismatch for intent=$intent");
    }

    // Preserve order by index
    usort($data, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

    $centroid = [];
    foreach ($data as $row) {
      $emb = $row['embedding'] ?? null;
      if (!is_array($emb)) throw new RuntimeException("Invalid embedding for intent=$intent");
      vec_add($centroid, $emb);
    }
    vec_div($centroid, (float)count($data));

    $intentsOut[] = [
      'intent' => $intent,
      'examples' => array_values($examples),
      'centroid' => $centroid,
    ];
    $total += count($examples);
  }

  $outDoc = [
    'meta' => [
      'version' => $doc['meta']['version'] ?? '2.9',
      'lang' => $doc['meta']['lang'] ?? 'it',
      'model' => $model,
      'dimensions' => $dimensions,
      'created_at' => date('c'),
      'total_examples' => $total,
      'notes' => 'Centroid embeddings per intent (one-shot). Runtime uses cosine with query embedding only in fallback.',
    ],
    'intents' => $intentsOut,
  ];

  file_put_contents($out, json_encode($outDoc, JSON_UNESCAPED_UNICODE));
  fwrite(STDERR, "[VEC] Wrote $out\n");

} catch (Throwable $e) {
  fwrite(STDERR, "[VEC][ERROR] " . $e->getMessage() . "\n");
  exit(1);
}
