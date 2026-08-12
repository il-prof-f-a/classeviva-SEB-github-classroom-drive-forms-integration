<?php
/**
 * Rigenera il token ClasseViva da popup rapido e torna alla pagina di origine.
 */

define('SKIP_CV_TOKEN_POPUP', true);
define('SKIP_CV_TOKEN_VALIDATION', true);
require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaSessionStore;
use App\Integration\ClasseVivaAPI;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

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

$flashKey = 'cv_quick_login_flash';
$returnToCandidate = $_POST['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$returnTo = $normalizeReturnUrl($returnToCandidate);
if ($returnTo === '') {
    $returnTo = 'user_integrations.php#classeviva-section';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION[$flashKey] = [
        'error' => 'Richiesta non valida.',
        'return_to' => $returnTo,
    ];
    header('Location: ' . $returnTo);
    exit;
}

try {
    $username = trim($_POST['token_username'] ?? '');
    $password = trim($_POST['token_password'] ?? '');

    if ($username === '' || $password === '') {
        throw new Exception('Inserisci username e password ClasseViva.');
    }

    $cvApi = new ClasseVivaAPI($config);
    $cvApi->authenticate($username, $password);
    unset($_POST['token_username'], $_POST['token_password']);
    $username = null;
    $password = null;

    if (!$cvApi->getPhpSessionToken()) {
        throw new Exception('Impossibile generare la sessione web ClasseViva dal token.');
    }
    $tokenPayload = $cvApi->getTokenPayload();
    if (!$tokenPayload) {
        throw new Exception('Impossibile ottenere il token ClasseViva.');
    }

    $classeVivaSessionStore = new ClasseVivaSessionStore($_SESSION);
    $classeVivaSessionStore->storeTokenPayload($tokenPayload);

    $_SESSION[$flashKey] = [
        'success' => 'Token ClasseViva rigenerato con successo.',
        'return_to' => $returnTo,
    ];
} catch (Exception $e) {
    unset($_POST['token_username'], $_POST['token_password']);
    $username = null;
    $password = null;
    $_SESSION[$flashKey] = [
        'error' => $e->getMessage(),
        'return_to' => $returnTo,
    ];
}

header('Location: ' . $returnTo);
exit;
