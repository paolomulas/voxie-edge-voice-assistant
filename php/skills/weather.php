<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/bus.php';

/**
 * Weather skill
 * Plays local mp3: data/cache/meteo/cache_meteo.mp3
 */
function skill_weather_run(): array {
  $base = bv_base_dir();
  $mp3 = $base . '/data/cache/meteo/cache_meteo.mp3';

  if (!is_file($mp3) || filesize($mp3) === 0) {
    return ['ok'=>false,'err'=>'WEATHER_MP3_MISSING','path'=>$mp3];
  }

  return audio_play_mp3($mp3);
}
