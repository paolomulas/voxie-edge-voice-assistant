<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/bus.php';

/**
 * Radio skill
 * 01) Reads data/stations/stations.json (object keyed by station id)
 * 02) Matches query against name + tags + moods
 * 03) If query provided: try up to 3 candidates until one starts
 * 04) If query empty: play first valid station
 */

function _radio_as_text($v): string {
  if (is_array($v)) return trim(implode(' ', array_map('strval', $v)));
  if (is_string($v)) return trim($v);
  return '';
}

function skill_radio_run(string $query): array {
  $stations_file = path_data() . '/stations/stations.json';
  if (!is_file($stations_file)) return ['ok'=>false,'err'=>'STATIONS_MISSING','path'=>$stations_file];

  $j = json_decode((string)(file_get_contents($stations_file) ?: ''), true);
  if (!is_array($j)) return ['ok'=>false,'err'=>'STATIONS_BAD_JSON'];

  // Normalize query early (fixes legacy $q usage before definition)
  $q = mb_strtolower(trim($query));
  $q = (string)preg_replace("/[^\\p{L}\\p{N}\\s]+/u", "", $q);
  $q = (string)preg_replace("/\\s+/u", " ", trim($q));

  // Playlist override (optional, file-based)
  if ($q !== '') {
    $pl = radio_resolve_playlist($q);
    if ($pl) {
      [$stationKey, $playlistKey] = $pl;
      if (isset($j[$stationKey]["url"])) {
        audio_stop();
        audio_play_stream((string)$j[$stationKey]["url"]);
        return ["ok"=>true, "playlist"=>$playlistKey, "station"=>$stationKey];
      }
    }
  }

  $matches = [];
  $fallbackFirstUrl = '';

  foreach ($j as $key => $st) {
    if (!is_array($st)) continue;

    $name  = _radio_as_text($st['name'] ?? $st['title'] ?? $key);
    $url   = _radio_as_text($st['url'] ?? $st['stream'] ?? $st['stream_url'] ?? $st['link'] ?? '');
    $tags  = _radio_as_text($st['tags'] ?? []);
    $moods = _radio_as_text($st['moods'] ?? []);

    if ($url === '') continue;
    if ($fallbackFirstUrl === '') $fallbackFirstUrl = $url;

    if ($q === '') continue;

    $hay = mb_strtolower(trim($name . ' ' . $tags . ' ' . $moods));
    if ($hay === '') continue;

    $score = 0;
    if (preg_match('/\b'.preg_quote($q,'/').'\b/u', $hay)) $score += 3;
    if (str_contains($hay, $q)) $score += 1;

    if ($score > 0) $matches[] = ['url'=>$url,'score'=>$score,'name'=>$name];
  }

  // If query empty: first valid
  if ($q === '') {
    if ($fallbackFirstUrl === '') return ['ok'=>false,'err'=>'NO_STATION_FOUND'];
    return audio_play_stream($fallbackFirstUrl);
  }

  // No match
  if (count($matches) === 0) return ['ok'=>false,'err'=>'NO_STATION_MATCH','q'=>$q];

  // Sort by score desc + add some randomness among top results
  usort($matches, fn($a,$b) => $b['score'] <=> $a['score']);
  $top = array_slice($matches, 0, min(8, count($matches)));
  shuffle($top);

  // Try up to 3 URLs (dead stream fallback)
  $attempts = min(3, count($top));
  for ($i=0; $i<$attempts; $i++) {
    $url = $top[$i]['url'];
    $res = audio_play_stream($url);
    if (!empty($res['ok'])) return $res;
    usleep(200 * 1000);
  }

  return ['ok'=>false,'err'=>'STREAM_FAILED_FOR_MATCHES','q'=>$q,'tried'=>$attempts];
}

/**
 * Playlist resolver
 * @return array|null [station_key, playlist_key]
 */
function radio_resolve_playlist(string $q): ?array {
  $q_l = strtolower($q);

  // Simple IT aliases -> playlist keys
  if (strpos($q_l, 'stud') !== false || strpos($q_l, 'concentr') !== false) $q_l = 'study';
  if (strpos($q_l, 'rilass') !== false || strpos($q_l, 'calm') !== false) $q_l = 'chill';
  if (strpos($q_l, 'indie') !== false || strpos($q_l, 'alternative') !== false) $q_l = 'indie';

  $pl_file = path_data() . '/stations/playlists.json';
  if (!is_file($pl_file)) return null;

  $playlists = json_decode((string)(file_get_contents($pl_file) ?: ''), true);
  if (!is_array($playlists)) return null;

  if (!isset($playlists[$q_l]) || !is_array($playlists[$q_l])) return null;

  $keys = $playlists[$q_l];
  if (count($keys) === 0) return null;

  $pick = $keys[array_rand($keys)];
  return [$pick, $q_l];
}
