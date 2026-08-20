<?php

declare(strict_types=1);

// Verifica statica che la rubrica orale preselezioni la prima classe disponibile
// e il primo studente (all'apertura e ad ogni (ri)selezione della classe).
$file = dirname(__DIR__, 2) . '/public/rubrica_orale_v2.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere rubrica_orale_v2.php\n");
    exit(1);
}
foreach (['empty($idClasseDaGet) && !empty($classi)', '$firstStudente = true', 'firstStudente ?', 'caricaValutazioneStudente(selectStudente.value)'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "preselezione classe/studente assente: " . $required;
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: " . $failure . "\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: rubrica orale preseleziona la prima classe e il primo studente.\n");
