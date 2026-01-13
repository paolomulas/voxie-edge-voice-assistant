<?php
// Voxie — Clarify Mode state (TTL micro-state)
// Safe: file-based, no RAM, auto-expire.

const CLARIFY_FILE = __DIR__ . '/../../data/state/clarify.json';
const CLARIFY_TTL  = 30; // seconds

function clarify_set(array $options): void {
  // Ensure parent dir exists (portable setups may not have it yet)
  @mkdir(dirname(CLARIFY_FILE), 0777, true);

  $data = [
    'ts' => time(),
    'options' => array_values($options),
  ];
  file_put_contents(CLARIFY_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function clarify_get(): ?array {
  if (!file_exists(CLARIFY_FILE)) return null;

  $raw = file_get_contents(CLARIFY_FILE);
  $data = json_decode($raw, true);
  if (!is_array($data)) return null;

  $ts = (int)($data['ts'] ?? 0);
  if ($ts <= 0 || (time() - $ts) > CLARIFY_TTL) {
    @unlink(CLARIFY_FILE);
    return null;
  }
  return $data;
}

function clarify_clear(): void {
  if (file_exists(CLARIFY_FILE)) @unlink(CLARIFY_FILE);
}
