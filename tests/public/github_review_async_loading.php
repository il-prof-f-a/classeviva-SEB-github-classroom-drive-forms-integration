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
$require(str_contains($review, 'const locPromise =') && str_contains($review, 'loadRepoLoc(locContainer'), 'LOC non parte dal coordinatore');
$require(str_contains($review, 'const metadataPromise =') && str_contains($review, 'loadRepoMetadata(metadataContainer'), 'metadati non partono dal coordinatore');
$require(str_contains($review, 'Promise.allSettled([locPromise, metadataPromise])'), 'LOC e metadati non sono coordinati in parallelo');
$require(str_contains($review, 'runWithConcurrency'), 'manca il pool per i dettagli commit');
$require(str_contains($review, 'function renderGithubReviewContributionStatus'), 'manca il riepilogo dedicato del percorso studente');
$require(str_contains($review, 'data-contribution-state'), 'manca lo stato dedicato del percorso studente');
$require(str_contains($review, 'function retryGithubReviewPanel'), 'manca il retry mirato di un pannello');
$require(str_contains($review, 'function loadCommitDetailsProgressively'), 'manca il caricamento progressivo dei dettagli commit');
$require(str_contains($review, 'GITHUB_REVIEW_COMMIT_CONCURRENCY = 4'), 'il limite del pool non è esplicito');
$require(str_contains($review, 'runWithConcurrency(commits'), 'i dettagli commit non usano il pool');
$require(str_contains($review, "setAttribute('data-commit-state', 'loading')"), 'manca lo stato loading del singolo commit');
$require(str_contains($review, 'function githubMetadataCacheKey'), 'manca la chiave cache metadata repository/ref');
$require(str_contains($review, "repoFull + '@' + (ref || 'main')"), 'la cache metadata non include il ref');
$require(str_contains($review, 'function isGithubReviewRequestCurrent'), 'manca la guardia per le risposte asincrone obsolete');
$require(str_contains($review, 'metadataRequestToken'), 'manca il token per le richieste metadati');
$require(str_contains($review, 'contributionsRequestToken'), 'manca il token per le richieste contributo');
$require(str_contains($review, 'locRequestToken'), 'manca il token per le richieste LOC');
$require(str_contains($review, 'commitRequestToken'), 'manca il token per i dettagli commit');
$require(!str_contains($review, "container.dataset.loading === '1'"), 'la guardia loading impedisce il riavvio della generazione corrente');
$require(!str_contains($review, "container.dataset.contributionsLoading === '1'"), 'la guardia contributionsLoading impedisce il riavvio della generazione corrente');
$require(preg_match('/loadCommitDetails\(container, repoFull, sha, false[^;]*\)\.catch/s', $review) === 1, 'il caricamento manuale dei dettagli commit non gestisce il rifiuto della promise');
$require(preg_match('/loadCommitDetails\(container, repoFull, sha, true[^;]*\)\.catch/s', $review) === 1, 'il caricamento dei commenti commit non gestisce il rifiuto della promise');
$require(substr_count($review, 'aria-live="polite"') >= 4, 'i quattro pannelli non hanno feedback ARIA indipendente');
$require(!preg_match('/function loadCommitDetailsProgressively\(row, commits, context, state\).*?const commits =/s', $review), 'il coordinatore commit ridefinisce il parametro commits');

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
