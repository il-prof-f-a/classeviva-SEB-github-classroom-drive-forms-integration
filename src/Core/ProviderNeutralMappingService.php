<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Utils\LegacyTeachingGroupView;
use RuntimeException;

/**
 * Facade per le pagine legacy di mappatura.
 *
 * Le pagine possono continuare a ricevere le chiavi storiche, ma la scrittura
 * avviene sempre su GRUPPI_DIDATTICI/GRUPPI_INTEGRAZIONI. In questo modo una
 * stessa classe-materia può essere collegata a più provider senza duplicare
 * l'entità didattica.
 */
final class ProviderNeutralMappingService
{
    private TeachingGroupRepository $groups;
    private TeachingGroupIntegrationRepository $integrations;

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        $this->groups = new TeachingGroupRepository($db, $this->userId);
        $this->integrations = new TeachingGroupIntegrationRepository($db, $this->userId);
    }

    /** @param array<string,mixed> $data */
    public function upsertGoogleClassroomMapping(array $data): array
    {
        return $this->upsertProviderMapping('google_classroom', 'course', $data, [
            'external_context_id' => $data['google_course_id'] ?? '',
            'external_name' => $data['google_course_name'] ?? '',
            'metadata' => [
                'classeviva_class_id' => $data['classeviva_class_id'] ?? '',
                'classeviva_class_name' => $data['classeviva_class_name'] ?? '',
                'classeviva_subject_id' => $data['classeviva_subject_id'] ?? '',
                'classeviva_subject_name' => $data['classeviva_subject_name'] ?? '',
            ],
        ]);
    }

    /** @param array<string,mixed> $data */
    public function upsertGithubClassroomMapping(array $data): array
    {
        return $this->upsertProviderMapping('github_classroom', 'roster', $data, [
            'external_context_id' => $data['github_classroom_id'] ?? '',
            'external_name' => $data['classroom_name'] ?? '',
            'metadata' => [
                'github_org_name' => $data['github_org_name'] ?? '',
                'note' => $data['note'] ?? '',
                'classeviva_class_id' => $data['classeviva_class_id'] ?? '',
                'classeviva_class_name' => $data['classeviva_class_name'] ?? '',
                'classeviva_subject_id' => $data['classeviva_subject_id'] ?? '',
                'classeviva_subject_name' => $data['classeviva_subject_name'] ?? '',
            ],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listGoogleClassroomMappings(): array
    {
        return $this->listProviderMappings('google_classroom');
    }

    /** @return list<array<string,mixed>> */
    public function listGithubClassroomMappings(): array
    {
        return $this->listProviderMappings('github_classroom');
    }

    /** @return list<array<string,mixed>> */
    public static function legacyRows(DatabaseAdapterInterface $db, string $provider, ?string $userId = null): array
    {
        $rows = [];
        foreach ($db->findAll('GRUPPI_DIDATTICI') as $group) {
            $owner = trim((string)($group['id_utente'] ?? ''));
            if ($userId !== null && $owner !== $userId) {
                continue;
            }
            foreach ($db->findWhere('GRUPPI_INTEGRAZIONI', [
                'id_utente' => $owner,
                'id_gruppo' => (string)($group['id_gruppo'] ?? ''),
                'provider' => $provider,
            ]) as $row) {
                if (($row['stato'] ?? 'attivo') === 'disattivo') {
                    continue;
                }
                $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true);
                $rows[] = self::legacyRow($provider, $group, $row, is_array($metadata) ? $metadata : []);
            }
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $where @return list<array<string,mixed>> */
    public static function filterLegacyRows(array $rows, array $where): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if ((string)($row[$field] ?? '') !== (string)$value) {
                    return false;
                }
            }
            return true;
        }));
    }

    public static function insertLegacy(DatabaseAdapterInterface $db, string $provider, array $data): bool
    {
        $userId = trim((string)($data['id_utente'] ?? 'system')) ?: 'system';
        $service = new self($db, $userId);
        if ($provider === 'google_classroom') {
            $service->upsertGoogleClassroomMapping([
                'classeviva_class_id' => $data['id_classe_cv'] ?? $data['classeviva_class_id'] ?? '',
                'classeviva_class_name' => $data['nome_classe_cv'] ?? $data['classeviva_class_name'] ?? '',
                'classeviva_subject_id' => $data['id_materia_cv'] ?? $data['classeviva_subject_id'] ?? '',
                'classeviva_subject_name' => $data['nome_materia_cv'] ?? $data['classeviva_subject_name'] ?? '',
                'google_course_id' => $data['id_corso_gc'] ?? $data['google_course_id'] ?? '',
                'google_course_name' => $data['nome_corso_gc'] ?? $data['google_course_name'] ?? '',
            ]);
            return true;
        }
        $service->upsertGithubClassroomMapping([
            'classeviva_class_id' => $data['id_classe_cv'] ?? '',
            'classeviva_class_name' => $data['nome_classe_cv'] ?? '',
            'classeviva_subject_id' => $data['id_materia_cv'] ?? '',
            'classeviva_subject_name' => $data['nome_materia_cv'] ?? '',
            'github_classroom_id' => $data['github_classroom_id'] ?? '',
            'github_org_name' => $data['github_org_name'] ?? '',
            'classroom_name' => $data['classroom_name'] ?? '',
            'note' => $data['note'] ?? '',
        ]);
        return true;
    }

    public static function updateLegacy(DatabaseAdapterInterface $db, string $provider, string $id, array $data): bool
    {
        $userId = trim((string)($data['id_utente'] ?? 'system')) ?: 'system';
        $existing = self::filterLegacyRows(self::legacyRows($db, $provider, $userId), ['id_mapping' => $id])[0] ?? null;
        if ($existing === null) return false;
        return self::insertLegacy($db, $provider, array_merge($existing, $data));
    }

    public static function deleteLegacy(DatabaseAdapterInterface $db, string $provider, string $id): bool
    {
        foreach (self::legacyRows($db, $provider) as $row) {
            if ((string)($row['id_mapping'] ?? '') === $id) {
                return (new self($db, (string)($row['id_utente'] ?? 'system')))->deactivateMapping($id);
            }
        }
        return false;
    }

    public function deactivateMapping(string $mappingId): bool
    {
        return $this->integrations->deactivate($mappingId);
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $providerData */
    private function upsertProviderMapping(string $provider, string $resourceType, array $data, array $providerData): array
    {
        $requestedGroupId = trim((string)($data['id_gruppo'] ?? ''));
        $classId = trim((string)($data['classeviva_class_id'] ?? ''));
        $subjectId = trim((string)($data['classeviva_subject_id'] ?? ''));
        $externalId = trim((string)($providerData['external_context_id'] ?? ''));
        if ($externalId === '') {
            throw new RuntimeException('La risorsa esterna è obbligatoria.');
        }

        $cv = ($classId !== '' && $subjectId !== '')
            ? $this->integrations->findByExternal('classeviva', $classId, $subjectId)
            : null;
        if ($requestedGroupId !== '') {
            $group = $this->groups->findById($requestedGroupId);
            if ($group === null) {
                throw new RuntimeException('Il gruppo didattico indicato non esiste.');
            }
            $groupId = $requestedGroupId;
        } elseif ($cv !== null) {
            $groupId = (string)$cv['id_gruppo'];
        } else {
            $className = trim((string)($data['classeviva_class_name'] ?? ''));
            $subjectName = trim((string)($data['classeviva_subject_name'] ?? ''));
            $providerName = trim((string)($providerData['external_name'] ?? ''));
            $fallbackName = trim($providerName !== '' ? $providerName : $externalId);
            $group = $this->groups->create([
                'nome_gruppo' => trim($className . ($subjectName !== '' ? ' - ' . $subjectName : '')) ?: $fallbackName,
                'nome_classe' => $className,
                'nome_materia' => $subjectName,
                'anno_scolastico' => (string)($data['anno_scolastico'] ?? ''),
            ]);
            $groupId = (string)$group['id_gruppo'];
            if ($classId !== '' && $subjectId !== '') {
                $cv = $this->integrations->link([
                    'id_gruppo' => $groupId,
                    'provider' => 'classeviva',
                    'tipo_risorsa' => 'classe_materia',
                    'external_context_id' => $classId,
                    'external_subject_id' => $subjectId,
                    'external_name' => $className,
                    'metadata_json' => json_encode(['subject_name' => $subjectName], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }

        $existing = $this->integrations->findByExternal($provider, $externalId);
        if ($existing !== null && (string)$existing['id_gruppo'] !== $groupId) {
            throw new RuntimeException('La risorsa esterna è già collegata a un altro gruppo didattico.');
        }

        if ($existing !== null) {
            // Aggiorna tramite il repository moderno: oltre a mantenere il
            // contratto legacy, garantisce sempre il vincolo id_utente sulla
            // riga esistente (evitando update per sola chiave tecnica).
            return $this->integrations->upsertForGroupProvider($groupId, [
                'provider' => $provider,
                'tipo_risorsa' => $resourceType,
                'external_context_id' => $externalId,
                'external_name' => (string)($providerData['external_name'] ?? ''),
                'metadata_json' => json_encode($providerData['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'stato' => 'attivo',
            ]);
        }

        return $this->integrations->link([
            'id_gruppo' => $groupId,
            'provider' => $provider,
            'tipo_risorsa' => $resourceType,
            'external_context_id' => $externalId,
            'external_name' => (string)($providerData['external_name'] ?? ''),
            'metadata_json' => json_encode($providerData['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function listProviderMappings(string $provider): array
    {
        $rows = [];
        foreach ($this->groups->listActive() as $group) {
            $integrations = $this->integrations->listForGroup((string)$group['id_gruppo']);
            $providerRows = array_values(array_filter($integrations, static function (array $row) use ($provider): bool {
                return ($row['provider'] ?? '') === $provider && ($row['stato'] ?? 'attivo') !== 'disattivo';
            }));
            foreach ($providerRows as $row) {
                $metadata = json_decode((string)($row['metadata_json'] ?? '{}'), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $rows[] = self::legacyRow($provider, $group, $row, $metadata);
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $group @param array<string,mixed> $row @param array<string,mixed> $metadata */
    private static function legacyRow(string $provider, array $group, array $row, array $metadata): array
    {
        $base = [
            'id_mapping' => (string)($row['id_collegamento'] ?? ''),
            'id_gruppo' => (string)($group['id_gruppo'] ?? ''),
            'id_utente' => (string)($group['id_utente'] ?? ''),
            'id_classe_cv' => (string)($metadata['classeviva_class_id'] ?? ''),
            'nome_classe_cv' => (string)($metadata['classeviva_class_name'] ?? $group['nome_classe'] ?? ''),
            'id_materia_cv' => (string)($metadata['classeviva_subject_id'] ?? ''),
            'nome_materia_cv' => (string)($metadata['classeviva_subject_name'] ?? $group['nome_materia'] ?? ''),
            'stato' => (string)($row['stato'] ?? 'attivo'),
            'data_mapping' => (string)($row['ultima_modifica'] ?? $row['data_creazione'] ?? ''),
            'note' => (string)($metadata['note'] ?? ''),
        ];

        if ($provider === 'google_classroom') {
            $base['id_corso_gc'] = (string)($row['external_context_id'] ?? '');
            $base['nome_corso_gc'] = (string)($row['external_name'] ?? '');
            $base['google_course_id'] = $base['id_corso_gc'];
            $base['google_course_name'] = $base['nome_corso_gc'];
        } else {
            $base['github_classroom_id'] = (string)($row['external_context_id'] ?? '');
            $base['github_org_name'] = (string)($metadata['github_org_name'] ?? '');
            $base['classroom_name'] = (string)($row['external_name'] ?? '');
        }
        return $base;
    }
}
