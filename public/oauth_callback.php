<?php

require_once __DIR__ . '/../bootstrap.php';

use Google\Client;
use App\Core\GoogleIdTokenVerifier;
use App\Core\Database\DatabaseFactory;
use App\Core\TestAccessPolicy;
use App\Core\Security\PublicError;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se manca il code, torna al login
if (empty($_GET['code'])) {
    header('Location: login.php?error=' . urlencode('Codice di autorizzazione mancante.'));
    exit;
}

try {
    $expectedState = $_SESSION['google_login_oauth_state'] ?? '';
    $receivedState = $_GET['state'] ?? '';
    unset($_SESSION['google_login_oauth_state']);

    if (!is_string($expectedState) || !is_string($receivedState) || $expectedState === '' || !hash_equals($expectedState, $receivedState)) {
        throw new RuntimeException('Richiesta OAuth non valida o scaduta. Riprova il login.');
    }

    $googleConfig = $config['google'] ?? [];
    $loginRedirectUri = $googleConfig['login_redirect_uri'] ?? env('GOOGLE_LOGIN_REDIRECT_URI');
    if (!$loginRedirectUri) {
        $loginRedirectUri = app_url('public/oauth_callback.php');
    }

    $client = new Client();
    $client->setApplicationName('UDA System Login');
    $client->setAuthConfig(ROOT_PATH . '/' . ($googleConfig['credentials_file'] ?? 'config/google_credentials.json'));
    $client->setRedirectUri($loginRedirectUri);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');
    $client->setScopes(['openid', 'email', 'profile']);

    // Scambia il code con il token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        throw new Exception('Errore nel token Google: ' . $token['error_description'] ?? $token['error']);
    }

    // Recupera informazioni utente dal token ID (OpenID Connect)
    $idToken = $token['id_token'] ?? null;
    if (!$idToken) {
        throw new Exception('ID token mancante nella risposta di Google.');
    }

    $payload = GoogleIdTokenVerifier::verify($client, $idToken);
    if (!$payload) {
        throw new Exception('Impossibile verificare ID token Google.');
    }

    $googleId = $payload['sub'] ?? null;
    $email = $payload['email'] ?? null;
    $nome = $payload['given_name'] ?? '';
    $cognome = $payload['family_name'] ?? '';

    if (!$email) {
        throw new Exception('Google non ha fornito un indirizzo email valido.');
    }

    // Durante la fase di test nessun account non autorizzato deve arrivare
    // alla persistenza locale o alla creazione della sessione applicativa.
    if (!TestAccessPolicy::isAllowed((string)$email, $config)) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../index.php?access=testing');
        exit;
    }

    // Crea/aggiorna record utente in tabella UTENTI
    $db = DatabaseFactory::createWithInitialization($config, true);

    // Cerca utente per email
    $userRows = $db->findWhere('UTENTI', ['email' => $email]);
    $now = date('Y-m-d H:i:s');
    $user = null;

    if (!empty($userRows)) {
        $user = $userRows[0];
        $user['google_id'] = $googleId;
        $user['nome'] = $nome;
        $user['cognome'] = $cognome;
        $user['last_login_at'] = $now;

        $db->updateRow('UTENTI', 'id_utente', $user['id_utente'], $user);
    } else {
        // Nuovo utente
        $idUtente = 'USER_' . uniqid();
        $user = [
            'id_utente' => $idUtente,
            'email' => $email,
            'nome' => $nome,
            'cognome' => $cognome,
            'ruolo' => 'docente',
            'google_id' => $googleId,
            'created_at' => $now,
            'last_login_at' => $now,
            'stato' => 'attivo',
        ];

        $db->insertRow('UTENTI', $user);
    }

    // Impedisce session fixation prima di associare l'identita autenticata.
    session_regenerate_id(true);

    // Salva info login in sessione
    $_SESSION['user_id'] = $user['id_utente'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = trim(($user['nome'] ?? '') . ' ' . ($user['cognome'] ?? ''));
    $_SESSION['user_role'] = $user['ruolo'] ?? 'docente';

    // In futuro qui potrai anche salvare il token di accesso/refresh in INTEGRAZIONI_UTENTE

    // Controlla se l'utente ha già dato il consenso privacy
    $privacyConsentGiven = $user['privacy_consent_given'] ?? 0;

    if (!$privacyConsentGiven) {
        // Reindirizza alla pagina di consenso privacy
        header('Location: privacy-consent.php');
        exit;
    }

    // Reindirizza alla home
    header('Location: index.php');
    exit;

} catch (Exception $e) {
    header('Location: login.php?error=' . urlencode(PublicError::message($e, 'google oauth callback')));
    exit;
}
