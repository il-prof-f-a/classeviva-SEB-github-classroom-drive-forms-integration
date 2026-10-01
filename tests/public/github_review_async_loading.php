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

$require(str_contains($review, 'function startGithubReviewLoads'), 'manca il coordinatore per la riga');
$require(str_contains($review, 'Promise.allSettled'), 'manca la gestione indipendente delle richieste');
$require(str_contains($review, 'new AbortController'), 'manca l’annullamento delle richieste obsolete');
$require(str_contains($review, 'github-review-generation'), 'manca il controllo di generazione della riga');
$require(str_contains($review, 'data-panel="loc"'), 'manca il placeholder LOC');
$require(str_contains($review, 'data-panel="metadata"'), 'manca il placeholder metadati');
$require(str_contains($review, 'data-panel="graph"'), 'manca il placeholder grafo');
$require(str_contains($review, 'data-panel="contributions"'), 'manca il placeholder contributo studente');
$require(str_contains($review, 'setPanelState'), 'manca uno stato indipendente per pannello');
$require(str_contains($review, 'const locPromise = loadRepoLoc'), 'LOC non parte dal coordinatore');
$require(str_contains($review, 'const metadataPromise = loadRepoMetadata'), 'metadati non partono dal coordinatore');
$require(str_contains($review, 'Promise.allSettled([locPromise, metadataPromise])'), 'LOC e metadati non sono coordinati in parallelo');
$require(str_contains($review, 'runWithConcurrency'), 'manca il pool per i dettagli commit');

$showDetailsStart = strpos($review, "document.querySelectorAll('.show-details-btn')");
$showDetailsHandler = $showDetailsStart === false ? '' : substr($review, $showDetailsStart, 7000);
$serialLoc = strpos($showDetailsHandler, 'await loadRepoLoc(');
$serialMetadata = strpos($showDetailsHandler, 'await loadRepoMetadata(');
$require($serialLoc === false || $serialMetadata === false || $serialLoc > $serialMetadata, 'il listener mantiene una sequenza seriale LOC prima dei metadati');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: contratto caricamento asincrono GitHub Review.\n");
