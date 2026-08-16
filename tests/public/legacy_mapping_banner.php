<?php

declare(strict_types=1);

// Verifica che le pagine di mappatura CV-only abbiano il banner di deprecazione.
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/classroom_mapping.php',
    $root . '/public/manage_subject_mappings.php',
    $root . '/public/map_courses.php',
];
$failures = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    foreach (['Pagina legacy', 'map_classes.php', 'gruppo didattico'] as $needle) {
        if (stripos($source, $needle) === false) {
            $failures[] = $base . ': banner deprecazione assente (manca: ' . $needle . ')';
        }
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: banner deprecazione sulle pagine di mappatura CV-only.\n");
