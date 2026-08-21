<?php
/**
 * AJAX: Carica studenti di una classe
 */

ob_start();
error_reporting(0);

require_once __DIR__ . '/../bootstrap.php';

ob_clean();
header('Content-Type: application/json');

use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

try {
    $classId = $_GET['class_id'] ?? null;

    if (!$classId) {
        throw new Exception("class_id richiesto");
    }

    // Funzione per generare email studente
    function generateStudentEmail($nome, $cognome, $domain) {
        $cognome = strtolower(trim($cognome));
        $nome = strtolower(trim($nome));
        $cognome = iconv('UTF-8', 'ASCII//TRANSLIT', $cognome);
        $nome = iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
        $cognome = preg_replace('/[^a-z]/', '', $cognome);
        $nome = preg_replace('/[^a-z]/', '', $nome);
        return "$cognome.$nome@$domain";
    }

    $emailDomain = trim((string)($config['school']['email_domain'] ?? ''));
    if ($emailDomain === '') {
        throw new RuntimeException('Configura il dominio email studenti nelle integrazioni utente.');
    }
    $result = [];

    // STRATEGIA 1: Prova a caricare da database (più veloce)
    try {
        $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
        $mappings = $dbAdapter->findAll('MAPPATURA_STUDENTI');

        // Filtra per classe (se abbiamo questa info)
        foreach ($mappings as $mapping) {
            $studentId = $mapping['id_studente_cv'] ?? null;
            $nome = $mapping['nome_studente'] ?? '';
            $cognome = $mapping['cognome_studente'] ?? '';
            $email = $mapping['email_google'] ?? '';

            if ($studentId && $nome && $cognome) {
                $generated_email = generateStudentEmail($nome, $cognome, $emailDomain);

                $result[] = [
                    'id' => $studentId,
                    'nome' => $nome,
                    'cognome' => $cognome,
                    'nome_completo' => "$cognome $nome",
                    'generated_email' => $generated_email
                ];
            }
        }
    } catch (Exception $dbError) {
        // Ignora errori DB, prova con API
    }

    // STRATEGIA 2: Se DB vuoto, prova con API ClasseViva (con timeout breve)
    if (empty($result)) {
        set_time_limit(10); // Timeout breve
        $cv = new ClasseVivaAPI($config);
        $students = $cv->getClassStudents($classId);

        foreach ($students as $student) {
            $generated_email = generateStudentEmail(
                $student['nome'] ?? '',
                $student['cognome'] ?? '',
                $emailDomain
            );

            $result[] = [
                'id' => $student['id'],
                'nome' => $student['nome'] ?? '',
                'cognome' => $student['cognome'] ?? '',
                'nome_completo' => ($student['cognome'] ?? '') . ' ' . ($student['nome'] ?? ''),
                'generated_email' => $generated_email
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'students' => $result,
        'source' => empty($result) ? 'none' : (count($result) > 0 ? 'database' : 'api')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_load_class_students')
    ]);
}
