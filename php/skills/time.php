<?php
declare(strict_types=1);

/**
 * Time skill
 * Returns: "Sono le HH:MM"
 * (TTS playback is handled upstream by the agent/router)
 */
function skill_time_run(): array {
  $t = date('H:i');
  return ['ok'=>true,'text'=>"Sono le $t"];
}
