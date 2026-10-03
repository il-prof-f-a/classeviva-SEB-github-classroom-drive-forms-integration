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
$require(str_contains($review, 'Promise.allSettled([locPromise, metadataPromise, studentLocPromise])'), 'LOC, metadati e percorso studente non sono coordinati in parallelo');
$require(str_contains($review, 'const studentLocPromise ='), 'percorso studente non parte insieme agli altri pannelli');
$require(str_contains($review, 'locOnly: true'), 'flusso parallelo non limitato al calcolo LOC studente');
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
$require(str_contains($review, 'application/x-ndjson'), 'manca il content type dello stream NDJSON');
$require(str_contains($review, 'function ghReviewStreamEmit'), 'manca l’emissione server-side degli eventi streaming');
$require(str_contains($review, 'function fetchGithubReviewStream'), 'manca il parser client-side dello stream');
$require(str_contains($review, 'TextDecoder'), 'il parser streaming non gestisce i chunk UTF-8');
$require(str_contains($review, "stream: '1'") || str_contains($review, "stream', '1'"), 'le richieste review non chiedono il formato streaming');
$require(str_contains($review, "type' => 'result'") || str_contains($review, '"type":"result"'), 'manca l’evento finale result dello stream');
$require(str_contains($review, 'metadata_item'), 'i metadati non hanno eventi incrementali per elemento');
$require(str_contains($review, 'metadata_progress'), 'mancano gli eventi di completamento per i badge metadati');
$require(str_contains($review, 'github-metadata-loading'), 'manca lo stato lampeggiante dei badge metadati');
$require(str_contains($review, 'github-commits-panel-loading'), 'manca lo stato lampeggiante del pannello commit');
$require(str_contains($review, 'github-worktree-panel-loading'), 'manca lo stato lampeggiante del pannello worktree');
$require(str_contains($review, 'syncGithubReviewCommitLoadingState'), 'manca il coordinamento del caricamento pannello commit');
$require(str_contains($review, 'contributionProgressDone'), 'manca lo stato di completamento del percorso studente');
$require(str_contains($review, 'metadataProgress'), 'il client non conserva lo stato di completamento dei badge');
$require(str_contains($review, "'kind' => 'branches'"), 'il server non segnala il completamento dei branch');
$require(str_contains($review, "'kind' => 'issues'"), 'il server non segnala il completamento delle issue');
$require(str_contains($review, "'kind' => 'commits'"), 'il server non segnala il completamento dei commit');
$branchesLoadPos = strpos($review, "'stage' => 'branches'");
$commitPayloadPos = strpos($review, '$commitPayload = []');
$require($branchesLoadPos !== false && $commitPayloadPos !== false && $branchesLoadPos < $commitPayloadPos, 'branch e issue attendono ancora il ciclo costoso dei commit');
$require(str_contains($review, "'kind' => 'issue_update'"), 'la timeline issue non aggiorna gli elementi già visualizzati');
$require(str_contains($review, "event.kind === 'issue_update'"), 'il client non applica gli aggiornamenti della timeline issue');
$require(str_contains($review, "'type' => 'contribution_item'"), 'il percorso studente non emette risultati parziali per elemento');
$require(str_contains($review, 'mergeContributionItem'), 'il client non applica i risultati parziali del percorso studente');
$require(str_contains($review, 'contribution_item'), 'il client non gestisce gli eventi parziali del percorso studente');
$require(str_contains($review, 'contribution_loc_progress'), 'LOC studente non viene inviata a chunk');
$require(str_contains($review, 'studentLocPartial'), 'il client non mantiene lo stato parziale delle LOC studente');
$require(str_contains($review, 'github-attribution-pending'), 'manca lo stato visivo di attribuzione in corso');
$require(str_contains($review, 'github-attribution-pulse'), 'manca l’animazione dello stato di attribuzione in corso');
$require(str_contains($review, 'repo-loc-attribution-pending'), 'LOC senza attribuzione non marcate come in corso');
$require(str_contains($review, 'recalculateGithubReviewLoc'), 'ricalcolo LOC non riavvia il percorso studente');
$require(str_contains($review, "closest('.repo-loc-force')"), 'pulsante Ricalcola non gestito con listener delegato');
$require(str_contains($review, 'github-worktree-attribution-pending'), 'grafo worktree senza attribuzione non marcato come in corso');
$require(str_contains($review, 'function ghReviewReleaseSessionLock'), 'manca il rilascio del lock sessione per richieste parallele');
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
