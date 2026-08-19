<?php
/**
 * AJAX (import) - Carica corsi Google Classroom con recupero token per-utente.
 * Endpoint dedicato alle nuove UI di import per non toccare i flussi legacy.
 * Se viene passato id_uda, i corsi associati ai gruppi didattici dell'UDA
 * vengono messi in cima, con il nome del gruppo tra parentesi.
 */

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

error_reporting(E_ALL);
ob_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleClassroomAPI;
use App\Core\Database\DatabaseFactory;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\UdaGroupRepository;

$responsePayload = null;

try {
    $classroomAPI = new GoogleClassroomAPI($config);
    $courses = $classroomAPI->getCourses();

    $udaId = trim((string)($_GET['id_uda'] ?? $_GET['id'] ?? ''));

    // Mappa corso -> gruppo didattico (per l'UDA indicata), in ordine di assegnazione.
    $associatedMap = [];
    $associatedOrder = 0;
    if ($udaId !== '') {
        $userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
        $db = DatabaseFactory::createWithInitialization($config, true);
        $groupRepo = new TeachingGroupRepository($db, $userId);
        $integrationRepo = new TeachingGroupIntegrationRepository($db, $userId);
        $assignments = (new UdaGroupRepository($db, $userId))->listForUda($udaId);

        usort($assignments, static function (array $a, array $b): int {
            return strcmp((string)($a['data_assegnazione'] ?? ''), (string)($b['data_assegnazione'] ?? ''));
        });

        foreach ($assignments as $assignment) {
            $groupId = trim((string)($assignment['id_gruppo'] ?? ''));
            if ($groupId === '') {
                continue;
            }
            $group = $groupRepo->findById($groupId);
            if ($group === null) {
                continue;
            }
            $groupName = trim((string)($group['nome_gruppo'] ?? ''));
            if ($groupName === '') {
                $groupName = trim(implode(' - ', array_filter([
                    (string)($group['nome_classe'] ?? ''),
                    (string)($group['nome_materia'] ?? ''),
                ])));
            }
            $groupName = $groupName !== '' ? $groupName : 'Gruppo didattico';

            foreach ($integrationRepo->listForGroup($groupId) as $row) {
                if (($row['provider'] ?? '') !== 'google_classroom') {
                    continue;
                }
                if (($row['stato'] ?? 'attivo') === 'disattivo') {
                    continue;
                }
                $courseId = trim((string)($row['external_context_id'] ?? ''));
                if ($courseId === '' || isset($associatedMap[$courseId])) {
                    continue;
                }
                $associatedMap[$courseId] = [
                    'group_name' => $groupName,
                    'order' => $associatedOrder++,
                ];
            }
        }
    }

    $associatedCourses = [];
    $otherCourses = [];
    foreach ($courses as $course) {
        $courseId = (string)($course['id'] ?? '');
        $assoc = $associatedMap[$courseId] ?? null;
        if ($assoc !== null) {
            $course['associated'] = true;
            $course['group_name'] = $assoc['group_name'];
            $course['order'] = (int)$assoc['order'];
            $course['label'] = trim((string)($course['name'] ?? '')) . ' (associata a ' . $assoc['group_name'] . ')';
            $associatedCourses[] = $course;
        } else {
            $course['associated'] = false;
            $course['group_name'] = '';
            $course['label'] = trim((string)($course['name'] ?? ''));
            $otherCourses[] = $course;
        }
    }

    usort($associatedCourses, static function (array $a, array $b): int {
        return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
    });
    usort($otherCourses, static function (array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $courses = array_merge($associatedCourses, $otherCourses);

    $responsePayload = [
        'success' => true,
        'courses' => $courses
    ];
} catch (Exception $e) {
    $responsePayload = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

$bufferedOutput = trim(ob_get_clean() ?? '');
if ($bufferedOutput !== '') {
    $responsePayload = [
        'success' => false,
        'error' => 'Output inatteso dal server',
        'detail' => substr($bufferedOutput, 0, 500)
    ];
}

if ($responsePayload === null) {
    $responsePayload = [
        'success' => false,
        'error' => 'Errore sconosciuto'
    ];
}

header('Content-Type: application/json');
echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
