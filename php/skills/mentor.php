<?php
declare(strict_types=1);

require_once __DIR__ . '/study.php';

/**
 * mentor skill
 * Alias name for enabling mentor mode.
 * Backward compatible with study_* intents.
 */
function skill_mentor_run(string $userText = ''): array {
  return study_handle_command('study_on', $userText);
}
