<?php
/**
 * Endpoint preliminare per generazione domande via AI.
 * Delega la logica a App\Integration\AIQuestionService (stub con fallback).
 */

error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';

use App\Integration\AIQuestionService;

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Utente non autenticato']);
        exit;
    }

    $config = require __DIR__ . '/../bootstrap.php';
    $dbAdapter = \App\Core\Database\DatabaseFactory::createWithInitialization($config, true);
    $integrationManager = new \App\Core\UserIntegrationManager($dbAdapter, (string)$_SESSION['user_id']);
    $aiConfig = $integrationManager->getConfig('ai') ?? [];
    $service = new AIQuestionService($config, $aiConfig);

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Payload JSON non valido']);
        exit;
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

    // Generazione stub
    $response = $service->generateDraft($data);
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
