<?php

namespace App\Core;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Exception;

/**
 * TemplateManager - Gestisce i template Excel per UDA, rubriche e voti
 */
class TemplateManager
{
    private array $config;
    private SpreadsheetFileService $fileService;

    public function __construct(array $config, ?SpreadsheetFileService $fileService = null)
    {
        $this->config = $config;
        $this->fileService = $fileService ?? SpreadsheetFileService::fromConfig($config);
    }

    /**
     * Carica un template Excel
     *
     * @param string $templateName Nome del template (senza path)
     * @return Spreadsheet
     */
    public function loadTemplate(string $templateName): Spreadsheet
    {
        return $this->fileService->loadTemplate($templateName);
    }

    /**
     * Crea una rubrica di valutazione da template
     *
     * @param array $udaData Dati dell'UDA
     * @param array $indicatori Indicatori personalizzati (opzionale)
     * @return string Path del file creato
     */
    public function createRubricaFromTemplate(array $udaData, array $indicatori = []): string
    {
        $spreadsheet = $this->loadTemplate('template_rubrica.xlsx');
        $sheet = $spreadsheet->getActiveSheet();

        // Compila intestazione
        $sheet->setCellValue('A1', 'Rubrica di Valutazione - ' . ($udaData['titolo'] ?? ''));
        $sheet->setCellValue('A2', 'UDA: ' . ($udaData['argomento'] ?? ''));
        $sheet->setCellValue('A3', 'Data: ' . date('d/m/Y'));

        // Usa indicatori personalizzati o default
        if (empty($indicatori)) {
            $indicatori = $this->getDefaultIndicatori();
        }

        // Popola indicatori (da riga 6 in poi)
        $row = 6;
        foreach ($indicatori as $indicatore) {
            $sheet->setCellValue("A{$row}", $indicatore['nome']);
            $sheet->setCellValue("B{$row}", $indicatore['peso']);

            // Livelli 1-5 (colonne C-G)
            for ($livello = 1; $livello <= 5; $livello++) {
                $col = chr(66 + $livello); // C, D, E, F, G
                $descrizione = $indicatore["livello_{$livello}"] ?? '';
                $sheet->setCellValue("{$col}{$row}", $descrizione);
            }

            $row++;
        }

        // Aggiungi formule per calcolo voto
        $this->addRubricaFormulas($sheet, $row, count($indicatori));

        // Salva file
        $fileName = 'rubrica_' . ($udaData['id_uda'] ?? uniqid()) . '.xlsx';
        $outputPath = $this->getOutputPath($udaData['id_uda'] ?? '', 'voti/rubriche', $fileName);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Crea un file voti da template
     *
     * @param string $udaId ID dell'UDA
     * @param array $studenti Lista studenti
     * @param string $tipoValutazione Tipo (orale, scritto, test, pratico)
     * @return string Path del file creato
     */
    public function createVotiFromTemplate(string $udaId, array $studenti, string $tipoValutazione = 'orale'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Intestazione
        $sheet->setCellValue('A1', 'Registro Voti - UDA: ' . $udaId);
        $sheet->setCellValue('A2', 'Tipo: ' . ucfirst($tipoValutazione));
        $sheet->setCellValue('A3', 'Data: ' . date('d/m/Y'));

        // Header colonne
        $headers = ['Cognome', 'Nome', 'Voto', 'Giudizio', 'Data Valutazione', 'Note'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}5", $header);
            $col++;
        }

        // Popola studenti
        $row = 6;
        foreach ($studenti as $studente) {
            $sheet->setCellValue("A{$row}", $studente['cognome'] ?? '');
            $sheet->setCellValue("B{$row}", $studente['nome'] ?? '');
            $sheet->setCellValue("C{$row}", ''); // Voto da compilare
            $sheet->setCellValue("D{$row}", ''); // Giudizio da compilare
            $sheet->setCellValue("E{$row}", ''); // Data da compilare
            $sheet->setCellValue("F{$row}", ''); // Note
            $row++;
        }

        // Formattazione
        $sheet->getStyle('A5:F5')->getFont()->setBold(true);
        $sheet->getStyle('A5:F5')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4CAF50');

        // Auto-size colonne
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Salva
        $fileName = "voti_{$tipoValutazione}_" . date('Y-m-d') . '.xlsx';
        $outputPath = $this->getOutputPath($udaId, 'voti/export', $fileName);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Applica dati a un template
     *
     * @param string $templateName Nome template
     * @param array $data Dati da applicare
     * @return Spreadsheet
     */
    public function applyDataToTemplate(string $templateName, array $data): Spreadsheet
    {
        $spreadsheet = $this->loadTemplate($templateName);
        $sheet = $spreadsheet->getActiveSheet();

        // Sostituisce placeholder nel formato {{chiave}}
        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';

            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $cellValue = $cell->getValue();
                    if (is_string($cellValue) && strpos($cellValue, $placeholder) !== false) {
                        $newValue = str_replace($placeholder, $value, $cellValue);
                        $cell->setValue($newValue);
                    }
                }
            }
        }

        return $spreadsheet;
    }

    /**
     * Salva spreadsheet in una posizione specifica
     */
    public function saveSpreadsheet(Spreadsheet $spreadsheet, string $udaId, string $category, string $fileName): string
    {
        $outputPath = $this->getOutputPath($udaId, $category, $fileName);
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Ottiene path di output per un file
     */
    private function getOutputPath(string $udaId, string $category, string $fileName): string
    {
        $year = $this->config['academic_year']['current'];
        $basePath = ROOT_PATH . '/' . $this->config['paths']['storage'] . "uda/{$year}/{$udaId}/{$category}";

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        return $basePath . '/' . $fileName;
    }

    /**
     * Indicatori di valutazione di default
     */
    private function getDefaultIndicatori(): array
    {
        $rubricaConfig = $this->config['rubrica']['default_structure'] ?? [];

        $indicatori = [];

        // Competenze trasversali
        if (isset($rubricaConfig['competenze_trasversali'])) {
            foreach ($rubricaConfig['competenze_trasversali'] as $comp) {
                $indicatori[] = [
                    'nome' => $comp['name'],
                    'peso' => $comp['weight'],
                    'livello_1' => 'Gravemente insufficiente',
                    'livello_2' => 'Insufficiente',
                    'livello_3' => 'Sufficiente',
                    'livello_4' => 'Buono',
                    'livello_5' => 'Ottimo'
                ];
            }
        }

        return $indicatori;
    }

    /**
     * Aggiunge formule per calcolo automatico voto nella rubrica
     */
    private function addRubricaFormulas(mixed $sheet, int $startRow, int $numIndicatori): void
    {
        $row = $startRow + 1;

        // Riga per punteggio selezionato per indicatore
        $sheet->setCellValue("A{$row}", 'Punteggio:');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);

        // Riga per totale e voto
        $totalRow = $row + 2;
        $sheet->setCellValue("A{$totalRow}", 'TOTALE PUNTEGGIO:');
        $sheet->setCellValue("A" . ($totalRow + 1), 'VOTO (su 10):');

        // Formula per somma pesi
        $pesoRange = "B6:B" . ($startRow - 1);
        $sheet->setCellValue("B{$totalRow}", "=SUM({$pesoRange})");

        // Formula per calcolo voto: (punteggio / peso_totale) * 10
        $punteggioRange = "C{$row}:C" . ($row + $numIndicatori - 1);
        $sheet->setCellValue("B" . ($totalRow + 1), "=(SUM({$punteggioRange})/B{$totalRow})*10");

        $sheet->getStyle("A{$totalRow}:B" . ($totalRow + 1))->getFont()->setBold(true);
        $sheet->getStyle("A{$totalRow}:B" . ($totalRow + 1))->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFD700');
    }
}
