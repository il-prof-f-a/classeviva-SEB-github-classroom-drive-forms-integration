<?php
/**
 * AJAX: Carica studenti dal database (MAPPATURA_STUDENTI)
 * Non usa ClasseViva API - solo database locale
 */

session_start();

ob_start();
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;

ob_clean();
header('Content-Type: application/json');

try {
    $classId = $_GET['class_id'] ?? null;

    if (!$classId) {
        throw new Exception("class_id richiesto");
    }

    // CARICA DA DATABASE - VELOCE
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    // Carica mappature studenti
    $allMappings = $dbAdapter->findAll('MAPPATURA_STUDENTI');

    $result = [];
    $processedIds = []; // Per evitare duplicati

    foreach ($allMappings as $mapping) {
        $studentId = $mapping['id_studente_cv'] ?? null;
        if (!$studentId || in_array($studentId, $processedIds)) {
            continue;
        }

        $emailGoogle = $mapping['email_google'] ?? '';
        $emailPart = '';
        $displayName = '';

        if ($emailGoogle) {
            // Estrai la parte prima della @ dall'email
            $emailParts = explode('@', strtolower($emailGoogle));
            $emailPart = $emailParts[0] ?? '';

            // Converti "cognome.nome" in "Cognome Nome" per display
            if ($emailPart) {
                $nameParts = explode('.', $emailPart);
                $displayName = implode(' ', array_map('ucfirst', $nameParts));
            }
        }

        // Se non c'è email, usa l'ID
        if (!$displayName) {
            $displayName = "ID: $studentId";
            $emailPart = strtolower($studentId);
        }

        $result[] = [
            'id' => $studentId,
            'nome' => '',
            'cognome' => '',
            'nome_completo' => $displayName,
            'email_part' => $emailPart,
            'generated_email' => $emailGoogle,
            'display_name' => $displayName
        ];

        $processedIds[] = $studentId;
    }

    // Ordina per display_name
    usort($result, function($a, $b) {
        return strcmp($a['display_name'], $b['display_name']);
    });

    echo json_encode([
        'success' => true,
        'students' => $result,
        'count' => count($result),
        'source' => 'database'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_load_students_from_db')
    ]);
}
