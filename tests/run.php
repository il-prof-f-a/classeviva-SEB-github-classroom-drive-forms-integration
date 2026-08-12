<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$passes = 0;

$adminEnvCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/admin_env_configuration.php');
exec($adminEnvCommand . ' 2>&1', $adminEnvOutput, $adminEnvExitCode);
if ($adminEnvExitCode === 0) {
    $passes++;
    echo "PASS: amministratori configurati tramite ambiente\n";
} else {
    $failures[] = 'Configurazione amministratore: ' . implode(' | ', $adminEnvOutput);
    echo "FAIL: amministratori configurati tramite ambiente\n";
}

$envPrecedenceCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bootstrap_env_precedence.php');
exec($envPrecedenceCommand . ' 2>&1', $envPrecedenceOutput, $envPrecedenceExitCode);
if ($envPrecedenceExitCode === 0) {
    $passes++;
    echo "PASS: precedenza delle variabili ambiente del processo\n";
} else {
    $failures[] = 'Bootstrap: le variabili del processo devono avere precedenza su config/.env ('
        . implode(' | ', $envPrecedenceOutput) . ')';
    echo "FAIL: precedenza delle variabili ambiente del processo\n";
}

$classeVivaSessionCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/classeviva_session_auth.php');
exec($classeVivaSessionCommand . ' 2>&1', $classeVivaSessionOutput, $classeVivaSessionExitCode);
if ($classeVivaSessionExitCode === 0) {
    $passes++;
    echo "PASS: autenticazione ClasseViva limitata alla sessione PHP\n";
} else {
    $failures[] = 'ClasseViva session auth: ' . implode(' | ', $classeVivaSessionOutput);
    echo "FAIL: autenticazione ClasseViva limitata alla sessione PHP\n";
}

$localUrlCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/local_url_configuration.php');
exec($localUrlCommand . ' 2>&1', $localUrlOutput, $localUrlExitCode);
if ($localUrlExitCode === 0) {
    $passes++;
    echo "PASS: URL e callback OAuth configurati per il locale\n";
} else {
    $failures[] = 'Configurazione URL locale: ' . implode(' | ', $localUrlOutput);
    echo "FAIL: URL e callback OAuth configurati per il locale\n";
}

$githubFixedCallbackCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/github_fixed_callback.php');
exec($githubFixedCallbackCommand . ' 2>&1', $githubFixedCallbackOutput, $githubFixedCallbackExitCode);
if ($githubFixedCallbackExitCode === 0) {
    $passes++;
    echo "PASS: callback GitHub fisso e derivato da APP_URL\n";
} else {
    $failures[] = 'Callback GitHub fisso: ' . implode(' | ', $githubFixedCallbackOutput);
    echo "FAIL: callback GitHub fisso e derivato da APP_URL\n";
}

$googleClockSkewCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/google_id_token_clock_skew.php');
exec($googleClockSkewCommand . ' 2>&1', $googleClockSkewOutput, $googleClockSkewExitCode);
if ($googleClockSkewExitCode === 0) {
    $passes++;
    echo "PASS: tolleranza temporale limitata per ID token Google\n";
} else {
    $failures[] = 'Google ID token clock skew: ' . implode(' | ', $googleClockSkewOutput);
    echo "FAIL: tolleranza temporale limitata per ID token Google\n";
}

$integrationHintsCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/user_integrations_empty_hints.php');
exec($integrationHintsCommand . ' 2>&1', $integrationHintsOutput, $integrationHintsExitCode);
if ($integrationHintsExitCode === 0) {
    $passes++;
    echo "PASS: riepiloghi integrazioni sicuri con configurazioni vuote\n";
} else {
    $failures[] = 'Riepiloghi integrazioni: ' . implode(' | ', $integrationHintsOutput);
    echo "FAIL: riepiloghi integrazioni sicuri con configurazioni vuote\n";
}

if (filter_var(getenv('TEST_MYSQL') ?: false, FILTER_VALIDATE_BOOLEAN)) {
    $databaseCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/database_mysql.php');
    exec($databaseCommand . ' 2>&1', $databaseOutput, $databaseExitCode);
    if ($databaseExitCode === 0) {
        $passes++;
        echo "PASS: inizializzazione e validazione MySQL\n";
    } else {
        $failures[] = 'MySQL: ' . implode(' | ', $databaseOutput);
        echo "FAIL: inizializzazione e validazione MySQL\n";
    }
}

function check(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "PASS: {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL: {$message}\n";
}

function filesMatching(string $root, string $extension): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, '.git/')) {
            continue;
        }
        if (strtolower($file->getExtension()) === $extension) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

function envKeysFromCode(string $root): array
{
    $keys = [];
    foreach (filesMatching($root, 'php') as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        if (str_starts_with($relative, 'tests/')) {
            continue;
        }
        $source = file_get_contents($path);
        if ($source === false) {
            continue;
        }
        $patterns = [
            '/\benv\(\s*[\'\"]([A-Z][A-Z0-9_]*)[\'\"]/',
            '/\bgetenv\(\s*[\'\"]([A-Z][A-Z0-9_]*)[\'\"]/',
            '/\$_ENV\s*\[\s*[\'\"]([A-Z][A-Z0-9_]*)[\'\"]\s*\]/',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] ?? [] as $key) {
                $keys[$key] = true;
            }
        }
    }
    $result = array_keys($keys);
    sort($result);
    return $result;
}

function envKeysFromFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=/', trim($line), $match)) {
            $keys[] = $match[1];
        }
    }
    return $keys;
}

foreach (['composer.json', 'composer.lock', 'Dockerfile', 'compose.yaml', '.dockerignore', '.env.example', 'LICENSE', 'README.md', 'scripts/setup_database.php', 'scripts/dev_router.php'] as $required) {
    check(is_file($root . '/' . $required), "required distribution file exists: {$required}");
}

$requiredAssets = [
    'Materiale/CBM_mappa_feedback_v2.xlsx',
    'Materiale/CBM_mappa_valutazioni.xlsx',
    'Materiale/Rubrica valutazione orale VUOTA.xlsx',
    'database/templates/template_domande.xlsx',
    'templates/KahootQuizTemplate.xlsx',
    'templates/KahootRisposte.xlsx',
    'templates/socrativeQuizTemplate.xlsx',
    'templates/socrativeRisposte.xlsx',
    'storage/template_obiettivi.xlsx',
];
foreach ($requiredAssets as $asset) {
    check(is_file($root . '/' . $asset), "required application asset exists: {$asset}");
}

$forbiddenDiagnostics = [
    'public/phpinfo.php',
    'public/info.php',
    'public/index_debug.php',
    'public/system_status_debug.php',
    'public/github_test_mappings_debug.php',
    'public/list_tables.php',
    'public/check_voti_schema.php',
    'public/compare_curl.php',
    'public/check_classi_data.php',
    'public/check_materie_insegnate.php',
    'public/check_test_sheets.php',
    'public/status_safe.php',
    'public/update-database-schema.php',
    'public/download_token.php',
    'public/generate_encryption_key.php',
    'public/import_form_results_step_preview_grades_temp.php',
    'public/google_reauth.php',
    'public/oauth_start.php',
    'templates/seb.seb',
    'google_auth.php',
];
foreach ($forbiddenDiagnostics as $file) {
    check(!is_file($root . '/' . $file), "diagnostic endpoint is absent: {$file}");
}

$requiredProductFiles = [
    'src/Core/ClasseVivaTokenGuard.php',
    'src/Core/GoogleTokenProvider.php',
    'public/refresh_classeviva_token.php',
    'public/test_api_integrations.php',
    'public/api/test_api_handler.php',
    'public/test_cbm_analysis.php',
    'public/test_google_courses.php',
    'public/test_wizard.php',
];
foreach ($requiredProductFiles as $file) {
    check(is_file($root . '/' . $file), "linked product file remains present: {$file}");
}

$phpFiles = filesMatching($root, 'php');
$lintFailures = [];
foreach ($phpFiles as $path) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        $lintFailures[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
    $output = [];
}
check($lintFailures === [], 'all publishable PHP files pass syntax lint' . ($lintFailures ? ': ' . implode(', ', $lintFailures) : ''));

$missingIncludes = [];
foreach ($phpFiles as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $tokens = token_get_all(file_get_contents($path) ?: '');
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $expression = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if ($piece === ';') {
                break;
            }
            $expression .= $piece;
        }
        if (preg_match('~__DIR__\s*\.\s*([\'\"])([^\'\"]+)\1~', $expression, $match)) {
            $target = dirname($path) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $match[2]);
            if (!file_exists($target)) {
                $missingIncludes[] = "{$relative} -> {$match[2]}";
            }
        }
    }
}
check($missingIncludes === [], 'literal include targets exist' . ($missingIncludes ? ': ' . implode('; ', $missingIncludes) : ''));

$displayErrorFiles = [];
foreach ($phpFiles as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if ($relative === 'bootstrap.php' || str_starts_with($relative, 'tests/')) {
        continue;
    }
    $source = file_get_contents($path) ?: '';
    if (preg_match('/ini_set\(\s*[\'\"]display_(?:startup_)?errors[\'\"]/', $source)) {
        $displayErrorFiles[] = $relative;
    }
}
check($displayErrorFiles === [], 'error display is controlled only by bootstrap' . ($displayErrorFiles ? ': ' . implode(', ', $displayErrorFiles) : ''));

$loginStart = is_file($root . '/public/login_google.php') ? (file_get_contents($root . '/public/login_google.php') ?: '') : '';
$loginCallback = is_file($root . '/public/oauth_callback.php') ? (file_get_contents($root . '/public/oauth_callback.php') ?: '') : '';
$integrationOAuth = is_file($root . '/public/google_auth.php') ? (file_get_contents($root . '/public/google_auth.php') ?: '') : '';
check(str_contains($loginStart, 'setState(') && str_contains($loginCallback, 'hash_equals('), 'Google login OAuth validates a session-bound state value');
check(str_contains($integrationOAuth, 'setState(') && str_contains($integrationOAuth, 'hash_equals('), 'Google integration OAuth validates a session-bound state value');
check(!str_contains($integrationOAuth, 'google_auth_flow.log') && !str_contains($integrationOAuth, 'redirect.log'), 'Google OAuth flow does not create diagnostic trace files');
check(!is_file($root . '/google5ddb29afacb8ff84.html'), 'deployment-specific Google verification file is absent');

$forbiddenHostFiles = [];
foreach (array_merge($phpFiles, filesMatching($root, 'md'), filesMatching($root, 'html')) as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (str_starts_with($relative, 'tests/')) {
        continue;
    }
    $source = file_get_contents($path) ?: '';
    if (preg_match('/flip-flop\.it|Sql1287228|89\.46\.111\.79|franchettisalviani\.net/i', $source)) {
        $forbiddenHostFiles[] = $relative;
    }
}
check($forbiddenHostFiles === [], 'source and public docs contain no staging-specific endpoints' . ($forbiddenHostFiles ? ': ' . implode(', ', $forbiddenHostFiles) : ''));

$usedEnvKeys = envKeysFromCode($root);
$exampleKeys = envKeysFromFile($root . '/.env.example');
check(count($exampleKeys) === count(array_unique($exampleKeys)), '.env.example contains no duplicate keys');
$sortedExampleKeys = $exampleKeys;
sort($sortedExampleKeys);
check($sortedExampleKeys === $usedEnvKeys, '.env.example keys exactly match code usage');
if (is_file($root . '/config/.env')) {
    $localKeys = envKeysFromFile($root . '/config/.env');
    check($localKeys === $exampleKeys, 'local config/.env keys use the same order and set as .env.example');
} else {
    echo "SKIP: local config/.env verification (file intentionally absent)\n";
}

$example = is_file($root . '/.env.example') ? (file_get_contents($root . '/.env.example') ?: '') : '';
check(!preg_match('/(?:AIza|gh[pousr]_|sk-)[A-Za-z0-9_-]{16,}/', $example), '.env.example contains no credential-shaped values');

$autoload = is_file($root . '/composer.json') ? json_decode(file_get_contents($root . '/composer.json') ?: '', true) : null;
check(is_array($autoload) && (($autoload['autoload']['psr-4']['App\\'] ?? null) === 'src/'), 'Composer defines App\\ PSR-4 autoloading');
check((($autoload['config']['platform']['php'] ?? null) === '8.2.0'), 'Composer resolves dependencies for the Docker PHP 8.2 runtime');
check((($autoload['require']['php'] ?? null) === '>=8.2 <8.5'), 'Composer declares the PHP range supported by locked dependencies');
check((($autoload['license'] ?? null) === 'CC-BY-NC-SA-4.0'), 'Composer declares the selected CC BY-NC-SA 4.0 license');

$license = is_file($root . '/LICENSE') ? (file_get_contents($root . '/LICENSE') ?: '') : '';
check(str_contains($license, 'Attribution-NonCommercial-ShareAlike 4.0 International'), 'LICENSE contains the official CC BY-NC-SA 4.0 legal text');

$readme = is_file($root . '/README.md') ? (file_get_contents($root . '/README.md') ?: '') : '';
check(
    str_contains($readme, 'API non ufficiali e non documentate')
    && str_contains($readme, 'processo PHP')
    && str_contains($readme, 'reverse engineering'),
    'README accurately discloses the unofficial ClasseViva integration and server-side request flow'
);

$databaseSetup = is_file($root . '/scripts/setup_database.php') ? (file_get_contents($root . '/scripts/setup_database.php') ?: '') : '';
$bootstrapLoad = strpos($databaseSetup, "require_once dirname(__DIR__) . '/bootstrap.php'");
$retryLoop = strpos($databaseSetup, 'do {');
check($bootstrapLoad !== false && $retryLoop !== false && $bootstrapLoad < $retryLoop, 'database bootstrap is loaded once before connection retries');

$entrypoint = is_file($root . '/docker/entrypoint.sh') ? (file_get_contents($root . '/docker/entrypoint.sh') ?: '') : '';
check(str_contains($entrypoint, 'storage/template_obiettivi.xlsx'), 'Docker persistent storage is seeded with the required objective template');

$dockerIgnore = is_file($root . '/.dockerignore') ? (file_get_contents($root . '/.dockerignore') ?: '') : '';
check(str_contains($dockerIgnore, '!storage/template_obiettivi.xlsx'), 'Docker build context includes the required objective template');
check(str_contains($dockerIgnore, '!.env.example'), 'Docker build context includes the public environment example');

$devRouter = is_file($root . '/scripts/dev_router.php') ? (file_get_contents($root . '/scripts/dev_router.php') ?: '') : '';
check(str_contains($devRouter, 'config|database|src|storage|vendor') && str_contains($devRouter, 'http_response_code(403)'), 'PHP development router blocks private application paths');

$ignoredRequired = [];
$gitVersion = [];
exec('git --version 2>/dev/null', $gitVersion, $gitExit);
if ($gitExit === 0) {
    foreach (array_merge($requiredAssets, $requiredProductFiles) as $relative) {
        exec('git -C ' . escapeshellarg($root) . ' check-ignore -q -- ' . escapeshellarg($relative), $ignoreOutput, $ignoreExit);
        if ($ignoreExit === 0) {
            $ignoredRequired[] = $relative;
        }
        $ignoreOutput = [];
    }
    check($ignoredRequired === [], 'required product files and assets are not ignored' . ($ignoredRequired ? ': ' . implode(', ', $ignoredRequired) : ''));
} else {
    echo "SKIP: Git ignore verification (git is not installed in this runtime)\n";
}

echo "\nSummary: {$passes} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
