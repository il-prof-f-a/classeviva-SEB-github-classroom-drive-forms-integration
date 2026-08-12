<?php

namespace App\Core\Database;

use App\Core\GoogleTokenProvider;
use App\Core\SchemaDefinitions;
use Exception;

/**
 * GoogleSheetsDatabaseAdapter - Implementazione dell'adapter per Google Sheets
 *
 * Utilizza Google Sheets API v4 per gestire il database su Google Sheets.
 * Permette di gestire fogli e dati in modo trasparente come se fossero Excel.
 *
 * REQUISITI:
 * - google/apiclient: ^2.0
 * - Credenziali OAuth2 o Service Account
 * - File di credenziali JSON configurato
 */
class GoogleSheetsDatabaseAdapter implements DatabaseAdapterInterface
{
    private array $config;
    private ?object $client = null;
    private ?object $service = null;
    private ?string $spreadsheetId = null;
    private array $sheetsCache = [];

    /**
     * Costruttore
     *
     * @param array $config Configurazione del database
     * @throws Exception Se la configurazione è invalida
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Verifica che la libreria Google API sia installata
        if (!class_exists('\Google\Client')) {
            throw new Exception(
                "Google API Client non installato. Esegui: composer require google/apiclient"
            );
        }

        // Verifica configurazione
        if (!isset($config['database']['google_sheets'])) {
            throw new Exception("Configurazione Google Sheets mancante");
        }

        $gsConfig = $config['database']['google_sheets'];

        if (!isset($gsConfig['spreadsheet_id'])) {
            throw new Exception("spreadsheet_id mancante nella configurazione");
        }

        if (!isset($gsConfig['credentials_file'])) {
            throw new Exception("credentials_file mancante nella configurazione");
        }

        $this->spreadsheetId = $gsConfig['spreadsheet_id'];

        // Inizializza client Google
        $this->initializeClient($gsConfig);
    }

    /**
     * Inizializza il client Google API
     *
     * @param array $gsConfig Configurazione Google Sheets
     * @throws Exception Se l'inizializzazione fallisce
     */
    private function initializeClient(array $gsConfig): void
    {
        $credentialsPath = ROOT_PATH . '/' . $gsConfig['credentials_file'];

        if (!file_exists($credentialsPath)) {
            throw new Exception("File credenziali non trovato: {$credentialsPath}");
        }

        try {
            $this->client = new \Google\Client();
            $this->client->setApplicationName('UDA System');
            $this->client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
            $this->client->setAuthConfig($credentialsPath);
            $this->client->setAccessType('offline');

            $tokenData = GoogleTokenProvider::getToken($this->config);

            if ($tokenData === null) {
                throw new Exception(
                    "Token OAuth Google non trovato per l'utente corrente. " .
                    "Eseguire prima l'autenticazione da public/google_auth.php."
                );
            }

            $this->client->setAccessToken($tokenData);

            // Refresh token se scaduto (in memoria per questa richiesta)
            if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
                $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            }

            $this->service = new \Google\Service\Sheets($this->client);
        } catch (Exception $e) {
            throw new Exception("Impossibile inizializzare Google API Client: " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(string $sheetName): array
    {
        try {
            // Verifica che il foglio esista
            if (!$this->sheetExists($sheetName)) {
                return [];
            }

            // Leggi tutti i dati dal foglio
            $range = "{$sheetName}!A1:ZZ";
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
            $values = $response->getValues();

            if (empty($values) || count($values) < 2) {
                return []; // Nessun dato o solo header
            }

            // Prima riga = headers
            $headers = array_shift($values);

            // Converti in array associativi
            $result = [];
            foreach ($values as $row) {
                // Assicurati che la riga abbia lo stesso numero di colonne degli headers
                $row = array_pad($row, count($headers), '');
                $result[] = array_combine($headers, $row);
            }

            return $result;
        } catch (Exception $e) {
            throw new Exception("Impossibile leggere foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findWhere(string $sheetName, array $where): array
    {
        $all = $this->findAll($sheetName);

        if (empty($where)) {
            return $all;
        }

        // Filtra i risultati
        return array_filter($all, function($row) use ($where) {
            foreach ($where as $field => $value) {
                if (!isset($row[$field]) || $row[$field] != $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function findOne(string $sheetName, string $keyField, $keyValue): ?array
    {
        $results = $this->findWhere($sheetName, [$keyField => $keyValue]);
        return !empty($results) ? array_values($results)[0] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function insertRow(string $sheetName, array $data): bool
    {
        try {
            $this->ensureSheetExists($sheetName);

            // Leggi headers
            $headers = $this->getColumns($sheetName);

            // Prepara riga da inserire
            $row = [];
            foreach ($headers as $header) {
                $row[] = $data[$header] ?? '';
            }

            // Aggiungi riga alla fine
            $range = "{$sheetName}!A:A";
            $body = new \Google\Service\Sheets\ValueRange([
                'values' => [$row]
            ]);

            $params = [
                'valueInputOption' => 'RAW'
            ];

            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile inserire riga in '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateRow(string $sheetName, string $keyField, $keyValue, array $data): bool
    {
        try {
            // Trova la riga da aggiornare
            $all = $this->findAll($sheetName);
            $headers = $this->getColumns($sheetName);
            $keyColIndex = array_search($keyField, $headers);

            if ($keyColIndex === false) {
                return false;
            }

            $rowIndex = null;
            foreach ($all as $index => $row) {
                if ($row[$keyField] == $keyValue) {
                    $rowIndex = $index + 2; // +2 perché indice 0-based e riga 1 è header
                    break;
                }
            }

            if ($rowIndex === null) {
                return false; // Riga non trovata
            }

            // Prepara riga aggiornata
            $updatedRow = $all[$rowIndex - 2]; // Riga originale
            foreach ($data as $field => $value) {
                if (in_array($field, $headers)) {
                    $updatedRow[$field] = $value;
                }
            }

            // Converti in array ordinato secondo headers
            $row = [];
            foreach ($headers as $header) {
                $row[] = $updatedRow[$header] ?? '';
            }

            // Aggiorna la riga
            $range = "{$sheetName}!A{$rowIndex}:ZZ{$rowIndex}";
            $body = new \Google\Service\Sheets\ValueRange([
                'values' => [$row]
            ]);

            $params = [
                'valueInputOption' => 'RAW'
            ];

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile aggiornare riga in '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteRow(string $sheetName, $keyValue, string $keyField = 'id'): bool
    {
        try {
            // Trova la riga da eliminare
            $all = $this->findAll($sheetName);
            $headers = $this->getColumns($sheetName);

            $rowIndex = null;
            foreach ($all as $index => $row) {
                if (isset($row[$keyField]) && $row[$keyField] == $keyValue) {
                    $rowIndex = $index + 1; // +1 perché riga 0 è header (in Google Sheets indice 1-based)
                    break;
                }
            }

            if ($rowIndex === null) {
                return false; // Riga non trovata
            }

            // Ottieni sheetId
            $sheetId = $this->getSheetId($sheetName);

            // Elimina riga usando batchUpdate
            $requests = [
                new \Google\Service\Sheets\Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => $rowIndex,
                            'endIndex' => $rowIndex + 1
                        ]
                    ]
                ])
            ];

            $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile eliminare riga da '{$sheetName}': " . $e->getMessage());
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

        // Crea il foglio se non esiste
        if (!SchemaDefinitions::isSheetDefined($sheetName)) {
            return false;
        }

        try {
            $definition = SchemaDefinitions::getSheetDefinition($sheetName);
            $columns = $definition['columns'];

            // Crea nuovo foglio
            $requests = [
                new \Google\Service\Sheets\Request([
                    'addSheet' => [
                        'properties' => [
                            'title' => $sheetName
                        ]
                    ]
                ])
            ];

            $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);

            // Aggiungi headers
            $range = "{$sheetName}!A1:ZZ1";
            $body = new \Google\Service\Sheets\ValueRange([
                'values' => [$columns]
            ]);

            $params = [
                'valueInputOption' => 'RAW'
            ];

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );

            // Formatta header (bold, sfondo blu)
            $sheetId = $this->getSheetId($sheetName);
            $this->formatHeader($sheetId);

            // Invalida cache
            $this->sheetsCache = [];

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile creare foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection()
    {
        return $this->service;
    }

    /**
     * Ottiene l'ID del foglio di calcolo Google Sheets
     *
     * @return string|null ID del foglio di calcolo
     */
    public function getSpreadsheetId(): ?string
    {
        return $this->spreadsheetId;
    }

    /**
     * {@inheritDoc}
     */
    public function createBackup(): string
    {
        try {
            // Per Google Sheets, crea una copia del foglio
            $driveService = new \Google\Service\Drive($this->client);

            $copiedFile = new \Google\Service\Drive\DriveFile([
                'name' => 'UDA Master Backup ' . date('Y-m-d H:i:s')
            ]);

            $copy = $driveService->files->copy($this->spreadsheetId, $copiedFile);

            return "https://docs.google.com/spreadsheets/d/{$copy->id}";
        } catch (Exception $e) {
            throw new Exception("Impossibile creare backup: " . $e->getMessage());
        }
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

        // Verifica che lo spreadsheet esista
        try {
            $this->service->spreadsheets->get($this->spreadsheetId);
        } catch (Exception $e) {
            $report['errors'][] = "Spreadsheet non trovato o inaccessibile: " . $e->getMessage();
            return $report;
        }

        // Itera su tutti i fogli definiti nello schema
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

        // Verifica che lo spreadsheet esista
        try {
            $this->service->spreadsheets->get($this->spreadsheetId);
        } catch (Exception $e) {
            $report['valid'] = false;
            $report['errors'][] = "Spreadsheet non trovato o inaccessibile: " . $e->getMessage();
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
            $backupUrl = $this->createBackup();
            $report['backup_created'] = $backupUrl;
        } catch (Exception $e) {
            $report['failed'][] = "Impossibile creare backup: " . $e->getMessage();
            return $report;
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
            $range = "{$sheetName}!A1:ZZ1";
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
            $values = $response->getValues();

            if (empty($values)) {
                return [];
            }

            return array_filter($values[0]); // Prima riga = headers
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
            // Usa cache per evitare chiamate API ripetute
            if (!empty($this->sheetsCache)) {
                return in_array($sheetName, $this->sheetsCache);
            }

            // Carica tutti i fogli
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $sheets = $spreadsheet->getSheets();

            $this->sheetsCache = [];
            foreach ($sheets as $sheet) {
                $this->sheetsCache[] = $sheet->getProperties()->getTitle();
            }

            return in_array($sheetName, $this->sheetsCache);
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

            // Ottieni numero di righe
            $all = $this->findAll($sheetName);
            $rowCount = count($all);

            if ($rowCount === 0) {
                return true; // Già vuoto
            }

            // Ottieni sheetId
            $sheetId = $this->getSheetId($sheetName);

            // Elimina tutte le righe tranne l'header
            $requests = [
                new \Google\Service\Sheets\Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => 1, // Indice 0 = header
                            'endIndex' => $rowCount + 1
                        ]
                    ]
                ])
            ];

            $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile svuotare foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Ottiene lo sheetId di un foglio
     *
     * @param string $sheetName Nome del foglio
     * @return int ID del foglio
     * @throws Exception Se il foglio non esiste
     */
    private function getSheetId(string $sheetName): int
    {
        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $sheets = $spreadsheet->getSheets();

            foreach ($sheets as $sheet) {
                if ($sheet->getProperties()->getTitle() === $sheetName) {
                    return $sheet->getProperties()->getSheetId();
                }
            }

            throw new Exception("Foglio '{$sheetName}' non trovato");
        } catch (Exception $e) {
            throw new Exception("Impossibile ottenere sheetId per '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Formatta l'header di un foglio (bold, sfondo blu)
     *
     * @param int $sheetId ID del foglio
     * @throws Exception Se la formattazione fallisce
     */
    private function formatHeader(int $sheetId): void
    {
        try {
            $requests = [
                // Bold + colore bianco
                new \Google\Service\Sheets\Request([
                    'repeatCell' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'startRowIndex' => 0,
                            'endRowIndex' => 1
                        ],
                        'cell' => [
                            'userEnteredFormat' => [
                                'textFormat' => [
                                    'bold' => true,
                                    'foregroundColor' => [
                                        'red' => 1.0,
                                        'green' => 1.0,
                                        'blue' => 1.0
                                    ]
                                ],
                                'backgroundColor' => [
                                    'red' => 0.0,
                                    'green' => 0.4,
                                    'blue' => 0.8
                                ],
                                'horizontalAlignment' => 'CENTER',
                                'verticalAlignment' => 'MIDDLE'
                            ]
                        ],
                        'fields' => 'userEnteredFormat(textFormat,backgroundColor,horizontalAlignment,verticalAlignment)'
                    ]
                ])
            ];

            $batchUpdateRequest = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdateRequest);
        } catch (Exception $e) {
            // Non fatale, continua
        }
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
            $currentColumns = $this->getColumns($sheetName);
            $newColumns = array_merge($currentColumns, $columns);

            // Aggiorna header
            $range = "{$sheetName}!A1:ZZ1";
            $body = new \Google\Service\Sheets\ValueRange([
                'values' => [$newColumns]
            ]);

            $params = [
                'valueInputOption' => 'RAW'
            ];

            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );
        } catch (Exception $e) {
            throw new Exception("Impossibile aggiungere colonne al foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadExternalFile(string $filePath)
    {
        // Per Google Sheets, i file esterni sono file Excel locali
        // Usa PhpSpreadsheet per caricarli

        // Validazione sicurezza
        if (!file_exists($filePath)) {
            throw new Exception("File non trovato: {$filePath}");
        }

        if (!is_file($filePath)) {
            throw new Exception("Path non è un file valido: {$filePath}");
        }

        // Verifica dimensione
        $maxSize = 50 * 1024 * 1024; // 50MB
        if (filesize($filePath) > $maxSize) {
            throw new Exception("File troppo grande. Max: 50MB");
        }

        // Usa realpath per sicurezza
        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new Exception("Path non valido: {$filePath}");
        }

        // Verifica tipo file (permette tmp senza estensione)
        $allowedExts = ['xlsx', 'xls', 'csv'];
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            try {
                $identified = strtolower(\PhpOffice\PhpSpreadsheet\IOFactory::identify($realPath));
            } catch (Exception $e) {
                $identified = '';
            }

            if (!in_array($identified, $allowedExts)) {
                throw new Exception("Tipo file non supportato: {$ext}. Supportati: xlsx, xls, csv");
            }
        }

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($realPath);
        } catch (Exception $e) {
            throw new Exception("Impossibile caricare file '{$realPath}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadTemplate(string $templateName)
    {
        // Sanitizza nome template
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.xlsx?$/i', $templateName)) {
            throw new Exception("Nome template non valido: {$templateName}");
        }

        // Costruisci path
        $templatePath = ROOT_PATH . '/database/templates/' . $templateName;

        // Verifica esistenza
        if (!file_exists($templatePath)) {
            throw new Exception("Template non trovato: {$templateName}");
        }

        // Usa loadExternalFile
        return $this->loadExternalFile($templatePath);
    }

    /**
     * Metodo magico per compatibilità con metodi specifici di DatabaseManager
     *
     * Converte chiamate a metodi specifici (findAllUDAs, etc.) in chiamate generiche
     * (findAll, findWhere, etc.)
     *
     * @param string $method Nome del metodo
     * @param array $arguments Argomenti del metodo
     * @return mixed Risultato della chiamata
     * @throws \BadMethodCallException Se il metodo non è supportato
     */
    public function __call(string $method, array $arguments)
    {
        // Mappa metodi specifici a metodi generici
        $methodMap = [
            'findAllUDAs' => fn() => $this->findAll('UDA_ANAGRAFICA'),
            'findUDAById' => fn($id) => $this->findOne('UDA_ANAGRAFICA', 'id_uda', $id),
            'findMaterialiByUDA' => fn($udaId) => $this->findWhere('MATERIALI', ['id_uda' => $udaId]),
            'findObiettiviByUDA' => fn($udaId) => $this->findWhere('OBIETTIVI', ['id_uda' => $udaId]),
            'findTestByUDA' => fn($udaId) => $this->findWhere('TEST', ['id_uda' => $udaId]),
            'findClassiAssegnate' => fn($udaId) => $this->findWhere('CLASSI_ASSEGNATE', ['id_uda' => $udaId]),
            'findVotiByUDA' => fn($udaId) => $this->findWhere('VOTI', ['id_uda' => $udaId]),
            'insertUDA' => fn($data) => $this->insertRow('UDA_ANAGRAFICA', $data),
            'updateUDA' => fn($id, $data) => $this->updateRow('UDA_ANAGRAFICA', 'id_uda', $id, $data),
            'deleteUDA' => fn($id) => $this->deleteRow('UDA_ANAGRAFICA', $id, 'id_uda'),
            'insertMateriale' => fn($data) => $this->insertRow('MATERIALI', $data),
            'deleteMateriale' => fn($id) => $this->deleteRow('MATERIALI', $id, 'id_materiale'),
            'insertObiettivo' => fn($data) => $this->insertRow('OBIETTIVI', $data),
            'deleteObiettivo' => fn($id) => $this->deleteRow('OBIETTIVI', $id, 'id_obiettivo'),
            'insertTest' => fn($data) => $this->insertRow('TEST', $data),
            'insertVoto' => fn($data) => $this->insertRow('VOTI', $data),
            'deleteVoto' => fn($id) => $this->deleteRow('VOTI', $id, 'id_voto'),
            'insertClasseAssegnata' => fn($data) => $this->insertRow('CLASSI_ASSEGNATE', $data),
        ];

        if (isset($methodMap[$method])) {
            return call_user_func_array($methodMap[$method], $arguments);
        }

        throw new \BadMethodCallException(
            "Metodo '{$method}' non supportato da GoogleSheetsDatabaseAdapter. " .
            "Usa i metodi generici dell'interfaccia: findAll, findWhere, insertRow, updateRow, deleteRow"
        );
    }

    /**
     * Ottiene la lista di tutti i fogli presenti nel Google Sheet
     *
     * @return array Array di nomi dei fogli
     * @throws \Exception Se non è possibile leggere la lista
     */
    public function getAllSheetNames(): array
    {
        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $sheets = [];

            foreach ($spreadsheet->getSheets() as $sheet) {
                $sheets[] = $sheet->getProperties()->getTitle();
            }

            return $sheets;
        } catch (\Exception $e) {
            throw new \Exception("Impossibile ottenere lista fogli: " . $e->getMessage());
        }
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
        if (!$this->sheetExists($sheetName)) {
            throw new \Exception("Foglio '{$sheetName}' non esiste");
        }

        try {
            // Leggi l'header (prima riga)
            $headerRange = "{$sheetName}!A1:ZZ1";
            $headerResponse = $this->service->spreadsheets_values->get(
                $this->spreadsheetId,
                $headerRange
            );
            $headers = $headerResponse->getValues()[0] ?? [];

            // Cancella tutto il contenuto del foglio
            $clearRange = "{$sheetName}!A2:ZZ";
            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                $clearRange,
                new \Google_Service_Sheets_ClearValuesRequest()
            );

            return true;
        } catch (\Exception $e) {
            throw new \Exception("Impossibile svuotare foglio '{$sheetName}': " . $e->getMessage());
        }
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
        if ($this->sheetExists($sheetName)) {
            throw new \Exception("Foglio '{$sheetName}' esiste già");
        }

        try {
            // Crea nuovo foglio
            $requests = [
                new \Google_Service_Sheets_Request([
                    'addSheet' => [
                        'properties' => [
                            'title' => $sheetName
                        ]
                    ]
                ])
            ];

            $batchUpdateRequest = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);

            $this->service->spreadsheets->batchUpdate(
                $this->spreadsheetId,
                $batchUpdateRequest
            );

            // Se sono fornite colonne, scrivile come header
            if (!empty($columns)) {
                $range = "{$sheetName}!A1";
                $values = [$columns];

                $body = new \Google_Service_Sheets_ValueRange([
                    'values' => $values
                ]);

                $this->service->spreadsheets_values->update(
                    $this->spreadsheetId,
                    $range,
                    $body,
                    ['valueInputOption' => 'RAW']
                );
            }

            return true;
        } catch (\Exception $e) {
            throw new \Exception("Impossibile creare foglio '{$sheetName}': " . $e->getMessage());
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
