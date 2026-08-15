<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

final class GroupStudentRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function add(string $groupId, string $studentId, array $data = []): array
    {
        $where = ['id_utente' => $this->userId, 'id_gruppo' => $groupId, 'id_studente' => $studentId];
        $existing = $this->db->findWhere('GRUPPI_STUDENTI', $where);
        if ($existing !== []) {
            return $existing[0];
        }
        $row = [
            'id_iscrizione' => (string)($data['id_iscrizione'] ?? ('MEM_' . bin2hex(random_bytes(12)))),
            'id_gruppo' => $groupId,
            'id_studente' => $studentId,
            'provider_origine' => (string)($data['provider_origine'] ?? ''),
            'external_context_id' => (string)($data['external_context_id'] ?? ''),
            'stato' => (string)($data['stato'] ?? 'attivo'),
            'data_inizio' => (string)($data['data_inizio'] ?? date('Y-m-d')),
            'data_fine' => (string)($data['data_fine'] ?? ''),
            'ultima_sincronizzazione' => (string)($data['ultima_sincronizzazione'] ?? date('Y-m-d H:i:s')),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('GRUPPI_STUDENTI', $row);
        return $row;
    }

    public function listForGroup(string $groupId): array
    {
        return $this->db->findWhere('GRUPPI_STUDENTI', ['id_utente' => $this->userId, 'id_gruppo' => $groupId]);
    }

    public function listForStudent(string $studentId): array
    {
        return $this->db->findWhere('GRUPPI_STUDENTI', ['id_utente' => $this->userId, 'id_studente' => $studentId]);
    }

    public function reassign(string $membershipId, string $studentId): bool
    {
        return $this->db->updateRow('GRUPPI_STUDENTI', 'id_iscrizione', $membershipId, ['id_studente' => $studentId]);
    }

    public function delete(string $membershipId): bool
    {
        return $this->db->deleteRow('GRUPPI_STUDENTI', $membershipId, 'id_iscrizione');
    }
}
