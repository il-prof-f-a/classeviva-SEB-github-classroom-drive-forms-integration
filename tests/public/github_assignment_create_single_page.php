<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$needles = [
    "'load_roster'",
    'application/json',
    'fetch(',
    'getAttribute(\'action\')',
    'roster-panel',
    'name-preview',
    'name-token-legend',
    'firstStudent',
    'firstTeam',
    'data-placeholder-mode',
    'incompatiblePlaceholders',
    'availableGroupIds',
    'availableTemplateIds',
    'availableOrgLogins',
    'Assegnazione singola/in team',
    'Assegnazione in team',
    'assignment_mode',
    'team_groups_json',
    'Carica studenti e continua',
];
$failures = [];
foreach ($needles as $needle) {
    if (!str_contains($source, $needle)) {
        $failures[] = "manca {$needle}";
    }
}
if (substr_count($source, 'id="assignment-config-form"') !== 1) {
    $failures[] = 'il form di configurazione deve essere unico';
}
if (str_contains($source, 'fetch(configForm.action')) {
    $failures[] = 'fetch usa la proprietà action sovrascrivibile dal campo hidden';
}
if (str_contains($source, 'await response.json()')) {
    $failures[] = 'la risposta HTML di una sessione scaduta non viene gestita';
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: flusso single-page roster verificato.\n");
