<?php
function voxie_sonar_ask(string $prompt): string {
  $key = getenv('PERPLEXITY_API_KEY') ?: '';
  if ($key === '') throw new RuntimeException("PERPLEXITY_API_KEY missing");

  // Skeleton: non implementiamo l'HTTP qui per non imporre dipendenze.
  // In docs/SONAR.md spieghiamo come fare la chiamata e parse della risposta.
  throw new RuntimeException("Sonar call not implemented in skeleton. See docs/SONAR.md");
}
