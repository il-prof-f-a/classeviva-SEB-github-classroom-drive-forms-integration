<?php
/**
 * Gestione Autenticazione Google OAuth
 * Permette di ottenere/rinnovare il token di accesso per le API Google
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use Google\Client;
use App\Core\Database\DatabaseFactory;
use App\Core\UserIntegrationManager;

$error_message = null;
$show_retry_link = false;
$success_message = null;
$token_info = null;
$googleRedirectUri = $config['google']['redirect_uri'] ?? env('GOOGLE_REDIRECT_URI');
if (!$googleRedirectUri) {
    $googleRedirectUri = app_url('public/google_auth.php');
}
// Config picker client
$pickerClientId = $config['google']['oauth_client_id'] ?? '';

// Percorsi file credenziali (globali, app-level)
$credentialsPath = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');

// Verifica esistenza credenziali
if (!file_exists($credentialsPath)) {
    $error_message = "File credenziali Google non trovato: $credentialsPath";
}

// Gestione revoca token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke') {
    try {
        // Revoca token per l'utente corrente (tabella INTEGRAZIONI_UTENTE)
        if (!empty($_SESSION['user_id'])) {
            $db = DatabaseFactory::createWithInitialization($config, true);
            $uim = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
            $googleCfg = $uim->getConfig('google');
            if (!empty($googleCfg['token'])) {
                unset($googleCfg['token']);
                $uim->saveConfig('google', $googleCfg, true);
            }
        }

        $success_message = "Token revocato con successo per l'utente corrente. Ora puoi autorizzare di nuovo.";
    } catch (Exception $e) {
        $error_message = "Errore durante la revoca: " . $e->getMessage();
    }
}

// Gestione callback OAuth
if (isset($_GET['code'])) {
    try {
        $expectedState = $_SESSION['google_integration_oauth_state'] ?? '';
        $receivedState = $_GET['state'] ?? '';
        unset($_SESSION['google_integration_oauth_state']);

        if (!is_string($expectedState) || !is_string($receivedState) || $expectedState === '' || !hash_equals($expectedState, $receivedState)) {
            throw new RuntimeException('Richiesta OAuth non valida o scaduta. Riprova l’autorizzazione.');
        }

        $codeValue = $_GET['code'];

        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setRedirectUri($googleRedirectUri);

        // Scopes richiesti
        $client->addScope(\Google\Service\Drive::DRIVE);
        $client->addScope(\Google\Service\Drive::DRIVE_METADATA_READONLY);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSES_READONLY);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSEWORK_ME);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSEWORK_STUDENTS);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSEWORKMATERIALS);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_ROSTERS_READONLY);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_TOPICS);
        // Necessario per risolvere le email degli studenti nel roster Classroom
                $client->addScope(\Google\Service\Forms::FORMS_BODY);
        $client->addScope(\Google\Service\Forms::FORMS_RESPONSES_READONLY);
        $client->addScope(\Google\Service\Forms::DRIVE_FILE);

        // Ottieni token
        $token = $client->fetchAccessTokenWithAuthCode($codeValue);
        if (isset($token['error'])) {
            // Errore specifico
            $errorMsg = $token['error_description'] ?? $token['error'];

            // Se è "Bad Request", probabilmente il codice è già stato usato
            if (stripos($errorMsg, 'bad request') !== false || stripos($errorMsg, 'invalid_grant') !== false) {
                throw new Exception("Il codice di autorizzazione è scaduto o già utilizzato. Riprova l'autorizzazione.");
            }

            throw new Exception("Errore OAuth: " . $errorMsg);
        }

        // Salva token per l'utente corrente in INTEGRAZIONI_UTENTE (provider 'google')
        if (empty($_SESSION['user_id'])) {
            throw new Exception("Utente non autenticato. Effettua il login e riprova.");
        }

        $db = DatabaseFactory::createWithInitialization($config, true);
        $uim = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
        $googleCfg = $uim->getConfig('google');
        if (!is_array($googleCfg)) {
            $googleCfg = [];
        }
        $googleCfg['token'] = $token;
        $uim->saveConfig('google', $googleCfg, true);
        $success_message = "Token ottenuto e salvato con successo per l'utente corrente!";

        $returnTo = trim((string)($_SESSION['google_return_to'] ?? ''));
        unset($_SESSION['google_return_to']);
        if ($returnTo !== '') {
            $_SESSION['user_integrations_flash'] = [
                'success' => $success_message,
                'return_to' => $returnTo,
                'anchor' => 'google-section',
            ];
            header('Location: user_integrations.php#google-section');
            exit;
        }

        // Redirect per pulire URL
        header('Location: google_auth.php?success=1');
        exit;

    } catch (Exception $e) {
        $error_message = "Errore durante l'autenticazione: " . $e->getMessage();
        // Aggiungi link per riprovare
        $show_retry_link = true;
    }
}

// Verifica token esistente (per utente corrente, con fallback al token globale legacy)
$tokenExists = false;
$tokenValid = false;
$tokenExpiry = null;
$tokenScopes = [];

try {
    $userTokenData = null;

    if (!empty($_SESSION['user_id'])) {
        $db = DatabaseFactory::createWithInitialization($config, true);
        $uim = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
        $googleCfg = $uim->getConfig('google');
        if (!empty($googleCfg['token']) && is_array($googleCfg['token'])) {
            $userTokenData = $googleCfg['token'];
        }
    }

    if ($userTokenData !== null) {
        $tokenExists = true;

        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setAccessType('offline');
        $client->setAccessToken($userTokenData);

        $tokenValid = !$client->isAccessTokenExpired();

        if (isset($userTokenData['created']) && isset($userTokenData['expires_in'])) {
            $tokenExpiry = $userTokenData['created'] + $userTokenData['expires_in'];
        }

        $tokenScopes = $userTokenData['scope'] ?? '';

        $token_info = [
            'valid' => $tokenValid,
            'expiry' => $tokenExpiry,
            'scopes' => $tokenScopes,
            'created' => $userTokenData['created'] ?? null,
            'expires_in' => $userTokenData['expires_in'] ?? null,
        ];
    }
} catch (Exception $e) {
    $error_message = "Errore durante la verifica del token: " . $e->getMessage();
}

// Genera URL di autorizzazione
$authUrl = null;
if (!$error_message) {
    try {
        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setRedirectUri($googleRedirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // Scopes completi
        $client->addScope(\Google\Service\Drive::DRIVE);
        $client->addScope(\Google\Service\Drive::DRIVE_METADATA_READONLY);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSES_READONLY);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSEWORK_ME);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSEWORK_STUDENTS);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_COURSEWORKMATERIALS);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_ROSTERS_READONLY);
        $client->addScope(\Google\Service\Classroom::CLASSROOM_TOPICS);
        // Necessario per risolvere le email degli studenti nel roster Classroom
                $client->addScope(\Google\Service\Forms::FORMS_BODY);
        $client->addScope(\Google\Service\Forms::FORMS_RESPONSES_READONLY);
        $client->addScope(\Google\Service\Forms::DRIVE_FILE);

        $oauthState = bin2hex(random_bytes(32));
        $_SESSION['google_integration_oauth_state'] = $oauthState;
        $client->setState($oauthState);

        $authUrl = $client->createAuthUrl();

    } catch (Exception $e) {
        $error_message = "Errore durante la generazione URL: " . $e->getMessage();
    }
}

// Success message da redirect
if (isset($_GET['success'])) {
    $success_message = "Autenticazione completata con successo!";
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autenticazione Google - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .status-card {
            border-left: 5px solid #dee2e6;
            transition: all 0.3s;
        }
        .status-card.valid {
            border-left-color: #198754;
            background-color: #d1e7dd;
        }
        .status-card.invalid {
            border-left-color: #dc3545;
            background-color: #f8d7da;
        }
        .scope-badge {
            font-size: 0.75rem;
            margin: 0.25rem;
        }
        .big-button {
            padding: 1.5rem;
            font-size: 1.25rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-google"></i> Autenticazione Google OAuth';
    ob_start();
    ?>
    <a class="nav-link" href="index.php">Dashboard</a>
    <a class="nav-link active" href="google_auth.php">Autenticazione Google</a>
    <a class="nav-link" href="system_status.php">Stato Sistema</a>
    <a class="btn btn-outline-light btn-sm" href="user_integrations.php#google-section">
        <i class="bi bi-arrow-left-circle"></i> Torna alle integrazioni
    </a>
    <?php
    $headerActions = ob_get_clean();
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 offset-md-2">
<?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <strong>Errore:</strong> <?= \App\Core\Security\OutputEncoder::html($error_message) ?>
                        <?php if ($show_retry_link): ?>
                            <a href="google_auth.php" class="alert-link">Click qui per riprovare</a>
                        <?php endif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>

                    <?php if (stripos($error_message, 'Bad Request') !== false || stripos($error_message, 'scaduto') !== false): ?>
                        <div class="alert alert-warning">
                            <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Cosa Significa Questo Errore?</h6>
                            <p class="mb-2">Il codice di autorizzazione può essere usato una sola volta e scade dopo pochi minuti.</p>
                            <p class="mb-0">
                                <strong>Soluzione:</strong>
                                <?php if ($authUrl): ?>
                                    <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-warning btn-sm">
                                        <i class="bi bi-arrow-clockwise"></i> Riprova Autorizzazione Ora
                                    </a>
                                <?php else: ?>
                                    Ricarica la pagina e riprova.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill"></i>
                        <strong>Successo!</strong> <?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stato Token -->
                <div class="card mb-4 status-card <?= $tokenExists && $tokenValid ? 'valid' : ($tokenExists ? 'invalid' : '') ?>">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <?php if ($tokenExists && $tokenValid): ?>
                                <i class="bi bi-check-circle-fill text-success"></i> Token Valido
                            <?php elseif ($tokenExists): ?>
                                <i class="bi bi-exclamation-triangle-fill text-warning"></i> Token Scaduto
                            <?php else: ?>
                                <i class="bi bi-x-circle-fill text-danger"></i> Token Non Presente
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($tokenExists): ?>
                            <dl class="row mb-0">
                                <dt class="col-sm-3">Stato:</dt>
                                <dd class="col-sm-9">
                                    <?php if ($tokenValid): ?>
                                        <span class="badge bg-success">Valido e Attivo</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Scaduto - Richiede Rinnovo</span>
                                    <?php endif; ?>
                                </dd>

                                <?php if ($tokenExpiry): ?>
                                    <dt class="col-sm-3">Scadenza:</dt>
                                    <dd class="col-sm-9">
                                        <?= date('d/m/Y H:i:s', $tokenExpiry) ?>
                                        <?php if ($tokenValid): ?>
                                            <small class="text-muted">(tra <?= round(($tokenExpiry - time()) / 3600, 1) ?> ore)</small>
                                        <?php endif; ?>
                                    </dd>
                                <?php endif; ?>

                                <?php if ($tokenScopes): ?>
                                    <dt class="col-sm-3">Scope:</dt>
                                    <dd class="col-sm-9">
                                        <?php
                                        $scopesList = is_array($tokenScopes) ? $tokenScopes : explode(' ', $tokenScopes);
                                        foreach ($scopesList as $scope):
                                            $scopeName = basename($scope);
                                        ?>
                                            <span class="badge bg-primary scope-badge"><?= htmlspecialchars($scopeName) ?></span>
                                        <?php endforeach; ?>
                                    </dd>
                                <?php endif; ?>
                            </dl>
                        <?php else: ?>
                            <p class="mb-0">
                                <i class="bi bi-info-circle"></i>
                                Nessun token di autenticazione presente. Clicca su "Autorizza Accesso" per iniziare.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Azioni -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear-fill"></i> Azioni</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$tokenExists || !$tokenValid): ?>
                            <!-- Autorizza/Rinnova -->
                            <div class="d-grid mb-3">
                                <?php if ($authUrl): ?>
                                    <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-primary big-button">
                                        <i class="bi bi-box-arrow-in-right"></i>
                                        <?= $tokenExists ? 'Rinnova Token' : 'Autorizza Accesso a Google' ?>
                                    </a>
                                    <small class="text-muted mt-2">
                                        Verrai reindirizzato a Google per autorizzare l'applicazione.
                                        Assicurati di accettare tutti i permessi richiesti.
                                    </small>
                                <?php else: ?>
                                    <button class="btn btn-secondary big-button" disabled>
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Impossibile Generare URL di Autorizzazione
                                    </button>
                                    <small class="text-danger mt-2">
                                        Verifica che il file google_credentials.json sia presente e valido.
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Token Valido - Mostra info -->
                            <div class="alert alert-success mb-3">
                                <i class="bi bi-check-circle-fill"></i>
                                <strong>Token attivo!</strong> Le API Google sono pronte per l'uso.
                            </div>

                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Serve un permesso nuovo?</strong> Se un'attività fallisce per
                                scope mancante (es. i template Forms richiedono l'accesso Drive
                                completo), riautorizza per rinnovare il consenso con gli scope correnti.
                            </div>
                            <?php if ($authUrl): ?>
                            <div class="d-grid mb-3">
                                <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-warning">
                                    <i class="bi bi-arrow-clockwise"></i> Riautorizza (aggiorna scope)
                                </a>
                            </div>
                            <?php endif; ?>

                            <div class="d-grid">
                                <a href="system_status.php" class="btn btn-outline-primary mb-2">
                                    <i class="bi bi-speedometer2"></i> Verifica Stato Sistema
                                </a>
                                <a href="test_google_courses.php" class="btn btn-outline-info mb-2">
                                    <i class="bi bi-google"></i> Test Google Classroom API
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($tokenExists): ?>
                            <hr class="my-4">

                            <!-- Revoca Token -->
                            <form method="POST" onsubmit="return confirm('Sei sicuro di voler revocare il token? Dovrai autorizzare di nuovo l\'applicazione.');">
                                <input type="hidden" name="action" value="revoke">
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-outline-danger">
                                        <i class="bi bi-x-circle"></i> Revoca Token
                                    </button>
                                </div>
                                <small class="text-muted mt-2 d-block">
                                    Rimuove il token salvato. Utile per risolvere problemi o cambiare account.
                                </small>
                            </form>
                            <hr class="my-4">
                            <div class="d-grid">
                                <button type="button" class="btn btn-outline-secondary" onclick="preAuthorizeDrivePicker()">
                                    <i class="bi bi-cloud-check"></i> Pre-autorizza Drive Picker (riduce popup nel wizard)
                                </button>
                                <small class="text-muted mt-2 d-block">
                                    Salva un token client-side (localStorage) con scope Drive readonly; il wizard UDA userà questo token evitando richieste ripetute finché valido.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Informazioni -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Informazioni</h5>
                    </div>
                    <div class="card-body">
                        <h6>Scope Richiesti:</h6>
                        <ul>
                            <li><strong>Google Drive</strong> - Per caricare e gestire materiali didattici</li>
                            <li><strong>Google Classroom - Courses</strong> - Per leggere e creare corsi</li>
                            <li><strong>Google Classroom - CourseWork Students</strong> - Per pubblicare compiti</li>
                            <li><strong>Google Classroom - CourseWork Materials</strong> - Per pubblicare materiali</li>
                            <li><strong>Google Classroom - Rosters</strong> - Per gestire studenti nei corsi</li>
                            <li><strong>Google Classroom - Topics</strong> - Per creare argomenti nei corsi</li>
                            <li><strong>Google Forms - Body</strong> - Per creare e modificare Google Forms</li>
                            <li><strong>Google Drive - File</strong> - Per gestire file Forms su Drive</li>
                        </ul>

                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Torna alla Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        const pickerClientId = "<?= htmlspecialchars($pickerClientId) ?>";
        const driveTokenCacheKey = 'uda_drive_token';
        let preAuthTokenClient = null;

        function cacheDriveToken(token, expiresInSec = 3600) {
            if (!token) return;
            const expiry = Date.now() + (expiresInSec * 1000);
            const data = { access_token: token, expiry };
            try { localStorage.setItem(driveTokenCacheKey, JSON.stringify(data)); } catch (e) {}
        }

        function preAuthorizeDrivePicker() {
            if (!pickerClientId) {
                alert('Config mancante: imposta web.client_id in google_credentials.json');
                return;
            }
            if (typeof google === 'undefined' || !google.accounts || !google.accounts.oauth2) {
                alert('Libreria Google Identity non caricata. Riprova.');
                return;
            }
            if (!preAuthTokenClient) {
                preAuthTokenClient = google.accounts.oauth2.initTokenClient({
                    client_id: pickerClientId,
                    scope: 'https://www.googleapis.com/auth/drive.file',
                    callback: (res) => {
                        if (res && res.access_token) {
                            cacheDriveToken(res.access_token, res.expires_in || 3600);
                            alert('Token Drive salvato: i popup nel wizard saranno ridotti finché il token resta valido.');
                        } else {
                            alert('Autorizzazione negata.');
                        }
                    }
                });
            }
            preAuthTokenClient.requestAccessToken({ prompt: 'consent' });
        }
    </script>
</body>
</html>
