<?php

declare(strict_types=1);

// Verifica statica che la rubrica orale non dipenda più esclusivamente da ClasseViva
// per generare i voti: i nomi studenti possono arrivare da Google Classroom tramite
// il gruppo mappato, e il salvataggio instrada l'ID provider corretto.
$file = dirname(__DIR__, 2) . '/public/rubrica_orale_v2.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere rubrica_orale_v2.php\n");
    exit(1);
}

// Il gate rigido che bloccava l'intera pagina senza token CV non deve più esserci.
if (strpos($source, 'if (!$cvReady) {') !== false) {
    $failures[] = 'gate rigido su $cvReady ancora presente';
}
foreach (['GoogleClassroomAPI', 'getCourseStudents', 'id_studente_provider', 'id_studente_gc', 'findForGroupProvider'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "fallback provider-neutrale assente: {$required}";
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: rubrica orale genera voti senza dipendere esclusivamente da ClasseViva (fallback Google Classroom).\n");
