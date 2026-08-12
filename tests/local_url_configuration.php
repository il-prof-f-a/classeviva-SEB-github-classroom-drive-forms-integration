<?php

declare(strict_types=1);

use App\Integration\GitHubIntegration;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$failures = [];

function localUrlCheck(bool $condition, string $message): void
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

$compose = file_get_contents($root . '/compose.yaml') ?: '';
$expectedComposeEntries = [
    'APP_URL: http://localhost:${APP_PORT:-8080}',
    'GOOGLE_LOGIN_REDIRECT_URI: http://localhost:${APP_PORT:-8080}/public/oauth_callback.php',
    'GOOGLE_REDIRECT_URI: http://localhost:${APP_PORT:-8080}/public/google_auth.php',
];
foreach ($expectedComposeEntries as $entry) {
    localUrlCheck(str_contains($compose, $entry), "Docker configura {$entry}");
}
localUrlCheck(
    str_contains($compose, './config:/var/www/html/config:ro'),
    'Docker monta la configurazione locale e le credenziali Google in sola lettura'
);

$_SESSION = [];
$github = new GitHubIntegration([
    'github' => [
        'client_id' => 'local-test-client',
        'client_secret' => 'local-test-secret',
    ],
]);
$authorizationUrl = $github->getAuthorizationUrl('fixed-test-state');
parse_str((string)parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
localUrlCheck(
    ($authorizationQuery['redirect_uri'] ?? '') === 'http://localhost:8080/public/github_callback.php',
    'GitHub usa APP_URL come fallback della callback'
);

$localEnvPath = $root . '/config/.env';
if (is_file($localEnvPath)) {
    $localValues = [];
    foreach (file($localEnvPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
            $localValues[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
        }
    }
    $expectedLocalValues = [
        'APP_ENV' => 'local',
        'APP_URL' => 'http://localhost:8080',
        'GOOGLE_LOGIN_REDIRECT_URI' => 'http://localhost:8080/public/oauth_callback.php',
        'GOOGLE_REDIRECT_URI' => 'http://localhost:8080/public/google_auth.php',
    ];
    foreach ($expectedLocalValues as $key => $expected) {
        localUrlCheck(($localValues[$key] ?? null) === $expected, "config/.env usa {$key} locale");
    }
    localUrlCheck(!array_key_exists('GITHUB_REDIRECT_URI', $localValues), 'config/.env non espone un redirect GitHub separato');
}

if ($failures !== []) {
    echo PHP_EOL . count($failures) . " controlli URL locale falliti.\n";
    exit(1);
}

echo PHP_EOL . "Configurazione URL locale valida.\n";
