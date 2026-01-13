<?php
declare(strict_types=1);

/**
 * speech.php
 * - speak_text($text): generate mp3 (cached) then play via audio daemon
 */

require_once __DIR__ . '/tts.php';

function speak_text(string $text): array {
  $r = tts_mp3_cached($text);
  if (empty($r['ok'])) return $r;

  $path = (string)$r['path'];

  if (isset($GLOBALS['t_start'])) {
    fwrite(STDERR, "[TIMING] tts_play ms=" . (int)((microtime(true) - $GLOBALS['t_start']) * 1000) . "\n");
  }

  $a = audio_play_mp3($path);

  return [
    'ok'   => (bool)($a['ok'] ?? false),
    'tts'  => $r,
    'audio'=> $a
  ];
}
