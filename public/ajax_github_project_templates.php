<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Core\Security\PublicError;
use App\Integration\GitHubIntegration;

header('Content-Type: application/json; charset=utf-8');

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    Authorization::assertAuthenticated($_SESSION);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $json(['ok' => false, 'error' => 'Metodo non consentito.'], 405);
    }
    Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
    $org = trim((string)($_POST['org'] ?? ''));
    if ($org === '' || preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $org) !== 1) {
        $json(['ok' => false, 'error' => 'Organizzazione GitHub non valida.'], 422);
    }

    $github = new GitHubIntegration($config);
    $github->loadTokenFromSession();
    if (!$github->isAuthenticated()) {
        $json(['ok' => false, 'error' => 'Autorizza GitHub prima di caricare i Project template.'], 401);
    }

    $organizations = $github->listOrganizations();
    $allowed = false;
    foreach ((array)$organizations as $organization) {
        if (strcasecmp(trim((string)($organization['login'] ?? '')), $org) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        $json(['ok' => false, 'error' => 'Organizzazione GitHub non disponibile per l’utente autenticato.'], 403);
    }

    $json(['ok' => true, 'projects' => $github->listOrganizationProjectTemplates($org)]);
} catch (Throwable $exception) {
    $json([
        'ok' => false,
        'error' => PublicError::message($exception, 'github project templates'),
    ], 500);
}
