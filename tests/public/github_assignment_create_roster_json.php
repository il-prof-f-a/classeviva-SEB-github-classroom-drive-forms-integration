<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$failures = [];

if (!str_contains($source, "define('SKIP_CV_TOKEN_POPUP', true)")) {
    $failures[] = 'la richiesta load_roster non sopprime il popup ClasseViva dalla risposta JSON';
}

$guardParts = [
    "\$_SERVER['REQUEST_METHOD'] === 'POST'",
    "filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW) === 'load_roster'",
];
$missingGuardPart = false;
foreach ($guardParts as $guardPart) {
    if (!str_contains($source, $guardPart)) {
        $missingGuardPart = true;
        break;
    }
}
if ($missingGuardPart) {
    $failures[] = 'la soppressione del popup non è limitata all’azione POST load_roster';
}

if (!str_contains($source, "header('Content-Type: application/json; charset=utf-8')")) {
    $failures[] = 'la risposta roster non dichiara il content type JSON';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: risposta JSON del roster non contaminata dal popup ClasseViva.\n");
