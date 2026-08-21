<?php

declare(strict_types=1);

// Verifica statica che laboratorio_valutazione_new.php sia provider-neutral:
// carica gli studenti dal roster del gruppo (non dall'API ClasseViva), mostra una
// label (non un selettore) per classe+materia, e disabilita la pubblicazione CV
// quando il gruppo non è mappato, con pulsante per andare ai mapping.
$file = dirname(__DIR__, 2) . '/public/laboratorio_valutazione_new.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere laboratorio_valutazione_new.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Studenti dal roster provider-neutral, non dall'API ClasseViva.
$require(str_contains($source, 'resolveGroupStudents'), 'studenti non caricati via resolveGroupStudents');
$require(!str_contains($source, 'getStudentiClasse'), 'caricamento studenti ancora via ClasseViva');

// Classe+materia mostrata come label, non come selettore.
$require(!str_contains($source, 'classe-materia-select'), 'selettore classe+materia ancora presente');
$require(str_contains($source, 'Gruppo didattico'), 'label classe+materia non derivata dal gruppo');

// Pubblicazione CV disabilitata senza mapping + pulsante per i mapping.
$require(str_contains($source, 'Nessun mapping ClasseViva'), 'avviso mapping ClasseViva assente');
$require(str_contains($source, 'Vai ai mapping'), 'pulsante per i mapping assente');

// Il salvataggio evidenze invia id_gruppo + id_studente.
$require(str_contains($source, 'id_gruppo: gruppoId'), 'salvataggio evidenze senza id_gruppo');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: laboratorio_valutazione_new provider-neutral (roster + label + gate CV).
");
