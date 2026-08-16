<?php

declare(strict_types=1);

// Verifica statica che la pubblicazione UDA non dipenda più da ClasseViva/CLASSI_ASSEGNATE.
$file = dirname(__DIR__, 2) . '/public/uda_publish.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere uda_publish.php\n");
    exit(1);
}
foreach (['CLASSI_ASSEGNATE', 'id_classe_cv', 'id_materia_cv', 'findMappedCourse', 'ClasseVivaAPI'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "dipendenza ClasseViva ancora presente: {$forbidden}";
    }
}
foreach (['UdaGroupRepository', 'UDA_PUBBLICAZIONI', 'google_classroom', 'id_gruppo'] as $required) {
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
fwrite(STDOUT, "PASS: pubblicazione UDA provider-neutral (nessuna dipendenza ClasseViva).\n");
