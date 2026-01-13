<?php

/**
 * semantic_intent.php
 * Embedding-based intent guess (fallback only).
 * This code is intentionally lightweight: file-based vectors + one embeddings call.
 */

function voxie_env_load_once(): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $envPath = __DIR__ . '/../../.env';
  if (!file_exists($envPath)) return;

  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;

    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $k = trim(substr($line, 0, $pos));
    $v = trim(substr($line, $pos + 1));

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

function cosine_sim(array $a, array $b): float {
  $dot = $na = $nb = 0.0;
  $n = count($a);
  for ($i = 0; $i < $n; $i++) {
    $dot += $a[$i] * $b[$i];
    $na  += $a[$i] * $a[$i];
    $nb  += $b[$i] * $b[$i];
  }
  $den = sqrt($na) * sqrt($nb);
  return $den > 0 ? ($dot / $den) : 0.0;
}

/**
 * Returns:
 *  ['intent'=>string,'score'=>float,'second_intent'=>string,'second_score'=>float,'margin'=>float]
 * or null
 */
function semantic_intent_guess(string $text): ?array {
  if (getenv('VOXIE_FEATURE_SEMANTIC') !== '1') return null;

  $text = trim(mb_strtolower($text));
  if ($text === '') return null;

  $vecFile = __DIR__ . '/../../data/vec/intents_vectors.json';
  if (!file_exists($vecFile)) return null;

  $doc = json_decode(file_get_contents($vecFile), true);
  if (!$doc || empty($doc['intents'])) return null;

  voxie_env_load_once();

  $apiKey = trim((string)getenv('OPENAI_API_KEY'));
  $apiKey = trim($apiKey, "\"'");
  if ($apiKey === '') return null;

  $model = (string)($doc['meta']['model'] ?? 'text-embedding-3-small');

  // Query embedding call (fallback only)
  $url = 'https://api.openai.com/v1/embeddings';
  $payload = ['model' => $model, 'input' => $text];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $res = json_decode($raw, true);
  if ($code < 200 || $code >= 300) return null;
  if (!isset($res['data'][0]['embedding'])) return null;

  $qv = $res['data'][0]['embedding'];

  $scores = [];
  foreach ($doc['intents'] as $it) {
    if (empty($it['intent']) || empty($it['centroid'])) continue;
    $scores[$it['intent']] = cosine_sim($qv, $it['centroid']);
  }
  if (!$scores) return null;

  arsort($scores);
  $keys = array_keys($scores);

  $bestIntent = $keys[0];
  $bestScore  = (float)$scores[$bestIntent];

  $secondIntent = $keys[1] ?? '';
  $secondScore  = $secondIntent ? (float)$scores[$secondIntent] : 0.0;

  $margin = $bestScore - $secondScore;

  // Decision policy: allow low-ish absolute scores, but require margin.
  $minScore  = (float)(getenv('VOXIE_SEMANTIC_MIN_SCORE')  ?: '0.55');
  $minMargin = (float)(getenv('VOXIE_SEMANTIC_MIN_MARGIN') ?: '0.04');

  // Soft exception: events_timeout is conversational by nature
  if ($bestIntent === 'events_timeout' && $bestScore >= $minScore) {
    return [
      'intent' => $bestIntent,
      'score' => $bestScore,
      'second_intent' => $secondIntent,
      'second_score' => $secondScore,
      'margin' => $margin,
    ];
  }

  if ($bestScore < $minScore) return null;
  if ($margin < $minMargin) return null;

  // Optional intent aliases (OFF by default)
  // This allows gradually migrating intent names without rebuilding vectors immediately.
  $useAliases = getenv('VOXIE_SEMANTIC_INTENT_ALIASES') === '1';
  if ($useAliases) {
    $alias = [
      // legacy -> new
      'studio'     => 'soundscape',
      'study_on'   => 'mentor',
      'study_auto' => 'mentor',
      // keep study_off as-is (explicit command)
    ];
    if (isset($alias[$bestIntent])) $bestIntent = $alias[$bestIntent];
    if ($secondIntent && isset($alias[$secondIntent])) $secondIntent = $alias[$secondIntent];
  }

  return [
    'intent' => $bestIntent,
    'score' => $bestScore,
    'second_intent' => $secondIntent,
    'second_score' => $secondScore,
    'margin' => $margin,
  ];
}
