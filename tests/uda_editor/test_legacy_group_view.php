<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Utils/LegacyTeachingGroupView.php';

use App\Utils\LegacyTeachingGroupView;

$view = LegacyTeachingGroupView::assignment(
    [
        'id_assegnazione' => 'ASSIGN_1',
        'id_uda' => 'UDA_1',
        'id_gruppo' => 'GRP_1',
        'data_assegnazione' => '2026-08-15 10:00:00',
        'note' => 'Nota',
        'stato' => 'assegnata',
    ],
    [
        'id_gruppo' => 'GRP_1',
        'nome_gruppo' => '4 Informatica',
        'nome_classe' => '4C',
        'nome_materia' => 'Informatica',
    ],
    [
        ['provider' => 'classeviva', 'external_context_id' => 'CV_4C', 'external_subject_id' => 'CV_INF'],
        ['provider' => 'google_classroom', 'external_context_id' => 'GC_1'],
    ],
    [
        ['provider' => 'google_classroom', 'external_resource_id' => 'WORK_1', 'external_url' => 'https://classroom.invalid/work/1'],
    ]
);

$failures = [];
foreach (['id_assegnazione' => 'ASSIGN_1', 'id_gruppo' => 'GRP_1', 'id_classe' => 'CV_4C', 'id_materia_cv' => 'CV_INF', 'nome_classe' => '4C', 'nome_materia' => 'Informatica'] as $key => $expected) {
    if (($view[$key] ?? '') !== $expected) {
        $failures[] = "chiave vista {$key} inattesa";
    }
}
if (($view['pubblicato_classroom'] ?? false) !== true || ($view['classroom_url'] ?? '') === '') {
    $failures[] = 'stato pubblicazione Classroom non derivato';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: facciata vista legacy derivata dal gruppo interno.\n");
