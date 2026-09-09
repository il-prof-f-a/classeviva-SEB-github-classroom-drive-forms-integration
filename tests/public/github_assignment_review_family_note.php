<?php

declare(strict_types=1);

// Verifica la composizione della nota destinata alla famiglia per i voti GitHub.
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

$require(str_contains($source, 'function gh_review_build_family_note'), 'builder nota famigliare assente');
$require(str_contains($source, 'Attività di laboratorio con GitHub:'), 'titolo attività GitHub assente nella nota');
$require(str_contains($source, "'Valutazione:'"), 'sezione valutazione assente nella nota');
$require(str_contains($source, 'livello_'), 'descrittori dei livelli non utilizzati nella nota');
$require(str_contains($source, 'gh_review_build_family_note($dbAdapter'), 'builder nota non usato nel salvataggio voto');
$require(str_contains($source, "'giudizio' => \$familyNote"), 'nota famigliare non salvata nel campo giudizio');
$require(str_contains($source, "'descrizione' => \$internalNote"), 'nota interna non separata nel campo descrizione');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: nota famigliare per voto GitHub.\n");
