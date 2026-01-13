<?php
declare(strict_types=1);

require_once __DIR__ . '/studio.php';

/**
 * soundscape skill
 * Alias name for starting background focus-friendly audio.
 * Backward compatible with legacy "studio" skill.
 */
function skill_soundscape_run(): array {
  return skill_studio_run();
}
