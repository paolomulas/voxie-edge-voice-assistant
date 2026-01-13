<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
bv_env_load(bv_base_dir() . '/.env');

require_once __DIR__ . '/../core/bus.php';
require_once __DIR__ . '/../core/router.php';
require_once __DIR__ . '/../core/latency.php';
require_once __DIR__ . '/../core/speech.php';
require_once __DIR__ . '/../core/study_state.php';

// Skills
require_once __DIR__ . '/../skills/weather.php';
require_once __DIR__ . '/../skills/news.php';
require_once __DIR__ . '/../skills/radio.php';
require_once __DIR__ . '/../skills/time.php';
require_once __DIR__ . '/../skills/alarm.php';
require_once __DIR__ . '/../skills/studio.php';      // legacy: background focus audio
require_once __DIR__ . '/../skills/chat.php';
require_once __DIR__ . '/../skills/clarify.php';
require_once __DIR__ . '/../skills/study.php';       // provides study_handle_command()
require_once __DIR__ . '/../skills/vox.php';
require_once __DIR__ . '/../skills/timeout.php';

// New aliases (do not remove legacy names)
require_once __DIR__ . '/../skills/soundscape.php';  // alias -> studio
require_once __DIR__ . '/../skills/mentor.php';      // alias -> study mode

$input = trim((string)($argv[1] ?? ''));

// TIMING_MARKS
$t_start = microtime(true);
$GLOBALS['t_start'] = $t_start;

$route   = route_intent($input);
$intent  = (string)($route['intent'] ?? 'chat');
$payload = is_array($route['payload'] ?? null) ? $route['payload'] : [];

$res = ['ok' => true];

//
// 0) STOP must be immediate
//
if ($intent === 'stop') {
  audio_stop();
  $res = ['ok' => true];

  echo json_encode(
    ['ok' => true, 'intent' => $intent, 'result' => $res],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
  ) . "\n";
  exit;
}

//
// 1) Deterministic skills: avoid LLM when possible
//
if ($intent === 'weather') {
  $res = skill_weather_run();

} elseif ($intent === 'news') {
  $cat = (string)($payload['category'] ?? '');
  $res = skill_news_run($cat);

} elseif ($intent === 'radio') {
  $q = (string)($payload['q'] ?? '');
  $res = skill_radio_run($q);

} elseif ($intent === 'time') {
  $res = skill_time_run();

// Legacy name (kept)
} elseif ($intent === 'studio') {
  $res = skill_studio_run();

// New name (alias)
} elseif ($intent === 'soundscape') {
  $res = skill_soundscape_run();

} elseif ($intent === 'alarm_set') {
  $hhmm = (string)($payload['hhmm'] ?? '');
  $res = skill_alarm_set_hhmm($hhmm);

} elseif ($intent === 'timer_set') {
  $min = (int)($payload['minutes'] ?? 0);
  $res = skill_timer_set_minutes($min);

} elseif ($intent === 'alarm_list') {
  $res = ['ok' => true, 'text' => "Funzione lista sveglie non ancora collegata."];

} elseif ($intent === 'alarm_cancel') {
  $res = ['ok' => true, 'text' => "Funzione cancella sveglie non ancora collegata."];

} elseif ($intent === 'vox') {
  // Vox Romana: audio-only (intro + local mp3), no TTS
  $q = (string)($payload['q'] ?? '');
  $res = skill_vox_run($q);

} elseif ($intent === 'events_timeout') {
  $res = skill_timeout(['input' => $input]);

} elseif ($intent === 'clarify') {
  $res = skill_clarify(['input' => $input]);

//
// 2) Study / mentor commands (explicit)
//

// New alias: "mentor" enables study mode
} elseif ($intent === 'mentor') {
  latency_pre_study();
  fwrite(STDERR, "[TIMING] intro_done ms=" . (int)((microtime(true) - $t_start) * 1000) . "\n");
  $res = skill_mentor_run($input);

// Legacy intents (kept)
} elseif ($intent === 'study_auto' || $intent === 'study_on') {
  latency_pre_study();
  fwrite(STDERR, "[TIMING] intro_done ms=" . (int)((microtime(true) - $t_start) * 1000) . "\n");
  $res = study_handle_command($intent, $input);

} elseif ($intent === 'study_off') {
  latency_pre_llm();
  fwrite(STDERR, "[TIMING] intro_done ms=" . (int)((microtime(true) - $t_start) * 1000) . "\n");
  $res = study_handle_command($intent, $input);

//
// 3) Chat / LLM
//
} else {
  $st = study_state_load();

  // Barge-in only for LLM paths
  audio_stop();

  if (!empty($st['enabled'])) {
    latency_pre_study();
    fwrite(STDERR, "[TIMING] intro_done ms=" . (int)((microtime(true) - $t_start) * 1000) . "\n");
    $res = ['ok' => true, 'text' => "Dimmi cosa vuoi capire e ti guido passo-passo."];
  } else {
    latency_pre_llm();
    fwrite(STDERR, "[TIMING] intro_done ms=" . (int)((microtime(true) - $t_start) * 1000) . "\n");
    $res = skill_chat_run($input);
  }
}

// AUDIO_AUTORUN: if a skill returns a local MP3 path, play it (bus -> audio_daemon)
if (
  is_array($res)
  && (!isset($res['text']) || trim((string)$res['text']) === '')
  && isset($res['local_path']) && is_string($res['local_path']) && $res['local_path'] !== ''
) {
  $path = $res['local_path'];

  if (file_exists($path)) {
    $play = audio_play_mp3($path);
    $res['spoken'] = [
      'ok' => true,
      'audio' => [
        'ok' => (bool)($play['ok'] ?? true),
        'path' => $path
      ]
    ];
  } else {
    $res = ['ok' => false, 'text' => "File audio non trovato.", 'meta' => ['missing' => $path]];
  }
}

// SPEAK_TEXT_AUTORUN: speak only if there is non-empty text
if (is_array($res) && isset($res['text']) && is_string($res['text']) && trim($res['text']) !== '') {
  $__spoken = speak_text($res['text']);
  $res['spoken'] = $__spoken;
}

echo json_encode(
  [
    'ok' => true,
    'intent' => $intent,
    'result' => $res
  ],
  JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
) . "\n";
