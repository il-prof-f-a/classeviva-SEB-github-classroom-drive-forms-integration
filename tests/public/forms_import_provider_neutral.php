<?php

declare(strict_types=1);

// Verifica statica che l'import Google Forms non dipenda più da ClasseViva.
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/import_form_results.php' => ['GradeImportStudentService', 'resolveByEmail', 'id_gruppo', 'id_studente'],
    $root . '/public/import_form_results_step_preview_grades.php' => ['internal_student_id', 'id_studente'],
];
$forbidden = ['getClassesWithTeacherSubjects', 'getStudentiClasse', 'MAPPATURA_STUDENTI', 'CLASSROOM_MAPPINGS', 'ClasseVivaAPI', 'id_studente_cv', 'id_classe_cv', 'id_materia_cv', 'id_studente_gc'];
$failures = [];

foreach ($files as $file => $required) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    foreach ($forbidden as $needle) {
        if (strpos($source, $needle) !== false) {
            $failures[] = $base . ': dipendenza ClasseViva ancora presente: ' . $needle;
        }
    }
    foreach ($required as $needle) {
        if (strpos($source, $needle) === false) {
            $failures[] = $base . ': riferimento provider-neutral assente: ' . $needle;
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: import Google Forms provider-neutral (nessuna dipendenza ClasseViva).\n");
