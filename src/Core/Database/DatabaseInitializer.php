<?php

namespace App\Core\Database;

use Exception;

/**
 * DatabaseInitializer - Utility per inizializzare, validare e riparare il database
 *
 * Questa classe fornisce procedure per:
 * - Inizializzare un database da zero
 * - Validare la struttura del database
 * - Riparare errori di struttura
 * - Gestire migrazioni e aggiornamenti schema
 *
 * Può essere usata sia dal codice che da CLI/script di manutenzione.
 */
class DatabaseInitializer
{
    private DatabaseAdapterInterface $adapter;
    private array $config;

    /**
     * Costruttore
     *
     * @param DatabaseAdapterInterface $adapter Adapter del database
     * @param array $config Configurazione
     */
    public function __construct(DatabaseAdapterInterface $adapter, array $config = [])
    {
        $this->adapter = $adapter;
        $this->config = $config;
    }

    /**
     * Inizializza il database creando tutti i fogli necessari
     *
     * Questa procedura può essere chiamata:
     * - Al primo avvio dell'applicazione
     * - Dopo un errore di lettura/scrittura
     * - Manualmente tramite script di manutenzione
     *
     * @param bool $createBackup Se true, crea un backup prima di inizializzare
     * @return array Report dell'inizializzazione
     */
    public function initialize(bool $createBackup = false): array
    {
        $report = [
            'success' => false,
            'backup_created' => null,
            'created_sheets' => [],
            'existing_sheets' => [],
            'errors' => [],
            'warnings' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Crea backup se richiesto
        if ($createBackup) {
            try {
                $backupPath = $this->adapter->createBackup();
                $report['backup_created'] = $backupPath;
            } catch (Exception $e) {
                $report['warnings'][] = "Impossibile creare backup: " . $e->getMessage();
            }
        }

        // Inizializza usando l'adapter
        try {
            $initResult = $this->adapter->initialize();
            $report['created_sheets'] = $initResult['created'] ?? [];
            $report['existing_sheets'] = $initResult['existing'] ?? [];
            $report['errors'] = array_merge($report['errors'], $initResult['errors'] ?? []);
            $report['success'] = empty($report['errors']);
        } catch (Exception $e) {
            $report['errors'][] = "Errore durante l'inizializzazione: " . $e->getMessage();
            $report['success'] = false;
        }

        return $report;
    }

    /**
     * Valida la struttura del database
     *
     * Verifica che:
     * - Il database sia accessibile
     * - Tutti i fogli definiti esistano
     * - Le colonne siano corrette
     * - Non ci siano dati corrotti
     *
     * @return array Report della validazione
     */
    public function validate(): array
    {
        $report = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'info' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        try {
            // Esegui validazione usando l'adapter
            $validationResult = $this->adapter->validate();

            $report['valid'] = $validationResult['valid'];
            $report['errors'] = array_merge($report['errors'], $validationResult['errors'] ?? []);
            $report['warnings'] = array_merge($report['warnings'], $validationResult['warnings'] ?? []);

            // Aggiungi informazioni aggiuntive
            $report['info'][] = "Database type: " . ($this->config['database']['type'] ?? 'excel');

        } catch (Exception $e) {
            $report['valid'] = false;
            $report['errors'][] = "Errore durante la validazione: " . $e->getMessage();
        }

        return $report;
    }

    /**
     * Ripara il database correggendo problemi di struttura
     *
     * Questa procedura può essere chiamata quando:
     * - Si verificano errori di lettura/scrittura
     * - La validazione fallisce
     * - Dopo un aggiornamento che modifica lo schema
     *
     * @param bool $createBackup Se true, crea un backup prima di riparare
     * @return array Report della riparazione
     */
    public function repair(bool $createBackup = true): array
    {
        $report = [
            'success' => false,
            'backup_created' => null,
            'fixed' => [],
            'failed' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Crea backup se richiesto
        if ($createBackup) {
            try {
                $backupPath = $this->adapter->createBackup();
                $report['backup_created'] = $backupPath;
            } catch (Exception $e) {
                $report['failed'][] = "Impossibile creare backup: " . $e->getMessage();
                // Non continuare se non possiamo fare backup
                return $report;
            }
        }

        // Ripara usando l'adapter
        try {
            $repairResult = $this->adapter->repair();
            $report['fixed'] = $repairResult['fixed'] ?? [];
            $report['failed'] = array_merge($report['failed'], $repairResult['failed'] ?? []);
            $report['success'] = empty($report['failed']);
        } catch (Exception $e) {
            $report['failed'][] = "Errore durante la riparazione: " . $e->getMessage();
            $report['success'] = false;
        }

        return $report;
    }

    /**
     * Esegue un check completo e ripara automaticamente se necessario
     *
     * Questa è la procedura principale da usare in caso di errori:
     * 1. Valida il database
     * 2. Se non valido, tenta di riparare
     * 3. Se la riparazione fallisce, inizializza da zero (opzionale)
     *
     * @param bool $autoRepair Se true, ripara automaticamente in caso di problemi
     * @param bool $autoInitialize Se true, inizializza da zero se la riparazione fallisce
     * @return array Report completo
     */
    public function checkAndRepair(bool $autoRepair = true, bool $autoInitialize = false): array
    {
        $report = [
            'action_taken' => 'none',
            'validation' => null,
            'repair' => null,
            'initialization' => null,
            'final_status' => 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Passo 1: Valida
        $validation = $this->validate();
        $report['validation'] = $validation;

        // Se è valido, tutto ok
        if ($validation['valid'] && empty($validation['warnings'])) {
            $report['final_status'] = 'ok';
            $report['action_taken'] = 'validation_only';
            return $report;
        }

        // Se ci sono solo warnings, segnala ma non riparare
        if ($validation['valid'] && !empty($validation['warnings'])) {
            $report['final_status'] = 'ok_with_warnings';
            $report['action_taken'] = 'validation_only';

            if ($autoRepair) {
                // Tenta di riparare i warnings
                $repair = $this->repair();
                $report['repair'] = $repair;
                $report['action_taken'] = 'repair';
                $report['final_status'] = $repair['success'] ? 'repaired' : 'repair_failed';
            }

            return $report;
        }

        // Se non è valido, tenta di riparare
        if (!$validation['valid']) {
            if ($autoRepair) {
                $repair = $this->repair();
                $report['repair'] = $repair;
                $report['action_taken'] = 'repair';

                if ($repair['success']) {
                    $report['final_status'] = 'repaired';
                    return $report;
                }

                // Riparazione fallita
                $report['final_status'] = 'repair_failed';

                // Se autorizzato, inizializza da zero
                if ($autoInitialize) {
                    $initialization = $this->initialize(true);
                    $report['initialization'] = $initialization;
                    $report['action_taken'] = 'full_reinitialization';
                    $report['final_status'] = $initialization['success'] ? 'reinitialized' : 'failed';
                }
            } else {
                $report['final_status'] = 'invalid_no_repair';
            }
        }

        return $report;
    }

    /**
     * Esegue una migrazione dello schema
     *
     * Utile quando si aggiungono nuovi fogli o colonne
     *
     * @param array $migrations Array di migrazioni da applicare
     * @return array Report della migrazione
     */
    public function migrate(array $migrations = []): array
    {
        $report = [
            'success' => false,
            'applied' => [],
            'failed' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Crea backup prima di migrare
        try {
            $backupPath = $this->adapter->createBackup();
            $report['backup_created'] = $backupPath;
        } catch (Exception $e) {
            $report['failed'][] = "Impossibile creare backup: " . $e->getMessage();
            return $report;
        }

        // Applica ogni migrazione
        foreach ($migrations as $migration) {
            try {
                $this->applyMigration($migration);
                $report['applied'][] = $migration['name'] ?? 'unnamed';
            } catch (Exception $e) {
                $report['failed'][] = ($migration['name'] ?? 'unnamed') . ": " . $e->getMessage();
            }
        }

        $report['success'] = empty($report['failed']);
        return $report;
    }

    /**
     * Applica una singola migrazione
     *
     * @param array $migration Definizione della migrazione
     * @throws Exception Se la migrazione fallisce
     */
    private function applyMigration(array $migration): void
    {
        $type = $migration['type'] ?? null;

        switch ($type) {
            case 'add_sheet':
                if (!isset($migration['sheet_name'])) {
                    throw new Exception("Migrazione add_sheet richiede 'sheet_name'");
                }
                $this->adapter->ensureSheetExists($migration['sheet_name']);
                break;

            case 'add_column':
                if (!isset($migration['sheet_name']) || !isset($migration['column_name'])) {
                    throw new Exception("Migrazione add_column richiede 'sheet_name' e 'column_name'");
                }
                // Implementa logica per aggiungere colonna
                // (già gestito da repair in caso di colonne mancanti)
                break;

            case 'custom':
                if (!isset($migration['callback']) || !is_callable($migration['callback'])) {
                    throw new Exception("Migrazione custom richiede 'callback' callable");
                }
                call_user_func($migration['callback'], $this->adapter);
                break;

            default:
                throw new Exception("Tipo di migrazione '{$type}' non supportato");
        }
    }

    /**
     * Genera un report dettagliato dello stato del database
     *
     * @return array Report completo
     */
    public function getHealthReport(): array
    {
        $report = [
            'database_type' => $this->config['database']['type'] ?? 'excel',
            'timestamp' => date('Y-m-d H:i:s'),
            'validation' => $this->validate(),
            'sheets_count' => 0,
            'total_rows' => 0,
            'sheets_details' => []
        ];

        // Conta fogli e righe
        try {
            $allSheets = \App\Core\SchemaDefinitions::getAllSheets();
            $report['sheets_count'] = count($allSheets);

            foreach ($allSheets as $sheetName => $definition) {
                try {
                    if ($this->adapter->sheetExists($sheetName)) {
                        $count = $this->adapter->count($sheetName);
                        $report['sheets_details'][$sheetName] = [
                            'exists' => true,
                            'rows' => $count
                        ];
                        $report['total_rows'] += $count;
                    } else {
                        $report['sheets_details'][$sheetName] = [
                            'exists' => false,
                            'rows' => 0
                        ];
                    }
                } catch (Exception $e) {
                    $report['sheets_details'][$sheetName] = [
                        'exists' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }
        } catch (Exception $e) {
            $report['error'] = $e->getMessage();
        }

        return $report;
    }
}
