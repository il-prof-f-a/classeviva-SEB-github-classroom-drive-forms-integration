<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/UdaMetadataHelper.php';
require_once dirname(__DIR__, 2) . '/src/Utils/UdaIntegrationResolver.php';

use App\Utils\UdaIntegrationResolver;
use App\Utils\UdaMetadataHelper;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertSameValue(
    '4 Informatica',
    UdaMetadataHelper::classTargetFromAssignments([
        ['nome_classe' => '4C Informatica'],
        ['nome_classe' => '4D Informatica'],
    ]),
    'classi target comuni'
);
assertSameValue('3', UdaMetadataHelper::classTargetFromNames(['3A', '3B', '3A']), 'classi stesso anno senza indirizzo');
assertSameValue('Informatica', UdaMetadataHelper::disciplineFromSubjectNames(['Informatica', 'Informatica']), 'disciplina unica');
assertSameValue(null, UdaMetadataHelper::disciplineFromSubjectNames(['Informatica', 'TPSIT']), 'materie diverse');
assertSameValue('attiva', UdaMetadataHelper::statusAfterIntegrationSelection(true, 'bozza'), 'stato attivo con integrazione');
assertSameValue('completata', UdaMetadataHelper::statusAfterIntegrationSelection(true, 'completata'), 'stato esplicito preservato');

$classroom = [['id_classe_cv' => 'C1', 'id_materia_cv' => 'M1', 'id_corso_gc' => 'GC1', 'nome_corso_gc' => 'Corso']];
$github = [['id_classe_cv' => 'C1', 'id_materia_cv' => 'M1', 'github_classroom_id' => 'GH1', 'classroom_name' => 'Classroom']];
assertSameValue('GC1', UdaIntegrationResolver::classroomForPair('C1', 'M1', $classroom)['course_id'], 'mappatura Google');
assertSameValue('GH1', UdaIntegrationResolver::githubForPair('C1', 'M1', $github)['classroom_id'], 'mappatura GitHub');
assertSameValue(true, UdaIntegrationResolver::hasIntegration([['course_id' => 'GC1']]), 'integrazione selezionata');
assertSameValue(false, UdaIntegrationResolver::hasIntegration([[]]), 'nessuna integrazione');

echo "PASS: UDA integration metadata\n";
