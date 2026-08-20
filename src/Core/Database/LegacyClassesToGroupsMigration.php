<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use RuntimeException;

/**
 * Converts only legacy class/subject data into the provider-neutral group domain.
 * Legacy tables are read-only inputs and are never deleted by this service.
 */
final class LegacyClassesToGroupsMigration
{
    /** @var callable():string */
    private $clock;

    public function __construct(private PDO $pdo, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): string => date('Y-m-d H:i:s');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $pairs = $this->collectPairs();
        $groups = [];
        $groupUpdates = [];
        $integrations = [];
        $integrationKeys = [];
        $assignments = [];
        $skipped = [];
        $conflicts = [];

        foreach ($pairs as $key => $pair) {
            $groupId = $this->findClassevivaGroupId($pair) ?? self::groupId($pair['id_utente'], $pair['id_classe_cv'], $pair['id_materia_cv']);
            $pair['id_gruppo'] = $groupId;
            $existingGroup = $this->findGroup($pair['id_utente'], $groupId);
            if ($existingGroup === null) {
                $groups[$groupId] = [
                    'id_gruppo' => $groupId,
                    'nome_gruppo' => self::groupName($pair),
                    'nome_classe' => $pair['nome_classe'],
                    'nome_materia' => $pair['nome_materia'],
                    'anno_scolastico' => $pair['anno_scolastico'],
                    'descrizione' => '',
                    'stato' => 'attivo',
                    'data_creazione' => $this->now(),
                    'ultima_modifica' => $this->now(),
                    'id_utente' => $pair['id_utente'],
                ];
            } else {
                $changes = [];
                foreach (['nome_classe', 'nome_materia', 'anno_scolastico'] as $column) {
                    if (trim((string)($existingGroup[$column] ?? '')) === ''
                        && trim((string)($pair[$column] ?? '')) !== '') {
                        $changes[$column] = $pair[$column];
                    }
                }
                if ($changes !== []) {
                    $changes['ultima_modifica'] = $this->now();
                    $groupUpdates[$groupId] = [
                        'id_gruppo' => $groupId,
                        'id_utente' => $pair['id_utente'],
                        'changes' => $changes,
                    ];
                }
            }

            $this->planIntegration(
                $integrations,
                $integrationKeys,
                $conflicts,
                $pair,
                'classeviva',
                'classe_materia',
                $pair['id_classe_cv'],
                $pair['id_materia_cv'],
                $pair['nome_classe'],
                ['subject_name' => $pair['nome_materia']]
            );
        }

        foreach ($this->rows('CLASSROOM_MAPPINGS') as $row) {
            $owner = self::value($row, ['id_utente']);
            $classId = self::value($row, ['id_classe_cv', 'classeviva_class_id']);
            $subjectId = self::value($row, ['id_materia_cv', 'classeviva_subject_id']);
            $courseId = self::value($row, ['id_corso_gc', 'google_course_id']);
            if ($owner === '' || $classId === '' || $subjectId === '') {
                $skipped[] = ['table' => 'CLASSROOM_MAPPINGS', 'id' => self::value($row, ['id_mapping']), 'reason' => 'classe o materia mancante'];
                continue;
            }
            if ($courseId === '') {
                $skipped[] = ['table' => 'CLASSROOM_MAPPINGS', 'id' => self::value($row, ['id_mapping']), 'reason' => 'corso Classroom mancante'];
                continue;
            }
            $key = self::pairKey($owner, $classId, $subjectId);
            if (!isset($pairs[$key])) {
                $skipped[] = ['table' => 'CLASSROOM_MAPPINGS', 'id' => self::value($row, ['id_mapping']), 'reason' => 'coppia non risolta'];
                continue;
            }
            $pair = $pairs[$key];
            $pair['id_gruppo'] = self::groupId($owner, $classId, $subjectId);
            $this->planIntegration(
                $integrations,
                $integrationKeys,
                $conflicts,
                $pair,
                'google_classroom',
                'course',
                $courseId,
                '',
                self::value($row, ['nome_corso_gc', 'google_course_name']),
                [
                    'classeviva_class_id' => $classId,
                    'classeviva_subject_id' => $subjectId,
                    'legacy_mapping_id' => self::value($row, ['id_mapping']),
                ]
            );
        }

        foreach ($this->rows('CLASSI_ASSEGNATE') as $row) {
            $owner = self::value($row, ['id_utente']);
            $udaId = self::value($row, ['id_uda']);
            $classId = self::value($row, ['id_classe', 'id_classe_cv']);
            $subjectId = self::value($row, ['id_materia_cv', 'classeviva_subject_id']);
            if ($owner === '' || $udaId === '' || $classId === '' || $subjectId === '') {
                $skipped[] = ['table' => 'CLASSI_ASSEGNATE', 'id' => self::value($row, ['id_assegnazione']), 'reason' => 'assegnazione incompleta'];
                continue;
            }
            $groupId = self::groupId($owner, $classId, $subjectId);
            if ($this->assignmentExists($owner, $udaId, $groupId)) {
                continue;
            }
            $sourceId = self::value($row, ['id_assegnazione']);
            $assignmentId = $sourceId !== '' && !$this->assignmentIdExists($owner, $sourceId)
                ? $sourceId
                : 'ASSIGN_LEGACY_' . substr(hash('sha256', $owner . '|' . $udaId . '|' . $groupId), 0, 24);
            $assignments[] = [
                'id_assegnazione' => $assignmentId,
                'id_uda' => $udaId,
                'id_gruppo' => $groupId,
                'data_assegnazione' => self::value($row, ['data_assegnazione']) ?: $this->now(),
                'data_inizio' => self::value($row, ['data_inizio']),
                'data_fine' => self::value($row, ['data_fine']),
                'note' => self::value($row, ['note']),
                'stato' => self::value($row, ['stato']) ?: 'assegnata',
                'id_utente' => $owner,
            ];
        }

        return [
            'success' => true,
            'counts' => [
                'pairs' => count($pairs),
                'groups_to_create' => count($groups),
                'groups_to_update' => count($groupUpdates),
                'integrations_to_create' => count($integrations),
                'assignments_to_create' => count($assignments),
                'skipped' => count($skipped),
                'conflicts' => count($conflicts),
            ],
            'groups' => array_values($groups),
            'group_updates' => array_values($groupUpdates),
            'integrations' => array_values($integrations),
            'assignments' => $assignments,
            'skipped' => $skipped,
            'conflicts' => $conflicts,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(): array
    {
        $plan = $this->plan();
        $this->pdo->beginTransaction();
        try {
            foreach ($plan['groups'] as $row) {
                $this->insert('GRUPPI_DIDATTICI', $row);
            }
            foreach ($plan['group_updates'] as $update) {
                $this->updateGroup($update['id_utente'], $update['id_gruppo'], $update['changes']);
            }
            foreach ($plan['integrations'] as $row) {
                $this->insert('GRUPPI_INTEGRAZIONI', $row);
            }
            foreach ($plan['assignments'] as $row) {
                $this->insert('UDA_GRUPPI', $row);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $plan['applied'] = true;
        $plan['counts']['groups_inserted'] = count($plan['groups']);
        $plan['counts']['integrations_inserted'] = count($plan['integrations']);
        $plan['counts']['assignments_inserted'] = count($plan['assignments']);
        return $plan;
    }

    public static function groupId(string $owner, string $classId, string $subjectId): string
    {
        return 'GRP_LEGACY_' . substr(hash('sha256', self::pairKey($owner, $classId, $subjectId)), 0, 24);
    }

    private function planIntegration(
        array &$integrations,
        array &$integrationKeys,
        array &$conflicts,
        array $pair,
        string $provider,
        string $resourceType,
        string $context,
        string $subject,
        string $externalName,
        array $metadata
    ): void {
        $owner = $pair['id_utente'];
        $groupId = $pair['id_gruppo'];
        $existing = $this->findActiveIntegrations($owner, $provider, $context, $subject);
        foreach ($existing as $row) {
            if ((string)($row['id_gruppo'] ?? '') !== $groupId) {
                $conflicts[] = [
                    'provider' => $provider,
                    'external_context_id' => $context,
                    'existing_group' => (string)($row['id_gruppo'] ?? ''),
                    'requested_group' => $groupId,
                    'reason' => 'risorsa esterna già collegata a un altro gruppo',
                ];
                return;
            }
            return;
        }
        $key = implode('|', [$owner, $provider, $context, $subject]);
        foreach ($integrations as $planned) {
            if ((string)($planned['id_utente'] ?? '') !== $owner
                || (string)($planned['provider'] ?? '') !== $provider
                || (string)($planned['external_context_id'] ?? '') !== $context
                || (string)($planned['external_subject_id'] ?? '') !== $subject) {
                continue;
            }
            if ((string)($planned['id_gruppo'] ?? '') !== $groupId) {
                $conflicts[] = [
                    'provider' => $provider,
                    'external_context_id' => $context,
                    'existing_group' => (string)($planned['id_gruppo'] ?? ''),
                    'requested_group' => $groupId,
                    'reason' => 'risorsa esterna già pianificata per un altro gruppo',
                ];
            }
            return;
        }
        if (isset($integrationKeys[$key])) {
            return;
        }
        $integrationKeys[$key] = true;
        $integrations[] = [
            'id_collegamento' => 'GIN_LEGACY_' . substr(hash('sha256', $key), 0, 24),
            'id_gruppo' => $groupId,
            'provider' => $provider,
            'tipo_risorsa' => $resourceType,
            'external_context_id' => $context,
            'external_subject_id' => $subject,
            'external_name' => $externalName,
            'principale' => '1',
            'stato' => 'attivo',
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'data_creazione' => $this->now(),
            'ultima_modifica' => $this->now(),
            'id_utente' => $owner,
        ];
    }

    /** @return array<string,array<string,string>> */
    private function collectPairs(): array
    {
        $pairs = [];
        foreach ($this->rows('CLASSROOM_MAPPINGS') as $row) {
            $this->addPair($pairs, $row, 'CLASSROOM_MAPPINGS');
        }
        foreach ($this->rows('CLASSI_ASSEGNATE') as $row) {
            $this->addPair($pairs, $row, 'CLASSI_ASSEGNATE');
        }
        ksort($pairs);
        return $pairs;
    }

    /** @param array<string,array<string,string>> $pairs */
    private function addPair(array &$pairs, array $row, string $table): void
    {
        $owner = self::value($row, ['id_utente']);
        $classId = self::value($row, ['id_classe_cv', 'id_classe', 'classeviva_class_id']);
        $subjectId = self::value($row, ['id_materia_cv', 'classeviva_subject_id']);
        if ($owner === '' || $classId === '' || $subjectId === '') {
            return;
        }
        $key = self::pairKey($owner, $classId, $subjectId);
        $current = $pairs[$key] ?? [
            'id_utente' => $owner,
            'id_classe_cv' => $classId,
            'id_materia_cv' => $subjectId,
            'nome_classe' => '',
            'nome_materia' => '',
            'anno_scolastico' => '',
        ];
        $current['nome_classe'] = $current['nome_classe'] !== ''
            ? $current['nome_classe']
            : self::value($row, ['nome_classe_cv', 'nome_classe', 'classeviva_class_name']);
        $current['nome_materia'] = $current['nome_materia'] !== ''
            ? $current['nome_materia']
            : self::value($row, ['nome_materia_cv', 'nome_materia', 'classeviva_subject_name']);
        $current['anno_scolastico'] = $current['anno_scolastico'] !== ''
            ? $current['anno_scolastico']
            : self::value($row, ['anno_scolastico', 'anno_corso']);
        $pairs[$key] = $current;
    }

    private function findGroup(string $owner, string $groupId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM `GRUPPI_DIDATTICI` WHERE `id_utente` = ? AND `id_gruppo` = ? LIMIT 1'
        );
        $statement->execute([$owner, $groupId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Reuses a group already migrated with a non-deterministic id when its
     * ClasseViva class/subject integration identifies the same logical pair.
     * This prevents duplicates when the source legacy tables come from an
     * older snapshot than the provider-neutral domain.
     *
     * @param array<string,string> $pair
     */
    private function findClassevivaGroupId(array $pair): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT `id_gruppo` FROM `GRUPPI_INTEGRAZIONI` '
            . 'WHERE `id_utente` = ? AND `provider` = ? AND `tipo_risorsa` = ? '
            . 'AND `external_context_id` = ? AND `external_subject_id` = ? '
            . 'AND COALESCE(`stato`, \'attivo\') <> \'disattivo\' LIMIT 1'
        );
        $statement->execute([
            $pair['id_utente'],
            'classeviva',
            'classe_materia',
            $pair['id_classe_cv'],
            $pair['id_materia_cv'],
        ]);
        $groupId = $statement->fetchColumn();
        return $groupId === false ? null : (trim((string)$groupId) ?: null);
    }

    /** @return list<array<string,mixed>> */
    private function findActiveIntegrations(string $owner, string $provider, string $context, string $subject): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM `GRUPPI_INTEGRAZIONI` WHERE `id_utente` = ? AND `provider` = ? '
            . 'AND `external_context_id` = ? AND `external_subject_id` = ? AND COALESCE(`stato`, \'attivo\') <> \'disattivo\''
        );
        $statement->execute([$owner, $provider, $context, $subject]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assignmentExists(string $owner, string $udaId, string $groupId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `UDA_GRUPPI` WHERE `id_utente` = ? AND `id_uda` = ? AND `id_gruppo` = ? LIMIT 1'
        );
        $statement->execute([$owner, $udaId, $groupId]);
        return $statement->fetchColumn() !== false;
    }

    private function assignmentIdExists(string $owner, string $assignmentId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `UDA_GRUPPI` WHERE `id_utente` = ? AND `id_assegnazione` = ? LIMIT 1'
        );
        $statement->execute([$owner, $assignmentId]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $changes */
    private function updateGroup(string $owner, string $groupId, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $parts = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $parts[] = '`' . $column . '` = ?';
            $params[] = $value;
        }
        $params[] = $owner;
        $params[] = $groupId;
        $statement = $this->pdo->prepare(
            'UPDATE `GRUPPI_DIDATTICI` SET ' . implode(', ', $parts)
            . ' WHERE `id_utente` = ? AND `id_gruppo` = ?'
        );
        $statement->execute($params);
    }

    /** @param array<string,mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute(array_values($row));
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        return $this->pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tableExists(string $table): bool
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $statement->execute([$table]);
            return $statement->fetchColumn() !== false;
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
        );
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function value(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string,string> $pair */
    private static function groupName(array $pair): string
    {
        $name = trim($pair['nome_classe'] . ($pair['nome_materia'] !== '' ? ' - ' . $pair['nome_materia'] : ''));
        return $name !== '' ? $name : $pair['id_classe_cv'] . ' - ' . $pair['id_materia_cv'];
    }

    private static function pairKey(string $owner, string $classId, string $subjectId): string
    {
        return $owner . '|' . $classId . '|' . $subjectId;
    }

    private function now(): string
    {
        return (string)($this->clock)();
    }
}
