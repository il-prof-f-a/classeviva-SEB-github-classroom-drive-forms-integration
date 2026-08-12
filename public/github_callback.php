<?php
/**
 * GitHub OAuth Callback
 * Gestisce il callback dopo l'autorizzazione OAuth di GitHub
 */

require_once '../bootstrap.php';

use App\Integration\GitHubIntegration;

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
    die("Errore GitHub OAuth: {$error} - {$errorDescription}");
}

try {
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

    $safeReturnTo = null;
    if (is_string($returnTo) && $returnTo !== '') {
        // Evita open redirect: accetta solo URL relative (senza schema/host)
        if (strpos($returnTo, "\n") === false && strpos($returnTo, "\r") === false) {
            $parsed = parse_url($returnTo);
            $hasHost = !empty($parsed['host']) || !empty($parsed['scheme']);
            $path = $parsed['path'] ?? '';
            if (!$hasHost && $path !== '') {
                // Permetti solo redirect dentro /public/
                if (strpos($path, '/public/') !== false || str_starts_with($path, 'github_') || str_starts_with($path, 'uda_')) {
                    $safeReturnTo = $returnTo;
                }
            }
        }
    }

    if ($safeReturnTo) {
        header('Location: ' . $safeReturnTo);
    } else {
        header('Location: github_classroom_mapping.php?auth=success');
    }
    exit;

} catch (Exception $e) {
    die("Errore nell'autenticazione GitHub: " . htmlspecialchars($e->getMessage()));
}
