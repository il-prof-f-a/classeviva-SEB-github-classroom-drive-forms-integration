<?php

declare(strict_types=1);

// Verifica statica che create/assignments GitHub non dipendano più da ClasseViva.
$files = [
    dirname(__DIR__, 2) . '/public/github_assignment_create.php',
    dirname(__DIR__, 2) . '/public/github_assignments.php',
];
$failures = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    foreach (['GITHUB_CLASSROOMS', 'CLASSI_ASSEGNATE', 'ClasseVivaAPI', 'id_classe_cv', 'id_materia_cv'] as $forbidden) {
        if (strpos($source, $forbidden) !== false) {
            $failures[] = $base . ': dipendenza ClasseViva ancora presente: ' . $forbidden;
        }
    }
    foreach (['listGithubClassroomMappings', 'github_classroom_id'] as $required) {
        if (strpos($source, $required) === false) {
            $failures[] = $base . ': riferimento provider-neutral assente: ' . $required;
        }
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: GitHub assignment create/assignments provider-neutral (nessuna dipendenza ClasseViva).\n");
