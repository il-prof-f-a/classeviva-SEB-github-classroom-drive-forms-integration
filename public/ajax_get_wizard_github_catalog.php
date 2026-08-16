<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Integration\GitHubAssignmentCatalog;
use App\Integration\GitHubIntegration;

header('Content-Type: application/json; charset=utf-8');

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $groupIds = array_values(array_filter(array_map('strval', (array)($_GET['id_gruppo'] ?? []))));
    $classIds = array_values(array_filter(array_map('strval', (array)($_GET['class_ids'] ?? []))));
    $subjectIds = array_values(array_map('strval', (array)($_GET['subject_ids'] ?? [])));
    $requestedClassroomId = trim((string)($_GET['classroom_id'] ?? ''));

    if ($groupIds === [] && $classIds === []) {
        $json([
            'success' => false,
            'error_code' => 'mapping_required',
            'error' => 'Seleziona prima almeno un gruppo o una classe.'
        ], 422);
    }

    $db = DatabaseFactory::createWithInitialization($config, true);
    $mappings = (new ProviderNeutralMappingService(
        $db,
        (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'))
    ))->listGithubClassroomMappings();
    $allowed = [];
    foreach ($mappings as $mapping) {
        $groupId = (string)($mapping['id_gruppo'] ?? '');
        $classId = (string)($mapping['id_classe_cv'] ?? '');
        $subjectId = (string)($mapping['id_materia_cv'] ?? '');
        $classroomId = trim((string)($mapping['github_classroom_id'] ?? ''));
        if ($classroomId === '') {
            continue;
        }
        if ($groupIds !== []) {
            if (!in_array($groupId, $groupIds, true)) {
                continue;
            }
        } else {
            if (!in_array($classId, $classIds, true)) {
                continue;
            }
            if ($subjectIds !== [] && !in_array($subjectId, $subjectIds, true)) {
                continue;
            }
        }
        $allowed[$classroomId] = [
            'id' => $classroomId,
            'name' => trim((string)($mapping['classroom_name'] ?? 'GitHub Classroom')),
            'group_id' => $groupId,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'org_name' => trim((string)($mapping['github_org_name'] ?? '')),
        ];
    }

    if ($allowed === []) {
        $json([
            'success' => false,
            'error_code' => 'mapping_missing',
            'error' => 'Nessuna GitHub Classroom associata alle classi e materie selezionate.'
        ], 404);
    }

    $classrooms = array_values($allowed);
    if ($requestedClassroomId === '') {
        $json(['success' => true, 'classrooms' => $classrooms, 'assignments' => []]);
    }
    if (!isset($allowed[$requestedClassroomId])) {
        $json([
            'success' => false,
            'error_code' => 'mapping_forbidden',
            'error' => 'La GitHub Classroom richiesta non è associata alle classi selezionate.'
        ], 403);
    }

    $github = new GitHubIntegration($config);
    if (!$github->loadTokenFromSession() || !$github->isAuthenticated()) {
        $json([
            'success' => false,
            'error_code' => 'github_auth_required',
            'error' => 'Autorizza GitHub Classroom nelle integrazioni prima di caricare gli assignment.'
        ], 401);
    }

    $assignments = [];
    for ($page = 1; $page <= 10; $page++) {
        $response = $github->listAssignments($requestedClassroomId, $page, 100);
        $items = $response['assignments'] ?? ($response['data'] ?? $response ?? []);
        if (!is_array($items) || $items === []) {
            break;
        }
        foreach ($items as $item) {
            if (is_array($item)) {
                $assignments[] = GitHubAssignmentCatalog::normalize($item, $requestedClassroomId);
            }
        }
        if (count($items) < 100) {
            break;
        }
    }

    $unique = [];
    foreach ($assignments as $assignment) {
        $key = (string)($assignment['id'] ?? $assignment['slug'] ?? '');
        if ($key !== '') {
            $unique[$key] = $assignment;
        }
    }

    $json([
        'success' => true,
        'classrooms' => $classrooms,
        'classroom_id' => $requestedClassroomId,
        'assignments' => array_values($unique)
    ]);
} catch (Throwable $e) {
    $json([
        'success' => false,
        'error_code' => 'github_catalog_unavailable',
        'error' => $e->getMessage()
    ], 500);
}
