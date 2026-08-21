<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class MaterialAccessService
{
    public function __construct(private DatabaseAdapterInterface $db)
    {
    }

    /** @param list<string> $materialIds @return list<array<string,string>> */
    public function resolveForUda(string $userId, string $udaId, array $materialIds): array
    {
        $resolved = [];
        foreach (array_values(array_unique(array_map('strval', $materialIds))) as $materialId) {
            if ($materialId === '') continue;
            $row = $this->db->findOne('MATERIALI', 'id_materiale', $materialId);
            if (!is_array($row)
                || (string)($row['id_utente'] ?? '') !== $userId
                || (string)($row['id_uda'] ?? '') !== $udaId) {
                throw new RuntimeException('Materiale non disponibile');
            }
            $driveId = (string)($row['drive_file_id'] ?? $row['file_id_drive'] ?? '');
            if ($driveId === '') {
                $url = (string)($row['url_drive'] ?? $row['url'] ?? '');
                if (preg_match('/(?:id=|\/d\/)([A-Za-z0-9_-]{10,})/', $url, $match)) {
                    $driveId = $match[1];
                }
            }
            if ($driveId === '') {
                throw new RuntimeException('Materiale non disponibile');
            }
            $resolved[] = [
                'material_id' => $materialId,
                'drive_file_id' => $driveId,
                'name' => (string)($row['titolo'] ?? $row['nome'] ?? $materialId),
                'mime_type' => (string)($row['mime_type'] ?? $row['tipo'] ?? 'application/octet-stream'),
            ];
        }
        return $resolved;
    }
}
