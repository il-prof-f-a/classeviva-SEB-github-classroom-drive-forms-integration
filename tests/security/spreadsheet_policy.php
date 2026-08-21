<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/SpreadsheetPolicy.php';

use App\Core\Security\SpreadsheetPolicy;

$throws = static function (array $info): void {
    try {
        SpreadsheetPolicy::assertWorksheetInfo($info, 2, 100, 20, 1000);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Limite foglio non applicato');
};

SpreadsheetPolicy::assertWorksheetInfo([
    ['worksheetName' => 'A', 'totalRows' => 50, 'totalColumns' => 10],
    ['worksheetName' => 'B', 'totalRows' => 50, 'totalColumns' => 10],
], 2, 100, 20, 1000);
$throws(array_fill(0, 3, ['worksheetName' => 'A', 'totalRows' => 1, 'totalColumns' => 1]));
$throws([['worksheetName' => 'A', 'totalRows' => 101, 'totalColumns' => 1]]);
$throws([['worksheetName' => 'A', 'totalRows' => 1, 'totalColumns' => 21]]);
$throws([['worksheetName' => 'A', 'totalRows' => 100, 'totalColumns' => 11]]);

fwrite(STDOUT, "PASS: limiti strutturali fogli di calcolo.\n");
