<?php

namespace App\Core\Database;

use Exception;

/**
 * DatabaseMigration - Gestisce la migrazione/importazione dati tra diversi tipi di database
 *
 * Permette di:
 * - Importare tutti i dati da un database all'altro
 * - Esportare dati verso formati diversi
 * - Preservare tutti i fogli/tabelle esistenti indipendentemente dallo schema
 */
class DatabaseMigration
{
    private DatabaseAdapterInterface $sourceAdapter;
    private DatabaseAdapterInterface $targetAdapter;
    private array $log = [];

    /**
     * @param DatabaseAdapterInterface $sourceAdapter Adapter database sorgente
     * @param DatabaseAdapterInterface $targetAdapter Adapter database destinazione
     */
    public function __construct(
        DatabaseAdapterInterface $sourceAdapter,
        DatabaseAdapterInterface $targetAdapter
    ) {
        $this->sourceAdapter = $sourceAdapter;
        $this->targetAdapter = $targetAdapter;
    }

    /**
     * Importa tutti i dati dal database sorgente al database di destinazione
     *
     * IMPORTANTE: Importa TUTTI i fogli/tabelle esistenti nel database sorgente,
     * indipendentemente dallo schema definito nell'applicazione
     *
     * @param bool $preserveExisting Se true, preserva i dati esistenti nel target
     * @param bool $createBackup Se true, crea backup del target prima dell'import
     * @return array Risultato dell'operazione con statistiche
     * @throws Exception Se l'importazione fallisce
     */
    public function importAll(bool $preserveExisting = false, bool $createBackup = true): array
    {
        $this->log = [];
        $startTime = microtime(true);

        try {
            // 1. Crea backup se richiesto
            if ($createBackup) {
                try {
                    $backupPath = $this->targetAdapter->createBackup();
                    $this->log[] = "Backup creato: {$backupPath}";
                } catch (Exception $e) {
                    $this->log[] = "WARN: Impossibile creare backup: " . $e->getMessage();
                }
            }

            // 2. Ottieni lista di tutti i fogli/tabelle dal database sorgente
            $sourceSheets = $this->getAllSheetsFromSource();
            $this->log[] = "Fogli trovati nel database sorgente: " . count($sourceSheets);

            $importedSheets = [];
            $skippedSheets = [];
            $errors = [];
            $totalRowsImported = 0;

            // 3. Per ogni foglio/tabella nel sorgente
            foreach ($sourceSheets as $sheetName) {
                try {
                    $this->log[] = "Elaborazione foglio: {$sheetName}";

                    // Leggi tutti i dati dal foglio sorgente
                    $data = $this->sourceAdapter->findAll($sheetName);

                    if (empty($data)) {
                        $this->log[] = "  → Foglio vuoto, salto";
                        $skippedSheets[] = $sheetName;
                        continue;
                    }

                    $rowCount = count($data);
                    $this->log[] = "  → {$rowCount} righe trovate";

                    // Verifica se il foglio esiste già nel target
                    $targetSheetExists = $this->targetAdapter->sheetExists($sheetName);

                    // Estrai le colonne dai dati sorgente
                    $sourceColumns = !empty($data) ? array_keys($data[0]) : [];

                    if ($targetSheetExists) {
                        // Foglio esiste - verifica e aggiungi colonne mancanti
                        try {
                            $targetColumns = $this->targetAdapter->getColumns($sheetName);
                            $missingColumns = array_diff($sourceColumns, $targetColumns);

                            if (!empty($missingColumns)) {
                                $this->log[] = "  → Aggiunta " . count($missingColumns) . " colonne mancanti: " . implode(', ', $missingColumns);
                                foreach ($missingColumns as $col) {
                                    try {
                                        $this->addColumnToSheet($sheetName, $col);
                                    } catch (\Exception $e) {
                                        $this->log[] = "  ⚠ Impossibile aggiungere colonna '{$col}': " . $e->getMessage();
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $this->log[] = "  ⚠ Impossibile verificare colonne: " . $e->getMessage();
                        }

                        if (!$preserveExisting) {
                            // Svuota il foglio esistente
                            $this->log[] = "  → Pulizia foglio esistente";
                            $this->targetAdapter->clearSheet($sheetName);
                        }
                    } else {
                        // Crea il foglio nel target
                        $this->log[] = "  → Creazione nuovo foglio";
                        $this->targetAdapter->createSheet($sheetName, $sourceColumns);
                    }

                    // Importa i dati
                    if ($preserveExisting && $targetSheetExists) {
                        // Append ai dati esistenti
                        $imported = $this->appendRows($sheetName, $data);
                    } else {
                        // Sovrascrivi/scrivi nuovi dati
                        $imported = $this->writeRows($sheetName, $data);
                    }

                    $totalRowsImported += $imported;
                    $importedSheets[] = $sheetName;
                    $this->log[] = "  IMPORT: {$imported} righe importate";

                } catch (Exception $e) {
                    $errorMsg = "Errore importando {$sheetName}: " . $e->getMessage();
                    $this->log[] = "  ✗ {$errorMsg}";
                    $errors[] = $errorMsg;
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            return [
                'success' => empty($errors),
                'imported_sheets' => $importedSheets,
                'skipped_sheets' => $skippedSheets,
                'total_sheets' => count($sourceSheets),
                'total_rows' => $totalRowsImported,
                'errors' => $errors,
                'log' => $this->log,
                'duration_seconds' => $duration,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            throw new Exception("Errore durante l'importazione: " . $e->getMessage());
        }
    }

    /**
     * Ottiene la lista di tutti i fogli/tabelle dal database sorgente
     *
     * @return array Lista dei nomi dei fogli/tabelle
     */
    private function getAllSheetsFromSource(): array
    {
        return $this->sourceAdapter->getAllSheetNames();
    }

    /**
     * Scrive righe in un foglio del database target
     *
     * @param string $sheetName Nome del foglio
     * @param array $rows Array di righe da scrivere
     * @return int Numero di righe scritte
     */
    private function writeRows(string $sheetName, array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            // Determina il campo ID primario
            $idField = $this->getIdField($sheetName, $row);

            if ($idField && isset($row[$idField])) {
                // Usa insertRow per scrivere con ID
                $this->targetAdapter->insertRow($sheetName, $row);
            } else {
                // Scrivi la riga senza gestione ID specifica
                $this->targetAdapter->insertRow($sheetName, $row);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Aggiunge righe a un foglio esistente
     *
     * @param string $sheetName Nome del foglio
     * @param array $rows Array di righe da aggiungere
     * @return int Numero di righe aggiunte
     */
    private function appendRows(string $sheetName, array $rows): int
    {
        return $this->writeRows($sheetName, $rows);
    }

    /**
     * Determina il campo ID primario per un dato foglio
     *
     * @param string $sheetName Nome del foglio
     * @param array $row Riga di esempio
     * @return string|null Nome del campo ID o null
     */
    private function getIdField(string $sheetName, array $row): ?string
    {
        // Lista comune di pattern per ID
        $idPatterns = ['id_', 'ID_'];

        foreach (array_keys($row) as $field) {
            foreach ($idPatterns as $pattern) {
                if (strpos($field, $pattern) === 0) {
                    return $field;
                }
            }
        }

        // Fallback: cerca solo "id"
        if (isset($row['id']) || isset($row['ID'])) {
            return isset($row['id']) ? 'id' : 'ID';
        }

        return null;
    }

    /**
     * Esporta un singolo foglio/tabella
     *
     * @param string $sheetName Nome del foglio da esportare
     * @return array Dati esportati
     */
    public function exportSheet(string $sheetName): array
    {
        return $this->sourceAdapter->findAll($sheetName);
    }

    /**
     * Ottiene il log delle operazioni
     *
     * @return array Log messaggi
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Verifica compatibilità tra due database adapter
     *
     * @param DatabaseAdapterInterface $adapter1
     * @param DatabaseAdapterInterface $adapter2
     * @return array Risultato della verifica
     */
    public static function checkCompatibility(
        DatabaseAdapterInterface $adapter1,
        DatabaseAdapterInterface $adapter2
    ): array {
        $warnings = [];
        $compatible = true;

        // Verifica che entrambi gli adapter supportino le operazioni necessarie
        $requiredMethods = [
            'findAll',
            'insertRow',
            'getAllSheetNames',
            'sheetExists',
            'createSheet'
        ];

        foreach ($requiredMethods as $method) {
            if (!method_exists($adapter1, $method)) {
                $warnings[] = "Source adapter non supporta: {$method}";
                $compatible = false;
            }
            if (!method_exists($adapter2, $method)) {
                $warnings[] = "Target adapter non supporta: {$method}";
                $compatible = false;
            }
        }

        return [
            'compatible' => $compatible,
            'warnings' => $warnings
        ];
    }

    /**
     * Stima le dimensioni dell'importazione
     *
     * @return array Statistiche sulla dimensione
     */
    public function estimateSize(): array
    {
        $sheets = $this->getAllSheetsFromSource();
        $totalRows = 0;
        $sheetStats = [];

        foreach ($sheets as $sheetName) {
            $data = $this->sourceAdapter->findAll($sheetName);
            $rowCount = count($data);
            $totalRows += $rowCount;

            $sheetStats[] = [
                'name' => $sheetName,
                'rows' => $rowCount,
                'size_estimate_kb' => round((strlen(json_encode($data)) / 1024), 2)
            ];
        }

        return [
            'total_sheets' => count($sheets),
            'total_rows' => $totalRows,
            'sheets' => $sheetStats
        ];
    }

    /**
     * Aggiunge una colonna a un foglio/tabella nel database di destinazione.
     *
     * NOTA: l'adapter di destinazione può essere wrappato (es. UserScopedDatabaseAdapter);
     * per questo motivo la logica si basa principalmente sul tipo di connessione
     * restituita da getConnection(), non solo sulla classe dell'adapter.
     *
     * @param string $sheetName Nome del foglio
     * @param string $columnName Nome della colonna da aggiungere
     * @throws Exception Se l'aggiunta fallisce
     */
    private function addColumnToSheet(string $sheetName, string $columnName): void
    {
        // Prova a usare la connessione sottostante per determinare il tipo di storage
        $connection = null;
        try {
            $connection = $this->targetAdapter->getConnection();
        } catch (Exception $e) {
            // Se non possiamo ottenere la connessione, ripieghiamo sul vecchio comportamento
        }

        // Caso 1: connessione PDO (SQLite / MySQL / altri RDBMS)
        if ($connection instanceof \PDO) {
            $driver = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $sanitizedTable = preg_replace('/[^a-zA-Z0-9_]/', '_', $sheetName);
            $sanitizedColumn = preg_replace('/[^a-zA-Z0-9_]/', '_', $columnName);

            if ($driver === 'sqlite') {
                $sql = "ALTER TABLE {$sanitizedTable} ADD COLUMN {$sanitizedColumn} TEXT";
                $connection->exec($sql);
                return;
            }

            if ($driver === 'mysql') {
                $sql = "ALTER TABLE `{$sanitizedTable}` ADD COLUMN `{$sanitizedColumn}` TEXT NULL";
                $connection->exec($sql);
                return;
            }

            // altri driver PDO non gestiti esplicitamente: niente
            return;
        }

        // Caso 2: storage non SQL - usa la classe dell'adapter come fallback
        $adapterClass = get_class($this->targetAdapter);

        if (strpos($adapterClass, 'Excel') !== false) {
            // Excel: aggiungi colonna nell'header
            $worksheet = $connection->getSheetByName($sheetName);
            if ($worksheet) {
                // Trova ultima colonna
                $highestColumn = $worksheet->getHighestColumn();
                $nextColumn = ++$highestColumn;
                $worksheet->setCellValue($nextColumn . '1', $columnName);
            }
        } elseif (strpos($adapterClass, 'GoogleSheets') !== false) {
            // Google Sheets: aggiungi colonna nell'header
            $service = $connection;
            $spreadsheetId = $this->targetAdapter->getSpreadsheetId();

            // Ottieni l'ID del foglio
            $spreadsheet = $service->spreadsheets->get($spreadsheetId);
            $sheetId = null;
            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() === $sheetName) {
                    $sheetId = $sheet->getProperties()->getSheetId();
                    break;
                }
            }

            if ($sheetId === null) {
                throw new Exception("Foglio '{$sheetName}' non trovato");
            }

            // Leggi header attuale
            $range = "{$sheetName}!1:1";
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $headers = $response->getValues()[0] ?? [];

            // Aggiungi nuova colonna
            $headers[] = $columnName;

            // Aggiorna header
            $updateRange = "{$sheetName}!1:1";
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => [$headers]
            ]);
            $params = ['valueInputOption' => 'RAW'];
            $service->spreadsheets_values->update($spreadsheetId, $updateRange, $body, $params);
        }
    }
}
