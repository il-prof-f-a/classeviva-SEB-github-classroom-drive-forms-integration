<?php
/**
 * Script per inizializzare il foglio MAPPATURA_STUDENTI
 * con le colonne corrette
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$dbFilePath = ROOT_PATH . '/' . $config['database']['master_file'];

if (!file_exists($dbFilePath)) {
    die("❌ File database non trovato: $dbFilePath");
}

try {
    echo "📂 Apertura file database...\n";
    $spreadsheet = IOFactory::load($dbFilePath);

    // Verifica se il foglio esiste già
    $sheetExists = false;
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        if ($sheet->getTitle() === 'MAPPATURA_STUDENTI') {
            $sheetExists = true;
            $targetSheet = $sheet;
            break;
        }
    }

    if ($sheetExists) {
        echo "⚠️  Il foglio MAPPATURA_STUDENTI esiste già.\n";
        echo "🔍 Verifico le colonne esistenti...\n\n";

        // Leggi intestazioni esistenti
        $existingHeaders = $targetSheet->rangeToArray('A1:Z1', null, true, true, true)[1];
        $existingHeaders = array_filter($existingHeaders);

        if (!empty($existingHeaders)) {
            echo "📋 Colonne trovate:\n";
            foreach ($existingHeaders as $col => $header) {
                echo "   - $col: $header\n";
            }
            echo "\n";

            $response = readline("❓ Vuoi sovrascrivere il foglio esistente? (s/n): ");
            if (strtolower($response) !== 's') {
                echo "❌ Operazione annullata.\n";
                exit;
            }

            // Elimina il foglio esistente
            $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($targetSheet));
            echo "🗑️  Foglio esistente eliminato.\n";
        }
    }

    // Crea nuovo foglio
    echo "✨ Creazione nuovo foglio MAPPATURA_STUDENTI...\n";
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('MAPPATURA_STUDENTI');

    // Definisci le colonne
    $headers = [
        'A1' => 'id_mappatura',
        'B1' => 'id_mapping_materia',
        'C1' => 'id_studente_cv',
        'D1' => 'id_studente_gc',
        'E1' => 'data_associazione',
        'F1' => 'stato',
        'G1' => 'confermato_da',
        'H1' => 'note'
    ];

    echo "\n📝 Colonne da creare:\n";
    foreach ($headers as $cell => $header) {
        echo "   - $cell: $header\n";
        $sheet->setCellValue($cell, $header);
    }

    // Stile intestazioni
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 11
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0066CC']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ];

    $sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

    // Larghezza colonne
    $sheet->getColumnDimension('A')->setWidth(20); // id_mappatura
    $sheet->getColumnDimension('B')->setWidth(20); // id_mapping_materia
    $sheet->getColumnDimension('C')->setWidth(18); // id_studente_cv
    $sheet->getColumnDimension('D')->setWidth(18); // id_studente_gc
    $sheet->getColumnDimension('E')->setWidth(15); // data_associazione
    $sheet->getColumnDimension('F')->setWidth(12); // stato
    $sheet->getColumnDimension('G')->setWidth(15); // confermato_da
    $sheet->getColumnDimension('H')->setWidth(30); // note

    // Freeze prima riga
    $sheet->freezePane('A2');

    // Salva il file
    echo "\n💾 Salvataggio file...\n";
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($dbFilePath);

    echo "\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "✅ FOGLIO MAPPATURA_STUDENTI CREATO CON SUCCESSO!\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "\n";
    echo "📊 Struttura foglio:\n";
    echo "   - id_mappatura: ID univoco mappatura (es: MAPSTUD_xxx)\n";
    echo "   - id_mapping_materia: Riferimento a CLASSROOM_MAPPINGS.id_mapping\n";
    echo "   - id_studente_cv: ID studente ClasseViva (SOLO ID, no nome)\n";
    echo "   - id_studente_gc: ID studente Google Classroom (SOLO ID, no nome)\n";
    echo "   - data_associazione: Data creazione (formato: d/m/Y)\n";
    echo "   - stato: 'attivo' o 'inattivo'\n";
    echo "   - confermato_da: 'manuale' o 'automatico'\n";
    echo "   - note: Note opzionali\n";
    echo "\n";
    echo "🔒 GDPR Compliant: Vengono salvati SOLO gli ID, mai dati personali!\n";
    echo "   I nomi vengono letti real-time dalle API esterne.\n";
    echo "\n";
    echo "🚀 Ora puoi usare la funzione 'Associa Studenti' in map_classes.php\n";
    echo "\n";

} catch (Exception $e) {
    echo "\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "❌ ERRORE!\n";
    echo "═══════════════════════════════════════════════════════\n";
    echo "\n";
    echo "Errore: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Linea: " . $e->getLine() . "\n";
    echo "\n";
    exit(1);
}
