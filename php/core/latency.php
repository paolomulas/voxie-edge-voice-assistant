<?php
declare(strict_types=1);

/**
 * latency.php
 * - latency_ack(): short wav ack
 * - latency_pre_llm(): ack + random intro (LLM path)
 * - latency_pre_study(): ack + random intro (STUDY path)
 */

function latency_ack(): void {
  $wav = bv_base_dir() . '/assets/ack/ack_neutral_ok.wav';
  if (is_file($wav)) audio_play_wav($wav);
}

function _latency_pick(string $glob): ?string {
  $f = glob($glob) ?: [];
  return $f ? $f[array_rand($f)] : null;
}

function latency_pre_llm(): void {
  latency_ack();
  $mp3 = _latency_pick(bv_base_dir() . '/assets/intros_mp3/*.mp3');
  if ($mp3) audio_play_mp3($mp3);
}

function latency_pre_study(): void {
  latency_ack();
  $mp3 = _latency_pick(bv_base_dir() . '/assets/intros_study_mp3/*.mp3');
  if ($mp3) audio_play_mp3($mp3);
}
