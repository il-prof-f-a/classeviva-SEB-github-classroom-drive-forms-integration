<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$integration = file_get_contents($root . '/src/Integration/GitHubIntegration.php') ?: '';
$failures = [];

if (!str_contains($integration, 'public function getRepository(')) {
    $failures[] = 'GitHubIntegration deve poter verificare i metadati del repository sorgente';
}
if (!str_contains($page, 'getRepository($tOwner, $tRepo)')) {
    $failures[] = 'la creazione deve verificare il repository template prima del batch';
}
if (!str_contains($page, "is_template")) {
    $failures[] = 'la pagina deve rifiutare sorgenti non configurate come template GitHub';
}
if (!str_contains($page, 'repoCreationErrors')) {
    $failures[] = 'gli errori di creazione repository non devono essere silenziati';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: preflight template e gestione errori repository assignment verificati.\n");
