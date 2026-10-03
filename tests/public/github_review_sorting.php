<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$review = file_get_contents($root . '/public/github_assignment_review.php');
if ($review === false) {
    fwrite(STDERR, "FAIL: impossibile leggere github_assignment_review.php\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($review, 'github-review-sort'), 'manca il contratto di ordinamento della review');
$require(str_contains($review, 'data-sort-key="student"'), 'manca l’hook di ordinamento Studente/Voto');
$require(str_contains($review, 'data-sort-key="repo"'), 'manca l’hook di ordinamento Repo');
$require(str_contains($review, 'function sortGithubReviewRows'), 'manca la funzione di ordinamento client-side');
$require(str_contains($review, 'aria-sort'), 'manca lo stato accessibile dell’ordinamento');
$require(str_contains($review, 'tbody.append'), 'l’ordinamento non riutilizza le righe già caricate');
$require(str_contains($review, 'data-sort-student=') && str_contains($review, 'data-sort-repo='), 'mancano i valori normalizzati per l’ordinamento');
$require(str_contains($review, 'addEventListener(\'click\''), 'manca il listener per il click sulle intestazioni');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: contratto ordinamento righe GitHub Review.\n");
