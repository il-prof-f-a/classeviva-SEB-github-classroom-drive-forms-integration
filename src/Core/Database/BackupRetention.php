<?php

declare(strict_types=1);

namespace App\Core\Database;

use DateTimeImmutable;
use DirectoryIterator;
use RuntimeException;

/**
 * Mantiene sotto controllo i backup locali creati dagli adapter SQL.
 *
 * La pulizia è intenzionalmente non ricorsiva e considera soltanto i nomi
 * prodotti dagli adapter: non deve rimuovere export, log o file runtime.
 */
final class BackupRetention
{
    private const BACKUP_FILE_PATTERN = '/^uda_(?:mysql_[^\/]+\.json|master_[^\/]+\.db)$/';

    public static function prune(
        string $directory,
        int $retentionDays,
        ?DateTimeImmutable $now = null
    ): int {
        if (!is_dir($directory)) {
            return 0;
        }

        $cutoff = ($now ?? new DateTimeImmutable('now'))->modify(
            '-' . max(0, $retentionDays) . ' days'
        )->getTimestamp();
        $removed = 0;

        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || !$entry->isFile() || $entry->isLink()) {
                continue;
            }

            $name = $entry->getFilename();
            if (!preg_match(self::BACKUP_FILE_PATTERN, $name)) {
                continue;
            }

            $modifiedAt = $entry->getMTime();
            if ($modifiedAt >= $cutoff) {
                continue;
            }

            if (!unlink($entry->getPathname())) {
                throw new RuntimeException("Impossibile rimuovere il backup scaduto: {$name}");
            }

            $removed++;
        }

        return $removed;
    }
}
