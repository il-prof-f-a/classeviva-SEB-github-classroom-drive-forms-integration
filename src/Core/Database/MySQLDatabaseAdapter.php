<?php

namespace App\Core\Database;

use App\Core\SchemaDefinitions;
use PDO;
use PDOException;
use Exception;

/**
 * MySQLDatabaseAdapter - Implementazione dell'adapter per MySQL
 *
 * Utilizza PDO per gestire un database MySQL/MariaDB.
 * Mappa i "fogli" definiti in SchemaDefinitions in tabelle SQL.
 */
class MySQLDatabaseAdapter implements DatabaseAdapterInterface
{
    private array $config;
    private ?PDO $pdo = null;
    private string $host;
    private int $port;
    private string $database;
    private string $username;
    private string $password;
    private string $charset;

    /**
     * Costruttore
     *
     * @param array $config Configurazione completa dell'app (bootstrap.php)
     * @throws Exception Se la configurazione è invalida o la connessione fallisce
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        $dbConf = $config['database']['mysql'] ?? [];

        $this->host = (string)($dbConf['host'] ?? '127.0.0.1');
        $this->port = (int)($dbConf['port'] ?? 3306);
        $this->database = (string)($dbConf['database'] ?? '');
        $this->username = (string)($dbConf['username'] ?? '');
        $this->password = (string)($dbConf['password'] ?? '');
        $this->charset = (string)($dbConf['charset'] ?? 'utf8mb4');

        if (empty($this->database)) {
            throw new Exception("Configurazione MySQL mancante: database non specificato (DB_DATABASE)");
        }

        if (!extension_loaded('pdo_mysql')) {
            throw new Exception("Estensione PDO MySQL non disponibile. Abilita pdo_mysql in php.ini");
        }

        $this->connect();
    }

    /**
     * Connessione a MySQL tramite PDO
     *
     * @throws Exception
     */
    private function connect(): void
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->host,
                $this->port,
                $this->database,
                $this->charset
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);

        } catch (PDOException $e) {
            throw new Exception("Impossibile connettersi a MySQL: " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(string $sheetName): array
    {
        if ($sheetName === 'CLASSI_ASSEGNATE') {
            return \App\Core\LegacyUdaDataGateway::findAllClassiAssegnate($this);
        }
        if ($sheetName === 'CLASSI') {
            return \App\Utils\LegacyTeachingGroupView::classes($this);
        }
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::legacyRows($this, 'google_classroom');
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::legacyRows($this, 'github_classroom');
        }
        if ($sheetName === 'MAPPATURA_STUDENTI') {
            return \App\Core\LegacyStudentMappingGateway::findAll($this);
        }
        if ($sheetName === 'GITHUB_ASSIGNMENT_STUDENT_MAP') {
            return \App\Core\LegacyGithubStudentMapGateway::findAll($this);
        }
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            if (!$this->sheetExists($sheetName)) {
                return [];
            }

            $stmt = $this->pdo->query("SELECT * FROM `{$tableName}`");
            return \App\Core\StudentReferenceGateway::exposeRows($this, $sheetName, $stmt->fetchAll(PDO::FETCH_ASSOC));

        } catch (PDOException $e) {
            throw new Exception("Errore lettura da '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findWhere(string $sheetName, array $where): array
    {
        if ($sheetName === 'CLASSI_ASSEGNATE') {
            return \App\Core\LegacyUdaDataGateway::findClassiAssegnateWhere($this, $where);
        }
        if ($sheetName === 'CLASSI') {
            return \App\Utils\LegacyTeachingGroupView::filter(
                \App\Utils\LegacyTeachingGroupView::classes($this),
                $where
            );
        }
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::filterLegacyRows(
                \App\Core\ProviderNeutralMappingService::legacyRows($this, 'google_classroom'), $where
            );
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::filterLegacyRows(
                \App\Core\ProviderNeutralMappingService::legacyRows($this, 'github_classroom'), $where
            );
        }
        if ($sheetName === 'MAPPATURA_STUDENTI') {
            return \App\Core\LegacyStudentMappingGateway::findWhere($this, $where);
        }
        if ($sheetName === 'GITHUB_ASSIGNMENT_STUDENT_MAP') {
            return \App\Core\LegacyGithubStudentMapGateway::findWhere($this, $where);
        }
        if (\App\Core\StudentReferenceGateway::handles($sheetName)
            && \App\Core\StudentReferenceGateway::hasExternalCriteria($where)) {
            return \App\Core\StudentReferenceGateway::filterExposed(
                \App\Core\StudentReferenceGateway::exposeRows($this, $sheetName, $this->findAll($sheetName)),
                $where
            );
        }
        if (empty($where)) {
            return $this->findAll($sheetName);
        }

        try {
            $tableName = $this->sanitizeTableName($sheetName);

            if (!$this->sheetExists($sheetName)) {
                return [];
            }

            $conditions = [];
            $params = [];
            foreach ($where as $field => $value) {
                $conditions[] = "`{$field}` = ?";
                $params[] = $value;
            }

            $whereClause = implode(' AND ', $conditions);
            $sql = "SELECT * FROM `{$tableName}` WHERE {$whereClause}";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return \App\Core\StudentReferenceGateway::exposeRows(
                $this,
                $sheetName,
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );

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
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::insertLegacy($this, 'google_classroom', $data);
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::insertLegacy($this, 'github_classroom', $data);
        }
        if ($sheetName === 'MAPPATURA_STUDENTI') {
            return \App\Core\LegacyStudentMappingGateway::insert($this, $data);
        }
        if ($sheetName === 'GITHUB_ASSIGNMENT_STUDENT_MAP') {
            return \App\Core\LegacyGithubStudentMapGateway::insert($this, $data);
        }
        if (\App\Core\StudentReferenceGateway::handles($sheetName)) {
            $data = \App\Core\StudentReferenceGateway::normalizeWrite($this, $sheetName, $data);
        }
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $this->ensureSheetExists($sheetName);

            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $sql = sprintf(
                "INSERT INTO `%s` (%s) VALUES (%s)",
                $tableName,
                implode(', ', array_map(fn($c) => "`{$c}`", $columns)),
                implode(', ', $placeholders)
            );

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($data));

            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            throw new Exception("Errore inserimento in '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool
    {
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::updateLegacy($this, 'google_classroom', (string)$keyValue, $data);
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::updateLegacy($this, 'github_classroom', (string)$keyValue, $data);
        }
        if ($sheetName === 'MAPPATURA_STUDENTI') {
            return \App\Core\LegacyStudentMappingGateway::update($this, (string)$keyValue, $data);
        }
        if ($sheetName === 'GITHUB_ASSIGNMENT_STUDENT_MAP') {
            return \App\Core\LegacyGithubStudentMapGateway::update($this, (string)$keyValue, $data);
        }
        if (\App\Core\StudentReferenceGateway::handles($sheetName)) {
            $data = \App\Core\StudentReferenceGateway::normalizeWrite($this, $sheetName, $data);
        }
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $setClause = [];
            $params = [];
            foreach ($data as $field => $value) {
                $setClause[] = "`{$field}` = ?";
                $params[] = $value;
            }
            $params[] = $keyValue;

            $sql = sprintf(
                "UPDATE `%s` SET %s WHERE `%s` = ?",
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
        if ($sheetName === 'CLASSROOM_MAPPINGS') {
            return \App\Core\ProviderNeutralMappingService::deleteLegacy($this, 'google_classroom', (string)$keyValue);
        }
        if ($sheetName === 'GITHUB_CLASSROOMS') {
            return \App\Core\ProviderNeutralMappingService::deleteLegacy($this, 'github_classroom', (string)$keyValue);
        }
        try {
            $tableName = $this->sanitizeTableName($sheetName);

            $sql = "DELETE FROM `{$tableName}` WHERE `{$keyField}` = ?";
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

        if (!SchemaDefinitions::isSheetDefined($sheetName)) {
            return false;
        }

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
     *
     * Per MySQL crea un file JSON con tutte le tabelle e i dati.
     */
    public function createBackup(): string
    {
        $backupDir = ROOT_PATH . '/database/backup';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . '/uda_mysql_' . date('Y-m-d_H-i-s') . '.json';

        $backupData = [
            'database' => $this->database,
            'created_at' => date('c'),
            'tables' => []
        ];

        $allSheets = SchemaDefinitions::getAllSheets();
        foreach ($allSheets as $sheetName => $definition) {
            if ($this->sheetExists($sheetName)) {
                $backupData['tables'][$sheetName] = [
                    'columns' => $this->getColumns($sheetName),
                    'rows' => $this->findAll($sheetName)
                ];
            }
        }

        if (file_put_contents($backupFile, json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new Exception("Impossibile scrivere file di backup: {$backupFile}");
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

        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            if (!$this->sheetExists($sheetName)) {
                $report['warnings'][] = "Tabella mancante: {$sheetName}";
                continue;
            }

            // Se per qualche motivo lo schema non definisce esplicitamente le colonne,
            // salta la validazione per evitare warning PHP.
            if (!isset($definition['columns']) || !is_array($definition['columns'])) {
                $report['warnings'][] = "Schema '{$sheetName}' senza chiave 'columns': validazione saltata";
                continue;
            }

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

        try {
            $backupFile = $this->createBackup();
            $report['backup_created'] = $backupFile;
        } catch (Exception $e) {
            $report['failed'][] = "Impossibile creare backup: " . $e->getMessage();
            return $report;
        }

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
                // Come per validate(), se lo schema non ha 'columns' saltiamo il repair strutturale
                if (!isset($definition['columns']) || !is_array($definition['columns'])) {
                    $report['failed'][] = "Schema '{$sheetName}' senza chiave 'columns': impossibile riparare colonne automaticamente";
                    continue;
                }

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

            $sql = "SELECT COLUMN_NAME FROM information_schema.columns 
                    WHERE table_schema = :db AND table_name = :table
                    ORDER BY ORDINAL_POSITION";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':db' => $this->database,
                ':table' => $tableName
            ]);

            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return $columns ?: [];

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

            $sql = "SELECT TABLE_NAME FROM information_schema.tables 
                    WHERE table_schema = :db AND table_name = :table
                    LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':db' => $this->database,
                ':table' => $tableName
            ]);

            return $stmt->fetchColumn() !== false;

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

            $stmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM `{$tableName}`");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['cnt'] ?? 0);

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
            $this->pdo->exec("TRUNCATE TABLE `{$tableName}`");
            return true;
        } catch (PDOException $e) {
            throw new Exception("Impossibile svuotare tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAllSheetNames(): array
    {
        $names = [];
        $allSheets = SchemaDefinitions::getAllSheets();
        foreach ($allSheets as $sheetName => $def) {
            if ($this->sheetExists($sheetName)) {
                $names[] = $sheetName;
            }
        }
        return $names;
    }

    /**
     * {@inheritDoc}
     */
    public function clearSheet(string $sheetName): bool
    {
        if (!$this->sheetExists($sheetName)) {
            throw new Exception("Tabella '{$sheetName}' non esiste");
        }

        $tableName = $this->sanitizeTableName($sheetName);

        try {
            $this->pdo->exec("DELETE FROM `{$tableName}`");
            return true;
        } catch (PDOException $e) {
            throw new Exception("Impossibile svuotare tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function createSheet(string $sheetName, array $columns = []): bool
    {
        if ($this->sheetExists($sheetName)) {
            throw new Exception("Tabella '{$sheetName}' esiste già");
        }

        $tableName = $this->sanitizeTableName($sheetName);

        try {
            if (empty($columns)) {
                $columns = ['id'];
            }

            $columnDefs = [];
            foreach ($columns as $column) {
                $columnDefs[] = "`{$column}` TEXT NULL";
            }

            $sql = "CREATE TABLE `{$tableName}` (" . implode(', ', $columnDefs) . ")";
            $this->pdo->exec($sql);

            return true;
        } catch (PDOException $e) {
            throw new Exception("Impossibile creare tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Crea una tabella per uno sheet definito in SchemaDefinitions
     *
     * @throws Exception
     */
    private function createTable(string $sheetName): bool
    {
        $definition = SchemaDefinitions::getSheetDefinition($sheetName);
        if ($definition === null) {
            throw new Exception("Schema non definito per '{$sheetName}'");
        }

        $tableName = $this->sanitizeTableName($sheetName);
        $columns = $definition['columns'];

        $columnsDef = array_map(function ($col) {
            return "`{$col}` TEXT NULL";
        }, $columns);

        $sql = sprintf(
            "CREATE TABLE IF NOT EXISTS `%s` (%s)",
            $tableName,
            implode(', ', $columnsDef)
        );

        try {
            $this->pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            throw new Exception("Errore creazione tabella '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Aggiunge colonne mancanti a una tabella
     */
    private function addMissingColumns(string $sheetName, array $columns): void
    {
        $tableName = $this->sanitizeTableName($sheetName);

        try {
            foreach ($columns as $column) {
                $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$column}` TEXT NULL";
                $this->pdo->exec($sql);
            }
        } catch (PDOException $e) {
            throw new Exception("Impossibile aggiungere colonne a '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Sanitizza il nome di una tabella
     */
    private function sanitizeTableName(string $sheetName): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $sheetName);
    }

    // ========================================================================
    // METODI DI CONVENIENZA PER UDA (come SQLiteDatabaseAdapter)
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
        return \App\Core\LegacyUdaDataGateway::findClassiAssegnate($this, $udaId);
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
        return \App\Core\LegacyUdaDataGateway::insertClasseAssegnata($this, $data);
    }

    public function deleteClasseAssegnata(string $id): bool
    {
        return \App\Core\LegacyUdaDataGateway::deleteClasseAssegnata($this, $id);
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
