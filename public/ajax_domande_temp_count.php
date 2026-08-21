<?php
// Restituisce il numero di domande associate a un id_uda (usato per l'UDA temporanea nel wizard)
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;

header('Content-Type: application/json');

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'id mancante']);
    exit;
}

try {
    $db = DatabaseFactory::createWithInitialization($config, true);
    $domande = $db->findWhere('DOMANDE_INTERROGAZIONE', ['id_uda' => $id]);
    echo json_encode(['success' => true, 'count' => count($domande)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => \App\Core\Security\PublicError::message($e, 'ajax_domande_temp_count')]);
}
