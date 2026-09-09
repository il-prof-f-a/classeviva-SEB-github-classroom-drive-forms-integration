<?php

declare(strict_types=1);

// Verifica il salvataggio affidabile della rubrica e la densità della tabella LOC.
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

$require(str_contains($source, 'voto_numerico: gradeRounded'), 'voto numerico non incluso nel salvataggio rubrica');
$require(str_contains($source, 'function flushRubricSave'), 'flush del salvataggio rubrica assente');
$require(str_contains($source, 'await flushRubricSave'), 'chiusura/navigazione non attende il salvataggio');
$require(str_contains($source, '.repo-loc table'), 'stile compatto per tabella LOC assente');
$require(str_contains($source, 'rubricEvaluationSummary'), 'riepilogo testuale della valutazione assente');
$require(str_contains($source, 'saved_json'), 'ricaricamento dati JSON della rubrica assente');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: persistenza rubrica e tabella LOC compatta.\n");
