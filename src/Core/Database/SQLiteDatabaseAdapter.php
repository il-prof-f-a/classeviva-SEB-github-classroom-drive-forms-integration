<?php

namespace App\Core\Database;

use App\Core\SchemaDefinitions;
use PDO;
use PDOException;
use Exception;

/**
 * SQLiteDatabaseAdapter - Implementazione dell'adapter per SQLite
 *
 * Utilizza PDO per gestire il database SQLite.
 * Mappa i "fogli" Excel in tabelle SQL.
 * Molto più performante di Excel per grandi volumi di dati.
 *
 * VANTAGGI:
 * - Performance eccellenti (query veloci, indici)
 * - Supporto transazioni ACID
 * - Query SQL native (WHERE, JOIN, etc.)
 * - File singolo portabile
 * - Zero configurazione server
 */
class SQLiteDatabaseAdapter implements DatabaseAdapterInterface
{
    private array $config;
    private ?PDO $pdo = null;
    private string $dbFilePath;

    /**
     * Costruttore
     *
     * @param array $config Configurazione del database
     * @throws Exception Se la configurazione è invalida
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Path del database SQLite
        $this->dbFilePath = ROOT_PATH . '/' . ($config['database']['sqlite']['file'] ?? 'database/uda_master.db');

        // Verifica che PDO SQLite sia disponibile
        if (!extension_loaded('pdo_sqlite')) {
            throw new Exception("Estensione PDO SQLite non disponibile. Installare: php-sqlite3");
        }

        // Connetti al database
        $this->connect();
    }

    /**
     * Connette al database SQLite
     *
     * @throws Exception Se la connessione fallisce
     */
    private function connect(): void
    {
        try {
            // Crea directory se non esiste
            $dir = dirname($this->dbFilePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Connetti
            $this->pdo = new PDO('sqlite:' . $this->dbFilePath);

            // Imposta modalità errori
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Abilita foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            // Performance optimization
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');

        } catch (PDOException $e) {
            throw new Exception("Impossibile connettersi a SQLite: " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(string $sheetName): array
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            // Verifica che la tabella esista
            if (!$this->sheetExists($sheetName)) {
                return [];
            }

            $stmt = $this->pdo->query("SELECT * FROM {$tableName}");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            throw new Exception("Errore lettura da '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findWhere(string $sheetName, array $where): array
    {
        if (empty($where)) {
            return $this->findAll($sheetName);
        }

        try {
            $tableName = $this->sanitizeTableName($sheetName);

            // Verifica che la tabella esista
            if (!$this->sheetExists($sheetName)) {
                return [];
            }

            // Costruisci WHERE clause
            $conditions = [];
            $params = [];
            foreach ($where as $field => $value) {
                $conditions[] = "{$field} = ?";
                $params[] = $value;
            }

            $whereClause = implode(' AND ', $conditions);
            $sql = "SELECT * FROM {$tableName} WHERE {$whereClause}";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            throw new Exception("Errore query su '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findOne(string $sheetName, string $keyField, $keyValue): ?array
    {
        $results = $this->findWhere($sheetName, [$keyField => $keyValue]);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function insertRow(string $sheetName, array $data): bool
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            // Assicurati che la tabella esista
            $this->ensureSheetExists($sheetName);

            // Prepara INSERT
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                $tableName,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(array_values($data));

        } catch (PDOException $e) {
            throw new Exception("Errore inserimento in '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            // Prepara UPDATE
            $setClause = [];
            $params = [];
            foreach ($data as $field => $value) {
                $setClause[] = "{$field} = ?";
                $params[] = $value;
            }
            $params[] = $keyValue; // Parametro per WHERE

            $sql = sprintf(
                "UPDATE %s SET %s WHERE %s = ?",
                $tableName,
                implode(', ', $setClause),
                $keyField
            );

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            throw new Exception("Errore aggiornamento '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteRow(string $sheetName, $keyValue, string $keyField = 'id'): bool
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $sql = "DELETE FROM {$tableName} WHERE {$keyField} = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$keyValue]);

            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            throw new Exception("Errore eliminazione da '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function ensureSheetExists(string $sheetName): bool
    {
        if ($this->sheetExists($sheetName)) {
            return true;
        }

        // Verifica che sia definito nello schema
        if (!SchemaDefinitions::isSheetDefined($sheetName)) {
            return false;
        }

        // Crea la tabella
        return $this->createTable($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function createBackup(): string
    {
        $backupDir = ROOT_PATH . '/database/backup';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . '/uda_master_' . date('Y-m-d_H-i-s') . '.db';

        // Copia il file database
        if (!copy($this->dbFilePath, $backupFile)) {
            throw new Exception("Impossibile creare backup");
        }

        return $backupFile;
    }

    /**
     * {@inheritDoc}
     */
    public function initialize(): array
    {
        $report = [
            'created' => [],
            'existing' => [],
            'errors' => []
        ];

        // Crea tutte le tabelle definite nello schema
        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            try {
                $existed = $this->sheetExists($sheetName);

                if (!$existed) {
                    $this->createTable($sheetName);
                    $report['created'][] = $sheetName;
                } else {
                    $report['existing'][] = $sheetName;
                }
            } catch (Exception $e) {
                $report['errors'][] = "Errore creazione tabella '{$sheetName}': " . $e->getMessage();
            }
        }

        return $report;
    }

    /**
     * {@inheritDoc}
     */
    public function validate(): array
    {
        $report = [
            'valid' => true,
            'errors' => [],
            'warnings' => []
        ];

        // Verifica che il file database esista
        if (!file_exists($this->dbFilePath)) {
            $report['valid'] = false;
            $report['errors'][] = "File database non trovato: {$this->dbFilePath}";
            return $report;
        }

        // Verifica tutte le tabelle
        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            if (!$this->sheetExists($sheetName)) {
                $report['warnings'][] = "Tabella mancante: {$sheetName}";
                continue;
            }

            // Verifica colonne
            try {
                $actualColumns = $this->getColumns($sheetName);
                $expectedColumns = $definition['columns'];

                $missingColumns = array_diff($expectedColumns, $actualColumns);
                if (!empty($missingColumns)) {
                    $report['warnings'][] = "Tabella '{$sheetName}' - Colonne mancanti: " . implode(', ', $missingColumns);
                }

                $extraColumns = array_diff($actualColumns, $expectedColumns);
                if (!empty($extraColumns)) {
                    $report['warnings'][] = "Tabella '{$sheetName}' - Colonne extra: " . implode(', ', $extraColumns);
                }
            } catch (Exception $e) {
                $report['errors'][] = "Errore lettura colonne tabella '{$sheetName}': " . $e->getMessage();
                $report['valid'] = false;
            }
        }

        return $report;
    }

    /**
     * {@inheritDoc}
     */
    public function repair(): array
    {
        $report = [
            'fixed' => [],
            'failed' => []
        ];

        // Crea backup
        try {
            $backupFile = $this->createBackup();
            $report['backup_created'] = $backupFile;
        } catch (Exception $e) {
            $report['failed'][] = "Impossibile creare backup: " . $e->getMessage();
            return $report;
        }

        // Crea tabelle mancanti
        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            if (!$this->sheetExists($sheetName)) {
                try {
                    $this->createTable($sheetName);
                    $report['fixed'][] = "Creata tabella mancante: {$sheetName}";
                } catch (Exception $e) {
                    $report['failed'][] = "Impossibile creare tabella '{$sheetName}': " . $e->getMessage();
                }
            } else {
                // Ripara colonne mancanti
                try {
                    $actualColumns = $this->getColumns($sheetName);
                    $expectedColumns = $definition['columns'];
                    $missingColumns = array_diff($expectedColumns, $actualColumns);

                    if (!empty($missingColumns)) {
                        $this->addMissingColumns($sheetName, $missingColumns);
                        $report['fixed'][] = "Aggiunte colonne mancanti a '{$sheetName}': " . implode(', ', $missingColumns);
                    }
                } catch (Exception $e) {
                    $report['failed'][] = "Impossibile riparare colonne tabella '{$sheetName}': " . $e->getMessage();
                }
            }
        }

        return $report;
    }

    /**
     * {@inheritDoc}
     */
    public function getColumns(string $sheetName): array
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $stmt = $this->pdo->query("PRAGMA table_info({$tableName})");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_column($columns, 'name');

        } catch (PDOException $e) {
            throw new Exception("Impossibile leggere colonne da '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function sheetExists(string $sheetName): bool
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $stmt = $this->pdo->prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
            );
            $stmt->execute([$tableName]);

            return $stmt->fetch() !== false;

        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function count(string $sheetName): int
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            if (!$this->sheetExists($sheetName)) {
                return 0;
            }

            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM {$tableName}");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int)$result['count'];

        } catch (PDOException $e) {
            throw new Exception("Errore conteggio righe '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function truncate(string $sheetName): bool
    {
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $this->pdo->exec("DELETE FROM {$tableName}");
            $this->pdo->exec("VACUUM"); // Ottimizza database

            return true;

        } catch (PDOException $e) {
            throw new Exception("Impossibile svuotare tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadExternalFile(string $filePath)
    {
        // Per SQLite, i file esterni sono Excel
        // Usa PhpSpreadsheet per caricarli

        if (!file_exists($filePath)) {
            throw new Exception("File non trovato: {$filePath}");
        }

        if (!is_file($filePath)) {
            throw new Exception("Path non è un file valido: {$filePath}");
        }

        $maxSize = 50 * 1024 * 1024;
        if (filesize($filePath) > $maxSize) {
            throw new Exception("File troppo grande (max 50MB)");
        }

        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new Exception("Path non valido: {$filePath}");
        }

        $allowedExts = ['xlsx', 'xls', 'csv'];
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            try {
                $identified = strtolower(\PhpOffice\PhpSpreadsheet\IOFactory::identify($realPath));
            } catch (Exception $e) {
                $identified = '';
            }

            if (!in_array($identified, $allowedExts)) {
                throw new Exception("Tipo file non supportato: {$ext}");
            }
        }

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($realPath);
        } catch (Exception $e) {
            throw new Exception("Impossibile caricare file: " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadTemplate(string $templateName)
    {
        if (!preg_match('/^[a-zA-Z0-9_. -]+\.xlsx?$/i', $templateName)) {
            throw new Exception("Nome template non valido: {$templateName}");
        }

        $templatePath = ROOT_PATH . '/database/templates/' . $templateName;

        if (!file_exists($templatePath)) {
            $templatePath = ROOT_PATH . '/Materiale/' . $templateName;
            if (!file_exists($templatePath)) {
                throw new Exception("Template non trovato: {$templateName}");
            }
        }

        return $this->loadExternalFile($templatePath);
    }

    /**
     * Crea una tabella SQL per un foglio
     *
     * @param string $sheetName Nome del foglio/tabella
     * @return bool True se creata con successo
     * @throws Exception Se la creazione fallisce
     */
    private function createTable(string $sheetName): bool
    {
        $definition = SchemaDefinitions::getSheetDefinition($sheetName);

        if ($definition === null) {
            throw new Exception("Schema non definito per '{$sheetName}'");
        }

        $tableName = $this->sanitizeTableName($sheetName);
        $columns = $definition['columns'];

        // Crea definizione colonne (tutte TEXT per semplicità)
        $columnsDef = array_map(function($col) {
            return "{$col} TEXT";
        }, $columns);

        $sql = sprintf(
            "CREATE TABLE IF NOT EXISTS %s (%s)",
            $tableName,
            implode(', ', $columnsDef)
        );

        try {
            $this->pdo->exec($sql);

            // Crea indice sulla prima colonna (solitamente ID)
            if (!empty($columns)) {
                $firstCol = $columns[0];
                $indexName = "idx_{$tableName}_{$firstCol}";
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableName}({$firstCol})");
            }

            return true;

        } catch (PDOException $e) {
            throw new Exception("Errore creazione tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Aggiunge colonne mancanti a una tabella
     *
     * @param string $sheetName Nome del foglio/tabella
     * @param array $columns Colonne da aggiungere
     * @throws Exception Se l'aggiunta fallisce
     */
    private function addMissingColumns(string $sheetName, array $columns): void
    {
        $tableName = $this->sanitizeTableName($sheetName);

        try {
            foreach ($columns as $column) {
                $sql = "ALTER TABLE {$tableName} ADD COLUMN {$column} TEXT";
                $this->pdo->exec($sql);
            }
        } catch (PDOException $e) {
            throw new Exception("Impossibile aggiungere colonne a '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Sanitizza il nome di una tabella
     *
     * @param string $sheetName Nome del foglio
     * @return string Nome tabella sanitizzato
     */
    private function sanitizeTableName(string $sheetName): string
    {
        // Rimuovi caratteri non sicuri
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $sheetName);
    }

    /**
     * Ottiene la lista di tutte le tabelle presenti nel database SQLite
     *
     * @return array Array di nomi delle tabelle
     * @throws \Exception Se non è possibile leggere la lista
     */
    public function getAllSheetNames(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            return $tables;
        } catch (\PDOException $e) {
            throw new \Exception("Impossibile ottenere lista tabelle: " . $e->getMessage());
        }
    }

    /**
     * Svuota completamente una tabella rimuovendo tutte le righe
     *
     * @param string $sheetName Nome della tabella
     * @return bool True se lo svuotamento ha successo
     * @throws \Exception Se lo svuotamento fallisce
     */
    public function clearSheet(string $sheetName): bool
    {
        if (!$this->sheetExists($sheetName)) {
            throw new \Exception("Tabella '{$sheetName}' non esiste");
        }

        $tableName = $this->sanitizeTableName($sheetName);

        try {
            $this->pdo->exec("DELETE FROM {$tableName}");

            // Reset autoincrement counter solo se la tabella sqlite_sequence esiste
            // e contiene un record per questa tabella
            try {
                $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='{$tableName}'");
            } catch (\PDOException $e) {
                // Ignora l'errore se sqlite_sequence non esiste (nessun autoincrement nella tabella)
            }

            return true;
        } catch (\PDOException $e) {
            throw new \Exception("Impossibile svuotare tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Crea una nuova tabella con le colonne specificate
     *
     * @param string $sheetName Nome della tabella da creare
     * @param array $columns Array di nomi delle colonne
     * @return bool True se la creazione ha successo
     * @throws \Exception Se la creazione fallisce o la tabella esiste già
     */
    public function createSheet(string $sheetName, array $columns = []): bool
    {
        if ($this->sheetExists($sheetName)) {
            throw new \Exception("Tabella '{$sheetName}' esiste già");
        }

        $tableName = $this->sanitizeTableName($sheetName);

        try {
            // Crea tabella con le colonne specificate
            // Se non sono fornite colonne, crea solo con id
            if (empty($columns)) {
                $columns = ['id'];
            }

            $columnDefs = [];
            foreach ($columns as $column) {
                $columnDefs[] = "{$column} TEXT";
            }

            $sql = "CREATE TABLE {$tableName} (" . implode(', ', $columnDefs) . ")";
            $this->pdo->exec($sql);

            return true;
        } catch (\PDOException $e) {
            throw new \Exception("Impossibile creare tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    // ========================================================================
    // METODI DI CONVENIENZA PER UDA
    // ========================================================================

    public function findAllUDAs(): array
    {
        return $this->findAll('UDA_ANAGRAFICA');
    }

    public function findUDAById(string $id): ?array
    {
        return $this->findOne('UDA_ANAGRAFICA', 'id_uda', $id);
    }

    public function findMaterialiByUDA(string $udaId): array
    {
        return $this->findWhere('MATERIALI', ['id_uda' => $udaId]);
    }

    public function findObiettiviByUDA(string $udaId): array
    {
        return $this->findWhere('OBIETTIVI', ['id_uda' => $udaId]);
    }

    public function findTestByUDA(string $udaId): array
    {
        return $this->findWhere('TEST', ['id_uda' => $udaId]);
    }

    public function findClassiAssegnate(string $udaId): array
    {
        return $this->findWhere('CLASSI_ASSEGNATE', ['id_uda' => $udaId]);
    }

    public function findVotiByUDA(string $udaId): array
    {
        return $this->findWhere('VOTI', ['id_uda' => $udaId]);
    }

    public function insertUDA(array $data): bool
    {
        return $this->insertRow('UDA_ANAGRAFICA', $data);
    }

    public function insertMateriale(array $data): bool
    {
        return $this->insertRow('MATERIALI', $data);
    }

    public function insertObiettivo(array $data): bool
    {
        return $this->insertRow('OBIETTIVI', $data);
    }

    public function insertTest(array $data): bool
    {
        return $this->insertRow('TEST', $data);
    }

    public function insertVoto(array $data): bool
    {
        return $this->insertRow('VOTI', $data);
    }

    public function insertClasseAssegnata(array $data): bool
    {
        return $this->insertRow('CLASSI_ASSEGNATE', $data);
    }

    public function updateUDA(string $id, array $data): bool
    {
        return $this->updateRow('UDA_ANAGRAFICA', 'id_uda', $id, $data);
    }

    public function deleteUDA(string $id): bool
    {
        return $this->deleteRow('UDA_ANAGRAFICA', $id, 'id_uda');
    }

    public function deleteMateriale(string $id): bool
    {
        return $this->deleteRow('MATERIALI', $id, 'id_materiale');
    }

    public function deleteObiettivo(string $id): bool
    {
        return $this->deleteRow('OBIETTIVI', $id, 'id_obiettivo');
    }
}
