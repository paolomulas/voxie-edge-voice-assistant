<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/llm.php';

function skill_chat_run(string $userText): array {
  // 01) Voice-first: breve, chiaro, 1 follow-up
  $system = "Rispondi in italiano, stile voce. Massimo 80 parole. Una sola domanda finale. Niente elenchi lunghi.";
  $r = llm_call($system, $userText);
  if (empty($r['ok'])) return $r;
  return ['ok'=>true,'text'=>$r['text'],'llm_ms'=>$r['ms']];
}
