<?php

declare(strict_types=1);

// Verifica statica che l'import Kahoot non dipenda più da ClasseViva.
$file = dirname(__DIR__, 2) . '/public/import_kahoot_grades.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere import_kahoot_grades.php\n");
    exit(1);
}
foreach (['ClasseVivaAPI', 'getStudentiClasse', 'id_studente_cv', 'id_classe_cv', 'id_materia_cv', 'UDA_CLASSI'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "dipendenza ClasseViva ancora presente: {$forbidden}";
    }
}
foreach (['GradeImportStudentService', 'resolveByName', 'id_gruppo', 'id_studente'] as $required) {
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
fwrite(STDOUT, "PASS: import Kahoot provider-neutral (nessuna dipendenza ClasseViva).\n");
