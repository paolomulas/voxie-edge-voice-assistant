<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

/**
 * Timeout skill (deterministic, offline)
 * Returns local_path to: data/events/cache/cagliari/timeout.latest.mp3
 */
function skill_timeout(array $ctx = []): array {
  $base = bv_base_dir();
  $mp3 = $base . '/data/events/cache/cagliari/timeout.latest.mp3';

  if (!is_file($mp3)) {
    return [
      'ok' => false,
      'text' => "Non ho ancora preparato gli eventi di oggi.",
      'meta' => ['missing' => $mp3],
    ];
  }

  return [
    'ok' => true,
    'text' => "",
    'meta' => ['kind' => 'timeout'],
    'local_path' => $mp3
  ];
}
