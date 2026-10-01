<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$review = file_get_contents($root . '/public/github_assignment_review.php');
$integration = file_get_contents($root . '/src/Integration/GitHubIntegration.php');
$logger = file_get_contents($root . '/src/Core/Security/DiagnosticsLogger.php');
if ($review === false || $integration === false || $logger === false) {
    fwrite(STDERR, "FAIL: impossibile leggere i file della diagnostica review GitHub\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($review, 'setDiagnosticsContext'), 'contesto diagnostico non impostato nella review');
$require(str_contains($review, 'DiagnosticsLogger'), 'logger diagnostico review non referenziato');
$require(str_contains($review, 'request_error'), 'errore endpoint non tracciato');
$require(str_contains($integration, 'api_request_start'), 'inizio chiamata REST GitHub non tracciato');
$require(str_contains($integration, 'api_request_result'), 'risultato chiamata REST GitHub non tracciato');
$require(str_contains($integration, 'graphql_request_result'), 'risultato chiamata GraphQL GitHub non tracciato');
$require(str_contains($integration, 'blame_snapshot_start'), 'inizio snapshot blame non tracciato');
$require(str_contains($integration, 'blame_snapshot_result'), 'risultato snapshot blame non tracciato');
$require(str_contains($logger, 'LOCK_EX'), 'scrittura log diagnostico senza lock');
$require(str_contains($logger, '[redacted]'), 'sanitizzazione diagnostica assente');
$require(!str_contains($integration, "'accessToken'"), 'token GitHub incluso nel contesto diagnostico');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: diagnostica interna GitHub Review.\n");
