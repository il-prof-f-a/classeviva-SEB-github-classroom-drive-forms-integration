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
$require(str_contains($showDetailsHandler, 'await loadRepoLoc('), 'LOC non viene caricata all’apertura dei dettagli');
$require(str_contains($showDetailsHandler, 'const locContainer'), 'contenitore LOC non gestito da Mostra dettagli');
$require(str_contains($source, "function_exists('shell_exec')"), 'LOC chiama shell_exec senza verificare la disponibilità');
$require(str_contains($source, 'catch (Throwable $e)'), 'handler LOC non intercetta errori PHP non-Exception');
$require(str_contains($source, 'responseText') && str_contains($source, 'JSON.parse(responseText)'), 'client LOC non gestisce risposte vuote o non JSON');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: review GitHub commit/LOC coerenti con l'hardening di sicurezza.
");
