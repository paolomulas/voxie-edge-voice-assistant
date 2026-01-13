<?php
declare(strict_types=1);

date_default_timezone_set(getenv('TZ') ?: 'Europe/Rome');

/**
 * config.php
 * - Base dir detection (portable; avoids hardcoded dev paths)
 * - Minimal .env loader (once)
 * - config_get() helper with defaults
 * - Standard project paths
 */

function bv_base_dir(): string {
  // Preferred: explicit root set by orchestrators / services
  $root = trim((string)(getenv('VOXIE_ROOT') ?: getenv('BITVOX_ROOT') ?: ''));
  if ($root !== '' && is_dir($root)) {
    $rp = realpath($root);
    if ($rp) return $rp;
    return $root;
  }

  // Typical layout: php/core -> project root is 2 levels up
  $base = realpath(__DIR__ . '/../../');
  if ($base) return $base;

  // Legacy fallback (kept to avoid breaking older installs)
  return '/home/paolo/bitvox/2.6';
}

function bv_env_load(string $path): void {
  static $loaded = false;
  if ($loaded) return; // avoid double-load
  $loaded = true;

  if (!is_file($path)) return;

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) return;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;

    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $k = trim(substr($line, 0, $pos));
    $v = trim(substr($line, $pos + 1));

    // Strip surrounding quotes "..." or '...'
    if (
      (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
      (str_starts_with($v, "'") && str_ends_with($v, "'"))
    ) {
      $v = substr($v, 1, -1);
    }

    if ($k === '') continue;

    $_ENV[$k] = $v;
    putenv($k . '=' . $v);
  }
}

function config_get(string $key, ?string $default = null): ?string {
  // Priority: $_ENV -> getenv() -> default
  if (array_key_exists($key, $_ENV)) return (string)$_ENV[$key];
  $v = getenv($key);
  if ($v !== false) return (string)$v;
  return $default;
}

function path_data(): string   { return bv_base_dir() . '/data'; }
function path_cache(): string  { return path_data() . '/cache'; }
function path_assets(): string { return bv_base_dir() . '/assets'; }
function path_logs(): string   { return path_data() . '/logs'; }
