<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/SchemaDefinitions.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/SqlIdentifierValidator.php';

use App\Core\Database\SqlIdentifierValidator;

SqlIdentifierValidator::assertColumn('UDA_ANAGRAFICA', 'id_uda');
foreach (["id` = 'x' OR 1=1 -- ", 'id; DROP TABLE UDA_ANAGRAFICA', 'missing_column'] as $field) {
    try {
        SqlIdentifierValidator::assertColumn('UDA_ANAGRAFICA', $field);
        fwrite(STDERR, "FAIL: identificatore accettato: {$field}\n");
        exit(1);
    } catch (InvalidArgumentException) {
    }
}
fwrite(STDOUT, "PASS: identificatori SQL limitati allo schema.\n");
