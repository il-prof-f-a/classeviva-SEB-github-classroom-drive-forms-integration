<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Utils\LegacyTeachingGroupView;
use RuntimeException;

/**
 * Compatibilità temporanea per i metodi usati dalle pagine legacy.
 * I dati vengono letti/scritti nel modello gruppo, mai in CLASSI_ASSEGNATE.
 */
final class LegacyUdaDataGateway
{
    /** @return list<array<string,mixed>> */
    public static function findAllClassiAssegnate(DatabaseAdapterInterface $db): array
    {
        $result = [];
        foreach ($db->findAll('UDA_GRUPPI') as $assignment) {
            $udaId = trim((string)($assignment['id_uda'] ?? ''));
            if ($udaId === '') {
                continue;
            }
            $result = array_merge($result, self::findClassiAssegnate($db, $udaId));
        }
        return $result;
    }

    /** @param array<string,mixed> $where @return list<array<string,mixed>> */
    public static function findClassiAssegnateWhere(DatabaseAdapterInterface $db, array $where): array
    {
        $rows = self::findAllClassiAssegnate($db);
        return array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if ((string)($row[$field] ?? '') !== (string)$value) {
                    return false;
                }
            }
            return true;
        }));
    }

    public static function findClassiAssegnate(DatabaseAdapterInterface $db, string $udaId): array
    {
        $result = [];
        foreach ($db->findWhere('UDA_GRUPPI', ['id_uda' => $udaId]) as $assignment) {
            $userId = (string)($assignment['id_utente'] ?? '');
            if ($userId === '') {
                continue;
            }
            $group = (new TeachingGroupRepository($db, $userId))->findById((string)$assignment['id_gruppo']);
            if ($group === null) {
                continue;
            }
            $integrations = (new TeachingGroupIntegrationRepository($db, $userId))->listForGroup((string)$assignment['id_gruppo']);
            $publications = $db->findWhere('UDA_PUBBLICAZIONI', [
                'id_utente' => $userId,
                'id_uda' => $udaId,
                'id_gruppo' => (string)$assignment['id_gruppo'],
            ]);
            $result[] = LegacyTeachingGroupView::assignment($assignment, $group, $integrations, $publications);
        }
        return $result;
    }

    public static function insertClasseAssegnata(DatabaseAdapterInterface $db, array $data): bool
    {
        $userId = trim((string)($data['id_utente'] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('id_utente obbligatorio per l’assegnazione UDA');
        }

        $groups = new TeachingGroupRepository($db, $userId);
        $integrations = new TeachingGroupIntegrationRepository($db, $userId);
        $groupId = trim((string)($data['id_gruppo'] ?? ''));
        $classId = trim((string)($data['id_classe'] ?? $data['classeviva_class_id'] ?? ''));
        $subjectId = trim((string)($data['id_materia_cv'] ?? $data['classeviva_subject_id'] ?? ''));

        if ($groupId === '' && $classId !== '') {
            $existing = $integrations->findByExternal('classeviva', $classId, $subjectId === '' ? null : $subjectId);
            $groupId = (string)($existing['id_gruppo'] ?? '');
        }
        if ($groupId === '') {
            $className = trim((string)($data['nome_classe'] ?? $data['classeviva_class_name'] ?? ''));
            $subjectName = trim((string)($data['nome_materia'] ?? $data['classeviva_subject_name'] ?? ''));
            $group = $groups->create([
                'nome_gruppo' => trim($className . ($subjectName !== '' ? ' - ' . $subjectName : '')) ?: 'Gruppo didattico',
                'nome_classe' => $className,
                'nome_materia' => $subjectName,
                'anno_scolastico' => (string)($data['anno_scolastico'] ?? ''),
            ]);
            $groupId = (string)$group['id_gruppo'];
        }

        if ($classId !== '') {
            $integrations->link([
                'id_gruppo' => $groupId,
                'provider' => 'classeviva',
                'tipo_risorsa' => 'classe_materia',
                'external_context_id' => $classId,
                'external_subject_id' => $subjectId,
                'external_name' => (string)($data['nome_classe'] ?? ''),
            ]);
        }

        $assignmentId = trim((string)($data['id_assegnazione'] ?? ''));
        if ($assignmentId === '') {
            $assignmentId = 'ASSEGN_' . bin2hex(random_bytes(10));
        }

        $assignment = (new UdaGroupRepository($db, $userId))->assign(
            (string)($data['id_uda'] ?? ''),
            $groupId,
            [
                'id_assegnazione' => $assignmentId,
                'data_assegnazione' => (string)($data['data_assegnazione'] ?? date('Y-m-d H:i:s')),
                'data_inizio' => (string)($data['data_inizio'] ?? ''),
                'data_fine' => (string)($data['data_fine'] ?? ''),
                'note' => (string)($data['note'] ?? ''),
                'stato' => (string)($data['stato'] ?? 'assegnata'),
            ]
        );

        $classroomUrl = trim((string)($data['classroom_url'] ?? ''));
        if ($classroomUrl !== '') {
            $db->insertRow('UDA_PUBBLICAZIONI', [
                'id_pubblicazione' => 'PUB_' . bin2hex(random_bytes(12)),
                'id_uda' => (string)$assignment['id_uda'],
                'id_gruppo' => $groupId,
                'provider' => 'google_classroom',
                'external_resource_id' => '',
                'external_url' => $classroomUrl,
                'stato' => 'pubblicato',
                'data_pubblicazione' => date('Y-m-d H:i:s'),
                'metadata_json' => '{}',
                'id_utente' => $userId,
            ]);
        }
        return true;
    }

    public static function deleteClasseAssegnata(DatabaseAdapterInterface $db, string $assignmentId): bool
    {
        $assignmentId = trim($assignmentId);
        if ($assignmentId === '') {
            return false;
        }

        $rows = $db->findWhere('UDA_GRUPPI', ['id_assegnazione' => $assignmentId]);
        if ($rows === []) {
            return false;
        }

        $deleted = false;
        foreach ($rows as $row) {
            $deleted = $db->deleteRow('UDA_GRUPPI', (string)($row['id_assegnazione'] ?? $assignmentId), 'id_assegnazione') || $deleted;
        }
        return $deleted;
    }
}
