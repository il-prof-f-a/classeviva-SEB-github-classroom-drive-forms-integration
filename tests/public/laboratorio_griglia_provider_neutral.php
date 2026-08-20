<?php

declare(strict_types=1);

// Verifica statica che laboratorio_griglia.php salvi i voti con id_gruppo
// (tabelle migrate) e inizializzi difensivamente le variabili della griglia.
$file = dirname(__DIR__, 2) . '/public/laboratorio_griglia.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere laboratorio_griglia.php\n");
    exit(1);
}
foreach (['findByExternal', 'id_gruppo', 'votiCoda', 'votiRegistrati', 'statisticheClassi'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "riferimento provider-neutral/difensivo assente: " . $required;
    }
}
// Le tabelle migrate non devono piu' essere scritte con la coppia legacy CV.
if (strpos($source, "'id_classe_cv' => \$idClasseCV") !== false) {
    $failures[] = "scrittura legacy id_classe_cv ancora presente su tabella migrata";
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: " . $failure . "\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: laboratorio_griglia salva i voti con id_gruppo e inizializza difensivamente la griglia.\n");
