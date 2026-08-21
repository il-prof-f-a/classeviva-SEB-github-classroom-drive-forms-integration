<?php
/**
 * AJAX - Carica corsi Google Classroom
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleClassroomAPI;

header('Content-Type: application/json');

try {
    $classroomAPI = new GoogleClassroomAPI($config);
    $courses = $classroomAPI->getCourses();

    echo json_encode([
        'success' => true,
        'courses' => $courses
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_get_classroom_courses')
    ]);
}
