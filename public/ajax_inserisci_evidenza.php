<?php
/**
 * AJAX Endpoint - Inserisci Evidenza PiùOMeno
 *
 * Inserisce una nuova evidenza (+/-) nella coda di registrazione
 * L'evidenza sarà modificabile per 2 ore, poi auto-registrata su ClasseViva
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
    $idUda = $_POST['id_uda'] ?? '';
    $idMateriaCV = $_POST['id_materia_cv'] ?? '';
    $idClasseCV = $_POST['id_classe_cv'] ?? '';
    $idStudenteInternal = trim((string)($_POST['id_studente'] ?? ''));
    $idStudenteCV = trim((string)($_POST['id_studente_cv'] ?? ''));
    $idIndicatore = $_POST['id_indicatore'] ?? '';
    $nomeIndicatore = $_POST['nome_indicatore'] ?? '';
    $valore = $_POST['valore'] ?? '';
    $commento = $_POST['commento'] ?? '';
    // Nome docente: priorità a configurazione profilo utente, altrimenti utente loggato
    $prof = null;
    if (!empty($config['user_profile']['prof_name'] ?? null)) {
        $prof = $config['user_profile']['prof_name'];
    } else {
        $prof = $_SESSION['username'] ?? 'unknown';
    }

    // Validazione
    if (empty($idUda) || (empty($idStudenteInternal) && empty($idStudenteCV)) || empty($idIndicatore) || empty($valore)) {
        throw new Exception('Parametri mancanti: id_uda, id_studente, id_indicatore, valore sono obbligatori');
    }

    if (!in_array($valore, ['+', '-'])) {
        throw new Exception('Valore deve essere + o -');
    }

    // Database
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    // Genera ID evidenza univoco
    $idEvidenza = 'EV_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);

    // Dati evidenza
    $datiEvidenza = [
        'id_evidenza' => $idEvidenza,
        'id_uda' => $idUda,
        'id_materia_cv' => $idMateriaCV,
        'id_classe_cv' => $idClasseCV,
        'id_indicatore' => $idIndicatore,
        'nome_indicatore' => $nomeIndicatore,
        'valore' => $valore,
        'data_inserimento' => date('Y-m-d H:i:s'),
        'registrato' => 0,  // In coda
        'data_registrazione' => null,
        'id_annotazione_cv' => null,
        'commento' => $commento,
        'prof' => $prof
    ];
    // L'ID interno viene passato così com'è; l'ID ClasseViva viene risolto dal gateway.
    if ($idStudenteInternal !== '') {
        $datiEvidenza['id_studente'] = $idStudenteInternal;
    } else {
        $datiEvidenza['id_studente_cv'] = $idStudenteCV;
    }

    // Inserisci nel database
    $success = $dbAdapter->insertRow('PLUSMINUS_QUEUE', $datiEvidenza);

    if (!$success) {
        throw new Exception('Errore durante inserimento nel database');
    }

    // Risposta successo
    echo json_encode([
        'success' => true,
        'message' => 'Evidenza inserita con successo',
        'data' => [
            'id_evidenza' => $idEvidenza,
            'valore' => $valore,
            'data_inserimento' => $datiEvidenza['data_inserimento'],
            'modificabile_fino' => date('Y-m-d H:i:s', strtotime($datiEvidenza['data_inserimento'] . ' +2 hours'))
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
