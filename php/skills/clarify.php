<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/clarify_state.php';

/**
 * Clarify skill (fixed 2-option disambiguation).
 * Later can be dynamic (e.g., semantic top-N).
 */
function skill_clarify(array $ctx): array {
  clarify_set(['news', 'weather']);

  return [
    'ok' => true,
    'text' => "Vuoi che ti aggiorni su ciò che sta accadendo, oppure il meteo?"
  ];
}
