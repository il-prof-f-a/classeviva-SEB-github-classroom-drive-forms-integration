<?php

declare(strict_types=1);

// Verifica la navigazione user-friendly tra gli studenti nella review GitHub:
// i pulsanti del pannello devono riusare i pulsanti rubric, aprire i dettagli
// della riga e spostare la viewport con un feedback visivo.
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

$require(str_contains($source, 'id="rubricNavPrev"'), 'pulsante studente precedente assente');
$require(str_contains($source, 'id="rubricNavNext"'), 'pulsante studente successivo assente');
$require(str_contains($source, 'function ensureRowDetails'), 'apertura automatica dei dettagli assente');
$require(str_contains($source, 'function navigateRubricStudent'), 'funzione navigazione studenti assente');
$require(str_contains($source, "behavior: 'smooth'"), 'scroll fluido assente');
$require(str_contains($source, 'student-row-flash'), 'feedback visivo sulla riga assente');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: navigazione studenti nella review GitHub.\n");
