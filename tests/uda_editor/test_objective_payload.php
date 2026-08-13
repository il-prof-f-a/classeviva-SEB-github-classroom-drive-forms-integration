<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/QuestionEditorHelper.php';

use App\Utils\QuestionEditorHelper;

$source = file_get_contents(dirname(__DIR__, 2) . '/public/uda_create.php');
if (str_contains($source, 'name="obiettivi_peso[]"') || str_contains($source, "obiettivi_peso")) {
    fwrite(STDERR, "FAIL: il peso e' ancora nel wizard obiettivi\n");
    exit(1);
}
$matches = QuestionEditorHelper::filterObjectives([
    ['codice' => 'A', 'descrizione' => 'Gestione processi', 'competenza' => 'Sistemi', 'parole_chiave' => 'scheduler'],
    ['codice' => 'B', 'descrizione' => 'Reti', 'competenza' => 'TCP/IP', 'parole_chiave' => 'protocolli'],
], 'TCP');
if (count($matches) !== 1 || $matches[0]['codice'] !== 'B') {
    fwrite(STDERR, "FAIL: ricerca obiettivi\n");
    exit(1);
}
echo "PASS: objective payload\n";
