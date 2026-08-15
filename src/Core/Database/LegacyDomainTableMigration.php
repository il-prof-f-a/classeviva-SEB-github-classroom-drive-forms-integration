<?php

declare(strict_types=1);

namespace App\Core\Database;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Rimozione esplicita delle sei tabelle storiche sostituite dalle facade
 * provider-neutral. Non viene eseguita automaticamente all'avvio.
 */
final class LegacyDomainTableMigration
{
    public const CONFIRMATION = 'REMOVE-LEGACY-TABLES';
    public const VERSION = '20260815_002_remove_legacy_domain_tables';

    /** @var list<string> */
    public const TABLES = [
        'GITHUB_ASSIGNMENT_STUDENT_MAP',
        'MAPPATURA_STUDENTI',
        'GITHUB_CLASSROOMS',
        'CLASSROOM_MAPPINGS',
        'CLASSI_ASSEGNATE',
        'CLASSI',
    ];

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $environment = 'development',
        private string $target = 'local'
    ) {
    }

    /** @return array<string,mixed> */
    public function dryRun(): array
    {
        $connection = $this->connection();
        $driver = (string)$connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $existing = [];
        foreach (self::TABLES as $table) {
            $exists = $this->db->sheetExists($table);
            $existing[$table] = [
                'exists' => $exists,
                'rows' => $exists ? $this->rowCount($connection, $driver, $table) : 0,
            ];
        }

        $blocked = strtolower(trim($this->environment)) === 'production'
            || strtolower(trim($this->target)) === 'production';

        return [
            'success' => !$blocked,
            'mode' => 'dry-run',
            'environment' => $this->environment,
            'target' => $this->target,
            'database_type' => $driver,
            'tables' => self::TABLES,
            'migration_version' => self::VERSION,
            'already_applied' => $this->db->findOne('SCHEMA_MIGRATIONS', 'versione', self::VERSION) !== null,
            'existing' => $existing,
            'blocked' => $blocked,
            'errors' => $blocked ? ['Migrazione vietata in ambiente production.'] : [],
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(): array
    {
        $report = $this->dryRun();
        if (($report['blocked'] ?? false) === true) {
            throw new RuntimeException('Migrazione legacy vietata in ambiente production.');
        }

        $connection = $this->connection();
        $driver = (string)$connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $transactional = $driver !== 'mysql';
        $dropped = [];
        $foreignKeysDisabled = false;

        if ($transactional) {
            $connection->beginTransaction();
        } elseif ($driver === 'mysql') {
            $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
            $foreignKeysDisabled = true;
        }

        try {
            foreach (self::TABLES as $table) {
                if (!$this->db->sheetExists($table)) {
                    continue;
                }
                $connection->exec('DROP TABLE IF EXISTS ' . $this->quote($driver, $table));
                $dropped[] = $table;
            }

            if ($transactional && $connection->inTransaction()) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($transactional && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        } finally {
            if ($foreignKeysDisabled) {
                $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        if ($this->db->findOne('SCHEMA_MIGRATIONS', 'versione', self::VERSION) === null) {
            $this->db->insertRow('SCHEMA_MIGRATIONS', [
                'versione' => self::VERSION,
                'descrizione' => 'Rimozione esplicita delle tabelle legacy del dominio didattico',
                'applicata_il' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'checksum' => hash('sha256', implode('|', self::TABLES)),
            ]);
        }

        $report['success'] = true;
        $report['mode'] = 'apply';
        $report['dropped'] = $dropped;
        $report['finished_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        return $report;
    }

    private function connection(): PDO
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof PDO) {
            throw new RuntimeException('La migrazione richiede una connessione PDO SQL.');
        }
        return $connection;
    }

    private function rowCount(PDO $connection, string $driver, string $table): int
    {
        $stmt = $connection->query('SELECT COUNT(*) FROM ' . $this->quote($driver, $table));
        return (int)$stmt->fetchColumn();
    }

    private function quote(string $driver, string $identifier): string
    {
        return $driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }
}
