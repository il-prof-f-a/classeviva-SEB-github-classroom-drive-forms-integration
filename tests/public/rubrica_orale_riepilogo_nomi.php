<?php

declare(strict_types=1);

// Verifica statica che il riepilogo voti salvati della rubrica orale ricostruisca
// il nome dello studente dalla lista caricata (i nomi non sono persistiti come PII).
$file = dirname(__DIR__, 2) . '/public/rubrica_orale_v2.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere rubrica_orale_v2.php\n");
    exit(1);
}
foreach (['$nomePerStudente = [];', 'nomePerStudente[$sid] = $nomeSt;', 'nomePerStudente[$idStud] ??'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "ricostruzione nome studente assente: " . $required;
    }
}
if (strpos($source, '$internalAlias') === false || strpos($source, 'nomePerStudente[$internalAlias]') === false) {
    $failures[] = 'alias interno dello studente non collegato al nome runtime';
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: " . $failure . "\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: rubrica orale mostra il nome studente nel riepilogo voti salvati.\n");
