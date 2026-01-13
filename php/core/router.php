<?php
declare(strict_types=1);

/**
 * router.php
 * Offline intent routing (no network).
 * Supports: time, studio/soundscape, study/mentor, alarms/timers, weather, news, radio, vox.
 */

function route_intent(string $text): array {
  $raw = trim($text);
  $t = mb_strtolower($raw);
  $payload = [];

  // STOP
  if (preg_match('/\b(stop|ferma|basta|silenzio)\b/u', $t)) return ['intent'=>'stop','payload'=>[]];

  // TIME
  if (preg_match('/\b(che ore sono|ore|dimmi l\'ora|dimmi l’ora)\b/u', $t)) return ['intent'=>'time','payload'=>[]];

  // MENTOR (new): enable guided mode
  if (preg_match('/\b(mentor|mentore|modalit[aà]\s*mentore|guidami|passo\s*passo)\b/u', $t)) {
    return ['intent'=>'mentor','payload'=>[]];
  }

  // SOUNDSCAPE (new): background audio (focus/relax/ambient)
  if (preg_match('/\b(soundscape|sottofondo|background|ambiente)\b/u', $t)) {
    return ['intent'=>'soundscape','payload'=>[]];
  }

  // STUDIO (legacy): kept for backward compatibility
  if (preg_match('/\b(studio|modalit[aà] studio|focus)\b/u', $t)) return ['intent'=>'studio','payload'=>[]];

  // ALARM LIST
  if (preg_match('/\b(lista sveglie|mostra sveglie|sveglie)\b/u', $t)) return ['intent'=>'alarm_list','payload'=>[]];

  // ALARM CANCEL: "cancella <id>"
  if (preg_match('/\b(cancella|rimuovi)\s+(alarm_\S+|timer_\S+)\b/u', $raw, $m)) {
    $payload['id'] = trim($m[2]);
    return ['intent'=>'alarm_cancel','payload'=>$payload];
  }

  // ALARM SET: "sveglia 07:30" / "sveglia alle 7:30"
  if (preg_match('/\b(sveglia)\b.*\b([01]?\d|2[0-3]):([0-5]\d)\b/u', $t, $m)) {
    $hh = str_pad($m[2], 2, '0', STR_PAD_LEFT);
    $mm = $m[3];
    $payload['hhmm'] = "$hh:$mm";
    return ['intent'=>'alarm_set','payload'=>$payload];
  }

  // TIMER: "timer 10 minuti"
  if (preg_match('/\b(timer)\b.*\b(\d{1,3})\s*(min|minuti)\b/u', $t, $m)) {
    $payload['minutes'] = (int)$m[2];
    return ['intent'=>'timer_set','payload'=>$payload];
  }

  // WEATHER
  if (preg_match('/\b(meteo|che tempo|temperatura|piove|vento)\b/u', $t)) return ['intent'=>'weather','payload'=>[]];

  // NEWS (optional category)
  if (preg_match('/\b(news|notizie|ultime)\b/u', $t)) {
    $payload['category'] = '';
    if (preg_match('/\b(?:news|notizie|ultime)\s+(.+)$/iu', $raw, $m)) {
      $payload['category'] = trim(mb_strtolower($m[1]));
      $payload['category'] = preg_replace('/^(di|del|della|dei|degli)\s+/u', '', $payload['category']);
    }
    return ['intent'=>'news','payload'=>$payload];
  }

  // RADIO/MUSIC
  if (preg_match('/\b(radio|musica|metti|play)\b/u', $t)) {
    $payload['q'] = '';
    if (preg_match('/\b(?:radio|musica)\s+(.+)$/iu', $raw, $m)) $payload['q'] = trim(mb_strtolower($m[1]));
    return ['intent'=>'radio','payload'=>$payload];
  }

  // VOX ROMANA
  if (preg_match('/\bvox\b/u', $t)) {
    $payload['q'] = '';
    if (preg_match('/\bvox(?:\s+romana)?\s*(.*)$/iu', $raw, $m)) $payload['q'] = trim((string)$m[1]);
    return ['intent'=>'vox','payload'=>$payload];
  }

  // Semantic fallback (may still return legacy intents; agent maps them)
  require_once __DIR__ . '/semantic_intent.php';
  $guess = semantic_intent_guess($t);
  if ($guess) {
    fwrite(STDERR, "[SEM] intent={$guess['intent']} score=".round($guess['score'],4)." margin=".round($guess['margin'],4)."\n");
    return ['intent'=>$guess['intent'],'payload'=>[]];
  }

  return ['intent' => (getenv('VOXIE_FEATURE_CLARIFY') ? 'clarify' : 'chat'),'payload'=>[]];
}
