<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rubrica = file_get_contents($root . '/public/rubrica_orale_v2.php') ?: '';
$laboratorio = file_get_contents($root . '/public/laboratorio_griglia.php') ?: '';
$publish = file_get_contents($root . '/public/publish_test_to_classroom.php') ?: '';
$builder = file_get_contents($root . '/src/Integration/GoogleFormsBuilder.php') ?: '';
$generateForm = file_get_contents($root . '/public/generate_google_form.php') ?: '';
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Rubrica: il gate CV è consentito solo per la pubblicazione esterna, non per il salvataggio locale.
$require(!preg_match('/define\(\s*[\'\"]REQUIRES_CLASSEVIVA[\'\"]\s*,\s*true\s*\)/', $rubrica),
    'rubrica orale mantiene il gate globale ClasseViva');
$require(str_contains($rubrica, "'id_gruppo' => \$groupId") || str_contains($rubrica, 'id_gruppo'),
    'rubrica orale non espone il contesto id_gruppo per il salvataggio');
$require(str_contains($rubrica, "\$lookup['id_gruppo'] = \$idGruppo")
        && str_contains($rubrica, "findWhere('VALUTAZIONI_RUBRICA', \$lookup)"),
    'rubrica orale non legge le valutazioni per gruppo provider-neutral');
$require(str_contains($rubrica, "'id_gruppo', 'id_studente'") && str_contains($rubrica, 'foglio/tavola vuota'),
    'rubrica orale non conserva le chiavi provider-neutral quando la tabella è vuota');
$require(!preg_match('/<form[^>]*id=[\'\"]formClasse[\'\"][^>]*>.*<form[^>]*id=[\'\"]formValutazione[\'\"]/s', $rubrica),
    'rubrica orale conserva form HTML annidati');

// Laboratorio: il salvataggio locale non deve richiedere capability CV e deve offrire il riepilogo voti.
$require(str_contains($laboratorio, 'uda_grades.php?id='),
    'griglia laboratorio non collega Visualizza voti assegnati');
$saveStart = strpos($laboratorio, "=== 'pubblica_voto'");
$plusStart = strpos($laboratorio, "=== 'pubblica_plusminus'");
$saveBlock = ($saveStart !== false && $plusStart !== false)
    ? substr($laboratorio, $saveStart, $plusStart - $saveStart)
    : $laboratorio;
$require(str_contains($laboratorio, 'Salvataggio locale') && !str_contains($saveBlock, 'cvAuthError'),
    'griglia laboratorio non separa il salvataggio locale dalla pubblicazione CV');
$require(str_contains($laboratorio, 'publish_grade') && str_contains($laboratorio, 'supportsCvForPair'),
    'griglia laboratorio non mantiene il controllo capability per la pubblicazione esterna');

// Classroom: ordinamento riusabile e option preselezionata.
$require(is_file($root . '/src/Core/ClassroomCourseOrdering.php'),
    'helper di ordinamento Classroom assente');
$require(str_contains($publish, 'UdaGroupRepository') && str_contains($publish, 'TeachingGroupIntegrationRepository'),
    'pagina pubblicazione test non carica le mappature Classroom dell’UDA');
$require(str_contains($publish, 'selected') && str_contains($publish, 'classroomCourses'),
    'pagina pubblicazione test non preseleziona il primo corso');

// Forms: entrambi i template dedicati devono rimanere nel costruttore condiviso.
$require(str_contains($builder, "template_id_cbm") && str_contains($builder, "template_id"),
    'GoogleFormsBuilder non distingue template standard e CBM');
$require(str_contains($builder, 'createFormWithConfidence') && str_contains($builder, 'createForm('),
    'GoogleFormsBuilder non espone i due flussi Forms');
$require(str_contains($generateForm, 'createFormWithConfidence') && str_contains($generateForm, 'createForm('),
    'generazione Forms non eredita i template standard/CBM dal builder condiviso');

// Copertura comportamentale dell'ordinamento (mapping, duplicati e lista vuota).
require_once $root . '/src/Core/ClassroomCourseOrdering.php';
$courses = [
    ['id' => 'other', 'name' => 'Altro'],
    ['id' => 'mapped', 'name' => 'Mappato'],
    ['id' => 'other-2', 'name' => 'Altro 2'],
];
$ordered = \App\Core\ClassroomCourseOrdering::mappedFirst($courses, ['mapped', 'mapped']);
$require(($ordered[0]['id'] ?? null) === 'mapped' && ($ordered[1]['id'] ?? null) === 'other',
    'ordinamento Classroom non porta in testa il corso mappato mantenendo la stabilità');
$require(\App\Core\ClassroomCourseOrdering::mappedFirst([], ['missing']) === [],
    'ordinamento Classroom non gestisce la lista vuota');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: voti provider-neutral, Classroom preselezionata e template Forms.\n");
