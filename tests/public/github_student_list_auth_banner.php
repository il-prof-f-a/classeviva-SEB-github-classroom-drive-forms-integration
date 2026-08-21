<?php

declare(strict_types=1);

// Verifica statica che le pagine che caricano l'elenco studenti dal roster GitHub
// mostrino un banner informativo + pulsante di autenticazione quando il gruppo è
// collegato solo a GitHub Classroom e l'utente non è autenticato.
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/rubrica_orale_v2.php',
    $root . '/public/laboratorio_valutazione_new.php',
    $root . '/public/laboratorio_griglia.php',
    $root . '/public/github_assignment_review.php',
];

$failures = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . basename($file);
        continue;
    }
    $base = basename($file);
    if (!str_contains($source, 'github_classroom')) {
        $failures[] = $base . ': riferimento github_classroom assente';
    }
    if (!str_contains($source, 'Autorizza GitHub') && !str_contains($source, 'Autentica con GitHub')) {
        $failures[] = $base . ': pulsante autenticazione GitHub assente';
    }
    if (!str_contains($source, 'getAuthorizationUrl')) {
        $failures[] = $base . ': auth URL con ritorno assente';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: banner autenticazione GitHub per elenco studenti su tutte le pagine.
");
