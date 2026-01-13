<?php
declare(strict_types=1);

/**
 * alarm_tick.php
 * 01) Checks for due alarms
 * 02) If due and not done -> play a local beep and mark as done
 */

require_once __DIR__ . '/../core/config.php';
bv_env_load(bv_base_dir() . '/.env');

require_once __DIR__ . '/../core/bus.php';
require_once __DIR__ . '/../skills/alarm.php';

$st = (function () {
  $p = path_data() . '/state/alarms.json';
  if (!is_file($p)) return ['alarms' => []];
  $j = json_decode(file_get_contents($p) ?: '', true);
  if (!is_array($j) || !isset($j['alarms']) || !is_array($j['alarms'])) return ['alarms' => []];
  return $j;
})();

$now = time();
$changed = false;

foreach ($st['alarms'] as &$a) {
  if (!is_array($a)) continue;
  if (!empty($a['done'])) continue;

  $due = (int)($a['due_ts'] ?? 0);
  if ($due > 0 && $due <= $now) {
    // Play a local beep (existing WAV asset) and mark as done
    $beep = bv_base_dir() . '/assets/ack/ack_neutral_ok.wav';
    audio_play_wav($beep);

    $a['done'] = true;
    $changed = true;
  }
}
unset($a);

if ($changed) {
  @file_put_contents(
    path_data() . '/state/alarms.json',
    json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
  );
}
