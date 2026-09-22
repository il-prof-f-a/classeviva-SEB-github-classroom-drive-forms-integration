<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$integration = file_get_contents($root . '/src/Integration/GitHubIntegration.php');
if ($integration === false) {
    fwrite(STDERR, "FAIL: impossibile leggere GitHubIntegration.php\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($integration, 'function getBranch'), 'getBranch mancante');
$require(str_contains($integration, 'function compareCommits'), 'compareCommits mancante');
$require(str_contains($integration, 'validatedRepository'), 'metodi review senza validazione repository');
$require(str_contains($integration, 'validatedBranch'), 'metodi review senza validazione branch');
$require(str_contains($integration, 'function getRepositoryBlameSnapshot'), 'snapshot blame repository mancante');
$require(str_contains($integration, 'graphqlRequest'), 'snapshot blame senza API GraphQL');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: integrazione API per attribuzione GitHub.\n");
