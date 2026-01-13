<?php
// Usage: php timeout_to_feed_v1.php weekend.latest.json timeout.latest.txt > feed.v1.json

$jsonFile = $argv[1] ?? null;
$textFile = $argv[2] ?? null;

if (!$jsonFile || !$textFile || !file_exists($jsonFile) || !file_exists($textFile)) {
  fwrite(STDERR, "Usage: php timeout_to_feed_v1.php weekend.json timeout.txt\n");
  exit(1);
}

$data = json_decode(file_get_contents($jsonFile), true);
$text = trim(file_get_contents($textFile));

$out = [
  'schema' => 'voxie.feed.v1',
  'skill' => 'timeout',
  'generated_at' => date(DATE_ATOM),
  'locale' => [
    'country' => $data['locale']['country'] ?? 'IT',
    'region' => $data['locale']['region'] ?? '',
    'city' => $data['city'] ?? ''
  ],
  'ttl_sec' => 3600,
  'source' => [
    'backend' => 'local',
    'provider' => $data['source'] ?? 'demo'
  ],
  'items' => [
    [
      'id' => 'timeout-001',
      'type' => 'timeout',
      'title' => 'Cosa fare',
      'summary' => '',
      'created_at' => date(DATE_ATOM),
      'url' => null,
      'audio' => [
        'script' => $text,
        'voice' => 'default',
        'model' => 'elevenlabs',
        'local_path' => 'data/cache/feed/timeout-001.mp3'
      ]
    ]
  ]
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
