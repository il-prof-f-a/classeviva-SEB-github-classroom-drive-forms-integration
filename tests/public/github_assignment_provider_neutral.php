<?php

declare(strict_types=1);

// Verifica statica che create/assignments GitHub non dipendano più da GitHub Classroom
// né da ClasseViva come gate obbligatorio (ClasseViva resta solo un fallback opzionale
// per la risoluzione email). I due file devono usare il dominio provider-neutral
// (GitHubAssignmentService + TeachingGroupStudentService) e la riga TEST piattaforma=github.
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
    foreach (['GITHUB_CLASSROOMS', 'CLASSI_ASSEGNATE', 'id_classe_cv', 'id_materia_cv', 'classroom.github.com', 'listClassrooms', 'listAssignments', 'listAcceptedAssignments', 'getAssignmentGrades'] as $forbidden) {
        if (strpos($source, $forbidden) !== false) {
            $failures[] = $base . ': dipendenza GitHub Classroom/ClasseViva ancora presente: ' . $forbidden;
        }
    }
    foreach (['GitHubAssignmentService', 'TeachingGroupStudentService', 'piattaforma', 'accept_assignment.php', 'github_config_json'] as $required) {
        if (strpos($source, $required) === false) {
            $failures[] = $base . ': riferimento provider-neutral assente: ' . $required;
        }
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: GitHub assignment create/assignments provider-neutral (nessuna dipendenza GitHub Classroom/ClasseViva).
");
