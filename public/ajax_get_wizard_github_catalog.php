<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentService;

header('Content-Type: application/json; charset=utf-8');

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $groupIds = array_values(array_filter(array_map('strval', (array)($_GET['id_gruppo'] ?? []))));

    if ($groupIds === []) {
        $json([
            'success' => false,
            'error_code' => 'mapping_required',
            'error' => 'Seleziona prima almeno un gruppo.'
        ], 422);
    }

    // Catalogo dal DB interno: gli assignment sono righe TEST (piattaforma='github')
    // collegate ai gruppi didattici selezionati. Nessuna chiamata alle API Classroom.
    $db = DatabaseFactory::createWithInitialization($config, true);
    $userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));

    $assignments = [];
    foreach ($db->findWhere('TEST', ['piattaforma' => 'github']) as $test) {
        $groupId = trim((string)($test['id_gruppo'] ?? ''));
        if (!in_array($groupId, $groupIds, true)) {
            continue;
        }
        $cfg = json_decode((string)($test['github_config_json'] ?? '{}'), true);
        $cfg = is_array($cfg) ? $cfg : [];
        $org = trim((string)($cfg['org'] ?? ''));
        $slug = trim((string)($cfg['slug'] ?? ''));
        if ($slug === '') {
            $slug = trim((string)($test['id_test'] ?? ''));
        }
        $assignments[] = [
            'id' => (string)($test['id_test'] ?? ''),
            'title' => (string)($test['nome'] ?? 'Assignment GitHub'),
            'slug' => $slug,
            'description' => (string)($test['descrizione'] ?? ''),
            'state' => '',
            'student_url' => (string)($test['url_assignment_student'] ?? ''),
            'teacher_url' => (string)($test['url_assignment_teacher'] ?? ''),
            'url_assignment_student' => (string)($test['url_assignment_student'] ?? ''),
            'url_assignment_teacher' => (string)($test['url_assignment_teacher'] ?? ''),
            'github_classroom_id' => $org,
            'github_assignment_id' => $slug,
            'source' => 'github',
        ];
    }

    $json([
        'success' => true,
        'classrooms' => [],
        'assignments' => $assignments
    ]);
} catch (Throwable $e) {
    $json([
        'success' => false,
        'error_code' => 'github_catalog_unavailable',
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_get_wizard_github_catalog')
    ], 500);
}
