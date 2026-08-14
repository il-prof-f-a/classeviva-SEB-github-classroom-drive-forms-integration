<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/UdaMetadataHelper.php';

use App\Utils\UdaMetadataHelper;

function assertMetadataValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$classes = UdaMetadataHelper::classTargetFromAssignments([
    ['nome_classe' => '3A'],
    ['nome_classe' => '3B'],
    ['nome_classe' => '3A'],
    ['nome_classe' => ''],
]);
assertMetadataValue('3', $classes, 'classi target comuni');

$notes = UdaMetadataHelper::mergeClassroomNotes('Prerequisiti: subnetting', '4A', 'Laboratorio 2');
assertMetadataValue("Prerequisiti: subnetting\n\nImport Classroom\nSezione: 4A\nAula: Laboratorio 2", $notes, 'note Classroom aggiunte');
assertMetadataValue($notes, UdaMetadataHelper::mergeClassroomNotes($notes, '4A', 'Laboratorio 2'), 'note Classroom idempotenti');
assertMetadataValue('attiva', UdaMetadataHelper::statusAfterClassroomImport(true, 'bozza'), 'import riuscito attiva UDA');
assertMetadataValue('bozza', UdaMetadataHelper::statusAfterClassroomImport(false, 'bozza'), 'import vuoto mantiene bozza');
assertMetadataValue('completata', UdaMetadataHelper::statusAfterClassroomImport(true, 'completata'), 'stato esplicito preservato');

$createMarkup = file_get_contents(dirname(__DIR__, 2) . '/public/uda_create.php') ?: '';
$editMarkup = file_get_contents(dirname(__DIR__, 2) . '/public/uda_edit.php') ?: '';
foreach ([$createMarkup, $editMarkup] as $markup) {
    if (preg_match('/name=["\'](?:progetto|durata_ore)["\']/', $markup)) {
        fwrite(STDERR, "FAIL: campo eliminato ancora esposto nel form UDA\n");
        exit(1);
    }
}
if (preg_match('/<input[^>]+name=["\']classi_target["\']/', $createMarkup . $editMarkup)) {
    fwrite(STDERR, "FAIL: classi target ancora modificabili come input\n");
    exit(1);
}

echo "PASS: Classroom metadata helper\n";
