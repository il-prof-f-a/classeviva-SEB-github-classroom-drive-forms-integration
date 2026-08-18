<?php

ob_start();
error_reporting(0);
require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleFormsCatalog;
use App\Integration\GoogleClassroomAPI;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new RuntimeException('Sessione utente non autenticata.', 401);
    }

    $forms = GoogleFormsCatalog::listForUser($config);

    $courseId = trim((string)($_GET['course_id'] ?? ''));
    $publishedSet = [];
    if ($courseId !== '') {
        try {
            $publishedSet = array_fill_keys(
                (new GoogleClassroomAPI($config))->listFormIdsInCourse($courseId),
                true
            );
        } catch (Throwable $e) {
            // Classroom non disponibile (token/scope): il catalogo resta utilizzabile,
            // senza evidenziare i form già pubblicati.
            error_log('ajax_list_google_forms: impossibile elencare i form Classroom: ' . $e->getMessage());
            $publishedSet = [];
        }
    }
    foreach ($forms as &$form) {
        $form['published_in_classroom'] = isset($publishedSet[$form['id']]);
    }
    unset($form);

    echo json_encode([
        'success' => true,
        'forms' => $forms,
        'required_scopes' => GoogleFormsCatalog::requiredScopes(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $code = $e->getCode();
    $errorCode = match ($code) {
        401 => GoogleFormsCatalog::ERROR_TOKEN_MISSING,
        403 => GoogleFormsCatalog::ERROR_SCOPE_MISSING,
        default => 'google_forms_unavailable',
    };
    if ($code === 0 && preg_match('/insufficient|scope|permission|forbidden/i', $e->getMessage())) {
        $errorCode = GoogleFormsCatalog::ERROR_SCOPE_MISSING;
        $code = 403;
    }
    if ($code >= 400 && $code < 600) {
        http_response_code($code);
    } elseif (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error_code' => $errorCode,
        'error' => $e->getMessage(),
        'required_scopes' => GoogleFormsCatalog::requiredScopes(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

ob_end_flush();
