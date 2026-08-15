<?php

declare(strict_types=1);

namespace App\Core\Database;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class TeachingDomainReset
{
    /** @var list<string> */
    private const PRESERVED_TABLES = [
        'UTENTI',
        'INTEGRAZIONI_UTENTE',
        'OBIETTIVI_MASTER',
        'INDICATORI_LABORATORIO',
        'CATEGORIE_COMPETENZE',
        'GITHUB_REPO_TEMPLATES',
        'SCHEMA_MIGRATIONS',
    ];

    /** @var list<string> */
    private const DROPPED_TABLES = [
        'UDA_ANAGRAFICA', 'UDA_GRUPPI', 'UDA_PUBBLICAZIONI',
        'GRUPPI_DIDATTICI', 'GRUPPI_INTEGRAZIONI',
        'STUDENTI', 'STUDENTI_IDENTITA_ESTERNE', 'GRUPPI_STUDENTI',
        'STUDENTI_RISORSE_ESTERNE', 'MATERIALI', 'OBIETTIVI',
        'RUBRICA', 'RUBRICA_DETTAGLI', 'VALUTAZIONI_RUBRICA',
        'VALUTAZIONI_LABORATORIO', 'PLUSMINUS_QUEUE', 'VOTI',
        'DOMANDE_INTERROGAZIONE', 'TEST', 'TEST_CBM_MAPPING',
        'TEST_CBM_RISPOSTE', 'GITHUB_ASSIGNMENTS', 'GITHUB_SUBMISSIONS',
        'GITHUB_REPO_LOC_SNAPSHOTS', 'UDA_CONDIVISIONI', 'INVITI_UDA',
        'CLASSI_ASSEGNATE', 'CLASSI', 'CLASSROOM_MAPPINGS',
        'GITHUB_CLASSROOMS', 'MAPPATURA_STUDENTI',
        'GITHUB_ASSIGNMENT_STUDENT_MAP',
    ];

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $environment = 'development'
    ) {
    }

    /** @return array<string,mixed> */
    public function dryRun(): array
    {
        return [
            'success' => true,
            'database_type' => $this->db->getConnection() instanceof PDO
                ? $this->db->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME)
                : 'unknown',
            'environment' => $this->environment,
            'preserved_tables' => self::PRESERVED_TABLES,
            'dropped_tables' => self::DROPPED_TABLES,
            'created_tables' => array_keys(\App\Core\SchemaDefinitions::getAllSheets()),
            'row_counts_before' => $this->rowCounts(self::PRESERVED_TABLES),
            'row_counts_after' => [],
            'schema_version' => SchemaMigrationRunner::PROVIDER_NEUTRAL_VERSION,
            'errors' => [],
        ];
    }

    /** @return array<string,mixed> */
    public function apply(): array
    {
        if (strtolower($this->environment) === 'production') {
            throw new RuntimeException('Reset didattico vietato in ambiente production.');
        }

        $report = $this->dryRun();
        $connection = $this->db->getConnection();
        if (!$connection instanceof PDO) {
            throw new RuntimeException('Il reset richiede una connessione PDO.');
        }

        $driver = (string)$connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = static fn(string $identifier): string => $driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';

        // SQLite consente di racchiudere il reset in una transazione.
        // MySQL invece esegue un commit implicito sui DDL (DROP/CREATE TABLE),
        // quindi una transazione esplicita renderebbe il commit finale
        // non valido dopo il primo DROP.
        $transactional = $driver !== 'mysql';
        if ($transactional) {
            $connection->beginTransaction();
        }
        try {
            foreach (self::DROPPED_TABLES as $table) {
                $connection->exec('DROP TABLE IF EXISTS ' . $quote($table));
            }
            $migrationReport = (new SchemaMigrationRunner($this->db))->migrate();
            if (($migrationReport['errors'] ?? []) !== []) {
                throw new RuntimeException(implode('; ', $migrationReport['errors']));
            }
            if ($transactional && $connection->inTransaction()) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($transactional && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        $report['success'] = true;
        $report['created_tables'] = array_keys(\App\Core\SchemaDefinitions::getAllSheets());
        $report['row_counts_after'] = $this->rowCounts(self::DROPPED_TABLES);
        $report['finished_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        return $report;
    }

    /** @param list<string> $tables */
    private function rowCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            try {
                $counts[$table] = $this->db->sheetExists($table) ? $this->db->count($table) : 0;
            } catch (\Throwable) {
                $counts[$table] = null;
            }
        }
        return $counts;
    }
}
