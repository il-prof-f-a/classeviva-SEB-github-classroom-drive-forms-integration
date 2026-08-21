<?php
/**
 * AJAX - Elenca topic e risorse (compiti/materiali/allegati) da Google Classroom
 */

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

error_reporting(E_ALL);
ob_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleClassroomAPI;
use Google\Service\Classroom as GoogleClassroomService;

header('Content-Type: application/json');

/**
 * Normalizza stringa in modo sicuro.
 */
function normalizeClassroomString($value, string $fallback = ''): string
{
    if (!is_string($value)) {
        return $fallback;
    }
    $value = trim($value);
    return $value !== '' ? $value : $fallback;
}

/**
 * Riconosce piattaforma test da URL.
 */
function detectClassroomTestPlatform(string $url, bool $isAssignment = false): string
{
    $normalized = strtolower(trim($url));
    if ($normalized === '') {
        return $isAssignment ? 'google-classroom' : 'altro';
    }
    if (strpos($normalized, 'docs.google.com/forms') !== false || strpos($normalized, 'forms.gle') !== false) {
        return 'google-forms';
    }
    if (strpos($normalized, 'kahoot.it') !== false || strpos($normalized, 'create.kahoot.it') !== false) {
        return 'kahoot';
    }
    if (strpos($normalized, 'socrative.com') !== false || strpos($normalized, 'b.socrative.com') !== false) {
        return 'socrative';
    }
    if (strpos($normalized, 'classroom.google.com') !== false && $isAssignment) {
        return 'google-classroom';
    }
    return $isAssignment ? 'google-classroom' : 'altro';
}

/**
 * Riconosce piattaforma test solo se esplicita nel link.
 */
function detectExplicitTestPlatform(string $url): string
{
    return detectClassroomTestPlatform($url, false);
}

/**
 * Stima tipo materiale da URL.
 */
function detectClassroomMaterialType(string $url, string $attachmentType = ''): string
{
    $normalized = strtolower(trim($url));
    if ($normalized === '') {
        return 'link';
    }

    if ($attachmentType === 'youtube' || strpos($normalized, 'youtube.com') !== false || strpos($normalized, 'youtu.be') !== false) {
        return 'video_youtube';
    }
    if (strpos($normalized, 'docs.google.com/spreadsheets') !== false) {
        return 'foglio_calcolo';
    }
    if (strpos($normalized, 'docs.google.com/presentation') !== false) {
        return 'presentazione';
    }
    if (strpos($normalized, 'docs.google.com/document') !== false || preg_match('/\.(pdf|doc|docx|txt)$/i', $normalized)) {
        return 'documento';
    }
    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $normalized)) {
        return 'immagine';
    }
    if (preg_match('/\.(mp4|avi|mov|webm)$/i', $normalized)) {
        return 'video';
    }
    return 'link';
}

/**
 * Estrae link allegati da Material[] Classroom.
 *
 * @param iterable $materials
 * @return array<int, array<string, string>>
 */
function extractClassroomLinksFromMaterials($materials): array
{
    $result = [];
    if (!is_iterable($materials)) {
        return $result;
    }

    foreach ($materials as $material) {
        if (!$material) {
            continue;
        }

        $link = method_exists($material, 'getLink') ? $material->getLink() : null;
        if ($link) {
            $url = normalizeClassroomString($link->getUrl() ?? '');
            if ($url !== '') {
                $result[] = [
                    'url' => $url,
                    'title' => normalizeClassroomString($link->getTitle() ?? ''),
                    'attachment_type' => 'link'
                ];
            }
        }

        $form = method_exists($material, 'getForm') ? $material->getForm() : null;
        if ($form) {
            $url = normalizeClassroomString($form->getFormUrl() ?? '');
            if ($url === '') {
                $url = normalizeClassroomString($form->getResponseUrl() ?? '');
            }
            if ($url !== '') {
                $result[] = [
                    'url' => $url,
                    'title' => normalizeClassroomString($form->getTitle() ?? ''),
                    'attachment_type' => 'form'
                ];
            }
        }

        $sharedDriveFile = method_exists($material, 'getDriveFile') ? $material->getDriveFile() : null;
        if ($sharedDriveFile) {
            $driveFile = method_exists($sharedDriveFile, 'getDriveFile') ? $sharedDriveFile->getDriveFile() : null;
            if ($driveFile) {
                $url = normalizeClassroomString($driveFile->getAlternateLink() ?? '');
                $fileId = normalizeClassroomString($driveFile->getId() ?? '');
                if ($url === '' && $fileId !== '') {
                    $url = 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
                }
                if ($url !== '') {
                    $result[] = [
                        'url' => $url,
                        'title' => normalizeClassroomString($driveFile->getTitle() ?? ''),
                        'attachment_type' => 'drive_file'
                    ];
                }
            }
        }

        $youtubeVideo = method_exists($material, 'getYoutubeVideo') ? $material->getYoutubeVideo() : null;
        if ($youtubeVideo) {
            $url = normalizeClassroomString($youtubeVideo->getAlternateLink() ?? '');
            $videoId = normalizeClassroomString($youtubeVideo->getId() ?? '');
            if ($url === '' && $videoId !== '') {
                $url = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
            }
            if ($url !== '') {
                $result[] = [
                    'url' => $url,
                    'title' => normalizeClassroomString($youtubeVideo->getTitle() ?? ''),
                    'attachment_type' => 'youtube'
                ];
            }
        }

        $gem = method_exists($material, 'getGem') ? $material->getGem() : null;
        if ($gem) {
            $url = normalizeClassroomString($gem->getUrl() ?? '');
            if ($url !== '') {
                $result[] = [
                    'url' => $url,
                    'title' => normalizeClassroomString($gem->getTitle() ?? ''),
                    'attachment_type' => 'link'
                ];
            }
        }

        $notebook = method_exists($material, 'getNotebook') ? $material->getNotebook() : null;
        if ($notebook) {
            $url = normalizeClassroomString($notebook->getUrl() ?? '');
            if ($url !== '') {
                $result[] = [
                    'url' => $url,
                    'title' => normalizeClassroomString($notebook->getTitle() ?? ''),
                    'attachment_type' => 'link'
                ];
            }
        }
    }

    return $result;
}

/**
 * Aggiunge una risorsa normalizzata in output.
 *
 * @param array<int, array<string, mixed>> $resources
 * @param array<string, bool> $dedupe
 */
function addClassroomResource(array &$resources, array &$dedupe, array $resource): void
{
    $url = normalizeClassroomString((string)($resource['url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return;
    }

    $topicId = normalizeClassroomString((string)($resource['topic_id'] ?? ''));
    $dedupeKey = strtolower($url) . '|' . strtolower($topicId);
    if (isset($dedupe[$dedupeKey])) {
        return;
    }
    $dedupe[$dedupeKey] = true;

    $isAssignment = !empty($resource['is_assignment']);
    $explicitDestination = strtolower(trim((string)($resource['default_destination'] ?? '')));
    if (!in_array($explicitDestination, ['test', 'materiale'], true)) {
        $explicitDestination = '';
    }
    $explicitPlatform = normalizeClassroomString((string)($resource['suggested_platform'] ?? ''));
    $platform = $explicitPlatform !== '' ? $explicitPlatform : detectClassroomTestPlatform($url, $isAssignment);
    $defaultDestination = $explicitDestination !== ''
        ? $explicitDestination
        : (($isAssignment || $platform !== 'altro') ? 'test' : 'materiale');

    $resources[] = [
        'resource_id' => normalizeClassroomString((string)($resource['resource_id'] ?? uniqid('RES_', true))),
        'source' => normalizeClassroomString((string)($resource['source'] ?? 'material')),
        'source_label' => normalizeClassroomString((string)($resource['source_label'] ?? 'Materiale Classroom')),
        'source_id' => normalizeClassroomString((string)($resource['source_id'] ?? '')),
        'title' => normalizeClassroomString((string)($resource['title'] ?? ''), 'Risorsa Classroom'),
        'description' => normalizeClassroomString((string)($resource['description'] ?? '')),
        'url' => $url,
        'topic_id' => $topicId,
        'topic_name' => normalizeClassroomString((string)($resource['topic_name'] ?? '')),
        'work_type' => normalizeClassroomString((string)($resource['work_type'] ?? '')),
        'state' => normalizeClassroomString((string)($resource['state'] ?? '')),
        'update_time' => normalizeClassroomString((string)($resource['update_time'] ?? '')),
        'attachment_type' => normalizeClassroomString((string)($resource['attachment_type'] ?? '')),
        'is_assignment' => $isAssignment,
        'default_destination' => $defaultDestination,
        'suggested_platform' => $platform,
        'suggested_material_type' => detectClassroomMaterialType($url, normalizeClassroomString((string)($resource['attachment_type'] ?? ''))),
    ];
}

$responsePayload = null;

try {
    $courseId = normalizeClassroomString($_GET['course_id'] ?? '');
    $topicFilter = normalizeClassroomString($_GET['topic_id'] ?? '');

    if ($courseId === '') {
        throw new Exception('Course ID mancante');
    }

    $classroomApi = new GoogleClassroomAPI($config);
    $service = new GoogleClassroomService($classroomApi->getClient());

    $topics = $classroomApi->getTopics($courseId);
    $topicMap = [];
    $topicList = [];
    foreach ($topics as $topic) {
        $topicId = normalizeClassroomString((string)($topic['id'] ?? ''));
        $topicName = normalizeClassroomString((string)($topic['name'] ?? ''));
        if ($topicId === '' || $topicName === '') {
            continue;
        }
        $topicMap[$topicId] = $topicName;
        $topicList[] = [
            'id' => $topicId,
            'name' => $topicName
        ];
    }

    $resources = [];
    $dedupe = [];

    $pageToken = null;
    do {
        $response = $service->courses_courseWork->listCoursesCourseWork($courseId, [
            'pageToken' => $pageToken,
            'orderBy' => 'updateTime desc',
            'courseWorkStates' => ['PUBLISHED', 'DRAFT']
        ]);

        $courseWorkList = $response->getCourseWork() ?? [];
        foreach ($courseWorkList as $courseWork) {
            $topicId = normalizeClassroomString($courseWork->getTopicId() ?? '');
            if ($topicFilter !== '' && $topicFilter !== $topicId) {
                continue;
            }

            $title = normalizeClassroomString($courseWork->getTitle() ?? '', 'Compito Classroom');
            $description = normalizeClassroomString($courseWork->getDescription() ?? '');
            $alternateLink = normalizeClassroomString($courseWork->getAlternateLink() ?? '');
            $courseWorkId = normalizeClassroomString($courseWork->getId() ?? '');
            $workType = normalizeClassroomString($courseWork->getWorkType() ?? '');
            $state = normalizeClassroomString($courseWork->getState() ?? '');
            $updateTime = normalizeClassroomString($courseWork->getUpdateTime() ?? '');
            $isAssignment = in_array($workType, ['ASSIGNMENT', 'QUIZ_ASSIGNMENT'], true);

            $attachments = extractClassroomLinksFromMaterials($courseWork->getMaterials() ?? []);
            $normalizedAttachments = [];
            $hasRecognizedTest = false;
            foreach ($attachments as $attachment) {
                $attachmentUrl = normalizeClassroomString($attachment['url'] ?? '');
                if ($attachmentUrl === '') {
                    continue;
                }
                $explicitPlatform = detectExplicitTestPlatform($attachmentUrl);
                if ($explicitPlatform !== 'altro') {
                    $hasRecognizedTest = true;
                }
                $attachment['explicit_platform'] = $explicitPlatform;
                $normalizedAttachments[] = $attachment;
            }

            if (!$hasRecognizedTest && $alternateLink !== '') {
                addClassroomResource($resources, $dedupe, [
                    'resource_id' => 'cw_' . $courseWorkId,
                    'source' => 'course_work',
                    'source_label' => $isAssignment ? 'Compito Classroom' : 'Attività Classroom',
                    'source_id' => $courseWorkId,
                    'title' => $title,
                    'description' => $description,
                    'url' => $alternateLink,
                    'topic_id' => $topicId,
                    'topic_name' => $topicMap[$topicId] ?? '',
                    'work_type' => $workType,
                    'state' => $state,
                    'update_time' => $updateTime,
                    'attachment_type' => '',
                    'is_assignment' => $isAssignment,
                    'default_destination' => 'test'
                ]);
            }

            foreach ($normalizedAttachments as $index => $attachment) {
                $attachmentTitle = normalizeClassroomString($attachment['title'] ?? '');
                if ($attachmentTitle !== '' && $title !== '' && $attachmentTitle !== $title) {
                    $attachmentTitle = $title . ' - ' . $attachmentTitle;
                } elseif ($attachmentTitle === '') {
                    $attachmentTitle = $title . ' - Allegato ' . ($index + 1);
                }
                $explicitPlatform = normalizeClassroomString($attachment['explicit_platform'] ?? '');
                $defaultDestination = $explicitPlatform !== '' && $explicitPlatform !== 'altro' ? 'test' : 'materiale';
                addClassroomResource($resources, $dedupe, [
                    'resource_id' => 'cw_' . $courseWorkId . '_att_' . $index,
                    'source' => 'course_work_attachment',
                    'source_label' => $isAssignment ? 'Allegato compito' : 'Allegato attività',
                    'source_id' => $courseWorkId,
                    'title' => $attachmentTitle,
                    'description' => $description,
                    'url' => $attachment['url'] ?? '',
                    'topic_id' => $topicId,
                    'topic_name' => $topicMap[$topicId] ?? '',
                    'work_type' => $workType,
                    'state' => $state,
                    'update_time' => $updateTime,
                    'attachment_type' => $attachment['attachment_type'] ?? '',
                    'is_assignment' => $isAssignment,
                    'default_destination' => $defaultDestination,
                    'suggested_platform' => $explicitPlatform
                ]);
            }
        }

        $pageToken = $response->getNextPageToken();
    } while ($pageToken);

    $pageToken = null;
    do {
        $response = $service->courses_courseWorkMaterials->listCoursesCourseWorkMaterials($courseId, [
            'pageToken' => $pageToken,
            'courseWorkMaterialStates' => ['PUBLISHED', 'DRAFT']
        ]);

        $courseWorkMaterialList = $response->getCourseWorkMaterial() ?? [];
        foreach ($courseWorkMaterialList as $courseWorkMaterial) {
            $topicId = normalizeClassroomString($courseWorkMaterial->getTopicId() ?? '');
            if ($topicFilter !== '' && $topicFilter !== $topicId) {
                continue;
            }

            $materialId = normalizeClassroomString($courseWorkMaterial->getId() ?? '');
            $title = normalizeClassroomString($courseWorkMaterial->getTitle() ?? '', 'Materiale Classroom');
            $description = normalizeClassroomString($courseWorkMaterial->getDescription() ?? '');
            $alternateLink = normalizeClassroomString($courseWorkMaterial->getAlternateLink() ?? '');
            $state = normalizeClassroomString($courseWorkMaterial->getState() ?? '');
            $updateTime = normalizeClassroomString($courseWorkMaterial->getUpdateTime() ?? '');

            $attachments = extractClassroomLinksFromMaterials($courseWorkMaterial->getMaterials() ?? []);
            $normalizedAttachments = [];
            foreach ($attachments as $attachment) {
                $attachmentUrl = normalizeClassroomString($attachment['url'] ?? '');
                if ($attachmentUrl === '') {
                    continue;
                }
                $attachment['explicit_platform'] = detectExplicitTestPlatform($attachmentUrl);
                $normalizedAttachments[] = $attachment;
            }

            if (empty($normalizedAttachments) && $alternateLink !== '') {
                addClassroomResource($resources, $dedupe, [
                    'resource_id' => 'cwm_' . $materialId,
                    'source' => 'course_work_material',
                    'source_label' => 'Materiale Classroom',
                    'source_id' => $materialId,
                    'title' => $title,
                    'description' => $description,
                    'url' => $alternateLink,
                    'topic_id' => $topicId,
                    'topic_name' => $topicMap[$topicId] ?? '',
                    'work_type' => 'MATERIAL',
                    'state' => $state,
                    'update_time' => $updateTime,
                    'attachment_type' => '',
                    'is_assignment' => false,
                    'default_destination' => 'materiale'
                ]);
            }

            foreach ($normalizedAttachments as $index => $attachment) {
                $attachmentTitle = normalizeClassroomString($attachment['title'] ?? '');
                if ($attachmentTitle !== '' && $title !== '' && $attachmentTitle !== $title) {
                    $attachmentTitle = $title . ' - ' . $attachmentTitle;
                } elseif ($attachmentTitle === '') {
                    $attachmentTitle = $title . ' - Allegato ' . ($index + 1);
                }
                $explicitPlatform = normalizeClassroomString($attachment['explicit_platform'] ?? '');
                $defaultDestination = $explicitPlatform !== '' && $explicitPlatform !== 'altro' ? 'test' : 'materiale';
                addClassroomResource($resources, $dedupe, [
                    'resource_id' => 'cwm_' . $materialId . '_att_' . $index,
                    'source' => 'course_work_material_attachment',
                    'source_label' => 'Allegato materiale',
                    'source_id' => $materialId,
                    'title' => $attachmentTitle,
                    'description' => $description,
                    'url' => $attachment['url'] ?? '',
                    'topic_id' => $topicId,
                    'topic_name' => $topicMap[$topicId] ?? '',
                    'work_type' => 'MATERIAL',
                    'state' => $state,
                    'update_time' => $updateTime,
                    'attachment_type' => $attachment['attachment_type'] ?? '',
                    'is_assignment' => false,
                    'default_destination' => $defaultDestination,
                    'suggested_platform' => $explicitPlatform
                ]);
            }
        }

        $pageToken = $response->getNextPageToken();
    } while ($pageToken);

    usort($resources, static function (array $a, array $b): int {
        $topicA = strtolower($a['topic_name'] ?? '');
        $topicB = strtolower($b['topic_name'] ?? '');
        if ($topicA !== $topicB) {
            return $topicA <=> $topicB;
        }

        $timeA = strtolower($a['update_time'] ?? '');
        $timeB = strtolower($b['update_time'] ?? '');
        if ($timeA !== $timeB) {
            return $timeB <=> $timeA;
        }

        return strtolower($a['title'] ?? '') <=> strtolower($b['title'] ?? '');
    });

    $responsePayload = [
        'success' => true,
        'course_id' => $courseId,
        'topics' => array_values($topicList),
        'resources' => $resources
    ];
} catch (Exception $e) {
    $responsePayload = [
        'success' => false,
        'error' => \App\Core\Security\PublicError::message($e, 'ajax_get_classroom_topic_resources')
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

echo json_encode($responsePayload);
