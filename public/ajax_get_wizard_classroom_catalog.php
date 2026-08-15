<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Integration\GoogleClassroomAPI;

header('Content-Type: application/json; charset=utf-8');

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $classIds = array_values(array_filter(array_map('strval', (array)($_GET['class_ids'] ?? []))));
    $subjectIds = array_values(array_map('strval', (array)($_GET['subject_ids'] ?? [])));
    $requestedCourseId = trim((string)($_GET['course_id'] ?? ''));

    if ($classIds === []) {
        $json([
            'success' => false,
            'error_code' => 'mapping_required',
            'error' => 'Seleziona prima almeno una classe nello step Classi.'
        ], 422);
    }

    $db = DatabaseFactory::createWithInitialization($config, true);
    $mappings = (new ProviderNeutralMappingService(
        $db,
        (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'))
    ))->listGoogleClassroomMappings();
    $allowed = [];
    foreach ($mappings as $mapping) {
        $classId = (string)($mapping['id_classe_cv'] ?? $mapping['classeviva_class_id'] ?? '');
        $subjectId = (string)($mapping['id_materia_cv'] ?? $mapping['classeviva_subject_id'] ?? '');
        $courseId = trim((string)($mapping['id_corso_gc'] ?? $mapping['google_course_id'] ?? ''));
        if ($courseId === '' || !in_array($classId, $classIds, true)) {
            continue;
        }
        if ($subjectIds !== [] && !in_array($subjectId, $subjectIds, true)) {
            continue;
        }
        $allowed[$courseId] = [
            'id' => $courseId,
            'name' => trim((string)($mapping['nome_corso_gc'] ?? $mapping['google_course_name'] ?? 'Corso Classroom')),
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ];
    }

    if ($allowed === []) {
        $json([
            'success' => false,
            'error_code' => 'mapping_missing',
            'error' => 'Nessun corso Google Classroom associato alle classi e materie selezionate.'
        ], 404);
    }

    $courses = array_values($allowed);
    if ($requestedCourseId === '') {
        $json(['success' => true, 'courses' => $courses, 'assignments' => []]);
    }
    if (!isset($allowed[$requestedCourseId])) {
        $json([
            'success' => false,
            'error_code' => 'mapping_forbidden',
            'error' => 'Il corso Classroom richiesto non è associato alle classi selezionate.'
        ], 403);
    }

    $classroom = new GoogleClassroomAPI($config);
    $json([
        'success' => true,
        'courses' => $courses,
        'course_id' => $requestedCourseId,
        'assignments' => $classroom->getCourseAssignments($requestedCourseId)
    ]);
} catch (Throwable $e) {
    $json([
        'success' => false,
        'error_code' => 'classroom_catalog_unavailable',
        'error' => $e->getMessage()
    ], 500);
}
