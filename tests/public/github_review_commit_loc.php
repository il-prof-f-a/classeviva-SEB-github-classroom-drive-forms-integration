<?php

declare(strict_types=1);

// Regressione: il dettaglio commit e il calcolo LOC della review GitHub devono
// funzionare dopo l'hardening di sicurezza. Verifica che:
//  - commit_details sia servito in POST (coerente con il JS);
//  - il download zip LOC segua più redirect HTTPS senza allowlist host rigida e
//    inoltri il token OAuth solo alla prima richiesta (api.github.com).
$file = dirname(__DIR__, 2) . '/public/github_assignment_review.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere github_assignment_review.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// commit_details: handler POST (non più GET-only).
$require(str_contains($source, "postAction === 'commit_details'"), 'handler commit_details non è POST');
$require(!str_contains($source, "getAction === 'commit_details'"), 'handler commit_details ancora GET-only');

// commit_details: il JS invia i parametri nel body POST.
$require(str_contains($source, "action: 'commit_details'"), 'JS commit_details non invia il body POST');

// LOC: nessuna allowlist host rigida e multi-hop HTTPS con auth solo al primo hop.
$require(!str_contains($source, 'codeload.github.com'), 'LOC ancora con allowlist host rigida');
$require(str_contains($source, 'hop <= 5'), 'LOC senza loop multi-hop');
$require(str_contains($source, 'hop === 0'), 'LOC non limita il token OAuth al primo hop');

// Aruba può disabilitare shell_exec: in tal caso il calcolo interno deve
// rimanere disponibile. LOC è parte dei dettagli e non ha più un pulsante
// separato nella riga.
$require(!str_contains($source, 'repo-loc-btn'), 'il pulsante LOC separato è ancora presente');
$showDetailsStart = strpos($source, "document.querySelectorAll('.show-details-btn')");
$showDetailsHandler = $showDetailsStart === false ? '' : substr($source, $showDetailsStart, 5200);
$require(str_contains($source, 'function startGithubReviewLoads'), 'coordinatore caricamento dettagli assente');
$require(str_contains($source, 'loadRepoLoc(locContainer'), 'LOC non viene avviata dal coordinatore all’apertura dei dettagli');
$require(str_contains($showDetailsHandler, 'const locContainer'), 'contenitore LOC non gestito da Mostra dettagli');
$require(str_contains($source, 'function hideCollapseElement'), 'helper di chiusura dei pannelli collapse assente');
$require(str_contains($showDetailsHandler, 'hideCollapseElement(locCollapse)'), 'chiusura dettagli non nasconde il pannello LOC');
$require(str_contains($source, "function_exists('shell_exec')"), 'LOC chiama shell_exec senza verificare la disponibilità');
$require(str_contains($source, 'catch (Throwable $e)'), 'handler LOC non intercetta errori PHP non-Exception');
$require(str_contains($source, 'fetchGithubReviewStream') && str_contains($source, 'TextDecoder'), 'client LOC non gestisce risposte streaming o fallback JSON');

// LOC: il ref iniziale può essere solo un fallback. Il branch predefinito reale
// della repository deve essere risolto lato server prima del download ZIP.
$locHandlerStart = strpos($source, "if (\$postAction === 'repo_loc')");
$locHandler = $locHandlerStart === false ? '' : substr($source, $locHandlerStart, 12000);
$require(str_contains($source, 'function ghReviewResolveRepositoryRef'), 'manca la risoluzione del default branch GitHub per la LOC');
$require(str_contains($source, "['default_branch']"), 'la LOC non legge default_branch dai metadati repository');
$require(str_contains($locHandler, '$ref = ghReviewResolveRepositoryRef($github, $owner, $repo, $ref);'), 'handler LOC non applica il branch risolto prima del download');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: review GitHub commit/LOC coerenti con l'hardening di sicurezza.
");
