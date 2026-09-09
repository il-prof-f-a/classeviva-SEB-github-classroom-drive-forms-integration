<?php

declare(strict_types=1);

// Verifica che la tabella GitHub riunisca le informazioni studente e voto in
// una sola colonna, liberando spazio per la repository.
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

$require(str_contains($source, '<th class="vote-col">Studente/Voto</th>'), 'intestazione Studente/Voto assente');
$require(!str_contains($source, '<th>Studente</th>'), 'colonna Studente separata ancora presente');
$require(!str_contains($source, 'col.col-student'), 'colonna CSS dello studente ancora presente');
$require(str_contains($source, 'class="student-vote-cell"'), 'contenitore combinato studente/voto assente');
$require(str_contains($source, 'class="student-info"'), 'blocco informazioni studente assente');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: colonna Studente/Voto nella review GitHub.\n");
