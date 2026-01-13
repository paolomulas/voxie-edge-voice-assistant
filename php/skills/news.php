<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/bus.php';

/**
 * News skill (deterministic, offline)
 *
 * Supports two feed formats:
 * A) Grouped (legacy):
 *    { "general":[{"local_path":"...mp3"},...], "tech":[...], ... }
 *
 * B) Flat (new):
 *    { "generated_at":"...", "items":[{"type":"news","category":"tech","local_path":"...mp3"}, ...] }
 */

function _news_norm(string $s): string {
  $s = mb_strtolower(trim($s));
  $s = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s);
  $s = (string)preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function _news_lastfile(string $cat): string {
  return path_data() . '/state/news_last_' . preg_replace('/[^a-z0-9_]+/i', '_', $cat) . '.txt';
}

/**
 * Normalize category + apply aliases.
 */
function _news_normalize_category(string $category): string {
  $cat = _news_norm($category);

  // Typical stopwords if user phrasing is noisy.
  $stop = ['abbiamo','che','notizie','news','oggi','ultime','ultima','un','una','del','della','dei','degli','di'];
  if (in_array($cat, $stop, true)) $cat = '';

  $alias = [
    'tecnologia' => 'tech',
    'tech' => 'tech',
    'economia' => 'finanza',
    'finanza' => 'finanza',
    'sport' => 'sport',
    'generale' => 'general',
    'general' => 'general',
    'cronaca' => 'general',
    'intrattenimento' => 'intrattenimento',
    'spettacolo' => 'intrattenimento',
  ];
  if ($cat !== '' && isset($alias[$cat])) $cat = $alias[$cat];

  return $cat;
}

/**
 * Always returns a dict cat => items[] with local_path.
 * Accepts both format A and B.
 *
 * @return array<string, array<int, array<string,mixed>>>
 */
function _news_build_buckets(array $j): array {
  // Format B
  if (isset($j['items']) && is_array($j['items'])) {
    $b = [];
    foreach ($j['items'] as $it) {
      if (!is_array($it)) continue;
      if (($it['type'] ?? '') !== 'news') continue;

      $catRaw = (string)($it['category'] ?? 'general');
      $cat = _news_normalize_category($catRaw);
      if ($cat === '') $cat = 'general';

      if (!isset($b[$cat])) $b[$cat] = [];
      $b[$cat][] = $it;
    }
    return $b;
  }

  // Format A
  $b = [];
  foreach ($j as $k => $arr) {
    if (!is_array($arr) || count($arr) === 0) continue;
    if (!is_string($k)) continue;

    $cat = _news_normalize_category($k);
    if ($cat === '') $cat = _news_norm($k);
    if ($cat === '') continue;

    $b[$cat] = $arr;
  }
  return $b;
}

function _news_pick_category(array $buckets, string $cat): string {
  $keys = array_values(array_filter(
    array_keys($buckets),
    fn($k) => is_array($buckets[$k]) && count($buckets[$k]) > 0
  ));
  if (count($keys) === 0) return '';

  if ($cat !== '' && isset($buckets[$cat]) && is_array($buckets[$cat]) && count($buckets[$cat]) > 0) return $cat;
  if (isset($buckets['general']) && is_array($buckets['general']) && count($buckets['general']) > 0) return 'general';

  return $keys[0];
}

function skill_news_run(string $category): array {
  $feed = path_data() . '/cache/news/feed_data.json';
  if (!is_file($feed)) return ['ok'=>false,'err'=>'FEED_MISSING','path'=>$feed];

  $j = json_decode((string)(file_get_contents($feed) ?: ''), true);
  if (!is_array($j)) return ['ok'=>false,'err'=>'FEED_BAD_JSON'];

  $catReq = _news_normalize_category($category);

  $buckets = _news_build_buckets($j);
  if (!$buckets) return ['ok'=>false,'err'=>'NO_NEWS_AVAILABLE'];

  $cat = _news_pick_category($buckets, $catReq);
  if ($cat === '') return ['ok'=>false,'err'=>'NO_NEWS_AVAILABLE'];

  $items = $buckets[$cat];
  $candidates = [];

  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $rel = (string)($it['local_path'] ?? '');
    if ($rel === '') continue;

    $abs = path_data() . '/' . ltrim($rel, '/');
    if (is_file($abs) && filesize($abs) > 1000) $candidates[] = $abs;
  }

  if (count($candidates) === 0) return ['ok'=>false,'err'=>'NO_NEWS_FOR_CATEGORY','category'=>$cat];

  // Avoid immediate repetition
  $lastFile = _news_lastfile($cat);
  $last = is_file($lastFile) ? trim((string)file_get_contents($lastFile)) : '';
  if (count($candidates) > 1 && $last !== '') {
    $filtered = array_values(array_filter($candidates, fn($p) => $p !== $last));
    if ($filtered) $candidates = $filtered;
  }

  $pick = $candidates[array_rand($candidates)];
  @mkdir(dirname($lastFile), 0777, true);
  @file_put_contents($lastFile, $pick);

  return audio_play_mp3($pick);
}
