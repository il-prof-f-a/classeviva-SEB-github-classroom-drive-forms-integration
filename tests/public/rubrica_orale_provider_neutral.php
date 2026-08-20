<?php

declare(strict_types=1);

// Verifica statica che la rubrica orale usi il resolver centralizzato del gruppo
// e non percorsi provider-specifici duplicati.
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
foreach (['RuntimeStudentNameService', 'resolveGroupStudents', 'id_studente_internal', 'findForGroupProvider'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "fallback provider-neutrale assente: {$required}";
    }
}
foreach (['GoogleClassroomAPI', 'getCourseStudents'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "chiamata provider-specifica duplicata ancora presente: {$forbidden}";
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: rubrica orale usa la risoluzione centralizzata dei nomi.\n");
