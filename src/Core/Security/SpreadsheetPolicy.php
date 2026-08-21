<?php

declare(strict_types=1);

namespace App\Core\Security;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class SpreadsheetPolicy
{
    public static function assertWithinLimits(
        string $path,
        int $maxSheets = 10,
        int $maxRows = 20000,
        int $maxColumns = 200,
        int $maxCells = 1000000
    ): void {
        $reader = IOFactory::createReaderForFile($path);
        self::assertWorksheetInfo($reader->listWorksheetInfo($path), $maxSheets, $maxRows, $maxColumns, $maxCells);
    }

    /** @param list<array<string,mixed>> $worksheetInfo */
    public static function assertWorksheetInfo(
        array $worksheetInfo,
        int $maxSheets = 10,
        int $maxRows = 20000,
        int $maxColumns = 200,
        int $maxCells = 1000000
    ): void {
        if (count($worksheetInfo) > $maxSheets) {
            throw new RuntimeException('Il file contiene troppi fogli');
        }
        $rows = 0;
        $cells = 0;
        foreach ($worksheetInfo as $sheet) {
            $sheetRows = max(0, (int)($sheet['totalRows'] ?? 0));
            $sheetColumns = max(0, (int)($sheet['totalColumns'] ?? 0));
            if ($sheetColumns > $maxColumns) {
                throw new RuntimeException('Il file contiene troppe colonne');
            }
            $rows += $sheetRows;
            $cells += $sheetRows * $sheetColumns;
            if ($rows > $maxRows) {
                throw new RuntimeException('Il file contiene troppe righe');
            }
            if ($cells > $maxCells) {
                throw new RuntimeException('Il file contiene troppe celle');
            }
        }
    }
}
