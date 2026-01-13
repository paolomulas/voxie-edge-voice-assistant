<?php
// Usage: php news_to_feed_v1.php feed_data.json > feed.v1.json

$in = $argv[1] ?? null;
if (!$in || !file_exists($in)) {
  fwrite(STDERR, "Usage: php news_to_feed_v1.php feed_data.json\n");
  exit(1);
}

$data = json_decode(file_get_contents($in), true);
if (!$data) {
  fwrite(STDERR, "Invalid JSON\n");
  exit(1);
}

$items = [];
foreach ($data['items'] ?? [] as $i => $it) {
  $items[] = [
    'id' => 'news-' . ($i+1),
    'type' => 'news',
    'title' => $it['title'] ?? '',
    'summary' => $it['summary'] ?? '',
    'created_at' => $it['created_at'] ?? date(DATE_ATOM),
    'url' => $it['url'] ?? null,
    'audio' => [
      'script' => trim(($it['title'] ?? '') . '. ' . ($it['summary'] ?? '')),
      'voice' => 'default',
      'model' => 'elevenlabs',
      'local_path' => $it['local_path'] ?? null
    ]
  ];
}

$out = [
  'schema' => 'voxie.feed.v1',
  'skill' => 'news',
  'generated_at' => date(DATE_ATOM),
  'locale' => [
    'country' => 'IT',
    'region' => '',
    'city' => ''
  ],
  'ttl_sec' => 1800,
  'source' => [
    'backend' => 'local',
    'provider' => 'demo'
  ],
  'items' => $items
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
