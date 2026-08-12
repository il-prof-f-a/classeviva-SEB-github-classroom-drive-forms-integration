<?php

require_once __DIR__ . '/../bootstrap.php';

use Google\Client;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $googleConfig = $config['google'] ?? [];

    $client = new Client();
    $client->setApplicationName('UDA System Login');
    $client->setAuthConfig(ROOT_PATH . '/' . ($googleConfig['credentials_file'] ?? 'config/google_credentials.json'));

    // Scope minimi per login; in futuro potrai estenderli o riusare GOOGLE_SCOPES
    $client->setScopes(['openid', 'email', 'profile']);

    $loginRedirectUri = $googleConfig['login_redirect_uri'] ?? env('GOOGLE_LOGIN_REDIRECT_URI');
    if (!$loginRedirectUri) {
        $loginRedirectUri = app_url('public/oauth_callback.php');
    }
    $client->setRedirectUri($loginRedirectUri);

    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    $oauthState = bin2hex(random_bytes(32));
    $_SESSION['google_login_oauth_state'] = $oauthState;
    $client->setState($oauthState);

    $authUrl = $client->createAuthUrl();

    header('Location: ' . $authUrl);
    exit;

} catch (Exception $e) {
    $msg = urlencode('Errore configurazione login Google: ' . $e->getMessage());
    header('Location: login.php?error=' . $msg);
    exit;
}
