<?php

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Models\Obiettivo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Exception;

/**
 * ObiettiviManager - Gestisce gli obiettivi didattici/disciplinari
 *
 * Funzionalità:
 * - Import bulk da file Excel/CSV
 * - Gestione repository master obiettivi riutilizzabili
 * - Ricerca e filtro obiettivi
 * - Associazione obiettivi a UDA
 * - Validazione dati
 */
class ObiettiviManager
{
    private DatabaseAdapterInterface $db;
    private array $config;
    private SpreadsheetFileService $fileService;

    public function __construct(
        DatabaseAdapterInterface $db,
        array $config,
        ?SpreadsheetFileService $fileService = null
    )
    {
        $this->db = $db;
        $this->config = $config;
        $this->fileService = $fileService ?? SpreadsheetFileService::fromConfig($config);
    }

    /**
     * Importa obiettivi da file Excel o CSV
     *
     * @param string $filePath Percorso del file da importare
     * @param string|null $udaId ID UDA per associazione diretta (null = import in master)
     * @param bool $validazioneStretta Se true, richiede tutti i campi obbligatori
     * @param string|null $originalFileName Nome originale del file (per determinare l'estensione)
     * @return array Array con risultati: ['imported' => n, 'errors' => [], 'obiettivi' => []]
     */
    public function importDaFile(string $filePath, ?string $udaId = null, bool $validazioneStretta = false, ?string $originalFileName = null): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("File non trovato: $filePath");
        }

        $risultato = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
            'obiettivi' => []
        ];

        // Determina il tipo di file dal nome originale se disponibile, altrimenti dal path
        $fileToCheck = $originalFileName ?? $filePath;
        $ext = strtolower(pathinfo($fileToCheck, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $dati = $this->parseCSV($filePath);
        } elseif (in_array($ext, ['xlsx', 'xls'])) {
            $dati = $this->parseExcel($filePath);
        } else {
            throw new Exception("Formato file non supportato: $ext. Usa Excel (.xlsx) o CSV (.csv)");
        }

        // Processa ogni riga
        foreach ($dati as $idx => $riga) {
            $numeroRiga = $idx + 2; // +2 perché indice parte da 0 e c'è intestazione

            // Valida la riga
            $erroriValidazione = $this->validaRigaImport($riga, $validazioneStretta);

            if (!empty($erroriValidazione)) {
                $risultato['errors'][] = [
                    'riga' => $numeroRiga,
                    'errori' => $erroriValidazione,
                    'dati' => $riga
                ];
                $risultato['skipped']++;
                continue;
            }

            // Crea obiettivo
            $obiettivo = $this->creaObiettivoDaRiga($riga, $udaId);

            // Salva l'obiettivo
            try {
                if ($udaId) {
                    // Salva nel foglio OBIETTIVI (associato a UDA)
                    $this->salvaObiettivo($obiettivo);
                } else {
                    // Salva nel foglio OBIETTIVI_MASTER
                    $this->salvaObiettivoMaster($obiettivo);
                }

                $risultato['obiettivi'][] = $obiettivo;
                $risultato['imported']++;
            } catch (Exception $e) {
                $risultato['errors'][] = [
                    'riga' => $numeroRiga,
                    'errori' => ['Errore salvataggio: ' . $e->getMessage()],
                    'dati' => $riga
                ];
                $risultato['skipped']++;
            }
        }

        return $risultato;
    }

    /**
     * Parse file CSV
     */
    private function parseCSV(string $filePath): array
    {
        $dati = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new Exception("Impossibile aprire il file CSV");
        }

        // Leggi intestazioni e rileva separatore
        $firstLine = fgets($handle);

        if ($firstLine === false) {
            throw new Exception("File CSV vuoto o malformato");
        }

        $delimiter = $this->detectCsvDelimiter($firstLine);
        $headers = str_getcsv($firstLine, $delimiter);

        // Normalizza intestazioni (rimuovi spazi, converti a lowercase)
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);
        if (!empty($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }

        // Leggi righe
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $row = array_pad($row, count($headers), '');
            if (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }

            if (!empty(array_filter($row, fn($value) => trim((string)$value) !== ''))) {
                $dati[] = array_combine($headers, $row);
            }
        }

        fclose($handle);

        return $dati;
    }

    /**
     * Rileva il separatore CSV piu' probabile.
     */
    private function detectCsvDelimiter(string $sample): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $maxFields = 0;

        foreach ($delimiters as $delimiter) {
            $fields = str_getcsv($sample, $delimiter);
            $count = count($fields);
            if ($count > $maxFields) {
                $maxFields = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    /**
     * Parse file Excel
     */
    private function parseExcel(string $filePath): array
    {
        $spreadsheet = $this->fileService->loadExternalFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $data = $sheet->toArray(null, true, true, true);

        if (empty($data)) {
            throw new Exception("File Excel vuoto");
        }

        // Prima riga = intestazioni
        $headers = array_shift($data);
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        $dati = [];
        foreach ($data as $row) {
            if (!empty(array_filter($row))) { // Salta righe vuote
                $dati[] = array_combine($headers, $row);
            }
        }

        return $dati;
    }

    /**
     * Valida una riga di import
     */
    private function validaRigaImport(array $riga, bool $stretta = false): array
    {
        $errori = [];

        // Campi obbligatori base
        if (empty($riga['tipo_obiettivo'] ?? '')) {
            $errori[] = "Campo 'tipo_obiettivo' obbligatorio";
        }

        if (empty($riga['descrizione'] ?? '')) {
            $errori[] = "Campo 'descrizione' obbligatorio";
        }

        // Valida tipo_obiettivo
        $tipiValidi = array_keys(Obiettivo::TIPI_OBIETTIVO);
        if (!empty($riga['tipo_obiettivo']) && !in_array($riga['tipo_obiettivo'], $tipiValidi)) {
            $errori[] = "tipo_obiettivo non valido. Valori ammessi: " . implode(', ', $tipiValidi);
        }

        // Valida livello_tassonomia
        if (!empty($riga['livello_tassonomia'])) {
            $livello = (int)$riga['livello_tassonomia'];
            if ($livello < 1 || $livello > 6) {
                $errori[] = "livello_tassonomia deve essere tra 1 e 6";
            }
        }

        // Validazione stretta
        if ($stretta) {
            if (empty($riga['competenza'] ?? '')) {
                $errori[] = "Campo 'competenza' obbligatorio (validazione stretta)";
            }

            if (empty($riga['livello_tassonomia'] ?? '')) {
                $errori[] = "Campo 'livello_tassonomia' obbligatorio (validazione stretta)";
            }
        }

        return $errori;
    }

    /**
     * Crea un obiettivo da una riga di dati
     */
    private function creaObiettivoDaRiga(array $riga, ?string $udaId): Obiettivo
    {
        $obiettivo = new Obiettivo();
        $obiettivo->id_obiettivo = 'OBT_' . uniqid();
        $obiettivo->id_uda = $udaId;
        $obiettivo->tipo_obiettivo = $riga['tipo_obiettivo'] ?? null;
        $obiettivo->codice = $riga['codice'] ?? null;
        $obiettivo->descrizione = $riga['descrizione'] ?? null;
        $obiettivo->competenza = $riga['competenza'] ?? null;
        $obiettivo->livello_tassonomia = isset($riga['livello_tassonomia']) ? (int)$riga['livello_tassonomia'] : null;
        $obiettivo->peso = isset($riga['peso']) ? (int)$riga['peso'] : 30;
        $obiettivo->raggiunto = 0;
        $obiettivo->note = $riga['note'] ?? null;

        // Campi master
        $obiettivo->area_disciplinare = $riga['area_disciplinare'] ?? null;
        $obiettivo->parole_chiave = $riga['parole_chiave'] ?? null;
        $obiettivo->riutilizzabile = isset($riga['riutilizzabile']) ? (bool)$riga['riutilizzabile'] : true;

        return $obiettivo;
    }

    /**
     * Salva un obiettivo nel foglio OBIETTIVI (associato a UDA)
     */
    public function salvaObiettivo(Obiettivo $obiettivo): bool
    {
        return $this->db->insertRow('OBIETTIVI', $obiettivo->toArray());
    }

    /**
     * Salva un obiettivo nel foglio OBIETTIVI_MASTER
     */
    public function salvaObiettivoMaster(Obiettivo $obiettivo): bool
    {
        // Assicurati che id_uda sia null per obiettivi master
        $obiettivo->id_uda = null;

        $data = $obiettivo->toMasterArray();
        $data['data_creazione'] = date('Y-m-d');

        return $this->db->insertRow('OBIETTIVI_MASTER', $data);
    }

    /**
     * Recupera tutti gli obiettivi master con filtri opzionali
     *
     * @param array $filtri Filtri possibili: tipo_obiettivo, area_disciplinare, livello_tassonomia, keyword
     * @return Obiettivo[]
     */
    public function getObiettiviMaster(array $filtri = []): array
    {
        $rows = $this->db->findAll('OBIETTIVI_MASTER');
        $obiettivi = [];

        foreach ($rows as $row) {
            // Applica filtri
            if (!empty($filtri['tipo_obiettivo']) && $row['tipo_obiettivo'] !== $filtri['tipo_obiettivo']) {
                continue;
            }

            if (!empty($filtri['area_disciplinare']) && $row['area_disciplinare'] !== $filtri['area_disciplinare']) {
                continue;
            }

            if (!empty($filtri['livello_tassonomia']) && (int)$row['livello_tassonomia'] !== (int)$filtri['livello_tassonomia']) {
                continue;
            }

            if (!empty($filtri['keyword'])) {
                $keyword = strtolower($filtri['keyword']);
                $descrizione = strtolower($row['descrizione'] ?? '');
                $paroleChiave = strtolower($row['parole_chiave'] ?? '');

                if (strpos($descrizione, $keyword) === false && strpos($paroleChiave, $keyword) === false) {
                    continue;
                }
            }

            $obiettivi[] = Obiettivo::fromArray($row);
        }

        return $obiettivi;
    }

    /**
     * Recupera gli obiettivi associati a una UDA
     */
    public function getObiettiviByUDA(string $udaId): array
    {
        $rows = $this->db->findObiettiviByUDA($udaId);
        $obiettivi = [];

        foreach ($rows as $row) {
            $obiettivi[] = Obiettivo::fromArray($row);
        }

        return $obiettivi;
    }

    /**
     * Associa obiettivi master a una UDA (clonandoli)
     *
     * @param array $obiettiviIds Array di ID obiettivi master
     * @param string $udaId ID UDA
     * @return int Numero di obiettivi associati
     */
    public function associaObiettiviAUDA(array $obiettiviIds, string $udaId): int
    {
        $count = 0;

        foreach ($obiettiviIds as $obiettivoId) {
            // Recupera obiettivo master
            $obiettivoMaster = $this->getObiettivoMaster($obiettivoId);

            if (!$obiettivoMaster) {
                continue;
            }

            // Clona per UDA
            $obiettivoUDA = $obiettivoMaster->clonaPerUDA($udaId);

            // Salva
            if ($this->salvaObiettivo($obiettivoUDA)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Recupera un obiettivo master per ID
     */
    public function getObiettivoMaster(string $id): ?Obiettivo
    {
        $row = $this->db->findById('OBIETTIVI_MASTER', $id, 'id_obiettivo');

        if (!$row) {
            return null;
        }

        return Obiettivo::fromArray($row);
    }

    /**
     * Elimina un obiettivo
     */
    public function eliminaObiettivo(string $id): bool
    {
        return $this->db->deleteRow('OBIETTIVI', $id, 'id_obiettivo');
    }

    /**
     * Elimina un obiettivo master
     */
    public function eliminaObiettivoMaster(string $id): bool
    {
        return $this->db->deleteRow('OBIETTIVI_MASTER', $id, 'id_obiettivo');
    }

    /**
     * Restituisce le aree disciplinari disponibili
     */
    public function getAreeDisciplinari(): array
    {
        $rows = $this->db->findAll('OBIETTIVI_MASTER');
        $aree = [];

        foreach ($rows as $row) {
            if (!empty($row['area_disciplinare']) && !in_array($row['area_disciplinare'], $aree)) {
                $aree[] = $row['area_disciplinare'];
            }
        }

        sort($aree);
        return $aree;
    }

    /**
     * Genera un file template Excel per l'import
     */
    public function generaTemplateExcel(string $outputPath): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Obiettivi');

        // Intestazioni
        $headers = [
            'A' => 'tipo_obiettivo',
            'B' => 'codice',
            'C' => 'descrizione',
            'D' => 'competenza',
            'E' => 'livello_tassonomia',
            'F' => 'peso',
            'G' => 'area_disciplinare',
            'H' => 'parole_chiave',
            'I' => 'note'
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getStyle($col . '1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4472C4');
            $sheet->getStyle($col . '1')->getFont()->getColor()->setRGB('FFFFFF');
        }

        // Riga di esempio
        $sheet->setCellValue('A2', 'conoscenze');
        $sheet->setCellValue('B2', 'INF_01');
        $sheet->setCellValue('C2', 'Conoscere i principi dei sistemi operativi');
        $sheet->setCellValue('D2', 'Competenze tecniche informatiche');
        $sheet->setCellValue('E2', '2');
        $sheet->setCellValue('F2', '30');
        $sheet->setCellValue('G2', 'Informatica');
        $sheet->setCellValue('H2', 'sistemi operativi, processi');
        $sheet->setCellValue('I2', 'Esempio');

        // Auto-size colonne
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Salva
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Recupera tutti gli obiettivi associati a una UDA
     *
     * @param string $idUda ID della UDA
     * @return array Array di oggetti Obiettivo
     */
    public function getObiettiviPerUDA(string $idUda): array
    {
        $tuttiObiettivi = $this->db->findAll('OBIETTIVI');

        $obiettiviUDA = array_filter($tuttiObiettivi, function($obj) use ($idUda) {
            return ($obj['id_uda'] ?? '') === $idUda;
        });

        // Converti in oggetti Obiettivo
        $obiettivi = [];
        foreach ($obiettiviUDA as $dati) {
            $obiettivi[] = Obiettivo::fromArray($dati);
        }

        return $obiettivi;
    }
}
