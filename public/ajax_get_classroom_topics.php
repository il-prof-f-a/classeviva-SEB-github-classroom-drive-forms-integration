<?php
/**
 * AJAX - Carica topics (argomenti) da un corso Classroom specifico o da tutti i corsi
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleClassroomAPI;

header('Content-Type: application/json');

$courseId = $_GET['course_id'] ?? null;

try {
    $classroomAPI = new GoogleClassroomAPI($config);

    $allTopics = [];

    // Se è specificato un corso, carica solo i topics di quel corso
    if ($courseId && $courseId !== 'all') {
        try {
            $topics = $classroomAPI->getTopics($courseId);

            foreach ($topics as $topic) {
                $allTopics[] = $topic['name'];
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
    // Altrimenti carica topics da tutti i corsi
    else {
        $courses = $classroomAPI->getCourses();

        // Per ogni corso, carica i topics
        foreach ($courses as $course) {
            try {
                $topics = $classroomAPI->getTopics($course['id']);

                foreach ($topics as $topic) {
                    $allTopics[] = $topic['name'];
                }
            } catch (Exception $e) {
                // Ignora errori su singoli corsi
                continue;
            }
        }
    }

    // Rimuovi duplicati e ordina
    $allTopics = array_unique($allTopics);
    sort($allTopics);

    echo json_encode([
        'success' => true,
        'topics' => array_values($allTopics)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
