<?php

declare(strict_types=1);

// La rubrica statica deve essere precaricata; ad ogni studente si ricaricano
// solo attribuzioni/voto, senza cache e senza race tra richieste asincrone.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_review.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere public/github_assignment_review.php\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($source, 'rubric_rows:') && str_contains($source, '$pageRubricRows'), 'rubrica non precaricata nel contesto pagina');
$require(str_contains($source, "if (\$getAction === 'rubric_saved')"), 'endpoint attribuzioni separato assente');
$require(str_contains($source, "url.searchParams.set('action', 'rubric_saved')"), 'popup non usa endpoint attribuzioni separato');
$require(str_contains($source, "'Cache-Control: no-store"), 'risposta attribuzioni non marcata no-store');
$require(str_contains($source, "cache: 'no-store'"), 'fetch attribuzioni non disabilita la cache browser');
$require(str_contains($source, 'let rubricLoadSequence = 0'), 'sequenza caricamento rubrica assente');
$require(str_contains($source, 'rubricLoadController'), 'AbortController caricamento rubrica assente');
$require(str_contains($source, 'if (loadSequence !== rubricLoadSequence)'), 'risposte asincrone obsolete non filtrate');
$require(str_contains($source, 'RUBRIC_CTX.rubric_rows'), 'render non usa la rubrica precaricata');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: caricamento rubrica precaricato e attribuzioni non cachabili.\n");
