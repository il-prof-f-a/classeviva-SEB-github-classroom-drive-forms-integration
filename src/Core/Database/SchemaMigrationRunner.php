<?php

declare(strict_types=1);

namespace App\Core\Database;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class SchemaMigrationRunner
{
    public const PROVIDER_NEUTRAL_VERSION = '20260815_001_provider_neutral_domain';

    public function __construct(private DatabaseAdapterInterface $db)
    {
    }

    /** @return list<string> */
    public function pending(): array
    {
        $this->ensureMigrationTable();
        $applied = [];
        foreach ($this->db->findAll('SCHEMA_MIGRATIONS') as $row) {
            $version = trim((string)($row['versione'] ?? ''));
            if ($version !== '') {
                $applied[$version] = true;
            }
        }

        return isset($applied[self::PROVIDER_NEUTRAL_VERSION])
            ? []
            : [self::PROVIDER_NEUTRAL_VERSION];
    }

    /** @return array{applied:list<string>,skipped:list<string>,errors:list<string>,started_at:string,finished_at:string} */
    public function migrate(): array
    {
        $started = (new DateTimeImmutable())->format(DATE_ATOM);
        $report = [
            'applied' => [],
            'skipped' => [],
            'errors' => [],
            'started_at' => $started,
            'finished_at' => $started,
        ];

        try {
            $this->ensureMigrationTable();
            $pending = $this->pending();
            if ($pending === []) {
                // Anche con una versione già registrata, riallinea tabelle e
                // indici: il reset didattico può aver ricreato solo il dominio.
                $this->applyProviderNeutralMigration();
                $report['skipped'][] = self::PROVIDER_NEUTRAL_VERSION;
            } else {
                foreach ($pending as $version) {
                    $this->applyProviderNeutralMigration();
                    $this->db->insertRow('SCHEMA_MIGRATIONS', [
                        'versione' => $version,
                        'descrizione' => 'Dominio gruppi didattici e identità studente provider-neutral',
                        'applicata_il' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                        'checksum' => hash('sha256', json_encode(SchemaIndexDefinitions::all(), JSON_THROW_ON_ERROR)),
                    ]);
                    $report['applied'][] = $version;
                }
            }
        } catch (\Throwable $exception) {
            $report['errors'][] = $exception->getMessage();
        }

        $report['finished_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        return $report;
    }

    private function ensureMigrationTable(): void
    {
        if (!$this->db->sheetExists('SCHEMA_MIGRATIONS')) {
            if (!$this->db->createSheet('SCHEMA_MIGRATIONS', ['versione', 'descrizione', 'applicata_il', 'checksum'])) {
                throw new RuntimeException('Impossibile creare SCHEMA_MIGRATIONS.');
            }
        }
    }

    private function applyProviderNeutralMigration(): void
    {
        $this->db->initialize();
        $connection = $this->db->getConnection();
        if (!$connection instanceof PDO) {
            throw new RuntimeException('La migrazione provider-neutral richiede una connessione PDO.');
        }

        $driver = (string)$connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = static fn(string $identifier): string => $driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';

        foreach (SchemaIndexDefinitions::all() as $index) {
            $table = (string)$index['table'];
            // Gli adapter MySQL creano colonne TEXT per mantenere lo schema
            // portabile; gli indici su TEXT richiedono quindi una lunghezza.
            $columns = array_map(
                static fn(string $column): string => $driver === 'mysql'
                    ? $quote($column) . '(191)'
                    : $quote($column),
                $index['columns']
            );
            $unique = $index['unique'] ? 'UNIQUE ' : '';
            $indexName = (string)$index['name'];
            if ($driver === 'mysql') {
                $check = $connection->prepare(
                    'SELECT 1 FROM information_schema.statistics '
                    . 'WHERE table_schema = DATABASE() AND table_name = :table_name '
                    . 'AND index_name = :index_name LIMIT 1'
                );
                $check->execute(['table_name' => $table, 'index_name' => $indexName]);
                if ($check->fetchColumn() !== false) {
                    continue;
                }
                $sql = sprintf(
                    'CREATE %sINDEX %s ON %s (%s)',
                    $unique,
                    $quote($indexName),
                    $quote($table),
                    implode(', ', $columns)
                );
            } else {
                $sql = sprintf(
                    'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
                    $unique,
                    $quote($indexName),
                    $quote($table),
                    implode(', ', $columns)
                );
            }
            $connection->exec($sql);
        }
    }
}
