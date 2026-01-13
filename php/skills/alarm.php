<?php
declare(strict_types=1);

/**
 * alarm.php
 * 01) Salva sveglie/alert/timer su data/state/alarms.json
 * 02) Formati supportati:
 *    - sveglia HH:MM
 *    - timer N minuti
 * 03) list/cancel
 */

function _alarms_path(): string {
  return path_data() . '/state/alarms.json';
}

function _alarms_load(): array {
  $p = _alarms_path();
  if (!is_file($p)) return ['alarms'=>[]];
  $j = json_decode(file_get_contents($p) ?: '', true);
  if (!is_array($j) || !isset($j['alarms']) || !is_array($j['alarms'])) return ['alarms'=>[]];
  return $j;
}

function _alarms_save(array $st): void {
  @file_put_contents(_alarms_path(), json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function skill_alarm_set_hhmm(string $hhmm, string $label='Sveglia'): array {
  // 10) valida HH:MM
  if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
    return ['ok'=>false,'err'=>'BAD_TIME_FORMAT','expect'=>'HH:MM'];
  }

  $h = (int)$m[1]; $min = (int)$m[2];
  $now = time();
  $due = mktime($h, $min, 0, (int)date('n'), (int)date('j'), (int)date('Y'));

  // 11) se già passato oggi, domani
  if ($due <= $now) $due += 24*3600;

  $st = _alarms_load();
  $id = 'alarm_' . $due . '_' . rand(100,999);

  $st['alarms'][] = ['id'=>$id,'label'=>$label,'due_ts'=>$due,'type'=>'alarm','done'=>false];
  if (count($st['alarms']) > 50) $st['alarms'] = array_slice($st['alarms'], -50);
  _alarms_save($st);

  return ['ok'=>true,'text'=>"Impostata sveglia alle $hhmm",'id'=>$id];
}

function skill_timer_set_minutes(int $minutes, string $label='Timer'): array {
  if ($minutes < 1 || $minutes > 240) return ['ok'=>false,'err'=>'BAD_TIMER_RANGE','range'=>'1..240'];
  $due = time() + $minutes*60;

  $st = _alarms_load();
  $id = 'timer_' . $due . '_' . rand(100,999);
  $st['alarms'][] = ['id'=>$id,'label'=>$label,'due_ts'=>$due,'type'=>'timer','done'=>false];
  if (count($st['alarms']) > 50) $st['alarms'] = array_slice($st['alarms'], -50);
  _alarms_save($st);

  return ['ok'=>true,'text'=>"Timer impostato: $minutes minuti",'id'=>$id];
}

function skill_alarm_list(): array {
  $st = _alarms_load();
  $out = [];
  foreach ($st['alarms'] as $a) {
    if (!is_array($a)) continue;
    $out[] = [
      'id'=>$a['id'] ?? '',
      'type'=>$a['type'] ?? '',
      'label'=>$a['label'] ?? '',
      'due'=>date('Y-m-d H:i', (int)($a['due_ts'] ?? 0)),
      'done'=>(bool)($a['done'] ?? false),
    ];
  }
  return ['ok'=>true,'alarms'=>$out];
}

function skill_alarm_cancel(string $id): array {
  $st = _alarms_load();
  $before = count($st['alarms']);
  $st['alarms'] = array_values(array_filter($st['alarms'], fn($a) => is_array($a) && (($a['id'] ?? '') !== $id)));
  _alarms_save($st);
  return ['ok'=>true,'text'=>($before === count($st['alarms']) ? 'Nessuna sveglia trovata' : 'Sveglia rimossa')];
}
