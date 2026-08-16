<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

/**
 * Risoluzione provider-neutral degli studenti per gli import voti.
 *
 * Traduce identità esterne (Google Classroom, GitHub Classroom, ...) in
 * id_studente interni tramite il roster del gruppo didattico, senza alcuna
 * dipendenza dalla coppia ClasseViva classe/materia. La chiave canonica resta
 * sempre l'id_studente interno.
 */
final class GradeImportStudentService
{
    private TeachingGroupStudentService $students;
    private TeachingGroupIntegrationRepository $integrations;

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        $this->students = new TeachingGroupStudentService($this->db, $this->userId);
        $this->integrations = new TeachingGroupIntegrationRepository($this->db, $this->userId);
    }

    /**
     * Restituisce l'id_gruppo collegato a un contesto provider, o lancia
     * RuntimeException quando nessun gruppo attivo è collegato.
     */
    public function resolveGroupId(string $provider, string $contextId): string
    {
        $integration = $this->integrations->findByContext($provider, $contextId);
        if ($integration === null) {
            throw new RuntimeException('Nessun gruppo didattico collegato a questo contesto provider.');
        }
        $groupId = trim((string)($integration['id_gruppo'] ?? ''));
        if ($groupId === '') {
            throw new RuntimeException('Gruppo didattico collegato senza id_gruppo.');
        }

        return $groupId;
    }

    /**
     * @param list<array<string,mixed>> $externalRows  righe con external_user_id più campi opzionali (display_name, email, voto...)
     * @return array{group_id:string, rows:list<array<string,mixed>>}
     */
    public function resolve(string $provider, string $contextId, array $externalRows): array
    {
        $groupId = $this->resolveGroupId($provider, $contextId);

        $roster = [];
        foreach ($externalRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $externalId = trim((string)($row['external_user_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $entry = ['external_user_id' => $externalId];
            foreach (['display_name', 'email'] as $label) {
                if (isset($row[$label]) && is_scalar($row[$label])) {
                    $entry[$label] = (string)$row[$label];
                }
            }
            $roster[] = $entry;
        }

        $synced = $this->students->syncRoster($groupId, $provider, $contextId, $roster);
        $map = [];
        foreach ($synced as $syncedRow) {
            $externalId = trim((string)($syncedRow['external_user_id'] ?? ''));
            $studentId = trim((string)($syncedRow['id_studente'] ?? ''));
            if ($externalId !== '' && $studentId !== '') {
                $map[$externalId] = $studentId;
            }
        }

        $rows = [];
        foreach ($externalRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $externalId = trim((string)($row['external_user_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $out = $row;
            $out['id_studente'] = $map[$externalId] ?? null;
            $rows[] = $out;
        }

        return ['group_id' => $groupId, 'rows' => $rows];
    }
}
