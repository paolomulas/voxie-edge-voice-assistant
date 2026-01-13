<?php
// semantic match test (loads .env)

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

$apiKey = trim((string)getenv('OPENAI_API_KEY'));
$apiKey = trim($apiKey, "\"'");
if ($apiKey === '') {
  echo "ERR: missing OPENAI_API_KEY\n";
  exit(1);
}

$vecFile = __DIR__ . '/../../data/vec/intents_vectors.json';
$doc = json_decode(file_get_contents($vecFile), true);
if (!$doc) {
  echo "ERR: cannot load vector store\n";
  exit(1);
}

$query = $argv[1] ?? 'che abbiamo di nuovo oggi';

$url = 'https://api.openai.com/v1/embeddings';
$payload = [
  'model' => $doc['meta']['model'],
  'input' => $query,
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$res = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
  $msg = $res['error']['message'] ?? $raw;
  echo "ERR: HTTP $code: $msg\n";
  exit(1);
}
if (!isset($res['data'][0]['embedding'])) {
  echo "ERR: invalid embedding response\n";
  print_r($res);
  exit(1);
}

$qv = $res['data'][0]['embedding'];

function cosine(array $a, array $b): float {
  $dot = $na = $nb = 0.0;
  $n = count($a);
  for ($i = 0; $i < $n; $i++) {
    $dot += $a[$i] * $b[$i];
    $na  += $a[$i] * $a[$i];
    $nb  += $b[$i] * $b[$i];
  }
  return $dot / (sqrt($na) * sqrt($nb));
}

$scores = [];
foreach ($doc['intents'] as $it) {
  $scores[$it['intent']] = cosine($qv, $it['centroid']);
}

arsort($scores);

echo "QUERY: \"$query\"\n";
echo "MODEL: " . $doc['meta']['model'] . "\n";
echo "TOP MATCHES:\n";
$i = 0;
foreach ($scores as $intent => $score) {
  printf("  %-12s %.4f\n", $intent, $score);
  if (++$i >= 5) break;
}
