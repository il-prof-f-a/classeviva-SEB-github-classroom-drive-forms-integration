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

        // Usa l'API per recuperare tutti i courseWork del corso
        $service = new Google\Service\Classroom($classroomAPI->getClient());

        $assignments = [];
        $pageToken = null;

        do {
            $response = $service->courses_courseWork->listCoursesCourseWork($courseId, [
                'pageToken' => $pageToken,
                'orderBy' => 'updateTime desc', // Più recenti prima
                'courseWorkStates' => ['PUBLISHED', 'DRAFT']
            ]);

            $courseWorkList = $response->getCourseWork() ?? [];
            foreach ($courseWorkList as $courseWork) {
                $workType = $courseWork->getWorkType();
                if (in_array($workType, ['ASSIGNMENT', 'QUIZ_ASSIGNMENT'], true)) {
                    $dueDateTime = null;
                    if ($courseWork->getDueDate()) {
                        $dueDateTime = sprintf(
                            '%04d-%02d-%02d',
                            $courseWork->getDueDate()->getYear(),
                            $courseWork->getDueDate()->getMonth(),
                            $courseWork->getDueDate()->getDay()
                        );

                        if ($courseWork->getDueTime()) {
                            $dueDateTime .= sprintf(
                                ' %02d:%02d',
                                $courseWork->getDueTime()->getHours() ?? 23,
                                $courseWork->getDueTime()->getMinutes() ?? 59
                            );
                        }
                    }

                    $assignments[] = [
                        'id' => $courseWork->getId(),
                        'title' => $courseWork->getTitle(),
                        'description' => $courseWork->getDescription() ?? '',
                        'state' => $courseWork->getState(),
                        'max_points' => $courseWork->getMaxPoints() ?? 100,
                        'due_date' => $dueDateTime,
                        'topic_id' => $courseWork->getTopicId() ?? null,
                        'link' => $courseWork->getAlternateLink(),
                        'creation_time' => $courseWork->getCreationTime(),
                        'update_time' => $courseWork->getUpdateTime()
                    ];
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        $responsePayload = [
            'success' => true,
            'assignments' => $assignments
        ];

    } catch (Exception $e) {
        $responsePayload = [
            'success' => false,
            'error' => $e->getMessage()
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
echo json_encode($responsePayload);
