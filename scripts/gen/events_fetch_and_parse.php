<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$ROOT = dirname(__DIR__);
$srcId = $argv[1] ?? 'cagliaritoday';
$srcPath = $ROOT . "/data/events/sources/{$srcId}.json";
if (!is_file($srcPath)) { fwrite(STDERR, "Missing source: $srcPath\n"); exit(2); }

$src = json_decode(file_get_contents($srcPath), true);
if (!is_array($src)) { fwrite(STDERR, "Invalid JSON in $srcPath\n"); exit(2); }

function curl_get(string $url): string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => 'Voxie/2.9 (offline-demo)',
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
  ]);
  $html = curl_exec($ch);
  if ($html === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException("curl error: $err");
  }
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($code >= 400) throw new RuntimeException("HTTP $code for $url");
  return $html;
}

function resolve_url(string $base, string $href): string {
  if (preg_match('~^https?://~i', $href)) return $href;
  if (str_starts_with($href, '//')) return 'https:' . $href;
  $u = parse_url($base);
  $scheme = $u['scheme'] ?? 'https';
  $host = $u['host'] ?? '';
  if ($href !== '' && $href[0] === '/') return $scheme . '://' . $host . $href;
  $path = $u['path'] ?? '/';
  $dir = preg_replace('~/[^/]*$~', '/', $path);
  return $scheme . '://' . $host . $dir . $href;
}

function clean_text(string $s): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = strip_tags($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function extract_weekend_detail_url(string $indexHtml, string $indexUrl): ?string {
  // 1) pattern “cosa-fare ... weekend ... .html”
  if (preg_match('~href="([^"]*/eventi/cosa-fare[^"]*weekend[^"]*\.html)"~i', $indexHtml, $m)) {
    return resolve_url($indexUrl, $m[1]);
  }
  // 2) fallback generico: primi link eventi con weekend
  if (preg_match('~href="([^"]*/eventi/[^"]*weekend[^"]*\.html)"~i', $indexHtml, $m)) {
    return resolve_url($indexUrl, $m[1]);
  }
  return null;
}

function slice_entry_area(string $html): string {
  $pos = stripos($html, 'class="c-entry');
  if ($pos === false) return $html;
  $chunk = substr($html, $pos);
  // taglia se trovi un footer/script enorme dopo (best effort)
  $cut = stripos($chunk, '<script');
  if ($cut !== false) $chunk = substr($chunk, 0, $cut);
  return $chunk;
}

function html_to_timeout_feed(string $html, string $pageUrl): array {
  $entry = slice_entry_area($html);

  // rimuovi rumore grosso
  $entry = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $entry);
  $entry = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $entry);

  // H1 come label periodo
  $periodLabel = null;
  if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $html, $m)) {
    $periodLabel = clean_text($m[1]);
  }

  // Prendi in ordine H2 + P
  preg_match_all('~(<h2\b[^>]*>.*?</h2>|<p\b[^>]*>.*?</p>)~is', $entry, $blocks);

  $section = null; // in_city / out_city
  $items = [];

  foreach ($blocks[1] as $b) {
    if (stripos($b, '<h2') !== false) {
      $h = clean_text($b);
      $h_l = mb_strtolower($h);
      if (mb_stripos($h_l, 'eventi a cagliari') !== false) $section = 'in_city';
      if (mb_stripos($h_l, 'eventi fuori') !== false) $section = 'out_city';
      continue;
    }

    // P con STRONG = evento
    if (!preg_match('~<strong\b[^>]*>(.*?)</strong>~is', $b, $sm)) continue;

    $titleRaw = clean_text($sm[1]);
    if ($titleRaw === '') continue;
    $title = preg_replace('/\.\s*$/u', '', $titleRaw);

    $url = null;
    if (preg_match('~<a\b[^>]*href="([^"]+)"~i', $b, $am)) {
      $url = resolve_url($pageUrl, $am[1]);
    }

    $full = clean_text($b);

    // descrizione = testo senza titolo all'inizio (best effort)
    $desc = $full;
    if (mb_stripos($desc, $titleRaw) === 0) {
      $desc = trim(mb_substr($desc, mb_strlen($titleRaw)));
    }
    $desc = preg_replace('/^[\s\.\:\-\–\—]+/u', '', $desc);

    if (mb_strlen($desc) > 220) $desc = mb_substr($desc, 0, 217) . '…';

    $items[] = [
      'section' => $section,
      'title' => $title,
      'desc' => $desc ?: null,
      'url' => $url
    ];
  }

  return [
    'ok' => true,
    'schema_version' => 'events.v1',
    'locale' => 'it-IT',
    'city' => 'Cagliari',
    'period_label' => $periodLabel,
    'generated_at' => date('c'),
    'source' => [
      'name' => 'CagliariToday',
      'url' => $pageUrl,
      'detail_url' => $pageUrl
    ],
    'items' => array_slice($items, 0, 10),
    'constraints' => [
      'max_seconds' => 45,
      'tone' => ['anni_80','simpatico','calmo','anti_digital'],
      'no_invent_details' => true,
      'max_items_mentioned' => 4
    ]
  ];
}

try {
  $indexUrl = $src['index_url'];
  $indexHtml = curl_get($indexUrl);

  $detailUrl = extract_weekend_detail_url($indexHtml, $indexUrl);
  if (!$detailUrl) throw new RuntimeException("Weekend detail URL not found from index.");

  $detailHtml = curl_get($detailUrl);
  $feed = html_to_timeout_feed($detailHtml, $detailUrl);

  $cacheDir = $ROOT . '/' . ($src['output']['cache_dir'] ?? 'data/events/cache/cagliari');
  if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);

  $outJson = $cacheDir . '/' . ($src['output']['latest_json'] ?? 'weekend.latest.json');
  file_put_contents($outJson, json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  file_put_contents($cacheDir . '/weekend.source_url.txt', $detailUrl . "\n");

  fwrite(STDOUT, "OK saved: $outJson\n");
  fwrite(STDOUT, "detail_url: $detailUrl\n");
} catch (Throwable $e) {
  fwrite(STDERR, "ERR: " . $e->getMessage() . "\n");
  exit(1);
}
