<?php
/**
 * AJAX - Carica assignments (compiti) da un corso Classroom
 */

error_reporting(E_ALL);
ob_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleClassroomAPI;

$courseId = $_GET['course_id'] ?? null;
$responsePayload = null;

if (!$courseId) {
    $responsePayload = [
        'success' => false,
        'error' => 'Course ID mancante'
    ];
} else {
    try {
        $classroomAPI = new GoogleClassroomAPI($config);
        $responsePayload = [
            'success' => true,
            'assignments' => $classroomAPI->getCourseAssignments((string)$courseId)
        ];
    } catch (Throwable $e) {
        $responsePayload = [
            'success' => false,
            'error' => \App\Core\Security\PublicError::message($e, 'ajax_get_classroom_assignments')
        ];
    }
}

$bufferedOutput = trim(ob_get_clean() ?? '');
if ($bufferedOutput !== '') {
    $responsePayload = [
        'success' => false,
        'error' => 'Output inatteso dal server',
        'detail' => substr($bufferedOutput, 0, 500)
    ];
}

header('Content-Type: application/json');
echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
