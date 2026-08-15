<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Pure mapping helpers shared by the legacy JSON restore command and tests.
 * The mapper never writes to a database and never exposes secret values.
 */
final class LegacyJsonRestoreMapper
{
    public function __construct(
        private readonly string $sourceOwnerId,
        private readonly string $targetOwnerId
    ) {
    }

    /** @param array<string,mixed> $row */
    public function belongsToSource(array $row): bool
    {
        foreach (['id_utente', 'id_utente_owner', 'id_utente_invitato'] as $field) {
            if (array_key_exists($field, $row) && (string)$row[$field] === $this->sourceOwnerId) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function ownedRows(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $this->belongsToSource($row)));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function rewriteOwner(array $row): array
    {
        foreach (['id_utente', 'id_utente_owner', 'id_utente_invitato'] as $field) {
            if (array_key_exists($field, $row) && (string)$row[$field] === $this->sourceOwnerId) {
                $row[$field] = $this->targetOwnerId;
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $row @param list<string> $allowed @return array<string,mixed> */
    public function onlyAllowedColumns(array $row, array $allowed): array
    {
        return array_intersect_key($row, array_flip($allowed));
    }

    public function sourceOwnerId(): string
    {
        return $this->sourceOwnerId;
    }

    public function targetOwnerId(): string
    {
        return $this->targetOwnerId;
    }
}

