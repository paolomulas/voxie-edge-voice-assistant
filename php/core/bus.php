<?php
declare(strict_types=1);

/**
 * bus.php
 * - Lightweight PHP logging
 * - Unix socket client to the Python audio daemon
 * - Small set of audio helpers (play/stop/status)
 * - http_json(): simple JSON HTTP wrapper (cURL)
 */

function bus_log(string $msg): void {
  // Optional logging (must never break runtime if permissions are missing)
  $enabled = config_get('LOG_PHP', '1');
  if (!in_array(strtolower((string)$enabled), ['1','true','yes','on'], true)) return;

  $file = config_get('PHP_LOG', path_logs() . '/php.log') ?: (path_logs() . '/php.log');
  @file_put_contents($file, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function audio_sock(): string {
  // Default must match the Python daemon default
  return config_get('AUDIO_SOCK', '/tmp/bitvox_audio.sock') ?? '/tmp/bitvox_audio.sock';
}

/** @return array<string,mixed> */
function audio_send(array $cmd, int $tries = 3, int $retry_ms = 120): array {
  $sock = audio_sock();
  $last = null;

  for ($i = 0; $i < $tries; $i++) {
    $fp = @stream_socket_client('unix://' . $sock, $errno, $errstr, 0.2);
    if ($fp === false) {
      $last = "connect_fail errno=$errno err=$errstr";
      usleep($retry_ms * 1000);
      continue;
    }

    stream_set_timeout($fp, 1, 0);
    $payload = json_encode($cmd, JSON_UNESCAPED_UNICODE) . "\n";
    fwrite($fp, $payload);

    $buf = '';
    while (!feof($fp)) {
      $chunk = fgets($fp);
      if ($chunk === false) break;
      $buf .= $chunk;
      if (str_contains($buf, "\n")) break;
    }
    fclose($fp);

    $res = json_decode(trim($buf), true);
    if (is_array($res)) return $res;

    $last = "bad_json_reply: " . trim($buf);
    usleep($retry_ms * 1000);
  }

  return ['ok' => false, 'err' => 'AUDIO_CLIENT_FAIL', 'msg' => $last];
}

// Audio command helpers
function audio_play_mp3(string $path): array    { return audio_send(['cmd' => 'PLAY_MP3',    'path' => $path]); }
function audio_play_wav(string $path): array    { return audio_send(['cmd' => 'PLAY_WAV',    'path' => $path]); }
function audio_play_stream(string $url): array  { return audio_send(['cmd' => 'PLAY_STREAM', 'url'  => $url]); }
function audio_stop(): array                    { return audio_send(['cmd' => 'STOP']); }
function audio_status(): array                  { return audio_send(['cmd' => 'STATUS']); }

/**
 * http_json(): JSON HTTP request wrapper using cURL
 * - $headers must be full header strings: ["Authorization: Bearer ...", ...]
 * - returns: ['ok'=>bool,'status'=>int,'json'=>array|null,'raw'=>string,'err'=>string|null]
 */
function http_json(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 12): array {
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

  $baseHeaders = array_merge(['Accept: application/json'], $headers);

  if ($body !== null) {
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $hasCT = false;
    foreach ($headers as $h) {
      if (stripos($h, 'content-type:') === 0) { $hasCT = true; break; }
    }
    if (!$hasCT) $baseHeaders = array_merge(['Content-Type: application/json'], $baseHeaders);
  }

  curl_setopt($ch, CURLOPT_HTTPHEADER, $baseHeaders);

  $raw = curl_exec($ch);
  $err = $raw === false ? curl_error($ch) : null;
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  $json = null;
  if ($raw !== false) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $json = $tmp;
  }

  return [
    'ok' => ($err === null) && ($status >= 200 && $status < 300),
    'status' => $status,
    'json' => $json,
    'raw' => $raw === false ? '' : (string)$raw,
    'err' => $err,
  ];
}
