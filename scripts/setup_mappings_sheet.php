<?php
require_once __DIR__ . '/../bootstrap.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$dbFilePath = __DIR__ . '/../database/uda_master.xlsx';

echo "=== SETUP FOGLIO CLASSROOM_MAPPINGS ===\n\n";

try {
    $spreadsheet = IOFactory::load($dbFilePath);
    
    // Verifica se il foglio esiste già
    $sheet = $spreadsheet->getSheetByName('CLASSROOM_MAPPINGS');
    
    if ($sheet !== null) {
        echo "⚠ Foglio CLASSROOM_MAPPINGS già esistente\n";
        echo "Vuoi sovrascriverlo? (elimina prima il foglio manualmente)\n";
        exit(0);
    }
    
    // Crea nuovo foglio
    $sheet = new PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'CLASSROOM_MAPPINGS');
    $spreadsheet->addSheet($sheet);
    
    echo "✓ Foglio CLASSROOM_MAPPINGS creato\n\n";
    
    // Definisci intestazioni secondo lo schema storico CLASSROOM_MAPPINGS
    $headers = [
        'id_mapping',
        'id_classe_cv',
        'nome_classe_cv',
        'id_materia_cv',
        'nome_materia_cv',
        'id_corso_gc',
        'nome_corso_gc',
        'data_mapping',
        'stato',
        'note',
        'id_utente'
    ];
    
    // Scrivi intestazioni
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    
    echo "Intestazioni create:\n";
    foreach ($headers as $idx => $header) {
        $colLetter = chr(65 + $idx);
        echo "  {$colLetter}: {$header}\n";
    }
    
    // Salva
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($dbFilePath);
    
    echo "\n✓ Database salvato con successo!\n";
    echo "✓ Foglio CLASSROOM_MAPPINGS pronto all'uso\n";
    
} catch (Exception $e) {
    echo "✗ Errore: " . $e->getMessage() . "\n";
    exit(1);
}
