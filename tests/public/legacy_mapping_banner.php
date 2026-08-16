<?php

declare(strict_types=1);

// Verifica che le pagine di mappatura CV-only/legacy abbiano il banner di deprecazione.
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/classroom_mapping.php' => ['Pagina legacy', 'map_classes.php'],
    $root . '/public/manage_subject_mappings.php' => ['Pagina legacy', 'map_classes.php'],
    $root . '/public/map_courses.php' => ['Pagina legacy', 'map_classes.php'],
    $root . '/public/map_students.php' => ['Percorso legacy', 'teaching_groups.php'],
    $root . '/public/github_classroom_mapping.php' => ['Percorso legacy', 'teaching_groups.php'],
];
$failures = [];
foreach ($files as $file => $needles) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    foreach ($needles as $needle) {
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
fwrite(STDOUT, "PASS: banner deprecazione sulle pagine di mappatura legacy/CV-only.\n");
