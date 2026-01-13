<?php
function voxie_root(): string {
  $r = realpath(__DIR__ . '/../../');
  if (!$r) { throw new RuntimeException("Cannot resolve repo root"); }
  return $r;
}

function voxie_cache_dir(): string {
  $d = voxie_root() . '/data/cache/feed';
  if (!is_dir($d)) { @mkdir($d, 0775, true); }
  return $d;
}

function voxie_location(): array {
  $city = getenv('VOXIE_CITY') ?: '';
  $region = getenv('VOXIE_REGION') ?: '';
  $country = getenv('VOXIE_COUNTRY') ?: '';
  $mode = getenv('VOXIE_GEO_MODE') ?: 'manual';

  if ($mode === 'timezone') {
    $tz = @trim(shell_exec('cat /etc/timezone 2>/dev/null')) ?: date_default_timezone_get();
    if ($country === '' && stripos($tz, 'Europe/Rome') !== false) $country = 'IT';
    return compact('city','region','country','mode') + ['tz'=>$tz];
  }

  // ip mode is intentionally opt-in; keep as TODO for repo safety
  if ($mode === 'ip' && (getenv('VOXIE_GEO_IP_ENABLE') ?: '0') === '1') {
    return compact('city','region','country','mode') + ['note'=>'ip-geo TODO in skeleton'];
  }

  return compact('city','region','country','mode');
}

function voxie_cache_key(string $skill, array $loc): string {
  $city = $loc['city'] ?: 'unknown';
  $country = $loc['country'] ?: 'xx';
  $k = strtolower($skill . '_' . $country . '_' . $city);
  return preg_replace('/[^a-z0-9_\-\.]/', '_', $k);
}

function voxie_cache_get(string $key, int $ttl): ?string {
  $file = voxie_cache_dir() . "/$key.txt";
  if (!file_exists($file)) return null;
  if ($ttl > 0 && (time() - filemtime($file) > $ttl)) return null;
  return file_get_contents($file);
}

function voxie_cache_put(string $key, string $text): void {
  $file = voxie_cache_dir() . "/$key.txt";
  file_put_contents($file, $text);
}

function voxie_feed_prompt(string $skill, array $loc): string {
  $city = $loc['city'] ?: 'la tua zona';
  return match($skill) {
    'news' => "Dammi le 5 notizie principali di oggi per $city. Punti brevi, tono neutro.",
    'timeout' => "Suggerisci 5 cose da fare oggi o nel weekend a $city. Breve, pratico.",
    'weather' => "Descrivi il meteo previsto per oggi e domani a $city in modo sintetico.",
    default => "Genera un breve aggiornamento per $city su: $skill."
  };
}

function voxie_feed(string $skill, array $loc): string {
  $ttl = (int)(getenv('VOXIE_CACHE_TTL_SEC') ?: 1800);
  $key = voxie_cache_key($skill, $loc);

  // 1) serve cache first
  $cached = voxie_cache_get($key, $ttl);
  if ($cached !== null) return $cached;

  $backend = getenv('VOXIE_FEED_BACKEND') ?: 'local';
  if ($backend === 'sonar') {
    require_once __DIR__ . '/sonar_stub.php';
    try {
      $prompt = voxie_feed_prompt($skill, $loc);
      $text = voxie_sonar_ask($prompt);
      voxie_cache_put($key, $text);
      return $text;
    } catch (Throwable $e) {
      // fallback to local
    }
  }

  // local fallback (universal)
  $city = $loc['city'] ?: 'questa zona';
  $text = match($skill) {
    'news' => "News locali non configurate per $city. Imposta VOXIE_CITY o abilita Sonar.",
    'timeout' => "Attività locali non configurate per $city. Imposta VOXIE_CITY o abilita Sonar.",
    'weather' => "Meteo non configurato per $city. Imposta VOXIE_CITY o abilita Sonar.",
    default => "Contenuto non configurato."
  };

  voxie_cache_put($key, $text);
  return $text;
}
