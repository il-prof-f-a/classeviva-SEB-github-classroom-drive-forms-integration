<?php

/**
 * API Endpoint: Carica valutazioni orali per uno studente
 *
 * Identico a carica_votazioni.php di piuomeno
 */

$config = require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Controllers\OralRubricController;
use App\Integration\ClasseVivaAPI;

header('Content-Type: application/json');

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

// Verifica parametri
if (!isset($_POST['studente']) || !isset($_POST['materia'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

try {
    // Usa bootstrap standard, così $config include le integrazioni per-utente
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile');
    }

    $clvApi = new ClasseVivaAPI($config);

    $controller = new OralRubricController($dbAdapter, $clvApi);

    $idStudente = (int)$_POST['studente'];
    $idMateria = (int)$_POST['materia'];
    $visualizzaVotiVecchi = isset($_POST['visualizzaVotiVecchi']) && filter_var($_POST['visualizzaVotiVecchi'], FILTER_VALIDATE_BOOLEAN);

    $result = $controller->loadEvaluations($idStudente, $idMateria, $visualizzaVotiVecchi);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Errore caricamento valutazioni: ' . $e->getMessage()
    ]);
}
