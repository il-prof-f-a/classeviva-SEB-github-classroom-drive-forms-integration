<?php

namespace App\Core;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Exception;

/**
 * DatabaseManager - Classe per interagire con il database Excel/Google Sheet.
 *
 * Gestisce tutte le operazioni di lettura e scrittura sul file uda_master.xlsx.
 * Utilizza la libreria PHPSpreadsheet.
 */
class DatabaseManager
{
    private string $dbFilePath;

    /**
     * Il costruttore riceve la configurazione e imposta il percorso del database.
     */
    public function __construct(array $config)
    {
        $this->dbFilePath = ROOT_PATH . '/' . $config['database']['master_file'];

        if (!file_exists($this->dbFilePath)) {
            throw new Exception("File database non trovato al percorso: {$this->dbFilePath}");
        }
    }

    /**
     * Restituisce un'istanza dello Spreadsheet per operazioni avanzate
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     * @throws Exception Se il file non può essere caricato
     */
    public function getConnection()
    {
        try {
            return IOFactory::load($this->dbFilePath);
        } catch (Exception $e) {
            throw new Exception("Impossibile caricare il database Excel: " . $e->getMessage());
        }
    }

    /**
     * Legge tutte le righe dal foglio 'UDA_ANAGRAFICA' e le restituisce come array.
     *
     * @return array Un array di UDA, dove ogni UDA è un array associativo.
     * @throws Exception Se il foglio non può essere letto o non esiste.
     */
    public function findAllUDAs(): array
    {
        try {
            // Auto-crea foglio se mancante
            $this->ensureSheetExists('UDA_ANAGRAFICA');

            $spreadsheet = IOFactory::load($this->dbFilePath);
            $sheet = $spreadsheet->getSheetByName('UDA_ANAGRAFICA');

            if ($sheet === null) {
                throw new Exception("Il foglio 'UDA_ANAGRAFICA' non esiste nel file Excel.");
            }

            $data = $sheet->toArray(null, true, true, true);
            
            if (count($data) < 2) {
                return []; // Il foglio è vuoto o contiene solo l'intestazione
            }

            $headers = array_shift($data); // Estrae la prima riga (intestazioni)
            $udas = [];

            foreach ($data as $row) {
                // Converte la riga indicizzata in un array associativo usando le intestazioni
                $udas[] = array_combine(array_values($headers), array_values($row));
            }

            return $udas;

        } catch (Exception $e) {
            // Logga l'errore o gestiscilo in modo più granulare
            throw new Exception("Impossibile leggere il file Excel. Dettagli: " . $e->getMessage());
        }
    }

    /**
     * Trova una UDA per ID
     */
    public function findUDAById(string $id): ?array
    {
        $udas = $this->findAllUDAs();
        foreach ($udas as $uda) {
            if ($uda['id_uda'] === $id) {
                return $uda;
            }
        }
        return null;
    }

    /**
     * Inserisce una nuova UDA
     */
    public function insertUDA(array $data): bool
    {
        return $this->insertRow('UDA_ANAGRAFICA', $data);
    }

    /**
     * Aggiorna una UDA esistente
     */
    public function updateUDA(string $id, array $data): bool
    {
        return $this->updateRow('UDA_ANAGRAFICA', 'id_uda', $id, $data);
    }

    /**
     * Elimina una UDA
     */
    public function deleteUDA(string $id): bool
    {
        return $this->deleteRow('UDA_ANAGRAFICA', $id, 'id_uda');
    }

    // ============ MATERIALI ============

    public function findMaterialiByUDA(string $udaId): array
    {
        return $this->findRowsByField('MATERIALI', 'id_uda', $udaId);
    }

    public function insertMateriale(array $data): bool
    {
        return $this->insertRow('MATERIALI', $data);
    }

    public function deleteMateriale(string $id): bool
    {
        return $this->deleteRow('MATERIALI', $id, 'id_materiale');
    }

    // ============ OBIETTIVI ============

    public function findObiettiviByUDA(string $udaId): array
    {
        return $this->findRowsByField('OBIETTIVI', 'id_uda', $udaId);
    }

    public function insertObiettivo(array $data): bool
    {
        return $this->insertRow('OBIETTIVI', $data);
    }

    public function deleteObiettivo(string $id): bool
    {
        return $this->deleteRow('OBIETTIVI', $id, 'id_obiettivo');
    }

    // ============ TEST ============

    public function findTestByUDA(string $udaId): array
    {
        return $this->findRowsByField('TEST', 'id_uda', $udaId);
    }

    public function insertTest(array $data): bool
    {
        return $this->insertRow('TEST', $data);
    }

    public function updateTest(string $id, array $data): bool
    {
        return $this->updateRow('TEST', 'id_test', $id, $data);
    }

    // ============ VOTI ============

    public function findVotiByUDA(string $udaId): array
    {
        return $this->findRowsByField('VOTI', 'id_uda', $udaId);
    }

    public function insertVoto(array $data): bool
    {
        return $this->insertRow('VOTI', $data);
    }

    public function updateVoto(string $id, array $data): bool
    {
        return $this->updateRow('VOTI', 'id_voto', $id, $data);
    }

    public function deleteVoto(string $id): bool
    {
        return $this->deleteRow('VOTI', $id, 'id_voto');
    }

    // ============ CLASSI ASSEGNATE ============

    public function findClassiAssegnate(string $udaId): array
    {
        return $this->findRowsByField('CLASSI_ASSEGNATE', 'id_uda', $udaId);
    }

    public function insertClasseAssegnata(array $data): bool
    {
        return $this->insertRow('CLASSI_ASSEGNATE', $data);
    }

    public function updateClasseAssegnata(string $id, array $data): bool
    {
        return $this->updateRow('CLASSI_ASSEGNATE', 'id_assegnazione', $id, $data);
    }

    // ============ CLASSROOM MAPPINGS ============

    public function findAllClassroomMappings(): array
    {
        return $this->findAll('CLASSROOM_MAPPINGS');
    }

    public function findClassroomMapping(string $classId, string $subjectId): ?array
    {
        $all = $this->findAll('CLASSROOM_MAPPINGS');
        foreach ($all as $mapping) {
            if (($mapping['id_classe_cv'] ?? '') === $classId &&
                ($mapping['id_materia_cv'] ?? '') === $subjectId) {
                // Considera attivo se il campo stato non è valorizzato oppure è 'attivo'
                $stato = strtolower(trim((string)($mapping['stato'] ?? 'attivo')));
                if ($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1') {
                    return $mapping;
                }
            }
        }
        return null;
    }

    public function insertClassroomMapping(array $data): bool
    {
        return $this->insertRow('CLASSROOM_MAPPINGS', $data);
    }

    public function updateClassroomMapping(string $id, array $data): bool
    {
        return $this->updateRow('CLASSROOM_MAPPINGS', 'id_mapping', $id, $data);
    }

    public function deleteClassroomMapping(string $id): bool
    {
        return $this->deleteRow('CLASSROOM_MAPPINGS', $id, 'id_mapping');
    }

    public function clearAllClassroomMappings(): bool
    {
        try {
            // Auto-crea foglio se mancante
            $this->ensureSheetExists('CLASSROOM_MAPPINGS');

            $spreadsheet = IOFactory::load($this->dbFilePath);
            $sheet = $spreadsheet->getSheetByName('CLASSROOM_MAPPINGS');

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
            return false;
        }
    }

    // ============ METODI GENERICI ============

    /**
     * Legge tutti i record da un foglio
     */
    public function findAll(string $sheetName): array
    {
        // Auto-crea foglio se mancante e definito nello schema
        $this->ensureSheetExists($sheetName);

        $spreadsheet = IOFactory::load($this->dbFilePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            return [];
        }

        $data = $sheet->toArray(null, true, true, true);

        if (count($data) < 2) {
            return [];
        }

        $headers = array_shift($data);
        $result = [];

        foreach ($data as $row) {
            $result[] = array_combine(array_values($headers), array_values($row));
        }

        return $result;
    }

    /**
     * Trova record singolo per ID
     */
    public function findById(string $sheetName, string $id, string $idField = 'ID'): ?array
    {
        $all = $this->findAll($sheetName);
        foreach ($all as $row) {
            if (isset($row[$idField]) && $row[$idField] === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Trova record per campo specifico
     */
    private function findRowsByField(string $sheetName, string $fieldName, string $fieldValue): array
    {
        $all = $this->findAll($sheetName);
        return array_filter($all, function($row) use ($fieldName, $fieldValue) {
            return isset($row[$fieldName]) && $row[$fieldName] === $fieldValue;
        });
    }

    /**
     * Trova record con filtri multipli (WHERE con AND)
     *
     * OTTIMIZZAZIONE CRITICA: Questo metodo carica comunque tutto il foglio
     * (PhpSpreadsheet non supporta query), ma è più efficiente che
     * chiamare findAll() nel codice utente perché:
     * 1. Evita duplicazione di toArray()
     * 2. Filtra durante il loop invece di dopo
     * 3. Sintassi più pulita
     *
     * @param string $sheetName Nome del foglio
     * @param array $where Array associativo campo => valore (es: ['id_uda' => 'UDA_001', 'id_studente_cv' => '123'])
     * @return array Array di righe che matchano TUTTI i criteri
     */
    public function findWhere(string $sheetName, array $where): array
    {
        // Se nessun filtro, ritorna tutto
        if (empty($where)) {
            return $this->findAll($sheetName);
        }

        // Carica il foglio (UNA sola volta)
        $this->ensureSheetExists($sheetName);
        $spreadsheet = IOFactory::load($this->dbFilePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            return [];
        }

        $data = $sheet->toArray(null, true, true, true);

        if (count($data) < 2) {
            return [];
        }

        $headers = array_shift($data);
        $result = [];

        // Filtra durante la conversione (più efficiente)
        foreach ($data as $row) {
            $rowAssoc = array_combine(array_values($headers), array_values($row));

            // Controlla se la riga matcha TUTTI i criteri
            $matches = true;
            foreach ($where as $field => $value) {
                // Usa confronto loose (!=) invece di strict (!==) per gestire int/string
                if (!isset($rowAssoc[$field]) || $rowAssoc[$field] != $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $result[] = $rowAssoc;
            }
        }

        return $result;
    }

    /**
     * Inserisce una riga in un foglio
     */
    public function insertRow(string $sheetName, array $data): bool
    {
        // Auto-crea foglio se mancante e definito nello schema
        $created = $this->ensureSheetExists($sheetName);

        $spreadsheet = IOFactory::load($this->dbFilePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            if ($created) {
                throw new Exception("Foglio {$sheetName} creato ma non trovato (errore interno)");
            } else {
                throw new Exception("Foglio {$sheetName} non trovato e non definito nello schema. Aggiungi definizione in SchemaDefinitions.php");
            }
        }

        // Trova ultima riga
        $lastRow = $sheet->getHighestRow();
        $newRow = $lastRow + 1;

        // Leggi intestazioni
        $headers = $sheet->rangeToArray("A1:{$sheet->getHighestColumn()}1")[0];

        // Inserisci dati
        $col = 'A';
        foreach ($headers as $header) {
            $value = $data[$header] ?? '';
            $sheet->setCellValue("{$col}{$newRow}", $value);
            $col++;
        }

        // Salva
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($this->dbFilePath);

        return true;
    }

    /**
     * Aggiorna una riga
     */
    public function updateRow(string $sheetName, string $keyField, string $keyValue, array $data): bool
    {
        // Auto-crea foglio se mancante e definito nello schema
        $this->ensureSheetExists($sheetName);

        $spreadsheet = IOFactory::load($this->dbFilePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            return false;
        }

        $headers = $sheet->rangeToArray("A1:{$sheet->getHighestColumn()}1")[0];
        $keyCol = array_search($keyField, $headers);

        if ($keyCol === false) {
            return false;
        }

        // Trova riga da aggiornare
        $lastRow = $sheet->getHighestRow();
        for ($row = 2; $row <= $lastRow; $row++) {
            $cellValue = $sheet->getCellByColumnAndRow($keyCol + 1, $row)->getValue();
            if ($cellValue === $keyValue) {
                // Aggiorna riga
                foreach ($data as $field => $value) {
                    $col = array_search($field, $headers);
                    if ($col !== false) {
                        $sheet->setCellValueByColumnAndRow($col + 1, $row, $value);
                    }
                }

                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save($this->dbFilePath);
                return true;
            }
        }

        return false;
    }

    /**
     * Elimina una riga
     */
    public function deleteRow(string $sheetName, string $keyValue, string $keyField = 'ID'): bool
    {
        // Auto-crea foglio se mancante e definito nello schema
        $this->ensureSheetExists($sheetName);

        $spreadsheet = IOFactory::load($this->dbFilePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            return false;
        }

        $headers = $sheet->rangeToArray("A1:{$sheet->getHighestColumn()}1")[0];
        $keyCol = array_search($keyField, $headers);

        if ($keyCol === false) {
            return false;
        }

        $lastRow = $sheet->getHighestRow();
        for ($row = 2; $row <= $lastRow; $row++) {
            $cellValue = $sheet->getCellByColumnAndRow($keyCol + 1, $row)->getValue();
            if ($cellValue === $keyValue) {
                $sheet->removeRow($row);
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save($this->dbFilePath);
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica che un foglio esista, altrimenti lo crea automaticamente
     *
     * @param string $sheetName Nome del foglio
     * @return bool True se il foglio esiste o è stato creato, false se non è definito nello schema
     */
    public function ensureSheetExists(string $sheetName): bool
    {
        $spreadsheet = IOFactory::load($this->dbFilePath);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        // Se il foglio esiste già, ok
        if ($sheet !== null) {
            return true;
        }

        // Se il foglio non è definito nello schema, non lo creare
        if (!SchemaDefinitions::isSheetDefined($sheetName)) {
            return false;
        }

        // Crea il foglio automaticamente
        return $this->createSheet($sheetName);
    }

    /**
     * Crea un nuovo foglio con le colonne definite nello schema o custom
     *
     * @param string $sheetName Nome del foglio da creare
     * @param array|null $customColumns Colonne custom (se null usa schema)
     * @return bool True se creato con successo
     * @throws Exception Se il foglio non è definito nello schema o la creazione fallisce
     */
    public function createSheet(string $sheetName, ?array $customColumns = null): bool
    {
        // Se sono fornite colonne custom, usale
        if ($customColumns !== null) {
            $columns = $customColumns;
            $description = "Custom sheet";
        } else {
            // Altrimenti usa lo schema
            $definition = SchemaDefinitions::getSheetDefinition($sheetName);

            if ($definition === null) {
                throw new Exception("Foglio '{$sheetName}' non definito nello schema");
            }

            $columns = $definition['columns'];
            $description = $definition['description'];
        }

        try {
            $spreadsheet = IOFactory::load($this->dbFilePath);

            // Crea nuovo foglio
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);

            // Scrivi intestazioni
            $col = 'A';
            foreach ($columns as $columnName) {
                $sheet->setCellValue("{$col}1", $columnName);
                $col++;
            }

            // Stile intestazioni
            $lastCol = chr(ord('A') + count($columns) - 1);
            $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
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

            // Larghezza colonne automatica
            foreach (range('A', $lastCol) as $columnID) {
                $sheet->getColumnDimension($columnID)->setWidth(20);
            }

            // Freeze prima riga
            $sheet->freezePane('A2');

            // Salva
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($this->dbFilePath);

            // Log creazione (se debug abilitato)
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("📋 Auto-creato foglio '{$sheetName}': {$description}");
            }

            return true;

        } catch (Exception $e) {
            throw new Exception("Errore creazione foglio '{$sheetName}': " . $e->getMessage());
        }
    }

    /**
     * Crea backup del database
     */
    public function createBackup(): string
    {
        $backupDir = ROOT_PATH . '/database/backup';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . '/uda_master_' . date('Y-m-d_H-i-s') . '.xlsx';
        copy($this->dbFilePath, $backupFile);

        return $backupFile;
    }

    /**
     * Carica un file Excel esterno (template, import, etc.)
     *
     * @param string $filePath Path assoluto del file
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     * @throws Exception Se il file non può essere caricato
     */
    public function loadExternalFile(string $filePath): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // Validazione sicurezza
        if (!file_exists($filePath)) {
            throw new Exception("File non trovato: {$filePath}");
        }

        if (!is_file($filePath)) {
            throw new Exception("Path non è un file valido: {$filePath}");
        }

        // Verifica estensione
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            throw new Exception("Tipo file non supportato: {$ext}");
        }

        // Verifica dimensione (max 50MB)
        $maxSize = 50 * 1024 * 1024;
        if (filesize($filePath) > $maxSize) {
            throw new Exception("File troppo grande (max 50MB)");
        }

        // Usa realpath per sicurezza
        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new Exception("Path non valido: {$filePath}");
        }

        try {
            return IOFactory::load($realPath);
        } catch (Exception $e) {
            throw new Exception("Impossibile caricare file: " . $e->getMessage());
        }
    }

    /**
     * Carica un template Excel dalla directory template
     *
     * @param string $templateName Nome del template (es: 'template_rubrica.xlsx')
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     * @throws Exception Se il template non esiste
     */
    public function loadTemplate(string $templateName): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // Sanitizza nome
        if (!preg_match('/^[a-zA-Z0-9_. -]+\.xlsx?$/i', $templateName)) {
            throw new Exception("Nome template non valido: {$templateName}");
        }

        $templatePath = ROOT_PATH . '/database/templates/' . $templateName;

        if (!file_exists($templatePath)) {
            // Prova anche nella directory Materiale (vecchia posizione)
            $templatePath = ROOT_PATH . '/Materiale/' . $templateName;
            if (!file_exists($templatePath)) {
                throw new Exception("Template non trovato: {$templateName}");
            }
        }

        return $this->loadExternalFile($templatePath);
    }

    /**
     * Ottiene la lista di tutti i fogli presenti nel database Excel
     *
     * @return array Array di nomi dei fogli
     * @throws Exception Se non è possibile leggere la lista
     */
    public function getAllSheetNames(): array
    {
        try {
            $spreadsheet = IOFactory::load($this->dbFilePath);
            return $spreadsheet->getSheetNames();
        } catch (Exception $e) {
            throw new Exception("Impossibile ottenere lista fogli: " . $e->getMessage());
        }
    }

    /**
     * Svuota completamente un foglio rimuovendo tutte le righe dati
     * Mantiene l'header (prima riga)
     *
     * @param string $sheetName Nome del foglio
     * @return bool True se lo svuotamento ha successo
     * @throws Exception Se lo svuotamento fallisce
     */
    public function clearSheet(string $sheetName): bool
    {
        try {
            $spreadsheet = IOFactory::load($this->dbFilePath);

            if (!$spreadsheet->sheetNameExists($sheetName)) {
                throw new Exception("Foglio '{$sheetName}' non esiste");
            }

            $worksheet = $spreadsheet->getSheetByName($sheetName);
            $highestRow = $worksheet->getHighestRow();

            // Elimina tutte le righe tranne l'header (prima riga)
            if ($highestRow > 1) {
                $worksheet->removeRow(2, $highestRow - 1);
            }

            // Salva
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($this->dbFilePath);

            return true;
        } catch (Exception $e) {
            throw new Exception("Impossibile svuotare foglio '{$sheetName}': " . $e->getMessage());
        }
    }

}
