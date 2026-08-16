<?php

declare(strict_types=1);

// Verifica che le pagine provider-neutral non dichiarino il gate globale ClasseViva.
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/teaching_groups.php',
    $root . '/public/map_students.php',
];
$failures = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    if (str_contains($source, "define('REQUIRES_CLASSEVIVA', true)")) {
        $failures[] = $base . ': gate globale ClasseViva ancora attivo';
    }
    if (!str_contains($source, "define('REQUIRES_CLASSEVIVA', false)")) {
        $failures[] = $base . ': gate provider-neutral assente';
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: pagine provider-neutral senza gate globale ClasseViva.\n");
