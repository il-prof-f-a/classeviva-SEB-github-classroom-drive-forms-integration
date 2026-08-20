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
    $mysqlE2eCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/e2e/new_uda_provider_neutral_mysql.php');
    exec($mysqlE2eCommand . ' 2>&1', $mysqlE2eOutput, $mysqlE2eExitCode);
    if ($mysqlE2eExitCode === 0) {
        $passes++;
        echo "PASS: E2E provider-neutral MySQL\n";
    } else {
        $failures[] = 'E2E provider-neutral MySQL: ' . implode(' | ', $mysqlE2eOutput);
        echo "FAIL: E2E provider-neutral MySQL\n";
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
check((($autoload['license'] ?? null) === 'PolyForm-Noncommercial-1.0.0'), 'Composer declares PolyForm Noncommercial 1.0.0');

$license = is_file($root . '/LICENSE') ? (file_get_contents($root . '/LICENSE') ?: '') : '';
check(
    str_contains($license, 'PolyForm Noncommercial License 1.0.0')
        && str_contains($license, 'https://polyformproject.org/licenses/noncommercial/1.0.0'),
    'LICENSE references official PolyForm Noncommercial 1.0.0 terms'
);

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

$architectureTests = [
    'ClasseViva optional capability' => __DIR__ . '/classeviva_optional_capability.php',
    'database manager SQL-only' => __DIR__ . '/database_manager_sql_only.php',
    'Docker MySQL local binding' => __DIR__ . '/docker_mysql_local_binding.php',
    'provider-neutral schema' => __DIR__ . '/architecture/provider_neutral_schema.php',
    'no legacy domain identifiers' => __DIR__ . '/architecture/no_legacy_domain_identifiers.php',
    'SQL-only persistence' => __DIR__ . '/architecture/sql_only_persistence.php',
    'student privacy schema' => __DIR__ . '/architecture/no_student_pii.php',
    'test access policy' => __DIR__ . '/domain/test_access_policy.php',
    'SQLite schema migrations' => __DIR__ . '/database/schema_v2_sqlite.php',
    'factory automatic migrations' => __DIR__ . '/database/factory_auto_migration.php',
    'teaching domain reset' => __DIR__ . '/database/teaching_domain_reset.php',
    'legacy table migration' => __DIR__ . '/database/legacy_table_migration.php',
    'legacy JSON restore mapping' => __DIR__ . '/database/legacy_json_restore_mapping.php',
    'PolyForm Noncommercial license' => __DIR__ . '/license_polyform_noncommercial.php',
    'teaching groups' => __DIR__ . '/domain/teaching_groups.php',
    'teaching group catalog' => __DIR__ . '/domain/teaching_group_catalog.php',
    'teaching group student matrix' => __DIR__ . '/domain/teaching_group_student_matrix.php',
    'teaching group matrix context' => __DIR__ . '/domain/teaching_group_matrix_context.php',
    'teaching groups page markup' => __DIR__ . '/public/teaching_groups_page_markup.php',
    'legacy mapping page markup' => __DIR__ . '/public/legacy_mapping_markup.php',
    'legacy mapping security' => __DIR__ . '/public/legacy_mapping_security.php',
    'UDA group assignments' => __DIR__ . '/domain/uda_group_assignments.php',
    'student identities' => __DIR__ . '/domain/student_identities.php',
    'student ownership' => __DIR__ . '/domain/student_ownership.php',
    'student provider mappings' => __DIR__ . '/domain/student_provider_mappings.php',
    'student reference gateway' => __DIR__ . '/domain/student_reference_gateway.php',
    'student reference gateway id gruppo' => __DIR__ . '/domain/student_reference_gateway_id_gruppo.php',
    'group integration resolver' => __DIR__ . '/domain/group_integration_resolver.php',
    'provider-neutral group mappings' => __DIR__ . '/domain/provider_neutral_group_mappings.php',
    'provider capability resolver' => __DIR__ . '/domain/provider_capability_resolver.php',
    'provider capability resolver group' => __DIR__ . '/domain/provider_capability_resolver_group.php',
    'grade import student service' => __DIR__ . '/domain/grade_import_student_service.php',
    'Google Forms score normalizer' => __DIR__ . '/domain/google_form_score_normalizer.php',
    'grade import google id resolution' => __DIR__ . '/domain/grade_import_google_id_resolution.php',
    'grade register target' => __DIR__ . '/domain/grade_register_target.php',
    'grade import name resolution' => __DIR__ . '/domain/grade_import_name_resolution.php',
    'classroom import provider-neutral' => __DIR__ . '/public/classroom_import_provider_neutral.php',
    'forms import provider-neutral' => __DIR__ . '/public/forms_import_provider_neutral.php',
    'forms score normalization integration' => __DIR__ . '/public/forms_score_normalization_integration.php',
    'forms import email smtp' => __DIR__ . '/public/import_forms_email_smtp.php',
    'forms import publish button' => __DIR__ . '/public/import_forms_publish_button.php',
    'forms import email detail' => __DIR__ . '/public/import_forms_email_detail.php',
    'kahoot import provider-neutral' => __DIR__ . '/public/kahoot_import_provider_neutral.php',
    'excel import provider-neutral' => __DIR__ . '/public/excel_import_provider_neutral.php',
    'publish provider-neutral' => __DIR__ . '/public/publish_provider_neutral.php',
    'UDA classroom publish service' => __DIR__ . '/domain/uda_classroom_publish.php',
    'uda view groups' => __DIR__ . '/public/uda_view_groups.php',
    'uda assign groups' => __DIR__ . '/public/uda_assign_groups.php',
    'uda grades publish summary' => __DIR__ . '/public/uda_grades_publish_summary.php',
    'github assignment provider-neutral' => __DIR__ . '/public/github_assignment_provider_neutral.php',
    'github review provider-neutral' => __DIR__ . '/public/github_review_provider_neutral.php',
    'GitHub assignment roster' => __DIR__ . '/domain/github_assignment_roster.php',
    'GitHub assignment group roster' => __DIR__ . '/domain/github_assignment_group_roster.php',
    'GitHub assignment grade repos' => __DIR__ . '/domain/github_assignment_grade_repos.php',
    'wizard catalog groups' => __DIR__ . '/public/wizard_catalog_groups.php',
    'legacy mapping banner' => __DIR__ . '/public/legacy_mapping_banner.php',
    'portal navigation cleanup' => __DIR__ . '/public/portal_navigation_cleanup.php',
    'test phase access' => __DIR__ . '/public/test_phase_access.php',
    'CV token gate on mapping pages' => __DIR__ . '/public/provider_neutral_gate.php',
    'resolver consumed' => __DIR__ . '/public/resolver_consumed.php',
    'rubrica orale provider-neutral' => __DIR__ . '/public/rubrica_orale_provider_neutral.php',
    'laboratorio griglia provider-neutral' => __DIR__ . '/public/laboratorio_griglia_provider_neutral.php',
    'rubrica orale preselezione' => __DIR__ . '/public/rubrica_orale_preselezione.php',
    'rubrica orale riepilogo nomi' => __DIR__ . '/public/rubrica_orale_riepilogo_nomi.php',
    'rubrica orale runtime names' => __DIR__ . '/public/rubrica_orale_runtime_names.php',
    'provider-neutral grades and Classroom publish' => __DIR__ . '/public/provider_neutral_grades_and_classroom_publish.php',
    'provider-neutral mappings' => __DIR__ . '/domain/provider_neutral_mappings.php',
    'GitHub mapping CV pair fallback' => __DIR__ . '/domain/provider_neutral_github_cv_pair.php',
    'ClasseViva student sync' => __DIR__ . '/integration/classeviva_student_sync.php',
    'provider-neutral E2E' => __DIR__ . '/e2e/new_uda_provider_neutral.php',
    'teaching groups editor E2E' => __DIR__ . '/e2e/teaching_groups_editor.php',
    'group ownership capability E2E' => __DIR__ . '/e2e/group_ownership_capability.php',
    'GitHub student resources' => __DIR__ . '/integration/github_student_resources.php',
    'GitHub student map owner' => __DIR__ . '/integration/github_student_map_owner.php',
];
foreach ($architectureTests as $label => $testFile) {
    $architectureOutput = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile) . ' 2>&1', $architectureOutput, $architectureExitCode);
    if ($architectureExitCode === 0) {
        $passes++;
        echo "PASS: architettura {$label}\n";
    } else {
        $failures[] = "Architettura {$label}: " . implode(' | ', $architectureOutput);
        echo "FAIL: architettura {$label}\n";
    }
}

$editorTests = [
    'wizard test fields' => __DIR__ . '/uda_editor/test_wizard_test_fields.php',
    'academic periods' => __DIR__ . '/uda_editor/test_uda_periods.php',
    'Classroom metadata' => __DIR__ . '/uda_editor/test_classroom_metadata.php',
    'UDA integration metadata' => __DIR__ . '/uda_editor/test_uda_integration_metadata.php',
    'local return URL' => __DIR__ . '/uda_editor/test_local_return_url.php',
    'editor utils' => __DIR__ . '/uda_editor/test_editor_utils.php',
    'editor markup' => __DIR__ . '/uda_editor/test_editor_markup.php',
    'objective payload' => __DIR__ . '/uda_editor/test_objective_payload.php',
    'wizard question payload' => __DIR__ . '/uda_editor/test_wizard_question_payload.php',
    'catalog normalizers' => __DIR__ . '/uda_editor/test_catalog_normalizers.php',
    'wizard catalog markup' => __DIR__ . '/uda_editor/test_wizard_catalog_markup.php',
    'wizard classroom preload' => __DIR__ . '/uda_editor/test_wizard_classroom_preload.php',
    'wizard imported questions' => __DIR__ . '/uda_editor/test_wizard_imported_questions.php',
    'wizard step6 provider links' => __DIR__ . '/uda_editor/test_wizard_step6_provider_links.php',
    'wizard mapping markup' => __DIR__ . '/uda_editor/test_wizard_mapping_markup.php',
    'wizard group selector' => __DIR__ . '/uda_editor/test_wizard_group_selector.php',
    'wizard optional assignments' => __DIR__ . '/uda_editor/test_wizard_optional_assignments.php',
    'wizard integration summary' => __DIR__ . '/uda_editor/test_wizard_integration_summary.php',
    'wizard question cancel' => __DIR__ . '/uda_editor/test_wizard_question_cancel.php',
    'legacy group view' => __DIR__ . '/uda_editor/test_legacy_group_view.php',
    'questions handler' => __DIR__ . '/uda_editor/test_questions_handler.php',
    'question card markup' => __DIR__ . '/uda_editor/test_question_card_markup.php',
    'question card pages' => __DIR__ . '/uda_editor/test_question_card_pages.php',
    'question card rendering' => __DIR__ . '/uda_editor/test_question_card_rendering.php',
    'Google Forms catalog' => __DIR__ . '/import_questions/test_google_forms_catalog.php',
    'JSON textarea parser' => __DIR__ . '/import_questions/test_json_textarea.php',
    'import questions markup' => __DIR__ . '/import_questions/test_import_questions_markup.php',
    'import preview full markup' => __DIR__ . '/import_questions/test_import_preview_full.php',
    'template download endpoint' => __DIR__ . '/import_questions/test_template_download.php',
    'import selection controls' => __DIR__ . '/import_questions/test_import_selection.php',
    'forms classroom highlight' => __DIR__ . '/import_questions/test_forms_classroom_highlight.php',
];
foreach ($editorTests as $label => $testFile) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile) . ' 2>&1', $editorOutput, $editorExitCode);
    if ($editorExitCode === 0) {
        $passes++;
        echo "PASS: UDA editor {$label}\n";
    } else {
        $failures[] = "UDA editor {$label}: " . implode(' | ', $editorOutput);
        echo "FAIL: UDA editor {$label}\n";
    }
    $editorOutput = [];
}

$nodeVersion = [];
exec('node --version 2>/dev/null', $nodeVersion, $nodeExitCode);
if ($nodeExitCode === 0) {
    exec('node ' . escapeshellarg(__DIR__ . '/uda_editor/test_editor_utils.js') . ' 2>&1', $nodeOutput, $nodeTestExitCode);
    if ($nodeTestExitCode === 0) {
        $passes++;
        echo "PASS: UDA editor JavaScript utils\n";
    } else {
        $failures[] = 'UDA editor JavaScript utils: ' . implode(' | ', $nodeOutput);
        echo "FAIL: UDA editor JavaScript utils\n";
    }
    exec('node ' . escapeshellarg(__DIR__ . '/uda_editor/test_question_card.js') . ' 2>&1', $nodeCardOutput, $nodeCardTestExitCode);
    if ($nodeCardTestExitCode === 0) {
        $passes++;
        echo "PASS: UDA question card JavaScript\n";
    } else {
        $failures[] = 'UDA question card JavaScript: ' . implode(' | ', $nodeCardOutput);
        echo "FAIL: UDA question card JavaScript\n";
    }
    exec('node ' . escapeshellarg(__DIR__ . '/uda_editor/test_catalog_picker.js') . ' 2>&1', $catalogPickerOutput, $catalogPickerExitCode);
    if ($catalogPickerExitCode === 0) {
        $passes++;
        echo "PASS: catalog picker JavaScript\n";
    } else {
        $failures[] = 'Catalog picker JavaScript: ' . implode(' | ', $catalogPickerOutput);
        echo "FAIL: catalog picker JavaScript\n";
    }
} else {
    echo "SKIP: UDA editor JavaScript utils (Node.js non disponibile)\n";
}

echo "\nSummary: {$passes} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
