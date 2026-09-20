<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$integration = file_get_contents($root . '/src/Integration/GitHubIntegration.php') ?: '';
$page = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$failures = [];

foreach ([
    'https://api.github.com/graphql' => 'manca endpoint GraphQL GitHub',
    'copyProjectV2' => 'manca mutazione copia ProjectV2',
    'linkProjectV2ToRepository' => 'manca collegamento ProjectV2/repository',
    'unlinkProjectV2FromRepository' => 'manca scollegamento ProjectV2/repository',
    'deleteProjectV2' => 'manca cleanup ProjectV2',
    'listOrganizationProjectTemplates' => 'manca catalogo template Project',
    'read:user read:org repo user:email admin:org project' => 'manca scope OAuth project',
] as $needle => $message) {
    $haystack = str_contains($needle, 'scope=') ? $page . $integration : $integration;
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: contratto GraphQL ProjectV2 verificato senza chiamate remote.\n");
