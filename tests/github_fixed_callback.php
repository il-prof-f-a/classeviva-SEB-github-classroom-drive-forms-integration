<?php

declare(strict_types=1);

use App\Integration\GitHubIntegration;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$failures = [];

function githubCallbackCheck(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        return 'http://localhost:8080/' . ltrim($path, '/');
    }
}

$_ENV['GITHUB_REDIRECT_URI'] = 'https://environment.invalid/callback';
$_SESSION = [];
$github = new GitHubIntegration([
    'github' => [
        'client_id' => 'fixed-callback-client',
        'client_secret' => 'fixed-callback-secret',
        'redirect_uri' => 'https://user-controlled.invalid/callback',
    ],
]);
$authorizationUrl = $github->getAuthorizationUrl('fixed-callback-state');
parse_str((string)parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
githubCallbackCheck(
    ($authorizationQuery['redirect_uri'] ?? '') === 'http://localhost:8080/public/github_callback.php',
    'GitHub ignora redirect legacy e usa il callback derivato da APP_URL'
);

$integrationClass = file_get_contents($root . '/src/Integration/GitHubIntegration.php') ?: '';
githubCallbackCheck(
    !str_contains($integrationClass, "\$config['github']['redirect_uri']")
        && !str_contains($integrationClass, "\$_ENV['GITHUB_REDIRECT_URI']"),
    'GitHubIntegration non legge redirect configurabili'
);

$integrationsPage = file_get_contents($root . '/public/user_integrations.php') ?: '';
githubCallbackCheck(
    !str_contains($integrationsPage, 'github_redirect_uri'),
    'la pagina Integrazioni non accetta un redirect GitHub per utente'
);

$bootstrap = file_get_contents($root . '/bootstrap.php') ?: '';
githubCallbackCheck(
    !str_contains($bootstrap, "['client_id', 'client_secret', 'redirect_uri', 'classroom_token']"),
    'bootstrap non importa redirect GitHub dai dati utente'
);

$envExample = file_get_contents($root . '/.env.example') ?: '';
$compose = file_get_contents($root . '/compose.yaml') ?: '';
$readme = file_get_contents($root . '/README.md') ?: '';
githubCallbackCheck(
    !str_contains($envExample, 'GITHUB_REDIRECT_URI')
        && !str_contains($compose, 'GITHUB_REDIRECT_URI')
        && !str_contains($readme, 'GITHUB_REDIRECT_URI'),
    'il parametro GITHUB_REDIRECT_URI non è esposto in configurazione o documentazione'
);
githubCallbackCheck(
    str_contains($readme, "APP_URL")
        && str_contains($readme, '/public/github_callback.php'),
    'README documenta il callback GitHub derivato da APP_URL'
);

if ($failures !== []) {
    echo PHP_EOL . count($failures) . " controlli callback GitHub falliti.\n";
    exit(1);
}

echo PHP_EOL . "Callback GitHub fisso verificato.\n";
