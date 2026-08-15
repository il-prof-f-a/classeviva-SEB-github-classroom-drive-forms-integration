<?php
/**
 * Bootstrap - Inizializza l'applicazione
 * Ora le impostazioni provengono principalmente da .env (root o config/.env).
 * YAML resta solo come fallback/merge per retrocompatibilità.
 */
 
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

define('ROOT_PATH', __DIR__);

// Autoloader Composer
if (file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
}

// Carica variabili d'ambiente (.env in root o in config/.env)
$envPaths = [ROOT_PATH, ROOT_PATH . '/config'];
foreach ($envPaths as $envPath) {
    if (file_exists($envPath . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->safeLoad();
    }
}
// Copia in putenv per codice legacy che usa getenv()
foreach ($_ENV as $key => $value) {
    if (!getenv($key)) {
        putenv("$key=$value");
    }
}

// Helper env()
if (!function_exists('env')) {
    function env($key, $default = null) {
        // Le variabili del processo (Docker, Apache, CI) devono poter
        // sovrascrivere in sicurezza i valori presenti nel file .env locale.
        $processValue = getenv($key);
        $value = ($processValue !== false && $processValue !== '')
            ? $processValue
            : ($_ENV[$key] ?? $_SERVER[$key] ?? null);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

$sessionIdleTimeout = max(60, (int)env('SESSION_IDLE_TIMEOUT', 3600));
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)$sessionIdleTimeout);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = env('APP_URL', '');

        $trimmedPath = trim($path, '/');

        if ($base === '') {
            return $trimmedPath ? '/' . $trimmedPath : '/';
        }

        $base = rtrim($base, '/');
        return $trimmedPath ? $base . '/' . $trimmedPath : $base;
    }
}

if (!function_exists('is_admin_user')) {
    function is_admin_user(?string $email = null): bool
    {
        $configured = array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', (string)env('ADMIN_EMAILS', ''))
        ));
        $candidate = strtolower(trim($email ?? (string)($_SESSION['user_email'] ?? '')));

        return $candidate !== '' && in_array($candidate, $configured, true);
    }
}

if (!function_exists('google_credentials_client_id')) {
    function google_credentials_client_id(string $credentialsFile): string
    {
        $credentialsFile = ltrim($credentialsFile, '/\\');
        $path = ROOT_PATH . '/' . $credentialsFile;
        if (!is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return '';
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return '';
        }
        if (!empty($data['web']['client_id'])) {
            return (string)$data['web']['client_id'];
        }
        return '';
    }
}

// Config da .env
function buildConfigFromEnv(): array {
    return [
        'system' => [
            'name' => env('SYSTEM_NAME', 'Sistema Gestione UDA'),
            'version' => env('SYSTEM_VERSION', '1.0.0'),
            'environment' => env('APP_ENV', 'production'),
            'timezone' => env('TIMEZONE', 'Europe/Rome'),
            'locale' => env('LOCALE', 'it_IT'),
            'debug_mode' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
            'maintenance_mode' => filter_var(env('MAINTENANCE_MODE', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'paths' => [
            'base' => env('PATH_BASE', ROOT_PATH . '/'),
            'storage' => env('PATH_STORAGE', 'storage/'),
            'templates' => env('PATH_TEMPLATES', 'database/templates/'),
            'logs' => env('PATH_LOGS', 'storage/logs/'),
            'cache' => env('PATH_CACHE', 'storage/cache/'),
            'uploads' => env('PATH_UPLOADS', 'storage/uploads/'),
            'temp' => env('PATH_TEMP', 'storage/temp/'),
            'uda_structure' => env('PATH_UDA_STRUCTURE', 'storage/uda/{year}/{uda_id}/'),
            'uda_materials' => env('PATH_UDA_MATERIALS', 'materiali/'),
            'uda_tests' => env('PATH_UDA_TESTS', 'test/'),
            'uda_presentations' => env('PATH_UDA_PRESENTATIONS', 'presentazioni/'),
            'uda_grades' => env('PATH_UDA_GRADES', 'voti/'),
            'uda_rubrics' => env('PATH_UDA_RUBRICS', 'rubriche/'),
        ],
        'database' => [
            'type' => env('DB_TYPE', 'sqlite'),
            'sqlite' => [
                'file' => env('DB_SQLITE_FILE', 'database/uda_master.db')
            ],
            'mysql' => [
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => intval(env('DB_PORT', 3306)),
                'database' => env('DB_DATABASE', 'uda_system'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => env('DB_CHARSET', 'utf8mb4'),
                'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => env('DB_PREFIX', ''),
            ],
            'auto_initialize' => filter_var(env('DB_AUTO_INITIALIZE', true), FILTER_VALIDATE_BOOLEAN),
            'auto_repair' => filter_var(env('DB_AUTO_REPAIR', true), FILTER_VALIDATE_BOOLEAN),
            'backup' => [
                'enabled' => filter_var(env('DB_BACKUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'frequency' => env('DB_BACKUP_FREQUENCY', 'daily'),
                'retention_days' => intval(env('DB_BACKUP_RETENTION_DAYS', 30)),
                'path' => env('DB_BACKUP_PATH', 'database/backup/'),
                'compress' => filter_var(env('DB_BACKUP_COMPRESS', true), FILTER_VALIDATE_BOOLEAN)
            ],
            'read_only' => filter_var(env('DB_READ_ONLY', false), FILTER_VALIDATE_BOOLEAN),
            'max_rows' => intval(env('DB_MAX_ROWS', 10000))
        ],
        'academic_year' => [
            'current' => env('CURRENT_ACADEMIC_YEAR', ''),
            'start_date' => env('ACADEMIC_YEAR_START', ''),
            'end_date' => env('ACADEMIC_YEAR_END', '')
        ],
        'classeviva' => [
            'enabled' => filter_var(env('CLASSEVIVA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'base_url' => env('CLASSEVIVA_BASE_URL', ''),
            'timeout' => intval(env('CLASSEVIVA_TIMEOUT', 30)),
            'max_retries' => intval(env('CLASSEVIVA_MAX_RETRIES', 3)),
            'retry_delay' => intval(env('CLASSEVIVA_RETRY_DELAY', 5)),
        ],
        'google' => [
            'enabled' => filter_var(env('GOOGLE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'credentials_file' => env('GOOGLE_CREDENTIALS_FILE', 'config/google_credentials.json'),
            'token_file' => env('GOOGLE_TOKEN_FILE', 'config/google_token.json'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
            'drive' => [
                'enabled' => filter_var(env('GOOGLE_DRIVE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'root_folder_id' => env('GOOGLE_DRIVE_ROOT_FOLDER_ID', ''),
                'root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', 'UDA System'),
                'create_structure' => filter_var(env('GOOGLE_DRIVE_CREATE_STRUCTURE', true), FILTER_VALIDATE_BOOLEAN),
            ],
            'classroom' => [
                'enabled' => filter_var(env('GOOGLE_CLASSROOM_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'default_topic_name' => env('GOOGLE_CLASSROOM_DEFAULT_TOPIC_NAME', 'Unità di Apprendimento'),
                'default_notification' => filter_var(env('GOOGLE_CLASSROOM_DEFAULT_NOTIFICATION', true), FILTER_VALIDATE_BOOLEAN),
            ],
            'sheets' => [
                'enabled' => filter_var(env('GOOGLE_SHEETS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'template_sheet_id' => env('GOOGLE_SHEETS_TEMPLATE_ID', ''),
            ],
            'forms' => [
                'enabled' => filter_var(env('GOOGLE_FORMS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'create_forms_for_tests' => filter_var(env('GOOGLE_FORMS_CREATE_FOR_TESTS', false), FILTER_VALIDATE_BOOLEAN),
            ],
            'picker_api_key' => env('GOOGLE_API_KEY', ''),
            'oauth_client_id' => google_credentials_client_id(env('GOOGLE_CREDENTIALS_FILE', 'config/google_credentials.json')),
        ],
        'github' => [
            // Di default ancora letti da .env, ma sovrascrivibili da INTEGRAZIONI_UTENTE (provider 'github')
            'client_id' => env('GITHUB_CLIENT_ID', ''),
            'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        ],
        'notifications' => [
            'email' => [
                'enabled' => filter_var(env('MAIL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'smtp_host' => env('MAIL_HOST', ''),
                'smtp_port' => env('MAIL_PORT', 587),
                'smtp_encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'smtp_user' => env('MAIL_USERNAME', ''),
                'smtp_password' => env('MAIL_PASSWORD', ''),
                'from_address' => env('MAIL_FROM_ADDRESS', ''),
                'from_name' => env('MAIL_FROM_NAME', ''),
                'test_recipient' => env('MAIL_TEST_RECIPIENT', ''),
            ]
        ],
        'logging' => [
            'enabled' => filter_var(env('LOG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'level' => env('LOG_LEVEL', 'info'),
            'path' => env('LOG_FILE', 'storage/logs/app.log'),
        ]
    ];
}

$config = buildConfigFromEnv();

// Fallback/merge con YAML (env ha precedenza)
if (file_exists(ROOT_PATH . '/config/config.yaml')) {
    $yamlConfig = Symfony\Component\Yaml\Yaml::parseFile(ROOT_PATH . '/config/config.yaml');
    $config = array_replace_recursive($yamlConfig ?? [], $config ?? []);
}

// ============================================================
// Integrazioni per-utente: sovrascrive parti di $config con
// i dati presenti in INTEGRAZIONI_UTENTE per l'utente loggato.
// Questo rende trasparente al resto dell'app il fatto che
// alcune chiavi non arrivano più da .env ma dal database.
// ============================================================
if (php_sapi_name() !== 'cli'
    && session_status() === PHP_SESSION_ACTIVE
    && !empty($_SESSION['user_id'])
) {
    try {
        $dbAdapter = \App\Core\Database\DatabaseFactory::createWithInitialization($config, true);
        $userId = (string)$_SESSION['user_id'];
        $integrationManager = new \App\Core\UserIntegrationManager($dbAdapter, $userId);

        // Profilo utente/scuola
        $profileCfg = $integrationManager->getConfig('profile');
        if (!empty($profileCfg)) {
            $config['user_profile'] = $profileCfg;
        }

        // ClasseViva
        $cvCfg = $integrationManager->getConfig('classeviva');
        // Migrazione automatica: i token eventualmente salvati dalle versioni
        // precedenti vengono rimossi dal database al primo accesso dell'utente.
        if (array_key_exists('token', $cvCfg)) {
            unset($cvCfg['token']);
            $integrationManager->saveConfig('classeviva', $cvCfg, !empty($cvCfg['enabled']));
        }
        if (!empty($cvCfg)) {
            $config['classeviva'] = array_merge($config['classeviva'] ?? [], $cvCfg);
        }

        $classeVivaSessionStore = new \App\Core\ClasseVivaSessionStore($_SESSION);
        $config = $classeVivaSessionStore->mergeIntoConfig($config, $sessionIdleTimeout);

        $config['classeviva']['token_valid'] = false;
        $config['classeviva']['token_error'] = null;
        $skipClasseVivaTokenValidation = defined('SKIP_CV_TOKEN_VALIDATION')
            && SKIP_CV_TOKEN_VALIDATION === true;
        $classeVivaEnabled = filter_var(
            $config['classeviva']['enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $classeVivaToken = trim((string)($config['classeviva']['token']['token'] ?? ''));
        $hasAuthenticatedUser = !empty($_SESSION['user_id']);

        // Un token assente e un token non valido hanno lo stesso effetto:
        // l'utente deve riautenticarsi. Il popup viene comunque renderizzato
        // esclusivamente dal bootstrap (mai dall'API ClasseViva).
        $requiresClasseViva = \App\Core\ClasseVivaCapability::requested();
        if (!$skipClasseVivaTokenValidation && $hasAuthenticatedUser && $requiresClasseViva) {
            if ($classeVivaEnabled && $classeVivaToken === '') {
                $config['classeviva']['token_error'] = 'Token ClasseViva mancante';
                $classeVivaSessionStore->markReauthenticationRequired();
            } elseif ($classeVivaToken !== '') {
                try {
                    $cvValidator = new \App\Integration\ClasseVivaAPI($config);
                    $config['classeviva']['token_valid'] = $cvValidator->validateToken(true, true);
                } catch (\Exception $e) {
                    $config['classeviva']['token_error'] = $e->getMessage();
                }
                if (!$config['classeviva']['token_valid']) {
                    $classeVivaSessionStore->markReauthenticationRequired();
                    unset($config['classeviva']['token']);
                }
            }
        }

        $suppressCvTokenPopup = (defined('SKIP_CV_TOKEN_POPUP') && SKIP_CV_TOKEN_POPUP === true)
            || (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], 'user_integrations.php'))
            || (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], 'user_integrations.php'));
        if ($requiresClasseViva && $classeVivaSessionStore->requiresReauthentication() && !$suppressCvTokenPopup) {
            if (!defined('CV_TOKEN_POPUP_ACTIVE')) {
                define('CV_TOKEN_POPUP_ACTIVE', true);
            }
            $tokenError = htmlspecialchars($config['classeviva']['token_error'] ?? 'Token ClasseViva scaduto', ENT_QUOTES);
            $baseUrl = env('APP_URL', '');
            if ($baseUrl) {
                $classevivaLink = rtrim($baseUrl, '/') . '/public/user_integrations.php#classeviva-section';
            } else {
                $classevivaLink = '/public/user_integrations.php#classeviva-section';
            }
            $cvReturnTo = $_SERVER['REQUEST_URI'] ?? '';
            $cvReturnToEscaped = htmlspecialchars($cvReturnTo, ENT_QUOTES);
            $cvLoginAction = $baseUrl ? rtrim($baseUrl, '/') . '/public/refresh_classeviva_token.php' : '/public/refresh_classeviva_token.php';
            $cvQuickLoginErrorHtml = '';
            $cvFlashKey = 'cv_quick_login_flash';
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $cvQuickLoginFlash = $_SESSION[$cvFlashKey] ?? null;
            if (is_array($cvQuickLoginFlash)) {
                $flashReturnTo = (string)($cvQuickLoginFlash['return_to'] ?? '');
                $flashError = (string)($cvQuickLoginFlash['error'] ?? '');
                $flashMatch = false;
                if ($flashReturnTo === '' || $flashReturnTo === $cvReturnTo) {
                    $flashMatch = true;
                } else {
                    $flashParts = parse_url($flashReturnTo);
                    if ($flashParts !== false) {
                        $flashPath = (string)($flashParts['path'] ?? '');
                        $flashQuery = isset($flashParts['query']) ? '?' . $flashParts['query'] : '';
                        if ($flashPath . $flashQuery === $cvReturnTo) {
                            $flashMatch = true;
                        }
                    }
                }
                if ($flashMatch) {
                    if ($flashError !== '') {
                        $cvQuickLoginErrorHtml = '<p class="cv-token-error">' . htmlspecialchars($flashError, ENT_QUOTES) . '</p>';
                    }
                    unset($_SESSION[$cvFlashKey]);
                }
            }
            register_shutdown_function(function() use ($tokenError, $classevivaLink, $cvReturnToEscaped, $cvLoginAction, $cvQuickLoginErrorHtml) {
                $modalId = 'cv-token-popup';
                echo <<<HTML
<div id="{$modalId}" class="cv-token-popup">
    <div class="cv-token-dialog">
                <h5>Autenticazione ClasseViva richiesta</h5>
        <p>{$tokenError}</p>
        <p>Accedi alla sezione <strong><a href="{$classevivaLink}">Integrazioni → ClasseViva</a></strong> per rinnovare il token.</p>
        {$cvQuickLoginErrorHtml}
        <form class="cv-token-form" method="post" action="{$cvLoginAction}">
            <input type="hidden" name="return_to" value="{$cvReturnToEscaped}">
            <input type="text" name="token_username" placeholder="Username ClasseViva" autocomplete="username" required>
            <input type="password" name="token_password" placeholder="Password ClasseViva" autocomplete="current-password" required>
            <button class="cv-token-submit" type="submit">Salva</button>
        </form>
        <p class="cv-token-note">Username e password non vengono salvati nel portale; viene salvato solo il token di sessione cifrato per ridurre i rischi in caso di data breach.</p>
        <button class="cv-token-close" onclick="document.getElementById('{$modalId}').classList.remove('cv-token-visible')">Chiudi</button>
    </div>
</div>
<style>
    .cv-token-popup {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        visibility: hidden;
        opacity: 0;
        transition: opacity 0.25s ease;
        z-index: 9999;
    }
    .cv-token-popup.cv-token-visible {
        visibility: visible;
        opacity: 1;
    }
    .cv-token-dialog {
        background: #fff;
        padding: 1.5rem;
        border-radius: 12px;
        max-width: 320px;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
    }
    .cv-token-form {
        margin-top: 0.75rem;
        display: grid;
        gap: 0.5rem;
    }
    .cv-token-form input {
        width: 100%;
        padding: 0.45rem 0.6rem;
        border: 1px solid #ced4da;
        border-radius: 6px;
    }
    .cv-token-submit {
        padding: 0.5rem 1rem;
        border: 0;
        background: #0d6efd;
        color: white;
        border-radius: 6px;
        cursor: pointer;
    }
    .cv-token-note {
        margin-top: 0.5rem;
        font-size: 0.75rem;
        color: #6c757d;
    }
    .cv-token-error {
        margin-top: 0.5rem;
        color: #dc3545;
        font-size: 0.85rem;
    }
    .cv-token-close {
        margin-top: 1rem;
        padding: 0.5rem 1rem;
        border: 0;
        background: #0d6efd;
        color: white;
        border-radius: 6px;
        cursor: pointer;
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        requestAnimationFrame(function() {
            var popup = document.getElementById('{$modalId}');
            if (popup) {
                popup.classList.add('cv-token-visible');
                popup.addEventListener('click', function(event) {
                    if (event.target === popup) {
                        popup.classList.remove('cv-token-visible');
                    }
                });
            }
        });
    });
</script>
HTML;
            });
        }

        // Google (Drive root, template Forms, token OAuth)
        $googleCfg = $integrationManager->getConfig('google');
        if (!empty($googleCfg)) {
            if (!empty($googleCfg['drive_root_folder_id'])) {
                $config['google']['drive']['root_folder_id'] = $googleCfg['drive_root_folder_id'];
            }
            if (!empty($googleCfg['forms_template_id'])) {
                $config['google']['forms']['template_id'] = $googleCfg['forms_template_id'];
            }
            if (!empty($googleCfg['forms_template_id_cbm'])) {
                $config['google']['forms']['template_id_cbm'] = $googleCfg['forms_template_id_cbm'];
            }
            // Token OAuth per le API Google (Drive, Classroom, Forms, Sheets)
        if (!empty($googleCfg['token']) && is_array($googleCfg['token'])) {
            $config['google']['oauth_token'] = $googleCfg['token'];
            $credentialsPath = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');
            if (file_exists($credentialsPath)) {
                try {
                    $googleClient = new \Google\Client();
                    $googleClient->setAuthConfig($credentialsPath);
                    $googleClient->setAccessType('offline');
                    $googleClient->setAccessToken($googleCfg['token']);

                    if ($googleClient->isAccessTokenExpired()) {
                        $refreshToken = $googleCfg['token']['refresh_token'] ?? $googleClient->getRefreshToken();
                        if ($refreshToken) {
                            $updatedToken = $googleClient->fetchAccessTokenWithRefreshToken($refreshToken);
                            if (!empty($updatedToken['access_token'])) {
                                $mergedToken = array_merge($googleCfg['token'], $updatedToken);
                                if (empty($mergedToken['refresh_token']) && $refreshToken) {
                                    $mergedToken['refresh_token'] = $refreshToken;
                                }
                                $mergedToken['created'] = time();
                                $config['google']['oauth_token'] = $mergedToken;
                                $googleCfg['token'] = $mergedToken;
                                $integrationManager->saveConfig('google', $googleCfg, true);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Google token refresh failed: " . $e->getMessage());
                }
            }
        }
        }

        // GitHub (client_id / client_secret + classroom token per-utente)
        $githubCfg = $integrationManager->getConfig('github');
        if (!empty($githubCfg)) {
            if (!isset($config['github'])) {
                $config['github'] = [];
            }
            foreach (['client_id', 'client_secret', 'classroom_token'] as $key) {
                if (!empty($githubCfg[$key])) {
                    $config['github'][$key] = $githubCfg[$key];
                }
            }
        }

        // Mail / notifiche
        $mailCfg = $integrationManager->getConfig('mail');
        if (!empty($mailCfg)) {
            if (!isset($config['notifications']['email'])) {
                $config['notifications']['email'] = [];
            }
            $emailCfg = &$config['notifications']['email'];
            $map = [
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_user',
                'smtp_password',
                'from_address',
                'from_name',
                'test_recipient'
            ];
            foreach ($map as $key) {
                if (array_key_exists($key, $mailCfg) && $mailCfg[$key] !== '' && $mailCfg[$key] !== null) {
                    $emailCfg[$key] = $mailCfg[$key];
                }
            }
        }
    } catch (\Throwable $e) {
        // In caso di errore sulle integrazioni, non bloccare l'applicazione;
        // si useranno i valori di fallback da .env / config.yaml.
    }
}

// Imposta error reporting
if ($config['system']['debug_mode'] ?? false) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Imposta timezone
date_default_timezone_set($config['system']['timezone'] ?? env('TIMEZONE', 'Europe/Rome'));

// ============================================================
// Protezione accesso: pagine in /public/ richiedono login
// (eccetto login Google e callback OAuth)
// ============================================================
if (php_sapi_name() !== 'cli') {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    if (!empty($script)) {
        // Normalizza
        $scriptLower = strtolower($script);

        $isPublicScript = strpos($scriptLower, '/public/') !== false;

        // Helper locale per verificare suffisso (compatibile con PHP < 8)
        $endsWith = function (string $haystack, string $needle): bool {
            if ($needle === '') {
                return true;
            }
            $len = strlen($needle);
            return substr($haystack, -$len) === $needle;
        };

        $isLoginScript = (
            $endsWith($scriptLower, '/public/login.php') ||
            $endsWith($scriptLower, '/public/login_google.php') ||
            $endsWith($scriptLower, '/public/oauth_callback.php')
        );

        if ($isPublicScript && !$isLoginScript) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (empty($_SESSION['user_id'])) {
                // URL base dall'APP_URL, altrimenti fallback percorso relativo
                $baseUrl = env('APP_URL', '');
                if ($baseUrl) {
                    $loginUrl = rtrim($baseUrl, '/') . '/index.php';
                } else {
                    $loginUrl = '../index.php';
                }
                header('Location: ' . $loginUrl);
                exit;
            }
        }
    }
}

// Ritorna la configurazione per l'uso nell'applicazione
return $config;
