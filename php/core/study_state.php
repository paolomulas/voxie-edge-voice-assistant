<?php
declare(strict_types=1);

/**
 * study_state.php
 * Minimal persistent state for "study mode".
 *
 * Fields:
 * - enabled (bool): if true, the assistant runs in study mode.
 * - pending_confirm (bool): soft confirmation pending (step-by-step vs short answer).
 *
 * Storage:
 * - JSON file under data/state/study.json
 */

function study_state_path(): string {
  return path_data() . '/state/study.json';
}

/**
 * Load study state from disk.
 * Always returns a normalized array with keys: enabled, pending_confirm.
 */
function study_state_load(): array {
  $p = study_state_path();

  if (!is_file($p)) {
    return ['enabled' => false, 'pending_confirm' => false];
  }

  $raw = file_get_contents($p);
  if ($raw === false || trim($raw) === '') {
    return ['enabled' => false, 'pending_confirm' => false];
  }

  $j = json_decode($raw, true);
  if (!is_array($j)) {
    return ['enabled' => false, 'pending_confirm' => false];
  }

  return [
    'enabled' => (bool)($j['enabled'] ?? false),
    'pending_confirm' => (bool)($j['pending_confirm'] ?? false),
  ];
}

/**
 * Save study state to disk (normalized).
 * Uses a temp file + rename to reduce chances of partial writes.
 */
function study_state_save(array $st): void {
  $dir = dirname(study_state_path());
  @mkdir($dir, 0777, true);

  $out = [
    'enabled' => (bool)($st['enabled'] ?? false),
    'pending_confirm' => (bool)($st['pending_confirm'] ?? false),
  ];

  $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

  $p = study_state_path();
  $tmp = $p . '.tmp';

  // Best-effort atomic write on typical Linux filesystems
  @file_put_contents($tmp, $json);
  @rename($tmp, $p);
}

function study_enable(bool $pendingConfirm = true): void {
  study_state_save(['enabled' => true, 'pending_confirm' => $pendingConfirm]);
}

function study_disable(): void {
  study_state_save(['enabled' => false, 'pending_confirm' => false]);
}
