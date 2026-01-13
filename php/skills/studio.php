<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/bus.php';

// Optional dependency: only used as fallback if no STUDIO_STREAM_URL is configured.
require_once __DIR__ . '/radio.php';

/**
 * Studio skill
 *
 * Purpose (practical):
 * - Start a "focus" background stream (direct URL preferred, radio fallback)
 * - (Optional) add a 25 min pomodoro timer in a simple JSON state file
 *
 * Env:
 * - STUDIO_STREAM_URL: if set, play this stream directly (no station matching needed)
 * - STUDIO_ENABLE_POMODORO: "1" (default) to write a 25-min timer, "0" to disable
 */

function _timers_path(): string {
  return path_data() . '/state/timers.json';
}

function _timers_load(): array {
  $p = _timers_path();
  if (!is_file($p)) return ['timers' => []];

  $j = json_decode((string)(file_get_contents($p) ?: ''), true);
  if (!is_array($j) || !isset($j['timers']) || !is_array($j['timers'])) return ['timers' => []];

  return $j;
}

function _timers_save(array $st): void {
  @mkdir(dirname(_timers_path()), 0777, true);

  // Best-effort: stable JSON on disk
  @file_put_contents(
    _timers_path(),
    json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
  );
}

function skill_studio_run(): array {
  // 1) Start focus audio
  $direct = trim((string)(getenv('STUDIO_STREAM_URL') ?: ''));
  if ($direct !== '') {
    audio_stop();
    usleep(100 * 1000);
    $res = audio_play_stream($direct);
  } else {
    // Fallback: reuse radio skill matching (keeps legacy behavior)
    $res = skill_radio_run('focus');
  }

  // 2) Optional pomodoro state
  $enablePomodoro = getenv('STUDIO_ENABLE_POMODORO');
  if ($enablePomodoro === false || $enablePomodoro === '' || $enablePomodoro === '1') {
    $st = _timers_load();
    $st['timers'][] = [
      'id' => 'pomodoro_' . time(),
      'label' => 'Pomodoro',
      'due_ts' => time() + 25 * 60,
      'type' => 'timer',
    ];

    // Keep file small and bounded
    if (count($st['timers']) > 20) $st['timers'] = array_slice($st['timers'], -20);
    _timers_save($st);
  }

  return $res;
}
