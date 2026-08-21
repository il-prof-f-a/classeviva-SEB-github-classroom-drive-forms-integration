<?php
/**
 * Configurazione integrazioni per utente
 *
 * - Dati profilo/scuola
 * - ClasseViva
 * - Google (Drive root, template Forms)
 * - Mail / notifiche
 */

// Flag per evitare il popup globale del token ClasseViva su questa pagina
if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\ClasseVivaSessionStore;
use App\Core\Database\DatabaseFactory;
use App\Core\UserIntegrationManager;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;
use App\Integration\GoogleFormsCatalog;
use App\Core\NotificationManager;
use App\Integration\GitHubIntegration;
use Google\Client;
use Google\Service\Classroom;
use Google\Service\Drive;
use Google\Service\Forms;
use App\Core\Security\Csrf;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)$_SESSION['user_id'];
$integrationManager = new UserIntegrationManager($dbAdapter, $userId);
$csrfSession = &$_SESSION;
$csrfToken = Csrf::token($csrfSession);

// Logout GitHub (solo sessione/token locale)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout_github') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $gh = new GitHubIntegration($config);
        $gh->logout();
    } catch (\Throwable $e) {
        // ignore
    }
    unset($_SESSION['github_user']);
    header('Location: user_integrations.php#github-section');
    exit;
}

$flashKey = 'user_integrations_flash';
$flash = $_SESSION[$flashKey] ?? null;
if ($flash) {
    $successMessage = $flash['success'] ?? null;
    $anchorFromFlash = $flash['anchor'] ?? null;
    $returnToFromFlash = $flash['return_to'] ?? null;
    unset($_SESSION[$flashKey]);
} else {
    $successMessage = null;
    $anchorFromFlash = null;
    $returnToFromFlash = null;
}
$errorMessage = null;

$normalizeReturnUrl = static function (?string $url): string {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return '';
    }
    if (!empty($parts['scheme']) || !empty($parts['host'])) {
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $targetHost = $parts['host'] ?? '';
        if ($currentHost !== '' && $targetHost !== '' && strcasecmp($currentHost, $targetHost) !== 0) {
            return '';
        }
    }
    return $url;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $returnToCandidate = $_GET['return_to'] ?? '';
    if ($returnToCandidate === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $returnToCandidate = $_SERVER['HTTP_REFERER'];
    }
    $returnToCandidate = $normalizeReturnUrl($returnToCandidate);
    if ($returnToCandidate !== '' && stripos($returnToCandidate, 'user_integrations.php') === false) {
        $_SESSION['cv_return_to'] = $returnToCandidate;
        $returnPath = (string)(parse_url($returnToCandidate, PHP_URL_PATH) ?? '');
        if (str_ends_with($returnPath, '/public/import_questions.php')) {
            $_SESSION['google_return_to'] = $returnToCandidate;
        } else {
            unset($_SESSION['google_return_to']);
        }
    } else {
        unset($_SESSION['google_return_to']);
    }
}
$returnTo = $normalizeReturnUrl($_SESSION['cv_return_to'] ?? '');

// Gestione salvataggi e test integrazioni
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['section'])) {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $section = $_POST['section'];
        $action  = $_POST['action'] ?? 'save';

        if ($section === 'profile') {
            $profileConfig = [
                'school_name'                   => trim($_POST['school_name'] ?? ''),
                'school_email_domain'           => trim($_POST['school_email_domain'] ?? ''),
                'school_student_email_template' => trim($_POST['school_student_email_template'] ?? ''),
                'prof_name'                     => trim($_POST['prof_name'] ?? ''),
            ];
            $integrationManager->saveConfig('profile', $profileConfig, true);
            $successMessage = 'Configurazione profilo salvata.';

    } elseif ($section === 'classeviva') {
            $existingSchoolCode = $classevivaConfig['school_code'] ?? '';
            $classevivaConfig = [
                'enabled'     => isset($_POST['cv_enabled']) ? 1 : 0,
                'base_url'    => trim($_POST['cv_base_url'] ?? 'https://web.spaggiari.eu/rest/v1'),
                'school_code' => trim($_POST['cv_school_code'] ?? $existingSchoolCode),
                // Configurazione periodi scolastici
                'period_count'  => (int)($_POST['cv_period_count'] ?? 2),
                'period_date_1' => trim($_POST['cv_period_date_1'] ?? '01-31'), // Fine primo periodo (MM-DD)
                'period_date_2' => trim($_POST['cv_period_date_2'] ?? ''),      // Fine secondo periodo (solo se 3 periodi)
            ];
            // Salva solo quando non si tratta di un semplice test: evitiamo di sovrascrivere il token
            if ($action !== 'test') {
                $integrationManager->saveConfig('classeviva', $classevivaConfig, !empty($classevivaConfig['enabled']));
            }

            $config['classeviva'] = array_merge($config['classeviva'] ?? [], $classevivaConfig);
            $classevivaTokenState = ClasseVivaTokenGuard::getTokenState($config);

            if ($action === 'refresh_token') {
                $username = trim($_POST['token_username'] ?? '');
                $password = trim($_POST['token_password'] ?? '');
                if (empty($username) || empty($password)) {
                    throw new Exception("Inserisci username e password ClasseViva per rigenerare il token.");
                }
                $cvApi = new ClasseVivaAPI($config);
                $cvApi->authenticate($username, $password);
                unset($_POST['token_username'], $_POST['token_password']);
                $username = null;
                $password = null;
                if (!$cvApi->getPhpSessionToken()) {
                    throw new Exception("Impossibile generare la sessione web ClasseViva dal token.");
                }
                $tokenPayload = $cvApi->getTokenPayload();
                if (!$tokenPayload) {
                    throw new Exception("Impossibile ottenere il token ClasseViva.");
                }
                $classeVivaSessionStore = new ClasseVivaSessionStore($_SESSION);
                $classeVivaSessionStore->storeTokenPayload($tokenPayload);
                $config = $classeVivaSessionStore->mergeIntoConfig(
                    $config,
                    max(60, (int)env('SESSION_IDLE_TIMEOUT', 3600))
                );
                $config['classeviva']['token_valid'] = true;
                $classevivaTokenState = ClasseVivaTokenGuard::getTokenState($config);
                $successMessage = "Token ClasseViva rigenerato con successo.";
                $returnTo = $normalizeReturnUrl($_POST['return_to'] ?? ($_SESSION['cv_return_to'] ?? ''));
                $_SESSION[$flashKey] = [
                    'success' => $successMessage,
                    'anchor' => '#classeviva-section',
                    'return_to' => $returnTo,
                ];
                header('Location: ' . $_SERVER['PHP_SELF'] . '#classeviva-section');
                exit;
            } elseif ($action === 'test') {
                $classeVivaSessionStore = new ClasseVivaSessionStore($_SESSION);
                $tokenPayload = $classeVivaSessionStore->getTokenPayload(
                    max(60, (int)env('SESSION_IDLE_TIMEOUT', 3600))
                );
                if (empty($tokenPayload['token'] ?? '')) {
                    throw new Exception('Token ClasseViva assente. Autorizza la sessione da Integrazioni.');
                }
                $cvApi = new ClasseVivaAPI($config);
                $classes = $cvApi->getClassesWithTeacherSubjects();
                $classCount = count($classes);
                $successMessage = "Test ClasseViva OK. Classi rilevate: {$classCount}";
            } else {
                $successMessage = 'Configurazione ClasseViva salvata.';
            }

        } elseif ($section === 'google') {
            $tokenAction = $_POST['token_action'] ?? null;
            if ($tokenAction === 'revoke') {
                $googleCfg = $integrationManager->getConfig('google');
                if (!empty($googleCfg['token'])) {
                    unset($googleCfg['token']);
                    $integrationManager->saveConfig('google', $googleCfg, true);
                }
                $successMessage = 'Token Google revocato. Puoi autorizzare di nuovo se necessario.';
            } else {
                $googleConfig = $integrationManager->getConfig('google');
                $googleConfig['drive_root_folder_id'] = trim($_POST['drive_root_folder_id'] ?? '');
                $googleConfig['forms_template_id'] = trim($_POST['forms_template_id'] ?? '');
                $googleConfig['forms_template_id_cbm'] = trim($_POST['forms_template_id_cbm'] ?? '');
                $integrationManager->saveConfig('google', $googleConfig, true);

                if ($action === 'test') {
                    // Test accesso Google Classroom (token + credenziali)
                    $config['google'] = array_merge($config['google'] ?? [], $googleConfig);
                    $gc = new GoogleClassroomAPI($config);
                    // Se arriva qui, client e token sono validi; proviamo a leggere qualche corso
                    $courses = $gc->getCourses();
                    $count = is_array($courses) ? count($courses) : 0;
                    $successMessage = "Test Google Classroom OK. Corsi visibili: {$count}";
                } else {
                    $successMessage = 'Configurazione Google salvata.';
                }
            }

        } elseif ($section === 'mail') {
            $existingMailConfig = $integrationManager->getConfig('mail') ?? [];
            $submittedMailPassword = trim((string)($_POST['smtp_password'] ?? ''));
            $mailConfig = [
                'smtp_host'      => trim($_POST['smtp_host'] ?? ''),
                'smtp_port'      => trim($_POST['smtp_port'] ?? ''),
                'smtp_encryption'=> trim($_POST['smtp_encryption'] ?? 'tls'),
                'smtp_user'      => trim($_POST['smtp_user'] ?? ''),
                'smtp_password'  => $submittedMailPassword !== '' ? $submittedMailPassword : (string)($existingMailConfig['smtp_password'] ?? ''),
                'from_address'   => trim($_POST['from_address'] ?? ''),
                'from_name'      => trim($_POST['from_name'] ?? ''),
                'test_recipient' => trim($_POST['test_recipient'] ?? ''),
            ];
            $integrationManager->saveConfig('mail', $mailConfig, !empty($mailConfig['smtp_host']));

            if ($action === 'test') {
                // Costruisci config notifications.email a partire dalla config per-utente
                $config['notifications']['email'] = [
                    'enabled'        => true,
                    'smtp_host'      => $mailConfig['smtp_host'],
                    'smtp_port'      => (int)($mailConfig['smtp_port'] ?: 587),
                    'smtp_encryption'=> $mailConfig['smtp_encryption'] ?: 'tls',
                    'smtp_user'      => $mailConfig['smtp_user'],
                    'smtp_password'  => $mailConfig['smtp_password'],
                    'from_address'   => $mailConfig['from_address'],
                    'from_name'      => $mailConfig['from_name'] ?: 'Sistema UDA',
                    'test_recipient' => $mailConfig['test_recipient'] ?: $mailConfig['from_address'],
                ];

                $nm = new NotificationManager($config);

                // Usa createMailer via riflessione per un semplice invio di test
                $recipient = $config['notifications']['email']['test_recipient'];
                if (empty($recipient)) {
                    throw new Exception("Destinatario test non impostato.");
                }

                $ref = new \ReflectionClass(NotificationManager::class);
                $method = $ref->getMethod('createMailer');
                $method->setAccessible(true);
                /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
                $mail = $method->invoke($nm);

                $mail->clearAllRecipients();
                $mail->addAddress($recipient);
                $mail->Subject = 'Test configurazione email UDA';
                $mail->Body = 'Questa è una email di prova inviata dal sistema UDA per verificare la configurazione SMTP.';
                $mail->AltBody = $mail->Body;

                $mail->send();

                $successMessage = "Email di test inviata a {$recipient}.";
            } else {
                $successMessage = 'Configurazione Mail salvata.';
            }

        } elseif ($section === 'github') {
            $existingGithubConfig = $integrationManager->getConfig('github') ?? [];
            $submittedGithubSecret = trim((string)($_POST['github_client_secret'] ?? ''));
            $githubConfig = [
                'client_id' => trim($_POST['github_client_id'] ?? ''),
                'client_secret' => $submittedGithubSecret !== '' ? $submittedGithubSecret : (string)($existingGithubConfig['client_secret'] ?? ''),
            ];
            $hasGithubOauth = !empty($githubConfig['client_id']) && !empty($githubConfig['client_secret']);
            $integrationManager->saveConfig('github', $githubConfig, $hasGithubOauth);

            if ($action === 'test') {
                // Il test qui è limitato: verifichiamo solo che i campi siano impostati
                if (empty($githubConfig['client_id']) || empty($githubConfig['client_secret'])) {
                    throw new Exception("Compila Client ID e Client Secret prima di eseguire il test.");
                }
                // Prova a creare l'URL di autorizzazione
                $config['github'] = array_merge($config['github'] ?? [], $githubConfig);
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $github = new GitHubIntegration($config);
                $authUrl = $github->getAuthorizationUrl(null, $_SERVER['REQUEST_URI'] ?? null);
                if (empty($authUrl)) {
                    throw new Exception("Impossibile generare URL di autorizzazione GitHub.");
                }
                $successMessage = "Configurazione GitHub OAuth valida. URL di autorizzazione generato correttamente.";
            } else {
                $successMessage = 'Configurazione GitHub salvata.';
            }
        } elseif ($section === 'ai') {
            $aiProvider = trim($_POST['ai_provider'] ?? '');
            $existingAiConfig = $integrationManager->getConfig('ai') ?? [];
            $submittedAiApiKey = trim((string)($_POST['ai_api_key'] ?? ''));
            $aiApiKey = $submittedAiApiKey !== '' ? $submittedAiApiKey : (string)($existingAiConfig['api_key'] ?? '');

            $aiConfig = [
                'provider' => $aiProvider,
                'api_key' => $aiApiKey,
            ];
            $integrationManager->saveConfig('ai', $aiConfig, !empty($aiProvider) && !empty($aiApiKey));

            if ($action === 'test') {
                if (empty($aiProvider)) {
                    throw new Exception("Seleziona un provider AI prima di eseguire il test.");
                }
                if (empty($aiApiKey)) {
                    throw new Exception("Inserisci una API key prima di eseguire il test.");
                }

                // Test base: verifica che la chiave abbia un formato plausibile
                $keyLength = strlen($aiApiKey);
                if ($keyLength < 20) {
                    throw new Exception("La API key sembra troppo corta. Verifica di aver copiato la chiave completa.");
                }

                // Verifica formato specifico per provider
                $validFormat = false;
                switch ($aiProvider) {
                    case 'gemini':
                        $validFormat = (strpos($aiApiKey, 'AIza') === 0);
                        break;
                    case 'openai':
                        $validFormat = (strpos($aiApiKey, 'sk-') === 0);
                        break;
                    case 'claude':
                        $validFormat = (strpos($aiApiKey, 'sk-ant-') === 0);
                        break;
                    case 'openrouter':
                        $validFormat = (strpos($aiApiKey, 'sk-or-') === 0);
                        break;
                    default:
                        $validFormat = true;
                }

                if (!$validFormat) {
                    throw new Exception("Il formato della API key non corrisponde al provider selezionato. Verifica di aver inserito la chiave corretta per " . strtoupper($aiProvider) . ".");
                }

                $successMessage = "Configurazione AI salvata. La chiave API per " . strtoupper($aiProvider) . " ha un formato valido.";
            } else {
                $successMessage = 'Configurazione AI salvata con successo.';
            }
        }
    } catch (Exception $e) {
        unset($_POST['token_username'], $_POST['token_password']);
        $username = null;
        $password = null;
        $errorMessage = 'Errore salvataggio configurazione: ' . $e->getMessage();
    }
}

// Carica configurazioni correnti
$profileConfig    = $integrationManager->getConfig('profile');
$classevivaConfig = $integrationManager->getConfig('classeviva');
unset($classevivaConfig['token']);
$googleConfig     = $integrationManager->getConfig('google');
$mailConfig       = $integrationManager->getConfig('mail');
$githubConfig     = $integrationManager->getConfig('github');
$aiConfig         = $integrationManager->getConfig('ai');
// Assicura che il token guard veda la config salvata
$config['classeviva'] = array_merge($config['classeviva'] ?? [], $classevivaConfig ?? []);
$classeVivaSessionStore = new ClasseVivaSessionStore($_SESSION);
$config = $classeVivaSessionStore->mergeIntoConfig(
    $config,
    max(60, (int)env('SESSION_IDLE_TIMEOUT', 3600))
);
$classevivaTokenState = ClasseVivaTokenGuard::getTokenState($config);

// Stato autenticazione GitHub (OAuth session token o PAT)
$config['github'] = array_merge($config['github'] ?? [], $githubConfig ?? []);
$githubAuthUser = null;
$githubAuthError = null;
$githubIntegration = new GitHubIntegration($config);
$githubIntegration->loadTokenFromSession();
$githubIsAuthenticated = $githubIntegration->isAuthenticated();
if ($githubIsAuthenticated) {
    $githubAuthUser = $_SESSION['github_user'] ?? null;
    if (!is_array($githubAuthUser) || empty($githubAuthUser['login'])) {
        try {
            $githubAuthUser = $githubIntegration->getUser();
        } catch (\Throwable $e) {
            $githubAuthError = $e->getMessage();
            $githubAuthUser = null;
        }
    }
}

// Calcolo stato token Google prima dei tab
$googleCredentialsPath = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');
$googleRedirectUri = $config['google']['redirect_uri'] ?? env('GOOGLE_REDIRECT_URI');
if (!$googleRedirectUri) {
    $googleRedirectUri = app_url('public/google_auth.php');
}

$googleScopes = [
    Drive::DRIVE_FILE,
    Drive::DRIVE_METADATA_READONLY,
    Classroom::CLASSROOM_COURSES_READONLY,
    Classroom::CLASSROOM_COURSEWORK_ME,
    Classroom::CLASSROOM_COURSEWORK_STUDENTS,
    Classroom::CLASSROOM_COURSEWORKMATERIALS,
    Classroom::CLASSROOM_ROSTERS_READONLY,
    Classroom::CLASSROOM_TOPICS,
    Forms::FORMS_BODY,
    Forms::FORMS_RESPONSES_READONLY,
    Forms::DRIVE_FILE,
];

$googleTokenStatus = [
    'exists' => false,
    'valid' => false,
    'expiry' => null,
    'scopes' => [],
    'error' => null,
];
$googleAuthUrl = null;
$googleAuthError = null;
$googleTokenData = null;
$googleMissingScopes = [];

if (!empty($googleConfig['token']) && is_array($googleConfig['token'])) {
    $googleTokenData = $googleConfig['token'];
    $googleMissingScopes = GoogleFormsCatalog::missingScopes($googleTokenData);
}

if (file_exists($googleCredentialsPath)) {
    if ($googleTokenData) {
        try {
            $tokenClient = new Client();
            $tokenClient->setAuthConfig($googleCredentialsPath);
            $tokenClient->setAccessType('offline');
            $tokenClient->setAccessToken($googleTokenData);

            $googleTokenStatus['exists'] = true;
            $googleTokenStatus['valid'] = !$tokenClient->isAccessTokenExpired();

            if (isset($googleTokenData['created'], $googleTokenData['expires_in'])) {
                $googleTokenStatus['expiry'] = $googleTokenData['created'] + $googleTokenData['expires_in'];
            }

            $tokenScopes = $googleTokenData['scope'] ?? '';
            $scopeList = is_array($tokenScopes) ? $tokenScopes : \preg_split('/\\s+/', trim((string)$tokenScopes));
            $googleTokenStatus['scopes'] = array_values(array_filter($scopeList));
        } catch (\Exception $e) {
            $googleTokenStatus['error'] = "Errore nel verificare il token: " . $e->getMessage();
        }
    }

    try {
        $authClient = new Client();
        $authClient->setAuthConfig($googleCredentialsPath);
        $authClient->setRedirectUri($googleRedirectUri);
        $authClient->setAccessType('offline');
        $authClient->setPrompt('consent');

        foreach ($googleScopes as $scope) {
            $authClient->addScope($scope);
        }

        $googleOAuthState = bin2hex(random_bytes(32));
        $_SESSION['google_integration_oauth_state'] = $googleOAuthState;
        $authClient->setState($googleOAuthState);

        $googleAuthUrl = $authClient->createAuthUrl();
    } catch (\Exception $e) {
        $googleAuthError = "Errore durante la generazione dell'URL di autorizzazione: " . $e->getMessage();
    }
} else {
    $googleAuthError = "File credenziali Google non trovato: {$googleCredentialsPath}";
    $googleTokenStatus['error'] = $googleTokenStatus['error'] ?? $googleAuthError;
}

$tabStatus = [];
// Valutazione stato integrazioni (diagnostica rapida)
$statusProfileOk = !empty($profileConfig['school_name']) && !empty($profileConfig['school_email_domain']);
$statusCvOk = !empty($classevivaConfig['enabled']) && $classevivaTokenState['ready'];
$statusGoogleOk = !empty($googleConfig['drive_root_folder_id']) || !empty($googleConfig['forms_template_id']) || !empty($googleConfig['forms_template_id_cbm']) || !empty($googleConfig['token']);
$statusMailOk = !empty($mailConfig['smtp_host']) && !empty($mailConfig['from_address']);
$statusAiOk = !empty($aiConfig['provider']) && !empty($aiConfig['api_key']);
$statusGithubOk = !empty($githubConfig['client_id']) && !empty($githubConfig['client_secret']);

$tabStatus = [
    'profile' => $statusProfileOk ? 'valid' : 'missing',
    'classeviva' => $statusCvOk
        ? 'valid'
        : ($classevivaTokenState['token'] ? 'warning' : 'missing'),
    'google' => ($googleTokenStatus['valid'] ?? false) && !$googleMissingScopes
        ? 'valid'
        : (!empty($googleTokenStatus['exists']) ? 'warning' : 'missing'),
    'mail' => $statusMailOk ? 'valid' : 'missing',
    'ai' => $statusAiOk ? 'valid' : 'missing',
    'github' => $statusGithubOk ? 'valid' : 'missing',
];

$configurationIssue = !$classevivaTokenState['ready'] || !(!empty($googleTokenStatus['exists']) && !empty($googleTokenStatus['valid']) && !$googleMissingScopes);
$configurationCardBorder = $configurationIssue ? 'border-danger config-issue' : 'border-primary';
$configurationHeaderClass = $configurationIssue ? 'bg-danger text-white' : 'bg-primary text-white';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurazione Integrazioni Utente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        section {
            scroll-margin-top: 90px;
        }
        .scope-badge {
            font-size: 0.75rem;
            margin-right: 0.35rem;
            margin-bottom: 0.35rem;
        }
        .big-button {
            padding: 1.25rem;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .status-card {
            border-left: 5px solid #dee2e6;
            transition: all 0.3s;
        }
        .integration-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .integration-tabs .col-12 {
            flex: 1 1 180px;
            max-width: 220px;
        }
        /* Navigazione sezioni: nascondi tutto di default, mostra solo la sezione attiva.
           Se JS fallisce, il body resta con la classe show-all e mostra comunque tutto. */
        .integration-section {
            display: none;
        }
        .integration-section.active {
            display: block;
        }
        body.show-all .integration-section {
            display: block !important;
        }
        .integration-tab {
            width: 100%;
            min-height: 140px;
        }
        .integration-tab.active {
            border: 1px solid #0d6efd;
            box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.4);
        }
        .dashboard-toggle-controls {
            justify-content: flex-start;
        }
        .dashboard-toggle-btn {
            min-width: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }
        .dashboard-toggle-btn .toggle-label {
            display: inline-block;
            white-space: nowrap;
        }
        .dashboard-toggle-btn.active {
            box-shadow: 0 0 0 1px currentColor;
        }
        .dashboard-section.d-none {
            display: none !important;
            opacity: 0;
            transform: translateY(-4px);
        }
        .config-issue .card-body {
            background: #fff5f5;
        }
        .integration-tab.valid {
            border: 1px solid #198754;
            background: #e9f7ef;
            color: #0f5132;
        }
        .integration-tab.warning {
            border: 1px solid #fd7e14;
            background: #fff4e0;
            color: #6c4600;
        }
        .integration-tab.missing {
            border: 1px solid #6c757d;
            background: #f8f9fa;
            color: #6c757d;
        }
        .integration-tab small {
            display: block;
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .status-card.valid {
            border-left-color: #198754;
            background-color: #d1e7dd;
        }
        .status-card.invalid {
            border-left-color: #dc3545;
            background-color: #f8d7da;
        }
        .btn-compact {
            min-width: 120px;
            padding: 0.45rem 1rem;
            border-radius: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .btn-label {
            font-size: 0.85rem;
            margin-left: 0.4rem;
            display: inline-block;
            max-width: 90px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        .btn-google {
            background: linear-gradient(135deg, #1c8ef9, #2dc5ff);
            border-color: transparent;
            color: #fff;
        }
        .btn-google:hover, .btn-google:focus {
            background: linear-gradient(135deg, #1465c8, #1b9bd9);
            color: #fff;
        }
        .btn-ghost {
            background: transparent;
            border-color: #0d6efd;
            color: #0d6efd;
        }
        .btn-ghost:hover, .btn-ghost:focus {
            background: rgba(13, 110, 253, 0.08);
        }
    </style>
</head>
<body class="show-all">
    <?php
    $pageTitle = '<i class="bi bi-gear"></i> Configurazione Utente';
    $pageSubtitle = 'Integrazioni e credenziali';
    $headerActions = '<a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Dashboard</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


<div class="container mb-5">
    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($successMessage) ?>
            <?php if (!empty($returnToFromFlash)): ?>
                <a class="btn btn-sm btn-outline-success ms-2" href="<?= htmlspecialchars($returnToFromFlash) ?>">
                    <i class="bi bi-arrow-left"></i> Torna indietro
                </a>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Stato sintetico integrazioni -->
    <?php
    $cvHint = $classevivaTokenState['ready']
        ? 'Token attivo e valido'
        : ($classevivaTokenState['token'] ? 'Token salvato (da rinnovare)' : 'Nessun token presente');
    // Evita TypeError in assenza di configurazioni (es. nuovo utente)
    $shortenHint = static function (?string $value, int $limit = 25): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, max(1, $limit - 3)) . '...';
    };

    $googleHintSegments = [];
    $tokenEmail = $googleTokenData['email'] ?? '';
    if ($tokenEmail !== '') {
        $googleHintSegments[] = 'Email: ' . $shortenHint($tokenEmail);
    }
    $driveId = $googleConfig['drive_root_folder_id'] ?? '';
    if ($driveId !== '') {
        $googleHintSegments[] = 'Root: ' . $shortenHint($driveId, 19);
    }
    if (empty($googleHintSegments)) {
        $googleHintSegments[] = 'Root: N/D';
    }
    $googleHintText = implode(' | ', $googleHintSegments);

    $aiProviderLabel = !empty($aiConfig['provider']) ? strtoupper($aiConfig['provider']) : 'N/D';
    $aiHint = !empty($aiConfig['provider']) ? $aiProviderLabel . ': ' . $shortenHint($aiConfig['api_key'] ?? '', 15) : 'Nessun provider configurato';

    $profileSchoolHint = $shortenHint($profileConfig['school_name'] ?? '');
    $mailFromHint = $shortenHint($mailConfig['from_address'] ?? '');
    $githubClientHint = $shortenHint($githubConfig['client_id'] ?? '');

    $tabInfo = [
        'profile' => ['label' => 'Profilo / Scuola', 'hint' => htmlspecialchars($profileSchoolHint !== '' ? $profileSchoolHint : 'Nessuna scuola impostata')],
        'classeviva' => ['label' => 'ClasseViva', 'hint' => $shortenHint(htmlspecialchars($cvHint))],
        'google' => ['label' => 'Google (Drive / Forms)', 'hint' => htmlspecialchars($googleHintText)],
        'mail' => ['label' => 'Mail / Notifiche', 'hint' => htmlspecialchars($mailFromHint !== '' ? $mailFromHint : 'Mittente non impostato')],
        'ai' => ['label' => 'Intelligenza Artificiale', 'hint' => htmlspecialchars($aiHint)],
        'github' => ['label' => 'GitHub', 'hint' => 'Client ID: ' . htmlspecialchars($githubClientHint !== '' ? $githubClientHint : 'N/D')],
    ];
    ?>
    <div class="integration-tabs row mb-4 gx-3 gy-3">
        <?php foreach ($tabInfo as $sectionKey => $info): ?>
            <div class="col-12 col-md-6 col-lg-4 col-xl-2">
                <button type="button"
                        class="card h-100 border-0 shadow-sm text-start integration-tab <?= $sectionKey === 'profile' ? 'active' : '' ?> <?= $tabStatus[$sectionKey] ?? '' ?>"
                        data-section="<?= $sectionKey ?>-section">
                    <div class="card-body">
                        <h6 class="card-title mb-1"><?= $info['label'] ?></h6>
                        <small class="text-muted"><?= $info['hint'] ?></small>
                    </div>
                </button>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white border border-top-0 p-4">
        <!-- Profilo -->
        <section id="profile-section" class="mb-5 integration-section active" data-section="profile-section">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Profilo / Scuola</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="profile">

                        <div class="mb-3">
                            <label class="form-label">Nome Istituto</label>
                            <input type="text" name="school_name" class="form-control"
                                   value="<?= htmlspecialchars($profileConfig['school_name'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dominio email studenti</label>
                            <input type="text" name="school_email_domain" class="form-control"
                                   placeholder="es. scuola.example"
                                   value="<?= htmlspecialchars($profileConfig['school_email_domain'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Template email studenti</label>
                            <input type="text" name="school_student_email_template" class="form-control"
                                   placeholder="{cognome}.{nome}@{domain}"
                                   value="<?= htmlspecialchars($profileConfig['school_student_email_template'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nome docente (per annotazioni)</label>
                            <input type="text" name="prof_name" class="form-control"
                                   value="<?= htmlspecialchars($profileConfig['prof_name'] ?? '') ?>">
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Salva profilo
                        </button>
                    </form>
                </div>
            </div>
        </section>

<!-- ClasseViva -->
        <section id="classeviva-section" class="mb-5 integration-section" data-section="classeviva-section">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">ClasseViva</h5>
                </div>
                <div class="card-body">
                    <?php
                        $cvTokenCardClass = $classevivaTokenState['ready'] ? 'valid' : '';
                    ?>
                    <div class="card mb-3 status-card <?= $cvTokenCardClass ?>">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <?php if ($classevivaTokenState['ready']): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i> Token attivo
                                <?php else: ?>
                                    <i class="bi bi-info-circle-fill text-muted"></i> Token ClasseViva
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($classevivaTokenState['ready']): ?>
                                <p class="mb-1">Il token è valido e può essere usato dalle API.</p>
                                <small class="text-muted">Se vuoi rigenerarlo, utilizza la procedura di rinnovo ClasseViva.</small>
                            <?php else: ?>
                                <p class="mb-1 text-muted">Rigenera la sessione ClasseViva quando necessario.</p>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#cvTokenModal">
                                        <i class="bi bi-shield-lock"></i> Rigenera token
                                    </button>
                                </div>
                            <?php endif; ?>
                            <p class="text-muted small mt-2 mb-0">
                                Username e password vengono usati una sola volta e subito rimossi. Token REST e PHPSESSID restano soltanto nella sessione PHP corrente e non vengono salvati nel database.
                            </p>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="classeviva">

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="cv_enabled"
                                   name="cv_enabled" <?= !empty($classevivaConfig['enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cv_enabled">Abilita integrazione ClasseViva</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Base URL API</label>
                            <input type="text" name="cv_base_url" class="form-control"
                                   value="<?= htmlspecialchars($classevivaConfig['base_url'] ?? 'https://web.spaggiari.eu/rest/v1') ?>">
                        </div>

                        <!-- Configurazione Periodi Scolastici -->
                        <div class="card mb-3">
                            <div class="card-header py-2">
                                <h6 class="mb-0"><i class="bi bi-calendar3"></i> Periodi Scolastici</h6>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small mb-3">
                                    Configura la suddivisione dell'anno scolastico per determinare in quale <strong>colonna del registro</strong> vengono scritti i voti.
                                    ClasseViva usa un parametro numerico (1, 2 o 3) per indicare il periodo: i voti inseriti vengono automaticamente posizionati
                                    nella colonna corretta in base alla data e alla configurazione scelta.
                                </p>
                                <?php
                                    $periodCount = (int)($classevivaConfig['period_count'] ?? 2);
                                    $periodDate1 = $classevivaConfig['period_date_1'] ?? '01-31';
                                    $periodDate2 = $classevivaConfig['period_date_2'] ?? '';
                                ?>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Numero di periodi</label>
                                        <select name="cv_period_count" id="cv_period_count" class="form-select" onchange="togglePeriodDates()">
                                            <option value="2" <?= $periodCount === 2 ? 'selected' : '' ?>>2 periodi (Quadrimestri)</option>
                                            <option value="3" <?= $periodCount === 3 ? 'selected' : '' ?>>3 periodi (Trimestri)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" id="period_date_1_label">
                                            <?= $periodCount === 3 ? 'Fine 1° trimestre' : 'Fine 1° quadrimestre' ?>
                                        </label>
                                        <input type="text" name="cv_period_date_1" id="cv_period_date_1"
                                               class="form-control" placeholder="MM-GG (es: 01-31)"
                                               value="<?= htmlspecialchars($periodDate1) ?>"
                                               pattern="\d{2}-\d{2}" title="Formato: MM-GG (es: 01-31 per 31 gennaio)">
                                        <small class="text-muted">Formato: MM-GG (es: 01-31 per 31 gennaio)</small>
                                    </div>
                                    <div class="col-md-4 mb-3" id="period_date_2_container" style="<?= $periodCount !== 3 ? 'display:none;' : '' ?>">
                                        <label class="form-label">Fine 2° trimestre</label>
                                        <input type="text" name="cv_period_date_2" id="cv_period_date_2"
                                               class="form-control" placeholder="MM-GG (es: 04-15)"
                                               value="<?= htmlspecialchars($periodDate2) ?>"
                                               pattern="\d{2}-\d{2}" title="Formato: MM-GG (es: 04-15 per 15 aprile)">
                                        <small class="text-muted">Formato: MM-GG (es: 04-15 per 15 aprile)</small>
                                    </div>
                                </div>
                                <div class="alert alert-info py-2 mb-0">
                                    <small>
                                        <i class="bi bi-info-circle"></i>
                                        <?php if ($periodCount === 2): ?>
                                            <strong>Quadrimestri:</strong>
                                            <span class="badge bg-primary">Colonna 1</span> 1° quadrimestre (fino al <?= $periodDate1 ? date('d/m', strtotime('2024-' . $periodDate1)) : '31/01' ?>),
                                            <span class="badge bg-success">Colonna 3</span> 2° quadrimestre (dal giorno successivo fino a fine anno).
                                        <?php else: ?>
                                            <strong>Trimestri:</strong>
                                            <span class="badge bg-primary">Colonna 1</span> 1° trimestre (fino al <?= $periodDate1 ? date('d/m', strtotime('2024-' . $periodDate1)) : '--' ?>),
                                            <span class="badge bg-warning text-dark">Colonna 2</span> 2° trimestre (fino al <?= $periodDate2 ? date('d/m', strtotime('2024-' . $periodDate2)) : '--' ?>),
                                            <span class="badge bg-success">Colonna 3</span> 3° trimestre (dal giorno successivo fino a fine anno).
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="cv_school_code" value="<?= htmlspecialchars($classevivaConfig['school_code'] ?? '') ?>">

                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="save" class="btn btn-primary">
                                <i class="bi bi-save"></i> Salva configurazione
                            </button>
                            <button type="submit" name="action" value="test" class="btn btn-outline-success">
                                <i class="bi bi-plug"></i> Verifica token
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Modal rigenera token ClasseViva -->
        <div class="modal fade" id="cvTokenModal" tabindex="-1" aria-labelledby="cvTokenModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="classeviva">
                        <input type="hidden" name="action" value="refresh_token">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                        <input type="hidden" name="cv_enabled" value="<?= !empty($classevivaConfig['enabled']) ? 1 : 0 ?>">
                        <input type="hidden" name="cv_base_url" value="<?= htmlspecialchars($classevivaConfig['base_url'] ?? 'https://web.spaggiari.eu/rest/v1') ?>">
                        <input type="hidden" name="cv_school_code" value="<?= htmlspecialchars($classevivaConfig['school_code'] ?? '') ?>">
                        <input type="hidden" name="cv_period_count" value="<?= (int)($classevivaConfig['period_count'] ?? 2) ?>">
                        <input type="hidden" name="cv_period_date_1" value="<?= htmlspecialchars($classevivaConfig['period_date_1'] ?? '01-31') ?>">
                        <input type="hidden" name="cv_period_date_2" value="<?= htmlspecialchars($classevivaConfig['period_date_2'] ?? '') ?>">
                        <div class="modal-header">
                            <h5 class="modal-title" id="cvTokenModalLabel">Rigenera token ClasseViva</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Inserisci le credenziali ClasseViva per ottenere una nuova sessione.</p>
                            <p class="text-muted small">
                                Le credenziali transitano nel server solo per il login REST e vengono subito rimosse. Token REST e PHPSESSID restano soltanto nella sessione PHP corrente.
                            </p>
                            <div class="mb-3">
                                <label class="form-label">Username ClasseViva</label>
                                <input type="text" name="token_username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password ClasseViva</label>
                                <input type="password" name="token_password" class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-lock"></i> Genera token
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Google -->
        <section id="google-section" class="mb-5 integration-section" data-section="google-section">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-3">Autenticazione Google</h5>
                    <div class="card mb-3 status-card <?= ($googleTokenStatus['exists'] && $googleTokenStatus['valid'] && !$googleMissingScopes) ? 'valid' : ($googleTokenStatus['exists'] ? 'invalid' : '') ?>">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <?php if ($googleTokenStatus['exists'] && $googleTokenStatus['valid'] && !$googleMissingScopes): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i> Token Valido
                                <?php elseif ($googleTokenStatus['exists']): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-warning"></i> Token Scaduto
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill text-danger"></i> Token Non Presente
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($googleTokenStatus['exists']): ?>
                                <dl class="row mb-0">
                                    <dt class="col-sm-3">Stato</dt>
                                    <dd class="col-sm-9">
                                        <?php if ($googleTokenStatus['valid']): ?>
                                            <span class="badge bg-success">Valido e attivo</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Scaduto - Richiede rinnovo</span>
                                        <?php endif; ?>
                                    </dd>

                                    <?php if ($googleTokenStatus['expiry']): ?>
                                        <dt class="col-sm-3">Scadenza</dt>
                                        <dd class="col-sm-9">
                                            <?= date('d/m/Y H:i:s', $googleTokenStatus['expiry']) ?>
                                            <?php if ($googleTokenStatus['valid']): ?>
                                                <small class="text-muted">(tra <?= round(($googleTokenStatus['expiry'] - time()) / 3600, 1) ?> ore)</small>
                                            <?php endif; ?>
                                        </dd>
                                    <?php endif; ?>

                                    <?php if (!empty($googleTokenStatus['scopes'])): ?>
                                        <dt class="col-sm-3">Scope</dt>
                                        <dd class="col-sm-9">
                                            <?php foreach ($googleTokenStatus['scopes'] as $scope): ?>
                                                <span class="badge bg-primary scope-badge"><?= htmlspecialchars(basename($scope)) ?></span>
                                            <?php endforeach; ?>
                                        </dd>
                                    <?php endif; ?>
                                </dl>
                            <?php else: ?>
                                <p class="mb-0">
                                    <i class="bi bi-info-circle"></i> Nessun token di autenticazione presente. Autorizza l'accesso per iniziare.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($googleTokenStatus['error']): ?>
                        <div class="alert alert-warning">
                            <?= htmlspecialchars($googleTokenStatus['error']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($googleAuthError): ?>
                        <div class="alert alert-danger">
                            <?= htmlspecialchars($googleAuthError) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($googleMissingScopes): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-shield-exclamation"></i>
                            Per elencare i Forms presenti nel Drive serve una nuova autorizzazione Google con lo scope Drive metadata.
                        </div>
                    <?php endif; ?>

                    <div class="d-grid mb-3">
                        <?php if (!$googleTokenStatus['exists'] || !$googleTokenStatus['valid'] || $googleMissingScopes): ?>
                            <?php if ($googleAuthUrl): ?>
                                <a href="<?= htmlspecialchars($googleAuthUrl) ?>" class="btn btn-primary big-button">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                    <?= $googleTokenStatus['exists'] ? 'Rinnova Token' : 'Autorizza Accesso a Google' ?>
                                </a>
                            <?php else: ?>
                                <button class="btn btn-secondary big-button" disabled>
                                    <i class="bi bi-exclamation-triangle"></i> Impossibile generare l'URL di autorizzazione
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle-fill"></i> Token attivo! Le API Google sono pronte.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($googleTokenStatus['exists']): ?>
                        <form method="POST" class="mt-3" onsubmit="return confirm('Sei sicuro di voler revocare il token? Dovrai autorizzare di nuovo l\\'applicazione.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="section" value="google">
                            <input type="hidden" name="token_action" value="revoke">
                            <div class="d-grid">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="bi bi-x-circle"></i> Revoca Token
                                </button>
                            </div>
                            <small class="text-muted mt-2 d-block">
                                Rimuove il token salvato. Utile per risolvere problemi o cambiare account.
                            </small>
                        </form>
                        <div class="d-grid mt-2">
                            <a href="test_google_courses.php" class="btn btn-outline-primary">
                                <i class="bi bi-search"></i> Test
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Drive / Forms</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="google">

                        <div class="mb-3">
                            <label class="form-label">ID cartella root Drive</label>
                            <input type="text" name="drive_root_folder_id" class="form-control"
                                   value="<?= htmlspecialchars($googleConfig['drive_root_folder_id'] ?? '') ?>">
                            <div class="form-text">Cartella in cui salvare moduli, materiali, ecc.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ID template Google Forms</label>
                            <input type="text" name="forms_template_id" class="form-control"
                                   value="<?= htmlspecialchars($googleConfig['forms_template_id'] ?? '') ?>">
                            <div class="form-text">Opzionale: modulo di partenza da duplicare.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ID template Google Forms con CBM</label>
                            <input type="text" name="forms_template_id_cbm" class="form-control"
                                   value="<?= htmlspecialchars($googleConfig['forms_template_id_cbm'] ?? '') ?>">
                            <div class="form-text">Opzionale: usato solo per quiz CBM. Se vuoto, usa il template standard.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="save"
                                    class="btn btn-compact btn-google"
                                    aria-label="Salva impostazioni Google">
                                <i class="bi bi-save"></i>
                                <span class="btn-label">Salva</span>
                            </button>
                            <button type="submit" name="action" value="test"
                                    class="btn btn-compact btn-ghost"
                                    aria-label="Test Google Classroom">
                                <i class="bi bi-plug"></i>
                                <span class="btn-label">Test</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Mail -->
        <section id="mail-section" class="mb-5 integration-section" data-section="mail-section">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Mail / Notifiche</h5>
                </div>
                <div class="card-body">
                    <?php
                    $mailProviders = [
                        'gmail' => [
                            'label' => 'Gmail / Google Workspace',
                            'host' => 'smtp.gmail.com',
                            'port' => 587,
                            'encryption' => 'tls',
                            'docs' => 'https://support.google.com/accounts/answer/185833'
                        ],
                        'outlook' => [
                            'label' => 'Outlook / Microsoft 365',
                            'host' => 'smtp.office365.com',
                            'port' => 587,
                            'encryption' => 'tls',
                            'docs' => 'https://support.microsoft.com/it-it/account/protezione-dell-account-con-la-verifica-in-due-passaggi-4f5e1e31-9da7-4b39-a511-d0f79d901d3b'
                        ],
                        'virgilio' => [
                            'label' => 'Virgilio Mail',
                            'host' => 'smtp.virgilio.it',
                            'port' => 587,
                            'encryption' => 'tls',
                            'docs' => 'https://help.virgilio.it/hc/it'
                        ],
                        'posteo' => [
                            'label' => 'Posteo',
                            'host' => 'posteo.de',
                            'port' => 587,
                            'encryption' => 'tls',
                            'docs' => 'https://posteo.de/en/help/search'
                        ],
                    ];
                    ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="mail">

                        <div class="card mb-3 border-info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Provider suggeriti</h6>
                                        <p class="mb-0 small text-muted">Scegli uno dei provider comuni per precompilare i campi SMTP. Puoi sempre modificare manualmente.</p>
                                    </div>
                                    <select id="mail-provider" class="form-select form-select-sm w-50">
                                        <option value="">Seleziona un provider</option>
                                        <?php foreach ($mailProviders as $key => $provider): ?>
                                            <option value="<?= $key ?>"
                                                    data-host="<?= $provider['host'] ?>"
                                                    data-port="<?= $provider['port'] ?>"
                                                    data-encryption="<?= $provider['encryption'] ?>"
                                                    data-docs="<?= $provider['docs'] ?>">
                                                <?= htmlspecialchars($provider['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="custom">Provider personalizzato</option>
                                    </select>
                                </div>
                                <div class="alert alert-light mt-3 mb-0 small">
                                    <p class="mb-0"><strong>Autenticazione consigliata:</strong> attiva la verifica in due passaggi e genera una password applicazione specifica.</p>
                                    <ul class="mb-0">
                                        <li>Gmail: <code>https://myaccount.google.com/security</code></li>
                                        <li>Outlook / Microsoft 365: <code>https://account.live.com/proofs/manage</code></li>
                                        <li>Virgilio: usa la sezione <em>Password per app</em> nella Webmail</li>
                                    </ul>
                                    <p class="mt-2 mb-0">
                                        È possibile usare anche la scoperta automatica (<code>https://autoconfig.&lt;dominio&gt;/mail/config-v1.1.xml?emailaddress=&lt;email&gt;</code> o <code>https://&lt;dominio&gt;/.well-known/autoconfig/mail/config-v1.1.xml</code>) per ottenere host e porte.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">SMTP host</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="form-control"
                                   value="<?= htmlspecialchars($mailConfig['smtp_host'] ?? '') ?>">
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Porta</label>
                                <input type="number" id="smtp_port" name="smtp_port" class="form-control"
                                       value="<?= htmlspecialchars($mailConfig['smtp_port'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cifratura</label>
                                <select id="smtp_encryption" name="smtp_encryption" class="form-select">
                                    <?php
                                    $enc = $mailConfig['smtp_encryption'] ?? 'tls';
                                    ?>
                                    <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="smtp_user" class="form-control"
                                   value="<?= htmlspecialchars($mailConfig['smtp_user'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="smtp_password" class="form-control"
                                   value="" placeholder="Lascia vuoto per mantenere il valore configurato" autocomplete="new-password">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Mittente (email)</label>
                            <input type="email" name="from_address" class="form-control"
                                   value="<?= htmlspecialchars($mailConfig['from_address'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mittente (nome)</label>
                            <input type="text" name="from_name" class="form-control"
                                   value="<?= htmlspecialchars($mailConfig['from_name'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Destinatario test</label>
                            <input type="email" name="test_recipient" class="form-control"
                                   value="<?= htmlspecialchars($mailConfig['test_recipient'] ?? '') ?>">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="save" class="btn btn-primary">
                                <i class="bi bi-save"></i> Salva Mail
                            </button>
                            <button type="submit" name="action" value="test" class="btn btn-outline-success">
                                <i class="bi bi-envelope-check"></i> Invia Email di Test
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- GitHub -->
        <!-- Intelligenza Artificiale -->
        <section id="ai-section" class="mb-5 integration-section" data-section="ai-section">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-stars"></i> Intelligenza Artificiale</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Configura il provider AI per generare automaticamente contenuti didattici, domande, rubriche e materiali per le tue UDA.
                    </p>

                    <form method="POST" id="aiForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="ai">

                        <div class="mb-3">
                            <label class="form-label">Provider AI *</label>
                            <select name="ai_provider" class="form-select" id="aiProviderSelect" required>
                                <option value="">Seleziona un provider...</option>
                                <option value="gemini" <?= ($aiConfig['provider'] ?? '') === 'gemini' ? 'selected' : '' ?>>Gemini AI (Google)</option>
                                <option value="openai" <?= ($aiConfig['provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI (ChatGPT)</option>
                                <option value="claude" <?= ($aiConfig['provider'] ?? '') === 'claude' ? 'selected' : '' ?>>Claude AI (Anthropic)</option>
                                <option value="openrouter" <?= ($aiConfig['provider'] ?? '') === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">API Key *</label>
                            <input type="password" name="ai_api_key" class="form-control" id="aiApiKeyInput"
                                   value="" placeholder="Lascia vuoto per mantenere la chiave configurata" autocomplete="new-password">
                            <div class="form-text">
                                La chiave API verrà salvata in modo sicuro e usata solo per il tuo account.
                            </div>
                        </div>

                        <!-- Info dinamica basata sul provider selezionato -->
                        <div id="aiProviderInfo" class="alert alert-info mb-3" style="display: none;">
                            <!-- Contenuto dinamico -->
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="save" class="btn btn-primary">
                                <i class="bi bi-save"></i> Salva Configurazione
                            </button>
                            <button type="submit" name="action" value="test" class="btn btn-outline-success">
                                <i class="bi bi-check-circle"></i> Test Configurazione
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- GitHub -->
        <section id="github-section" class="mb-5 integration-section" data-section="github-section">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <!-- Stato Autenticazione GitHub -->
                    <div class="card mb-3">
                        <?php $githubHeaderClass = ($githubIsAuthenticated && $githubAuthUser && !$githubAuthError) ? 'bg-success text-white' : 'bg-warning'; ?>
                        <div class="card-header <?= $githubHeaderClass ?>">
                            <h6 class="mb-0">
                                <i class="bi bi-person-circle"></i> Stato Autenticazione GitHub
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($githubIsAuthenticated && $githubAuthUser && !$githubAuthError): ?>
                                <div class="d-flex align-items-center">
                                    <?php if (isset($githubAuthUser['avatar_url'])): ?>
                                        <img src="<?= htmlspecialchars($githubAuthUser['avatar_url']) ?>"
                                             alt="Avatar" class="rounded-circle me-3" style="width: 50px; height: 50px;">
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">✓ Autenticato come: <strong><?= htmlspecialchars($githubAuthUser['login']) ?></strong></h6>
                                        <p class="mb-0 text-muted small">
                                            <?= htmlspecialchars($githubAuthUser['name'] ?? '') ?>
                                            <?php if (!empty($githubAuthUser['email'])): ?>
                                                · <?= htmlspecialchars($githubAuthUser['email']) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <form method="POST" action="#github-section" class="d-inline">
                                        <input type="hidden" name="action" value="logout_github">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Disconnetti</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php if ($githubAuthError): ?>
                                    <div class="text-danger small mb-2">
                                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($githubAuthError) ?>
                                    </div>
                                <?php endif; ?>
                                <p class="mb-3">
                                    <i class="bi bi-info-circle"></i>
                                    Per usare GitHub Classroom devi autenticarti con GitHub (oppure inserire un Token GitHub Classroom).
                                </p>
                                <a href="<?= htmlspecialchars($githubIntegration->getAuthorizationUrl(null, $_SERVER['REQUEST_URI'] ?? null)) ?>#github-section" class="btn btn-dark">
                                    <i class="bi bi-github"></i> Autentica con GitHub
                                </a>
                                <p class="text-muted small mt-2 mb-0">
                                    Scopes richiesti: read:user, read:org, repo
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6 class="mb-1"><i class="bi bi-github"></i> Come ottenere le chiavi GitHub</h6>
                        <ol class="mb-0 small">
                            <li>Vai su <code>https://github.com/settings/developers</code> → <strong>OAuth Apps</strong> → <strong>New OAuth App</strong>.</li>
                            <li>Compila:
                                <ul class="mb-0">
                                    <li><strong>Application name</strong>: es. <em>UDA Manager - Nome Docente</em></li>
                                    <li><strong>Homepage URL</strong>: <code><?= htmlspecialchars(app_url()) ?></code></li>
                                    <li><strong>Authorization callback URL</strong>: <code><?= htmlspecialchars(app_url('public/github_callback.php')) ?></code></li>
                                </ul>
                            </li>
                            <li>Salva l'app, poi copia <strong>Client ID</strong> e genera un <strong>Client Secret</strong>.</li>
                            <li>Incolla i valori qui sotto e salva: saranno usati solo per il tuo utente.</li>
                        </ol>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="section" value="github">

                        <div class="mb-3">
                            <label class="form-label">Client ID GitHub OAuth</label>
                            <input type="text" name="github_client_id" class="form-control"
                                   value="<?= htmlspecialchars($githubConfig['client_id'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Client Secret GitHub OAuth</label>
                            <input type="password" name="github_client_secret" class="form-control"
                                   value="" placeholder="Lascia vuoto per mantenere il secret configurato" autocomplete="new-password">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="save" class="btn btn-primary">
                                <i class="bi bi-save"></i> Salva GitHub
                            </button>
                            <button type="submit" name="action" value="test" class="btn btn-outline-success">
                                <i class="bi bi-plug"></i> Test Configurazione
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Funzione per mostrare/nascondere il secondo campo data in base al numero di periodi
    function togglePeriodDates() {
        const periodCount = document.getElementById('cv_period_count');
        const date2Container = document.getElementById('period_date_2_container');
        const date1Label = document.getElementById('period_date_1_label');

        if (!periodCount || !date2Container) return;

        const count = parseInt(periodCount.value);

        if (count === 3) {
            date2Container.style.display = 'block';
            if (date1Label) date1Label.textContent = 'Fine 1° trimestre';
        } else {
            date2Container.style.display = 'none';
            if (date1Label) date1Label.textContent = 'Fine 1° quadrimestre';
        }

        // Aggiorna anche il riepilogo
        updatePeriodSummary();
    }

    function updatePeriodSummary() {
        const periodCount = document.getElementById('cv_period_count');
        const date1 = document.getElementById('cv_period_date_1');
        const date2 = document.getElementById('cv_period_date_2');

        if (!periodCount) return;

        const count = parseInt(periodCount.value);
        const d1 = date1 ? date1.value : '01-31';
        const d2 = date2 ? date2.value : '';

        // Formatta le date per la visualizzazione (MM-DD -> DD/MM)
        const formatDate = (mmdd) => {
            if (!mmdd || mmdd.length !== 5) return '--';
            const parts = mmdd.split('-');
            return parts[1] + '/' + parts[0];
        };

        const summaryEl = document.querySelector('#classeviva-section .alert-info small');
        if (summaryEl) {
            if (count === 2) {
                summaryEl.innerHTML = '<i class="bi bi-info-circle"></i> <strong>Quadrimestri:</strong> ' +
                    '<span class="badge bg-primary">Colonna 1</span> 1° quadrimestre (fino al ' + formatDate(d1) + '), ' +
                    '<span class="badge bg-success">Colonna 3</span> 2° quadrimestre (dal giorno successivo fino a fine anno).';
            } else {
                summaryEl.innerHTML = '<i class="bi bi-info-circle"></i> <strong>Trimestri:</strong> ' +
                    '<span class="badge bg-primary">Colonna 1</span> 1° trimestre (fino al ' + formatDate(d1) + '), ' +
                    '<span class="badge bg-warning text-dark">Colonna 2</span> 2° trimestre (fino al ' + formatDate(d2) + '), ' +
                    '<span class="badge bg-success">Colonna 3</span> 3° trimestre (dal giorno successivo fino a fine anno).';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        try {
        // Inizializza la visualizzazione dei periodi
        togglePeriodDates();

        // Aggiungi listener per aggiornare il riepilogo quando cambiano le date
        const date1Input = document.getElementById('cv_period_date_1');
        const date2Input = document.getElementById('cv_period_date_2');
        if (date1Input) date1Input.addEventListener('input', updatePeriodSummary);
        if (date2Input) date2Input.addEventListener('input', updatePeriodSummary);

        const tabs = document.querySelectorAll('.integration-tab');
        const sections = document.querySelectorAll('.integration-section');

        const activateSection = (targetId) => {
            sections.forEach(sec => sec.classList.toggle('active', sec.getAttribute('data-section') === targetId));
            tabs.forEach(tab => tab.classList.toggle('active', tab.getAttribute('data-section') === targetId));
        };

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.getAttribute('data-section');
                activateSection(target);
            });
        });

        const hashTarget = window.location.hash.slice(1);
        if (hashTarget && document.querySelector(`[data-section="${hashTarget}"]`)) {
            activateSection(hashTarget);
        } else {
            activateSection('profile-section');
        }
        // JS ok: abilita navigazione a tab singola nascondendo le altre sezioni
        document.body.classList.remove('show-all');
        const dashboardToggleButtons = document.querySelectorAll('.dashboard-toggle-btn');
        dashboardToggleButtons.forEach(btn => {
            const targetId = btn.dataset.target;
            if (!targetId) {
                return;
            }
            const target = document.getElementById(targetId);
            if (!target) {
                return;
            }
            const label = btn.querySelector('.toggle-label');
            const showText = btn.dataset.showText || (label ? label.textContent.trim() : '');
            const hideText = btn.dataset.hideText || showText;
            const toggle = () => {
                const hidden = target.classList.toggle('d-none');
                const visible = !hidden;
                btn.classList.toggle('active', visible);
                btn.setAttribute('aria-pressed', String(visible));
                if (label) {
                    label.textContent = visible ? hideText : showText;
                }
            };
            btn.addEventListener('click', toggle);
            if (btn.dataset.autoOpen === 'true') {
                toggle();
            }
        });
        const providerSelect = document.getElementById('mail-provider');
        const hostField = document.getElementById('smtp_host');
        const portField = document.getElementById('smtp_port');
        const encryptionField = document.getElementById('smtp_encryption');
        const providerNotes = document.createElement('div');
        providerNotes.className = 'mt-2 small text-muted';
        providerSelect.parentNode.appendChild(providerNotes);

        providerSelect.addEventListener('change', function () {
            const option = providerSelect.selectedOptions[0];
            if (!option || option.value === 'custom') {
                providerNotes.textContent = 'Provider personalizzato: modifica host, porta e cifratura manualmente.';
                return;
            }
            hostField.value = option.getAttribute('data-host') ?? hostField.value;
            portField.value = option.getAttribute('data-port') ?? portField.value;
            encryptionField.value = option.getAttribute('data-encryption') ?? encryptionField.value;
            providerNotes.innerHTML = 'Guida rapida: <a href="' + option.getAttribute('data-docs') + '" target="_blank" rel="noopener">come creare una password per app.</a>';
        });

        // Gestione dinamica info provider AI
        const aiProviderSelect = document.getElementById('aiProviderSelect');
        const aiProviderInfo = document.getElementById('aiProviderInfo');

        const aiProviderData = {
            'gemini': {
                name: 'Gemini AI',
                description: 'Google Gemini offre modelli AI avanzati con un piano gratuito generoso (fino a 50.000 token al mese).',
                keyPage: 'https://aistudio.google.com/app/apikey',
                keyPageLabel: 'Genera la tua API key',
                guideUrl: 'https://ai.google.dev/gemini-api/docs/api-key',
                guideLabel: 'Guida ufficiale (inglese)',
                steps: [
                    'Accedi a <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">AI Studio</a>',
                    'Clicca su "Get API key" e poi "Create API key"',
                    'Copia la chiave (inizia con "AIza") e incollala qui sopra'
                ]
            },
            'openai': {
                name: 'OpenAI',
                description: 'OpenAI offre i modelli GPT-4o e GPT-3.5. Nuovi account ricevono $5 di credito gratuito per i primi 3 mesi.',
                keyPage: 'https://platform.openai.com/api-keys',
                keyPageLabel: 'Gestisci le tue API keys',
                guideUrl: 'https://www.punto-informatico.it/come-ottenere-e-usare-la-chiave-api-openai-di-gpt-4o-guida-passo-passo/',
                guideLabel: 'Guida in italiano',
                steps: [
                    'Accedi a <a href="https://platform.openai.com/signup" target="_blank" rel="noopener">OpenAI Platform</a>',
                    'Vai su "API keys" nel menu laterale',
                    'Clicca su "Create new secret key"',
                    'Copia la chiave (inizia con "sk-") immediatamente (non sarà più visibile)'
                ]
            },
            'claude': {
                name: 'Claude AI',
                description: 'Anthropic Claude offre modelli avanzati (Sonnet 4, Opus 4). Piano gratuito fino a 50.000 token/mese.',
                keyPage: 'https://console.anthropic.com/settings/keys',
                keyPageLabel: 'Console API keys',
                guideUrl: 'https://support.claude.com/en/articles/8114521-how-can-i-access-the-claude-api',
                guideLabel: 'Guida ufficiale (inglese)',
                steps: [
                    'Registrati su <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Anthropic Console</a>',
                    'Verifica la tua email',
                    'Vai su "API Keys" e clicca "Create Key"',
                    'Copia la chiave (inizia con "sk-ant-") subito (non sarà più visibile)'
                ]
            },
            'openrouter': {
                name: 'OpenRouter',
                description: 'OpenRouter offre accesso unificato a centinaia di modelli AI attraverso una singola API.',
                keyPage: 'https://openrouter.ai/settings/keys',
                keyPageLabel: 'Gestisci le tue keys',
                guideUrl: 'https://openrouter.ai/docs/quickstart',
                guideLabel: 'Guida rapida (inglese)',
                steps: [
                    'Crea un account su <a href="https://openrouter.ai/" target="_blank" rel="noopener">OpenRouter</a>',
                    'Vai su "Keys" nel menu',
                    'Clicca "Create Key" e assegna un nome',
                    'Imposta i limiti di spesa',
                    'Copia la chiave (inizia con "sk-or-") e salvala in modo sicuro'
                ]
            }
        };

        function updateAIProviderInfo() {
            const selectedProvider = aiProviderSelect.value;

            if (!selectedProvider || !aiProviderData[selectedProvider]) {
                aiProviderInfo.style.display = 'none';
                return;
            }

            const data = aiProviderData[selectedProvider];
            let stepsHtml = '<ol class="mb-0 small">';
            data.steps.forEach(step => {
                stepsHtml += '<li>' + step + '</li>';
            });
            stepsHtml += '</ol>';

            aiProviderInfo.innerHTML = `
                <h6 class="mb-2"><i class="bi bi-stars"></i> ${data.name}</h6>
                <p class="mb-2 small">${data.description}</p>
                <h6 class="mb-1 small">Come ottenere la tua API key:</h6>
                ${stepsHtml}
                <div class="mt-2">
                    <a href="${data.keyPage}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary me-2">
                        <i class="bi bi-key"></i> ${data.keyPageLabel}
                    </a>
                    <a href="${data.guideUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-book"></i> ${data.guideLabel}
                    </a>
                </div>
            `;
            aiProviderInfo.style.display = 'block';
        }

        if (aiProviderSelect) {
            aiProviderSelect.addEventListener('change', updateAIProviderInfo);
            // Mostra info se c'è già un provider selezionato
            if (aiProviderSelect.value) {
                updateAIProviderInfo();
            }
        }

        } catch (err) {
            console.error('Errore JS integrazioni:', err);
            // fallback: mostra tutte le sezioni se lo script fallisce
            document.querySelectorAll('.integration-section').forEach(sec => sec.classList.add('active'));
            document.querySelectorAll('.integration-tab').forEach(tab => tab.classList.add('active'));
        }
    });
</script>
</body>
</html>
