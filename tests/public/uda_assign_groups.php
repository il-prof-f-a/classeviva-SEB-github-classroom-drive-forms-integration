<?php

declare(strict_types=1);

// Verifica statica che l'assegnazione UDA usi i gruppi didattici (non le classi ClasseViva).
$file = dirname(__DIR__, 2) . '/public/uda_assign.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere uda_assign.php\n");
    exit(1);
}
foreach (['ClasseVivaAPI', 'getClassesWithTeacherSubjects', 'CLASSI_ASSEGNATE', 'insertClasseAssegnata', 'deleteClasseAssegnata', 'id_materia_cv'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "dipendenza ClasseViva ancora presente: {$forbidden}";
    }
}
foreach (['UdaGroupRepository', 'TeachingGroupRepository', 'assign_groups', 'id_gruppo', 'Gruppi Già Assegnati'] as $required) {
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
fwrite(STDOUT, "PASS: assegnazione UDA provider-neutral (gruppi didattici).\n");
