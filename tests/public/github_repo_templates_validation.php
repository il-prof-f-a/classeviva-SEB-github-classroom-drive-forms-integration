<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/public/github_repo_templates.php') ?: '';
$failures = [];

foreach ([
    'use App\\Integration\\GitHubIntegration;' => 'la pagina deve usare il client GitHub per validare il repository',
    'loadTokenFromSession' => 'la pagina deve caricare il token GitHub della sessione',
    'getRepository($repoOwner, $repoName)' => 'il salvataggio deve leggere i metadati del repository',
    "is_template" => 'il salvataggio deve controllare il flag template',
    'Il repository GitHub non è configurato come template' => 'l’errore deve spiegare che il repository non è un template',
] as $needle => $message) {
    if (!str_contains($page, $needle)) {
        $failures[] = $message;
    }
}

$validationPosition = strpos($page, 'is_template');
$insertPosition = strpos($page, "insertRow('GITHUB_REPO_TEMPLATES'");
if ($validationPosition === false || $insertPosition === false || $validationPosition > $insertPosition) {
    $failures[] = 'il controllo template deve avvenire prima dell’inserimento nel database';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: validazione repository template GitHub verificata.\n");
