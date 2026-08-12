<?php
/**
 * AJAX Endpoint - Elimina Evidenza PiùOMeno
 *
 * Elimina un'evidenza dalla coda, ma SOLO se:
 * 1. È stata inserita da meno di 2 ore
 * 2. Non è ancora stata registrata (registrato=0)
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

    // Estrai parametro
    $idEvidenza = $_POST['id_evidenza'] ?? '';

    if (empty($idEvidenza)) {
        throw new Exception('Parametro id_evidenza mancante');
    }

    // Database
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    // Recupera evidenza
    $evidenza = $dbAdapter->findOne('PLUSMINUS_QUEUE', ['id_evidenza' => $idEvidenza]);

    if (!$evidenza) {
        throw new Exception('Evidenza non trovata');
    }

    // Verifica se è già stata registrata
    $registrato = (int)($evidenza['registrato'] ?? 0);
    if ($registrato === 1) {
        throw new Exception('Impossibile eliminare: evidenza già registrata su ClasseViva');
    }

    // Verifica se sono passate meno di 2 ore
    $dataInserimento = $evidenza['data_inserimento'] ?? '';
    if (empty($dataInserimento)) {
        throw new Exception('Data inserimento non valida');
    }

    $timestampInserimento = strtotime($dataInserimento);
    $timestampAdesso = time();
    $differenzaOre = ($timestampAdesso - $timestampInserimento) / 3600;

    if ($differenzaOre >= 2) {
        $tempoRimasto = round((2 - $differenzaOre) * 60);
        throw new Exception("Impossibile eliminare: sono trascorse più di 2 ore dall'inserimento. L'evidenza è in attesa di registrazione automatica.");
    }

    // Elimina evidenza
    $success = $dbAdapter->deleteRow('PLUSMINUS_QUEUE', ['id_evidenza' => $idEvidenza]);

    if (!$success) {
        throw new Exception('Errore durante eliminazione dal database');
    }

    // Calcola tempo rimanente per informazione
    $minutiRimasti = round((2 - $differenzaOre) * 60);

    // Risposta successo
    echo json_encode([
        'success' => true,
        'message' => 'Evidenza eliminata con successo',
        'data' => [
            'id_evidenza' => $idEvidenza,
            'era_modificabile_per' => "$minutiRimasti minuti ancora"
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
