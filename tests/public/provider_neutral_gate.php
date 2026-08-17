<?php

declare(strict_types=1);

// Verifica che le pagine di mapping (e l'editor gruppi) ripristinino il gate
// ClasseViva per-pagina, cosi' il popup di riautenticazione compare quando
// ClasseViva e' abilitata e il token manca o e' scaduto.
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/teaching_groups.php',
    $root . '/public/map_students.php',
    $root . '/public/map_classes.php',
];
$failures = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    if (!str_contains($source, "define('REQUIRES_CLASSEVIVA', true)")) {
        $failures[] = $base . ': gate globale ClasseViva non ripristinato';
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: pagine di mapping richiedono nuovamente il token ClasseViva.\n");
