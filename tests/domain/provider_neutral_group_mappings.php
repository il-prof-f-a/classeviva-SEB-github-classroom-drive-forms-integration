<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Utils/UdaIntegrationResolver.php';

use App\Utils\UdaIntegrationResolver;

$failures = [];

// Gruppo Google-only: nessun campo CV, deve comunque essere indicizzato per gruppo.
$classroomRows = [
    ['id_gruppo' => 'GRP_1', 'id_corso_gc' => 'GC1', 'google_course_id' => 'GC1', 'nome_corso_gc' => 'Corso GC'],
];
$google = UdaIntegrationResolver::classroomForGroup('GRP_1', $classroomRows);
if (($google['course_id'] ?? '') !== 'GC1') {
    $failures[] = 'gruppo Google-only non risolto dal gruppo';
}
if (UdaIntegrationResolver::classroomForGroup('MISSING', $classroomRows) !== null) {
    $failures[] = 'gruppo inesistente risolto erroneamente';
}

// Gruppo GitHub-only.
$githubRows = [
    ['id_gruppo' => 'GRP_2', 'github_classroom_id' => 'GH2', 'classroom_name' => 'Classroom GH'],
];
$gh = UdaIntegrationResolver::githubForGroup('GRP_2', $githubRows);
if (($gh['classroom_id'] ?? '') !== 'GH2') {
    $failures[] = 'gruppo GitHub-only non risolto dal gruppo';
}

// Riga con coppia CV E gruppo: il lookup legacy per coppia continua a funzionare
// e il lookup per gruppo funziona allo stesso tempo.
$mixedRows = [
    ['id_gruppo' => 'GRP_3', 'id_classe_cv' => 'C1', 'id_materia_cv' => 'M1', 'id_corso_gc' => 'GC3', 'nome_corso_gc' => 'Corso misto'],
];
if (($pair = UdaIntegrationResolver::classroomForPair('C1', 'M1', $mixedRows)) === null || ($pair['course_id'] ?? '') !== 'GC3') {
    $failures[] = 'regressione: mappatura per coppia CV non risolta';
}
if (($group = UdaIntegrationResolver::classroomForGroup('GRP_3', $mixedRows)) === null || ($group['course_id'] ?? '') !== 'GC3') {
    $failures[] = 'riga con coppia CV e gruppo non indicizzata per gruppo';
}

// Riga senza corso (ma con gruppo) deve essere scartata.
if (UdaIntegrationResolver::classroomForGroup('X', [['id_gruppo' => 'X']]) !== null) {
    $failures[] = 'riga senza corso indicizzata erroneamente';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: mappature provider-neutral indicizzate per gruppo.\n");
