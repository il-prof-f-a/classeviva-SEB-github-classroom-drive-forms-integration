<?php

declare(strict_types=1);

// Verifica che la review GitHub precarichi i voti già salvati, così che il
// select non torni a "non importare" dopo un salvataggio precedente.
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

$require(str_contains($source, 'existingGradesByStudent'), 'mappa dei voti VOTI non definita');
$require(str_contains($source, "findWhere('VOTI'"), 'lettura dei voti salvati assente');
$require(str_contains($source, '$existingGradesByStudent[$studentId]'), 'voto salvato non usato come default');
$require(str_contains($source, 'savedRubricGradesByStudent'), 'fallback dei voti rubricati non definito');
$require(str_contains($source, 'number_format((float)'), 'normalizzazione del formato voto assente');
$require(str_contains($source, "\$selectedSaveIndexes"), 'filtro degli indici selezionati non definito');
$require(str_contains($source, "if (!isset(\$selectedSaveIndexes[\$idx]))"), 'voti non selezionati non esclusi dal salvataggio');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: precaricamento voti salvati nella review GitHub.\n");
