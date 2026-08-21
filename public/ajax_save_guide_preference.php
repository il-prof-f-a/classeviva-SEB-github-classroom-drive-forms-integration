<?php
/**
 * AJAX endpoint per salvare la preferenza "non mostrare più la guida"
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifica autenticazione
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

try {
    $userId = (string)$_SESSION['user_id'];
    $mostraGuida = isset($_POST['mostra_guida']) ? (int)$_POST['mostra_guida'] : 0;

    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    // Aggiorna il campo mostra_guida per l'utente corrente
    $dbAdapter->updateRow('UTENTI', 'id_utente', $userId, [
        'mostra_guida' => $mostraGuida
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Preferenza salvata correttamente'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_save_guide_preference')
    ]);
}
