<?php
/**
 * AJAX (import) - Carica corsi Google Classroom con recupero token per-utente
 * Endpoint dedicato alle nuove UI di import per non toccare i flussi legacy.
 */

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

error_reporting(E_ALL);
ob_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleClassroomAPI;

$responsePayload = null;

try {
    $classroomAPI = new GoogleClassroomAPI($config);
    $courses = $classroomAPI->getCourses();

    $responsePayload = [
        'success' => true,
        'courses' => $courses
    ];
} catch (Exception $e) {
    $responsePayload = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

$bufferedOutput = trim(ob_get_clean() ?? '');
if ($bufferedOutput !== '') {
    $responsePayload = [
        'success' => false,
        'error' => 'Output inatteso dal server',
        'detail' => substr($bufferedOutput, 0, 500)
    ];
}

if ($responsePayload === null) {
    $responsePayload = [
        'success' => false,
        'error' => 'Errore sconosciuto'
    ];
}

header('Content-Type: application/json');
echo json_encode($responsePayload);
