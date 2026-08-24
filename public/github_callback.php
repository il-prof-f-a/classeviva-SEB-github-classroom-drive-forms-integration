<?php
/**
 * GitHub OAuth Callback
 * Gestisce il callback dopo l'autorizzazione OAuth di GitHub
 */

require_once '../bootstrap.php';

use App\Integration\GitHubIntegration;
use App\Core\Security\PublicError;
use App\Core\Security\LocalReturnUrl;

// Verifica state per sicurezza CSRF
$state = $_GET['state'] ?? null;
$sessionState = $_SESSION['github_oauth_state'] ?? null;

if (!$state || $state !== $sessionState) {
    die("Errore: State non valido. Possibile attacco CSRF.");
}

// Ottieni il code
$code = $_GET['code'] ?? null;

if (!$code) {
    $error = $_GET['error'] ?? 'unknown';
    $errorDescription = $_GET['error_description'] ?? 'Autenticazione annullata o fallita';
    error_log('GitHub OAuth rifiutato: ' . (string)$error . ' - ' . (string)$errorDescription);
    http_response_code(400);
    die('Autenticazione GitHub annullata o non riuscita.');
}

try {
    // Flusso studente: se le credenziali OAuth del docente non sono nel config
    // (utente non loggato), le recupera dalla sessione impostata da accept_assignment.php.
    if (empty($config['github']['client_id'] ?? null) && !empty($_SESSION['github_student_client_id'] ?? null)) {
        $config['github']['client_id'] = (string)($_SESSION['github_student_client_id'] ?? '');
        $config['github']['client_secret'] = (string)($_SESSION['github_student_client_secret'] ?? '');
    }

    // Inizializza GitHub Integration
    $github = new GitHubIntegration($config);

    // Scambia code per token
    $accessToken = $github->exchangeCodeForToken($code);

    // Salva token in sessione
    $github->saveTokenToSession($accessToken);

    // Ottieni informazioni utente per conferma
    $github->setAccessToken($accessToken);
    $user = $github->getUser();

    $_SESSION['github_user'] = [
        'login' => $user['login'],
        'name' => $user['name'],
        'avatar_url' => $user['avatar_url'],
        'email' => $user['email']
    ];

    // Redirect alla pagina di origine (se nota) per miglior UX
    $returnTo = $_SESSION['github_oauth_return_to'] ?? null;
    unset($_SESSION['github_oauth_return_to']);

    $allowedScripts = array_map('basename', glob(__DIR__ . '/*.php') ?: []);
    $safeReturnTo = LocalReturnUrl::normalize(
        is_string($returnTo) ? $returnTo : null,
        $allowedScripts,
        (string)($_SERVER['HTTP_HOST'] ?? '')
    );

    if ($safeReturnTo) {
        header('Location: ' . $safeReturnTo);
    } else {
        header('Location: github_classroom_mapping.php?auth=success');
    }
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die(htmlspecialchars(PublicError::message($e, 'github oauth callback')));
}
