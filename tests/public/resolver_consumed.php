<?php

declare(strict_types=1);

// Verifica che: 1) nessuna pagina dichiari più il gate globale ClasseViva; 2) il
// resolver sia realmente consumato (non solo definito) in almeno una pagina.
$root = dirname(__DIR__, 2);
$publicDir = $root . '/public';
$failures = [];

$phpFiles = glob($publicDir . '/*.php') ?: [];
foreach ($phpFiles as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        continue;
    }
    if (str_contains($source, "define('REQUIRES_CLASSEVIVA', true)")) {
        $failures[] = basename($file) . ': gate globale ClasseViva ancora attivo';
    }
}

$consumers = [];
foreach ($phpFiles as $file) {
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
fwrite(STDOUT, "PASS: gate globale rimosso e resolver consumato (" . count($consumers) . " pagina/e).\n");
