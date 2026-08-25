<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Integration\GoogleClassroomAPI;
use Throwable;

/**
 * Helper centralizzato per lo stato di pubblicazione Classroom di una risorsa
 * (materiale UDA o test) in un corso, usato per mostrare badge uniformi:
 * NON CREATO / BOZZA / PUBBLICATO.
 */
final class ClassroomPublishState
{
    public const COLOR_NON_CREATO = '#6c757d';
    public const COLOR_BOZZA = '#D97706';
    public const COLOR_PUBBLICATO = '#198754';

    /**
     * Stato live di una risorsa Classroom.
     *
     * @param string $kind 'material' (CourseWorkMaterial) oppure 'assignment' (CourseWork)
     * @return string|null 'PUBLISHED' | 'DRAFT' | null (non trovata/eliminata)
     */
    public static function resourceState(GoogleClassroomAPI $api, string $courseId, string $resourceId, string $kind): ?string
    {
        if ($courseId === '' || $resourceId === '') {
            return null;
        }
        try {
            $res = $kind === 'material'
                ? $api->getMaterial($courseId, $resourceId)
                : $api->getAssignment($courseId, $resourceId);
            return (string)($res['state'] ?? '');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Classifica lo stato in badge.
     *
     * @param string|null $link  link presente (null/'' = nessun link)
     * @param string|null $state 'PUBLISHED' | 'DRAFT' | null
     * @return array{label:string,color:string,url:string}
     */
    public static function classify(?string $link, ?string $state): array
    {
        if ($link === null || $link === '') {
            return ['label' => 'NON CREATO', 'color' => self::COLOR_NON_CREATO, 'url' => ''];
        }
        if ($state === 'PUBLISHED') {
            return ['label' => 'PUBBLICATO', 'color' => self::COLOR_PUBBLICATO, 'url' => $link];
        }
        return ['label' => 'BOZZA', 'color' => self::COLOR_BOZZA, 'url' => $link];
    }

    /**
     * Badge del MATERIALE UDA per un gruppo didattico.
     *
     * @return array{label:string,color:string,url:string}
     */
    public static function materialBadgeForGroup(
        GoogleClassroomAPI $api,
        DatabaseAdapterInterface $db,
        string $userId,
        string $udaId,
        string $groupId,
        string $courseId
    ): array {
        $link = '';
        $resourceId = '';
        $rows = $db->findWhere('UDA_PUBBLICAZIONI', [
            'id_utente' => $userId,
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
            'provider' => 'google_classroom',
        ]);
        $row = $rows[0] ?? null;
        if (is_array($row)) {
            $link = trim((string)($row['material_classroom_url'] ?? ''));
            $resourceId = trim((string)($row['material_classroom_id'] ?? ''));
        }
        $state = self::resourceState($api, $courseId, $resourceId, 'material');
        return self::classify($link, $state);
    }

    /**
     * Badge di un TEST per un gruppo didattico.
     *
     * @param array<string,mixed> $test
     * @return array{label:string,color:string,url:string}
     */
    public static function testBadgeForGroup(
        GoogleClassroomAPI $api,
        DatabaseAdapterInterface $db,
        string $userId,
        array $test,
        string $groupId,
        string $courseId
    ): array {
        $link = '';
        $resourceId = '';
        $rows = $db->findWhere('TEST_CLASSROOM_PUBBLICAZIONI', [
            'id_utente' => $userId,
            'id_test' => trim((string)($test['id_test'] ?? '')),
            'id_gruppo' => $groupId,
        ]);
        $row = $rows[0] ?? null;
        if (is_array($row)) {
            $link = trim((string)($row['classroom_url'] ?? ''));
            $resourceId = trim((string)($row['classroom_assignment_id'] ?? ''));
            $courseId = trim((string)($row['course_id'] ?? '')) !== '' ? trim((string)($row['course_id'] ?? '')) : $courseId;
        } else {
            // Fallback per i flussi single-course che aggiornano solo TEST.classroom_*.
            if (trim((string)($test['classroom_course_id'] ?? '')) === $courseId) {
                $link = trim((string)($test['classroom_url'] ?? ''));
                $resourceId = trim((string)($test['classroom_assignment_id'] ?? ''));
            }
        }
        $state = self::resourceState($api, $courseId, $resourceId, 'assignment');
        return self::classify($link, $state);
    }

    /**
     * HTML del badge (con link se presente).
     *
     * @param array{label:string,color:string,url:string} $badge
     */
    public static function badgeHtml(array $badge, string $text = ''): string
    {
        $label = $text !== '' ? $text : $badge['label'];
        $color = $badge['color'];
        if ($badge['url'] !== '') {
            return '<a class="badge text-decoration-none" style="background-color:' . htmlspecialchars($color, ENT_QUOTES) . ';color:#fff" href="'
                . htmlspecialchars($badge['url'], ENT_QUOTES) . '" target="_blank" rel="noopener"><i class="bi bi-google"></i> '
                . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }
        return '<span class="badge" style="background-color:' . htmlspecialchars($color, ENT_QUOTES) . ';color:#fff">'
            . htmlspecialchars($label, ENT_QUOTES) . '</span>';
    }
}
