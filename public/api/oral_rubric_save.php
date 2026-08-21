<?php

/**
 * API Endpoint: Salva una valutazione orale +/-
 *
 * Identico a salva_votazione.php di piuomeno
 */

$config = require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Controllers\OralRubricController;
use App\Integration\ClasseVivaAPI;
use App\Core\Security\PublicError;

header('Content-Type: application/json');

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

// Verifica parametri
if (!isset($_POST['id_descrittore']) || !isset($_POST['id_studente']) || !isset($_POST['voto']) || !isset($_POST['id_materia'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    $clvApi = new ClasseVivaAPI($config);

    $controller = new OralRubricController($dbAdapter, $clvApi);

    $idDescrittore = (int)$_POST['id_descrittore'];
    $idStudente = (int)$_POST['id_studente'];
    $idMateria = (int)$_POST['id_materia'];
    $voto = (int)$_POST['voto'];
    $username = $_SESSION['user_id'];
    $commento = $_POST['commento'] ?? '';

    $result = $controller->saveEvaluation($idDescrittore, $idStudente, $idMateria, $voto, $username, $commento);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(PublicError::json($e, 'oral rubric save'));
}
