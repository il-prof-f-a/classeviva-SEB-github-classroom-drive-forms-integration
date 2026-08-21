<?php
/**
 * AJAX Endpoint - Cancella Evidenza PiùOMeno
 *
 * Cancella un'evidenza (+/-) dalla coda (solo se non ancora registrata)
 */

use App\Core\Database\DatabaseFactory;

header('Content-Type: application/json');
error_reporting(0);

try {
    $config = require_once __DIR__ . '/../bootstrap.php';

    // Verifica metodo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito');
    }

    // Estrai parametri
    $idStudenteInternal = trim((string)($_POST['id_studente'] ?? ''));
    $idStudenteCV = trim((string)($_POST['id_studente_cv'] ?? ''));
    $idIndicatore = $_POST['id_indicatore'] ?? '';
    $valore = $_POST['valore'] ?? '';

    // Validazione
    if ((empty($idStudenteInternal) && empty($idStudenteCV)) || empty($idIndicatore) || empty($valore)) {
        throw new Exception('Parametri mancanti: id_studente, id_indicatore, valore');
    }

    if (!in_array($valore, ['+', '-'])) {
        throw new Exception('Valore deve essere + o -');
    }

    // Database
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    // Trova l'evidenza in coda (non registrata) che corrisponde
    $where = [
        'id_indicatore' => $idIndicatore,
        'valore' => $valore
    ];
    if ($idStudenteInternal !== '') {
        $where['id_studente'] = $idStudenteInternal;
    } else {
        $where['id_studente_cv'] = $idStudenteCV;
    }
    $tutteEvidenze = $dbAdapter->findWhere('PLUSMINUS_QUEUE', $where);

    if (empty($tutteEvidenze)) {
        throw new Exception('Evidenza non trovata in coda (nessun match studente/indicatore/valore)');
    }

    // Filtra solo quelle NON registrate (gestisce sia 0 che "0")
    $nonRegistrate = array_filter($tutteEvidenze, function($ev) {
        return empty($ev['registrato']) || $ev['registrato'] == 0 || $ev['registrato'] === '0';
    });

    if (empty($nonRegistrate)) {
        throw new Exception('Evidenza trovata ma già registrata (non cancellabile)');
    }

    // Prendi l'ultima (più recente) tra quelle non registrate
    $evidenzaDaCancellare = end($nonRegistrate);
    $idEvidenza = $evidenzaDaCancellare['id_evidenza'];

    // Cancella dal database
    $success = $dbAdapter->deleteRow('PLUSMINUS_QUEUE', $idEvidenza, 'id_evidenza');

    if (!$success) {
        throw new Exception('Errore durante cancellazione dal database');
    }

    // Risposta successo
    echo json_encode([
        'success' => true,
        'message' => 'Evidenza cancellata con successo',
        'data' => [
            'id_evidenza' => $idEvidenza,
            'valore' => $valore
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_cancella_evidenza')
    ]);
}
