<?php

namespace App\Core\Database;

use App\Core\DatabaseManager;
use App\Core\SchemaDefinitions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Exception;

/**
 * ExcelDatabaseAdapter - Implementazione dell'adapter per file Excel locali
 *
 * Wrappa il DatabaseManager esistente e implementa DatabaseAdapterInterface.
 * Permette di gestire il database Excel locale in modo trasparente.
 */
class ExcelDatabaseAdapter implements DatabaseAdapterInterface
{
    private DatabaseManager $manager;
    private string $dbFilePath;
    private array $config;

    /**
     * Costruttore
     *
     * @param array $config Configurazione del database
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->dbFilePath = ROOT_PATH . '/' . $config['database']['master_file'];

        // Inizializza il DatabaseManager esistente
        $this->manager = new DatabaseManager($config);
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(string $sheetName): array
    {
        return $this->manager->findAll($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function findWhere(string $sheetName, array $where): array
    {
        return $this->manager->findWhere($sheetName, $where);
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
        return $this->manager->insertRow($sheetName, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool
    {
        return $this->manager->updateRow($sheetName, $keyField, $keyValue, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteRow(string $sheetName, $keyValue, string $keyField = 'id'): bool
    {
        return $this->manager->deleteRow($sheetName, $keyValue, $keyField);
    }

    /**
     * {@inheritDoc}
     */
    public function ensureSheetExists(string $sheetName): bool
    {
        return $this->manager->ensureSheetExists($sheetName);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->manager->getConnection();
    }

    /**
     * {@inheritDoc}
     */
    public function createBackup(): string
    {
        return $this->manager->createBackup();
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

        // Verifica che il file esista
        if (!file_exists($this->dbFilePath)) {
            // Crea il file Excel se non esiste
            try {
                $this->createDatabaseFile();
                $report['file_created'] = true;
            } catch (Exception $e) {
                $report['errors'][] = "Impossibile creare file database: " . $e->getMessage();
                return $report;
            }
        } else {
            $report['file_created'] = false;
        }

        // Itera su tutti i fogli definiti nello schema e assicurati che esistano
        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            try {
                $existed = $this->sheetExists($sheetName);

                if (!$existed) {
                    $this->ensureSheetExists($sheetName);
                    $report['created'][] = $sheetName;
                } else {
                    $report['existing'][] = $sheetName;
                }
            } catch (Exception $e) {
                $report['errors'][] = "Errore durante la creazione del foglio '{$sheetName}': " . $e->getMessage();
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

        // Verifica che il file esista
        if (!file_exists($this->dbFilePath)) {
            $report['valid'] = false;
            $report['errors'][] = "File database non trovato: {$this->dbFilePath}";
            return $report;
        }

        // Verifica che sia un file Excel valido
        try {
            $spreadsheet = IOFactory::load($this->dbFilePath);
        } catch (Exception $e) {
            $report['valid'] = false;
            $report['errors'][] = "File database corrotto: " . $e->getMessage();
            return $report;
        }

        // Verifica che tutti i fogli definiti nello schema esistano
        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            if (!$this->sheetExists($sheetName)) {
                $report['warnings'][] = "Foglio mancante: {$sheetName}";
                continue;
            }

            // Verifica che le colonne siano corrette
            try {
                $actualColumns = $this->getColumns($sheetName);
                $expectedColumns = $definition['columns'];

                // Verifica colonne mancanti
                $missingColumns = array_diff($expectedColumns, $actualColumns);
                if (!empty($missingColumns)) {
                    $report['warnings'][] = "Foglio '{$sheetName}' - Colonne mancanti: " . implode(', ', $missingColumns);
                }

                // Verifica colonne extra
                $extraColumns = array_diff($actualColumns, $expectedColumns);
                if (!empty($extraColumns)) {
                    $report['warnings'][] = "Foglio '{$sheetName}' - Colonne extra: " . implode(', ', $extraColumns);
                }
            } catch (Exception $e) {
                $report['errors'][] = "Errore lettura colonne foglio '{$sheetName}': " . $e->getMessage();
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

        // Prima crea backup
        try {
            $backupFile = $this->createBackup();
            $report['backup_created'] = $backupFile;
        } catch (Exception $e) {
            $report['failed'][] = "Impossibile creare backup: " . $e->getMessage();
            return $report;
        }

        // Esegui validazione
        $validation = $this->validate();

        // Se il file non esiste o è corrotto, ricrealo
        if (!file_exists($this->dbFilePath) || in_array('File database corrotto', array_map(function($err) {
            return strpos($err, 'corrotto') !== false;
        }, $validation['errors']))) {
            try {
                $this->createDatabaseFile();
                $report['fixed'][] = "File database ricreato";
            } catch (Exception $e) {
                $report['failed'][] = "Impossibile ricreare file database: " . $e->getMessage();
                return $report;
            }
        }

        // Crea fogli mancanti
        $allSheets = SchemaDefinitions::getAllSheets();

        foreach ($allSheets as $sheetName => $definition) {
            if (!$this->sheetExists($sheetName)) {
                try {
                    $this->ensureSheetExists($sheetName);
                    $report['fixed'][] = "Creato foglio mancante: {$sheetName}";
                } catch (Exception $e) {
                    $report['failed'][] = "Impossibile creare foglio '{$sheetName}': " . $e->getMessage();
                }
            }
        }

        // Ripara colonne mancanti nei fogli esistenti
        foreach ($allSheets as $sheetName => $definition) {
            if ($this->sheetExists($sheetName)) {
                try {
                    $actualColumns = $this->getColumns($sheetName);
                    $expectedColumns = $definition['columns'];
                    $missingColumns = array_diff($expectedColumns, $actualColumns);

                    if (!empty($missingColumns)) {
                        $this->addMissingColumns($sheetName, $missingColumns);
                        $report['fixed'][] = "Aggiunte colonne mancanti a '{$sheetName}': " . implode(', ', $missingColumns);
                    }
                } catch (Exception $e) {
                    $report['failed'][] = "Impossibile riparare colonne foglio '{$sheetName}': " . $e->getMessage();
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
            $spreadsheet = IOFactory::load($this->dbFilePath);
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null) {
                throw new Exception("Foglio '{$sheetName}' non trovato");
            }

            $headers = $sheet->rangeToArray("A1:{$sheet->getHighestColumn()}1")[0];
            return array_filter($headers); // Rimuove celle vuote
        } catch (Exception $e) {
            throw new Exception("Impossibile leggere colonne dal foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function sheetExists(string $sheetName): bool
    {
        try {
            $spreadsheet = IOFactory::load($this->dbFilePath);
            $sheet = $spreadsheet->getSheetByName($sheetName);
            return $sheet !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function count(string $sheetName): int
    {
        $data = $this->findAll($sheetName);
        return count($data);
    }

    /**
     * {@inheritDoc}
     */
    public function truncate(string $sheetName): bool
    {
        try {
            $this->ensureSheetExists($sheetName);

            $spreadsheet = IOFactory::load($this->dbFilePath);
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null) {
                return false;
            }

            // Elimina tutte le righe tranne l'intestazione
            $lastRow = $sheet->getHighestRow();
            if ($lastRow > 1) {
                $sheet->removeRow(2, $lastRow - 1);
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($this->dbFilePath);

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile svuotare foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Crea un nuovo file database Excel vuoto
     *
     * @throws Exception Se la creazione fallisce
     */
    private function createDatabaseFile(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Rimuovi il foglio di default
        $spreadsheet->removeSheetByIndex(0);

        // Crea la directory se non esiste
        $dir = dirname($this->dbFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Salva il file vuoto
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($this->dbFilePath);
    }

    /**
     * Aggiunge colonne mancanti a un foglio
     *
     * @param string $sheetName Nome del foglio
     * @param array $columns Array di nomi colonne da aggiungere
     * @throws Exception Se l'aggiunta fallisce
     */
    private function addMissingColumns(string $sheetName, array $columns): void
    {
        try {
            $spreadsheet = IOFactory::load($this->dbFilePath);
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null) {
                throw new Exception("Foglio '{$sheetName}' non trovato");
            }

            // Trova l'ultima colonna
            $lastCol = $sheet->getHighestColumn();
            $lastColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol);

            // Aggiungi le colonne mancanti
            foreach ($columns as $columnName) {
                $lastColIndex++;
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex);
                $sheet->setCellValue("{$colLetter}1", $columnName);

                // Applica stile header
                $sheet->getStyle("{$colLetter}1")->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF'],
                        'size' => 11
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '0066CC']
                    ],
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                        'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                    ]
                ]);

                $sheet->getColumnDimension($colLetter)->setWidth(20);
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($this->dbFilePath);
        } catch (Exception $e) {
            throw new Exception("Impossibile aggiungere colonne al foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadExternalFile(string $filePath)
    {
        // Validazione sicurezza: verifica che il file esista e sia un file reale
        if (!file_exists($filePath)) {
            throw new Exception("File non trovato: {$filePath}");
        }

        if (!is_file($filePath)) {
            throw new Exception("Path non è un file valido: {$filePath}");
        }

        // Verifica che il file non sia troppo grande (max 50MB)
        $maxSize = 50 * 1024 * 1024; // 50MB
        if (filesize($filePath) > $maxSize) {
            throw new Exception("File troppo grande. Max: 50MB");
        }

        // Usa realpath per prevenire path traversal
        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new Exception("Path non valido: {$filePath}");
        }

        // Verifica che sia un file Excel/CSV valido
        $allowedExts = ['xlsx', 'xls', 'csv'];
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            try {
                $identified = strtolower(IOFactory::identify($realPath));
            } catch (Exception $e) {
                $identified = '';
            }

            if (!in_array($identified, $allowedExts)) {
                throw new Exception("Tipo file non supportato: {$ext}. Supportati: xlsx, xls, csv");
            }
        }

        try {
            return IOFactory::load($realPath);
        } catch (Exception $e) {
            throw new Exception("Impossibile caricare file '{$realPath}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadTemplate(string $templateName)
    {
        // Sanitizza il nome del template
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.xlsx?$/i', $templateName)) {
            throw new Exception("Nome template non valido: {$templateName}");
        }

        // Costruisci path del template
        $templatePath = ROOT_PATH . '/database/templates/' . $templateName;

        // Verifica che esista
        if (!file_exists($templatePath)) {
            throw new Exception("Template non trovato: {$templateName}");
        }

        // Usa loadExternalFile per sicurezza
        return $this->loadExternalFile($templatePath);
    }

    /**
     * Metodo magico per delegare chiamate a metodi del DatabaseManager sottostante
     *
     * Permette di chiamare metodi specifici di DatabaseManager (findAllUDAs, etc.)
     * mantenendo compatibilità con codice esistente
     *
     * @param string $method Nome del metodo
     * @param array $arguments Argomenti del metodo
     * @return mixed Risultato della chiamata al metodo
     * @throws \BadMethodCallException Se il metodo non esiste
     */
    public function __call(string $method, array $arguments)
    {
        if (method_exists($this->manager, $method)) {
            return call_user_func_array([$this->manager, $method], $arguments);
        }

        throw new \BadMethodCallException(
            "Metodo '{$method}' non trovato in ExcelDatabaseAdapter né in DatabaseManager"
        );
    }

    /**
     * Restituisce il DatabaseManager sottostante per accesso diretto
     *
     * Utile quando serve accesso completo ai metodi specifici di DatabaseManager
     *
     * @return DatabaseManager
     */
    public function getUnderlyingManager(): DatabaseManager
    {
        return $this->manager;
    }

    /**
     * Ottiene la lista di tutti i fogli presenti nel database Excel
     *
     * @return array Array di nomi dei fogli
     * @throws \Exception Se non è possibile leggere la lista
     */
    public function getAllSheetNames(): array
    {
        return $this->manager->getAllSheetNames();
    }

    /**
     * Svuota completamente un foglio rimuovendo tutte le righe dati
     * Mantiene l'header (prima riga)
     *
     * @param string $sheetName Nome del foglio
     * @return bool True se lo svuotamento ha successo
     * @throws \Exception Se lo svuotamento fallisce
     */
    public function clearSheet(string $sheetName): bool
    {
        return $this->manager->clearSheet($sheetName);
    }

    /**
     * Crea un nuovo foglio con le colonne specificate
     *
     * @param string $sheetName Nome del foglio da creare
     * @param array $columns Array di nomi delle colonne
     * @return bool True se la creazione ha successo
     * @throws \Exception Se la creazione fallisce o il foglio esiste già
     */
    public function createSheet(string $sheetName, array $columns = []): bool
    {
        return $this->manager->createSheet($sheetName, $columns);
    }

    // ========================================================================
    // METODI DI CONVENIENZA PER UDA
    // ========================================================================

    public function findAllUDAs(): array
    {
        return $this->manager->findAllUDAs();
    }

    public function findUDAById(string $id): ?array
    {
        return $this->manager->findUDAById($id);
    }

    public function findMaterialiByUDA(string $udaId): array
    {
        return $this->manager->findMaterialiByUDA($udaId);
    }

    public function findObiettiviByUDA(string $udaId): array
    {
        return $this->manager->findObiettiviByUDA($udaId);
    }

    public function findTestByUDA(string $udaId): array
    {
        return $this->manager->findTestByUDA($udaId);
    }

    public function findClassiAssegnate(string $udaId): array
    {
        return $this->manager->findClassiAssegnate($udaId);
    }

    public function findVotiByUDA(string $udaId): array
    {
        return $this->manager->findVotiByUDA($udaId);
    }

    public function insertUDA(array $data): bool
    {
        return $this->manager->insertUDA($data);
    }

    public function insertMateriale(array $data): bool
    {
        return $this->manager->insertMateriale($data);
    }

    public function insertObiettivo(array $data): bool
    {
        return $this->manager->insertObiettivo($data);
    }

    public function insertTest(array $data): bool
    {
        return $this->manager->insertTest($data);
    }

    public function insertVoto(array $data): bool
    {
        return $this->manager->insertVoto($data);
    }

    public function insertClasseAssegnata(array $data): bool
    {
        return $this->manager->insertClasseAssegnata($data);
    }

    public function updateUDA(string $id, array $data): bool
    {
        return $this->manager->updateUDA($id, $data);
    }

    public function deleteUDA(string $id): bool
    {
        return $this->manager->deleteUDA($id);
    }

    public function deleteMateriale(string $id): bool
    {
        return $this->manager->deleteMateriale($id);
    }

    public function deleteObiettivo(string $id): bool
    {
        return $this->manager->deleteObiettivo($id);
    }
}
