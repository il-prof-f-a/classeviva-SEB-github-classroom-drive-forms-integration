<?php

declare(strict_types=1);

// Verifica che il resolver sia realmente consumato (non solo definito) in almeno
// una pagina. Il gate ClasseViva e' di nuovo per-pagina (vedi provider_neutral_gate.php).
$root = dirname(__DIR__, 2);
$publicDir = $root . '/public';
$failures = [];

$consumers = [];
foreach (glob($publicDir . '/*.php') ?: [] as $file) {
    $source = file_get_contents($file);
    if ($source !== false && str_contains($source, 'ProviderCapabilityResolver')) {
        $consumers[] = basename($file);
    }
}
if ($consumers === []) {
    $failures[] = 'ProviderCapabilityResolver non consumato da nessuna pagina';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: resolver consumato (" . count($consumers) . " pagina/e).\n");
