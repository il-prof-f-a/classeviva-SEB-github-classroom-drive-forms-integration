<?php
/**
 * AJAX: Carica studenti VERI di una classe dall'API ClasseViva
 */

session_start();

ob_start();
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');

use App\Integration\ClasseVivaAPI;

try {
    $classId = $_GET['class_id'] ?? null;

    if (!$classId) {
        throw new Exception("class_id richiesto");
    }

    set_time_limit(60);

    $cv = new ClasseVivaAPI($config);
    $students = $cv->getClassStudents($classId);

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
            'generated_email' => $generatedEmail
        ];
    }

    // Salva in sessione per riutilizzo
    $_SESSION['cached_class_students'][$classId] = $result;

    echo json_encode([
        'success' => true,
        'students' => $result,
        'count' => count($result)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
