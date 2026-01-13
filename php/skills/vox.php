<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/bus.php';

/**
 * Vox Romana (offline, deterministic-ish, local assets)
 * - Optional audio cues (rotation) before author signature
 * - STOP-before-play for responsiveness
 * - MP3 duration fallback via sox (if available)
 */
function skill_vox_run(string $q): array {
  $base = bv_base_dir();

  $jsonPath = $base . '/data/vox_romana/vox_romana_demo.json';
  $mp3Dir   = $base . '/assets/vox_romana_mp3';

  if (!is_file($jsonPath)) return ['ok'=>false,'text'=>"Vox Romana: JSON missing."];

  $data = json_decode((string)file_get_contents($jsonPath), true);
  if (!is_array($data) || !$data) return ['ok'=>false,'text'=>"Vox Romana: invalid JSON."];

  // MP3 duration helper via sox (seconds). Returns 0.0 if unavailable.
  $mp3_duration_sec = function(string $file): float {
    if (!is_file($file)) return 0.0;
    if (!function_exists('shell_exec')) return 0.0;

    $cmd = 'sox --i -D ' . escapeshellarg($file) . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!is_string($out)) return 0.0;

    $v = (float)trim($out);
    return ($v > 0.0 && $v < 3600.0) ? $v : 0.0;
  };

  // Normalize: lowercase + remove punctuation + compact spaces
  $norm = function(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = (string)preg_replace("/[^\\p{L}\\p{N}\\s]+/u", " ", $s);
    $s = (string)preg_replace("/\\s+/u", " ", trim($s));
    return $s;
  };

  // Blocking playback helper: STOP-before-play + status check + duration fallback
  $vox_play_blocking = function(string $file, int $timeoutSec = 30) use ($mp3_duration_sec): void {
    if (!is_file($file)) return;

    audio_stop();
    usleep(80000);

    audio_play_mp3($file);
    usleep(120000);

    $tStart = microtime(true);
    $started = false;

    while (true) {
      $st = audio_status();
      $playing = is_array($st) && !empty($st['playing']);
      if ($playing) { $started = true; break; }
      if ((microtime(true) - $tStart) > 0.35) break;
      usleep(70000);
    }

    if (!$started) {
      $d = $mp3_duration_sec($file);
      if ($d <= 0.0) $d = min(2.0, (float)$timeoutSec);
      usleep((int)(($d + 0.15) * 1000000));
      return;
    }

    $t0 = microtime(true);
    while (true) {
      $st = audio_status();
      $playing = is_array($st) && !empty($st['playing']);
      if (!$playing) break;
      if ((microtime(true) - $t0) > (float)$timeoutSec) break;
      usleep(90000);
    }
  };

  // ----------------------------
  // Input normalization
  // ----------------------------
  $raw = trim($q);
  $t = $norm($raw);

  // Optional explicit author in request
  $ph = '';
  if (preg_match('/\b(seneca)\b/u', $t)) $ph = 'seneca';
  elseif (preg_match('/\b(marco\s*aurelio|aurelio)\b/u', $t)) $ph = 'marco aurelio';
  elseif (preg_match('/\b(cicerone)\b/u', $t)) $ph = 'cicerone';

  // Remove author token from text
  $t = (string)preg_replace('/\b(seneca|cicerone|marco\s*aurelio|aurelio)\b/u', ' ', $t);

  // Stopwords cleanup
  $t = (string)preg_replace('/\b(
    vox|romana|
    dimmi|dammi|dai|fammi|parlami|raccontami|spiegami|
    qualcosa|qualcuno|una|un|frase|massima|pensiero|consiglio|
    serio|seria|profondo|profonda|
    per\s+favore|grazie|
    che|mi|ti|ci|vi|me|te|se|gli|le|lo|la|il|i|un|una|
    dia|dare|dimi|dicci|
    di|del|della|dei|degli|delle|da|a|ad|al|alla|alle|agli|ai|
    per|su|con|in|nel|nella|nelle|nei|sul|sulla|sulle|oggi|adesso
  )\b/ux', ' ', $t);

  $t = (string)preg_replace('/\s+/u', ' ', trim($t));
  $kw = $t !== '' ? explode(' ', $t)[0] : '';

  // ----------------------------
  // Theme aliases
  // ----------------------------
  $alias = [
    'motivazione' => ['coraggio','azione','forza'],
    'forza'       => ['resilienza','forza'],
    'coraggio'    => ['coraggio','audacia'],
    'calma'       => ['pace','accettazione'],
    'ansia'       => ['pace','accettazione'],
    'stress'      => ['pace','accettazione'],
    'tristezza'   => ['impermanenza','speranza'],
    'pazienza'    => ['pazienza','resilienza'],
    'destino'     => ['destino','accettazione'],
    'tempo'       => ['tempo','impermanenza'],
    'desiderio'   => ['desiderio','ricchezza'],
    'avidità'     => ['desiderio','ricchezza'],
    'difficoltà'  => ['resilienza','forza'],

    // Common user phrasing
    'carica'      => ['coraggio','azione','forza'],
    'energia'     => ['azione','forza'],
    'grinta'      => ['coraggio','azione'],
    'concentrazione' => ['disciplina','tempo'],
    'focus'       => ['disciplina','tempo'],
  ];

  $wantedTags = [];
  if ($kw !== '' && isset($alias[$kw])) $wantedTags = $alias[$kw];

  // ----------------------------
  // Dataset filtering
  // ----------------------------
  $candidates = [];

  foreach ($data as $item) {
    if (!is_array($item)) continue;

    $itemPhRaw = (string)($item['philosopher'] ?? '');
    $itemPh = $norm($itemPhRaw);

    $itemTags = $item['tags'] ?? [];
    if (!is_array($itemTags)) $itemTags = [];

    $normTags = [];
    foreach ($itemTags as $tag) {
      $nt = $norm((string)$tag);
      if ($nt !== '') $normTags[] = $nt;
    }
    $itemTags = $normTags;

    if ($ph !== '' && mb_strpos($itemPh, $ph) === false) continue;

    if (!$wantedTags) {
      $candidates[] = $item;
      continue;
    }

    foreach ($wantedTags as $wt) {
      if (in_array($wt, $itemTags, true)) {
        $candidates[] = $item;
        break;
      }
    }
  }

  if (!$candidates) $candidates = $data;

  // ----------------------------
  // Pick one
  // ----------------------------
  $pick = $candidates[random_int(0, count($candidates) - 1)];

  $file = (string)($pick['file'] ?? '');
  $quoteMp3 = $mp3Dir . '/' . $file;

  // ----------------------------
  // Roman cues (simple anti-repeat)
  // ----------------------------
  $cueDir = $mp3Dir . '/cues';
  $cueList = ['forum_hit','senate_murmur','scroll_tap','shield_knock','camp_echo'];

  $lastFile = '/tmp/vox_last_cue';
  $lastCue = @trim((string)@file_get_contents($lastFile));

  $cue = $cueList[random_int(0, count($cueList)-1)];
  if ($cue === $lastCue) $cue = $cueList[random_int(0, count($cueList)-1)];
  @file_put_contents($lastFile, $cue);

  $cueMp3 = $cueDir . '/' . $cue . '.mp3';
  if (is_file($cueMp3)) $vox_play_blocking($cueMp3, 3);

  // ----------------------------
  // Author signature
  // ----------------------------
  $sigDir = $mp3Dir . '/signatures';
  $whoRaw = $norm((string)($pick['philosopher'] ?? ''));

  $who = '';
  if (strpos($whoRaw, 'seneca') !== false) $who = 'seneca';
  elseif (strpos($whoRaw, 'marco') !== false || strpos($whoRaw, 'aurelio') !== false) $who = 'aurelio';
  elseif (strpos($whoRaw, 'cicer') !== false) $who = 'cicerone';

  if ($who) {
    $sig = $sigDir . "/$who/sig_" . random_int(1,3) . ".mp3";
    if (is_file($sig)) $vox_play_blocking($sig, 5);
  }

  // ----------------------------
  // Quote
  // ----------------------------
  if (is_file($quoteMp3)) {
    $vox_play_blocking($quoteMp3, 45);
  }

  return [
    'ok'=>true,
    'text'=>'',
    'meta'=>[
      'picked'=>$pick['id'] ?? '',
      'philosopher'=>$pick['philosopher'] ?? '',
      'kw'=>$kw,
      'tags'=>$wantedTags,
      'cue'=>$cue,
      'file'=>$file,
      'latin'=>$pick['latin'] ?? '',
      'italian'=>$pick['italian'] ?? ''
    ]
  ];
}
