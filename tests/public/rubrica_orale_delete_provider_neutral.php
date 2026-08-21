<?php

declare(strict_types=1);

// Regressione: la cancellazione dei voti nella rubrica orale deve funzionare anche
// con i link provider-neutral (id_classe vuoto, solo id_gruppo/id_uda). I due
// handler di cancellazione non devono più esigere id_classe e devono risolvere il
// gruppo didattico + cancellare per id_gruppo/id_studente (con fallback legacy).
$file = dirname(__DIR__, 2) . '/public/rubrica_orale_v2.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere rubrica_orale_v2.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Estrae un blocco a partire da un commento ACTION fino al successivo marcatore.
$extractBlock = static function (string $marker) use ($source): string {
    $start = strpos($source, $marker);
    if ($start === false) {
        return '';
    }
    $tail = substr($source, $start);
    $next = strpos($tail, '// ==== ACTION:', strlen($marker));
    return $next === false ? $tail : substr($tail, 0, $next);
};

$elimina = $extractBlock('// ==== ACTION: Elimina valutazione studente ====');
$cancella = $extractBlock('// ==== ACTION: Cancella voti registrati (solo DB) ====');

$require($elimina !== '', 'handler elimina_valutazione non trovato');
$require($cancella !== '', 'handler cancella_registrati_blocco non trovato');

// I gate rigidi su id_classe non devono più esserci (solo in questi due handler).
$require(!str_contains($elimina, 'if ($idStudente && $idUda && $idClasse)'), 'elimina_valutazione esige ancora id_classe');
$require(!str_contains($cancella, 'if (!$idUda || !$idClasse)'), 'cancella_registrati_blocco esige ancora id_classe');

// Entrambi risolvono il gruppo e cancellano per id_gruppo.
$require(str_contains($elimina, 'id_gruppo') && str_contains($elimina, 'listForUda'), 'elimina_valutazione non usa il gruppo');
$require(str_contains($cancella, 'id_gruppo') && str_contains($cancella, 'listForUda'), 'cancella_registrati_blocco non usa il gruppo');

// Il JS dei pulsanti di cancellazione invia id_gruppo.
$require(str_contains($source, "'id_gruppo': document.querySelector"), 'il JS di cancellazione non invia id_gruppo');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: cancellazione voti rubrica orale provider-neutral.
");
