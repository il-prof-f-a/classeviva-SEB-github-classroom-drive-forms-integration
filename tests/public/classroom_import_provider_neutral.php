<?php

declare(strict_types=1);

// Verifica statica che l'import voti Classroom non dipenda più da ClasseViva.
$file = dirname(__DIR__, 2) . '/public/import_classroom_grades.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere import_classroom_grades.php\n");
    exit(1);
}
$failures = [];
foreach (['getStudentiClasse', 'id_studente_cv', 'CLASSROOM_MAPPINGS', 'ClasseVivaAPI', 'id_classe_cv', 'id_materia_cv'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "dipendenza ClasseViva ancora presente: {$forbidden}";
    }
}
foreach (['GradeImportStudentService', 'id_studente', 'id_gruppo', 'resolveGroupId'] as $required) {
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
fwrite(STDOUT, "PASS: import voti Classroom provider-neutral (nessuna dipendenza ClasseViva).\n");
