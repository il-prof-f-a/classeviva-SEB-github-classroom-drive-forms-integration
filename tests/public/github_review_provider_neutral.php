<?php

declare(strict_types=1);

// Verifica statica che github_assignment_review.php non dipenda più da ClasseViva.
$file = dirname(__DIR__, 2) . '/public/github_assignment_review.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere github_assignment_review.php\n");
    exit(1);
}
foreach (['id_studente_cv', 'id_classe_cv', 'id_materia_cv', 'GITHUB_CLASSROOMS', 'CLASSI_ASSEGNATE', 'GITHUB_ASSIGNMENT_STUDENT_MAP', 'ClasseVivaAPI'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "dipendenza ClasseViva ancora presente: {$forbidden}";
    }
}
foreach (['id_gruppo', 'id_studente', 'GITHUB_ASSIGNMENT_STUDENT_LINKS', 'listGithubClassroomMappings'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "riferimento provider-neutral assente: {$required}";
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: github_assignment_review provider-neutral (nessuna dipendenza ClasseViva).\n");
