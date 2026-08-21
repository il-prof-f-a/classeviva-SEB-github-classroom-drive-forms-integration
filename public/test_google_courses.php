<?php

use App\Integration\GoogleClassroomAPI;
use Google\Service\Classroom;

$config = require_once __DIR__ . '/../bootstrap.php';

function count_course_students(Classroom $service, string $courseId): int
{
    $count = 0;
    $pageToken = null;

    do {
        $response = $service->courses_students->listCoursesStudents($courseId, [
            'pageToken' => $pageToken,
            'fields' => 'nextPageToken,students/userId'
        ]);

        $students = $response->getStudents();
        if (!empty($students)) {
            $count += count($students);
        }

        $pageToken = $response->getNextPageToken();
    } while (!empty($pageToken));

    return $count;
}

function course_to_array(\Google\Service\Classroom\Course $course): array
{
    $simple = $course->toSimpleObject();
    $json = json_encode($simple);
    if ($json === false) {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function format_course_value($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value) || is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '[unavailable]' : $encoded;
    }
    return (string)$value;
}

$courses = [];
$errorMessage = '';

try {
    $classroomAPI = new GoogleClassroomAPI($config);
    $service = new Classroom($classroomAPI->getClient());

    $pageToken = null;
    do {
        $response = $service->courses->listCourses([
            'teacherId' => 'me',
            'pageToken' => $pageToken
        ]);

        $courseItems = $response->getCourses() ?? [];
        foreach ($courseItems as $courseItem) {
            $courseId = $courseItem->id ?? null;
            if (!$courseId) {
                continue;
            }

            $courseErrors = [];

            try {
                $courseDetail = $service->courses->get($courseId);
            } catch (\Throwable $e) {
                $courseDetail = $courseItem;
                $courseErrors[] = 'Course details error: ' . $e->getMessage();
            }

            try {
                $studentCount = count_course_students($service, $courseId);
            } catch (\Throwable $e) {
                $studentCount = null;
                $courseErrors[] = 'Student count error: ' . $e->getMessage();
            }

            $courses[] = [
                'course' => $courseDetail,
                'student_count' => $studentCount,
                'errors' => $courseErrors
            ];
        }

        $pageToken = $response->getNextPageToken();
    } while (!empty($pageToken));
} catch (\Throwable $e) {
    $errorMessage = $e->getMessage();
}

$courseFields = [
    'id' => 'ID',
    'name' => 'Nome',
    'section' => 'Sezione',
    'descriptionHeading' => 'Descrizione heading',
    'description' => 'Descrizione',
    'room' => 'Aula',
    'ownerId' => 'Owner ID',
    'courseState' => 'Stato',
    'creationTime' => 'Creato il',
    'updateTime' => 'Aggiornato il',
    'enrollmentCode' => 'Codice iscrizione',
    'alternateLink' => 'Link',
    'calendarId' => 'Calendar ID',
    'courseGroupEmail' => 'Email corso',
    'teacherGroupEmail' => 'Email docenti',
    'guardiansEnabled' => 'Guardians enabled',
    'teacherFolder' => 'Teacher folder',
    'gradebookSettings' => 'Gradebook settings',
    'courseMaterialSets' => 'Course material sets'
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Test Google Classroom - Corsi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        pre {
            white-space: pre-wrap;
            word-break: break-word;
        }
        summary {
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <h1>Test Google Classroom - Corsi</h1>
    <p class="text-muted">
        Lista corsi con tutte le informazioni disponibili. Gli studenti non sono mostrati,
        viene indicato solo il numero.
    </p>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger">
            Errore: <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php else: ?>
        <div class="mb-3">
            <strong>Corsi trovati:</strong> <?= count($courses) ?>
        </div>

        <?php if (empty($courses)): ?>
            <div class="alert alert-warning">Nessun corso trovato.</div>
        <?php endif; ?>

        <?php foreach ($courses as $index => $item): ?>
            <?php $courseData = course_to_array($item['course']); ?>
            <?php $courseName = $courseData['name'] ?? 'Corso ' . ($index + 1); ?>
            <?php $courseId = $courseData['id'] ?? ''; ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= htmlspecialchars($courseName) ?></strong>
                        <?php if ($courseId !== ''): ?>
                            <span class="text-muted">ID: <?= htmlspecialchars($courseId) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="badge bg-primary">
                        Studenti: <?= $item['student_count'] === null ? 'N/D' : (int)$item['student_count'] ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!empty($item['errors'])): ?>
                        <div class="alert alert-warning">
                            <?= htmlspecialchars(implode(' | ', $item['errors'])) ?>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <table class="table table-sm">
                                <tbody>
                                <?php foreach ($courseFields as $fieldKey => $label): ?>
                                    <tr>
                                        <th><?= htmlspecialchars($label) ?></th>
                                        <td>
                                            <?php
                                            $value = $courseData[$fieldKey] ?? null;
                                            $formatted = format_course_value($value);
                                            if ($fieldKey === 'alternateLink' && $formatted !== '-') {
                                                $link = htmlspecialchars($formatted);
                                                echo "<a href=\"{$link}\" target=\"_blank\" rel=\"noopener\">{$link}</a>";
                                            } else {
                                                echo htmlspecialchars($formatted);
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-lg-6">
                            <details>
                                <summary>Dettagli JSON</summary>
                                <pre class="mt-2"><?= htmlspecialchars(json_encode($courseData, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?></pre>
                            </details>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
