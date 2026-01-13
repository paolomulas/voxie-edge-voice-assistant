<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/study_state.php';

/**
 * Study/Mentor mode command handler.
 * Agent calls study_handle_command() for legacy intents:
 * - study_auto, study_on, study_off
 *
 * This file is intentionally small and deterministic.
 */

function study_handle_command(string $intent, string $userText = ''): array {
  $intent = trim($intent);

  if ($intent === 'study_off') {
    study_disable();
    return ['ok' => true, 'text' => "Modalità mentore disattivata."];
  }

  // study_on / study_auto -> enable
  // pending_confirm=true allows the app to optionally ask short vs step-by-step later.
  study_enable(true);
  return ['ok' => true, 'text' => "Modalità mentore attivata. Dimmi cosa vuoi capire e ti guido passo-passo."];
}
