<?php

declare(strict_types=1);

// Verifica statica delle nuove funzionalità della pagina gruppi didattici:
//  - il conteggio "X di Y studenti da mappare" usa il roster del gruppo quando c'è
//    un solo provider (0 di m);
//  - esiste un'azione di cancellazione gruppo con banner di attenzione e doppia
//    conferma (pulsante + pulsante sul banner).
$file = dirname(__DIR__, 2) . '/public/teaching_groups.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere teaching_groups.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Conteggio studenti con un solo provider: totale dal roster, nessun mapping.
$require(str_contains($source, 'count($providers) === 1'), 'conteggio single-provider assente');
$require(str_contains($source, 'resolveGroupStudents($groupId)'), 'totale studenti non derivato dal roster');

// Azione di cancellazione gruppo.
$require(str_contains($source, "case 'delete_group'"), 'azione delete_group assente');

// Banner di attenzione + doppia conferma.
$require(str_contains($source, 'Conferma eliminazione'), 'pulsante conferma sul banner assente');
$require(str_contains($source, 'data-bs-toggle="modal"'), 'apertura banner di attenzione assente');
$require(str_contains($source, 'vote_count') && str_contains($source, 'uda_count'), 'conteggi voti/UDA nel banner assenti');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: gruppi didattici con conteggio roster e cancellazione confermata.
");
