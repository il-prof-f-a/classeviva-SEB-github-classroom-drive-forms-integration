<?php
/**
 * AJAX: Carica studenti con caching session
 * Usa session cache per evitare chiamate API ripetute
 */

session_start();

ob_start();
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');

use App\Core\ClasseVivaTokenGuard;
use App\Integration\ClasseVivaAPI;

try {
    $classId = $_GET['class_id'] ?? null;

    if (!$classId) {
        throw new Exception("class_id richiesto");
    }

    // CHECK CACHE IN SESSION FIRST
    if (isset($_SESSION['cached_class_students'][$classId])) {
        echo json_encode([
            'success' => true,
            'students' => $_SESSION['cached_class_students'][$classId],
            'count' => count($_SESSION['cached_class_students'][$classId]),
            'cached' => true
        ]);
        exit;
    }

    // CALL API WITH TIMEOUT
    set_time_limit(90);

    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile');
    }

    $cv = new ClasseVivaAPI($config);
    $students = $cv->getStudents($classId);

    $emailDomain = trim((string)($config['school']['email_domain'] ?? ''));
    if ($emailDomain === '') {
        throw new RuntimeException('Configura il dominio email studenti nelle integrazioni utente.');
    }
    $result = [];

    foreach ($students as $student) {
        $cognome = strtolower(trim($student['cognome'] ?? ''));
        $nome = strtolower(trim($student['nome'] ?? ''));

        // Normalizza per email
        $cognomeNorm = iconv('UTF-8', 'ASCII//TRANSLIT', $cognome);
        $nomeNorm = iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
        $cognomeNorm = preg_replace('/[^a-z]/', '', $cognomeNorm);
        $nomeNorm = preg_replace('/[^a-z]/', '', $nomeNorm);

        $generatedEmail = "$cognomeNorm.$nomeNorm@$emailDomain";

        $result[] = [
            'id' => $student['id'],
            'nome' => $student['nome'] ?? '',
            'cognome' => $student['cognome'] ?? '',
            'nome_completo' => ($student['cognome'] ?? '') . ' ' . ($student['nome'] ?? ''),
            'email_part' => "$cognomeNorm.$nomeNorm",
            'generated_email' => $generatedEmail,
            'display_name' => ($student['cognome'] ?? '') . ' ' . ($student['nome'] ?? '')
        ];
    }

    // SAVE TO SESSION CACHE
    $_SESSION['cached_class_students'][$classId] = $result;

    echo json_encode([
        'success' => true,
        'students' => $result,
        'count' => count($result),
        'cached' => false
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_load_students_with_cache'),
        'trace' => $e->getTraceAsString()
    ]);
}
