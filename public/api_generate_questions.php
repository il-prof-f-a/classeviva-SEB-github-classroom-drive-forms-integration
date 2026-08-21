<?php
/**
 * Endpoint preliminare per generazione domande via AI.
 * Delega la logica a App\Integration\AIQuestionService (stub con fallback).
 */

error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';

use App\Integration\AIQuestionService;
use App\Core\Security\Csrf;
use App\Core\Security\MaterialAccessService;

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Utente non autenticato']);
        exit;
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'Metodo non consentito']);
        exit;
    }
    Csrf::assertValid($_SESSION, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $config = require __DIR__ . '/../bootstrap.php';
    $dbAdapter = \App\Core\Database\DatabaseFactory::createWithInitialization($config, true);
    $integrationManager = new \App\Core\UserIntegrationManager($dbAdapter, (string)$_SESSION['user_id']);
    $aiConfig = $integrationManager->getConfig('ai') ?? [];
    $service = new AIQuestionService($config, $aiConfig);

    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'Payload troppo grande']);
        exit;
    }
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Payload JSON non valido']);
        exit;
    }

    $requestAction = (string)($data['action'] ?? 'generate');
    if (!in_array($requestAction, ['list_models', 'preview_prompt'], true)) {
        $limiter = new \App\Core\Security\RateLimiter(ROOT_PATH . '/storage/rate_limits');
        if (!$limiter->allow('ai_generate:' . (string)$_SESSION['user_id'], 20, 600)) {
            http_response_code(429);
            header('Retry-After: 600');
            echo json_encode(['error' => 'Troppe richieste. Riprova tra alcuni minuti.']);
            exit;
        }
    }

    // List models
    if (($data['action'] ?? '') === 'list_models') {
        $provider = $data['provider'] ?? null;
        if (!$provider) {
            http_response_code(400);
            echo json_encode(['error' => 'provider obbligatorio']);
            exit;
        }
        $models = $service->listModelsWithMeta($provider);
        echo json_encode($models);
        exit;
    }

    if (($data['action'] ?? '') === 'preview_prompt') {
        $preview = $service->previewPrompt($data);
        echo json_encode($preview);
        exit;
    }

    if (isset($data['material_ids'])) {
        if (!is_array($data['material_ids']) || count($data['material_ids']) > 20) {
            throw new RuntimeException('Elenco materiali non valido');
        }
        $udaId = trim((string)($data['uda_id'] ?? ''));
        if ($udaId === '') throw new RuntimeException('UDA obbligatoria per gli allegati');
        $materialService = new MaterialAccessService($dbAdapter);
        $data['attachments'] = $materialService->resolveForUda(
            (string)$_SESSION['user_id'], $udaId, $data['material_ids']
        );
    } else {
        $data['attachments'] = [];
    }

    // Generazione stub
    $response = $service->generateDraft($data);
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => \App\Core\Security\PublicError::message($e, 'api_generate_questions')]);
}
