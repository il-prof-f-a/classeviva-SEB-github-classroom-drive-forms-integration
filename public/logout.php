<?php

define('SKIP_CV_TOKEN_POPUP', true);
define('SKIP_CV_TOKEN_VALIDATION', true);
require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaSessionStore;

// Distrugge la sessione e rimanda al login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$classeVivaSessionStore = new ClasseVivaSessionStore($_SESSION);
$classeVivaSessionStore->clearAuth();
$_SESSION = [];

$cookieParams = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => $cookieParams['secure'],
    'httponly' => $cookieParams['httponly'],
    'samesite' => $cookieParams['samesite'] ?? 'Lax',
]);
session_destroy();

header('Location: login.php');
exit;
