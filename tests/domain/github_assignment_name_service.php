<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/src/Core/GitHubAssignmentNameService.php';

use App\Core\GitHubAssignmentNameService;

$failures = [];
$service = new GitHubAssignmentNameService();
$context = [
    'gruppo' => '4L Informatica - TPSIT',
    'template' => 'La Talpa',
    'studente' => 'Mario Rossi',
    'team' => 'Gruppo 1',
    'data' => '2026-09-16',
    'org' => 'il-prof-f-a',
];

if ($service->expand('{template} - fls 2026-27 {gruppo}', $context)
    !== 'La Talpa - fls 2026-27 4L Informatica - TPSIT') {
    $failures[] = 'espansione gruppo/template errata';
}
if ($service->expand('{studente} {team} {data} {anno} {org}', $context)
    !== 'Mario Rossi Gruppo 1 2026-09-16 2026 il-prof-f-a') {
    $failures[] = 'espansione studente/team/data/anno/org errata';
}
if (!$service->containsStudentPlaceholder('Titolo-{STUDENTE}')) {
    $failures[] = 'rilevazione placeholder studente case-insensitive errata';
}
if ($service->containsStudentPlaceholder('{gruppo}-{team}')) {
    $failures[] = 'rilevato erroneamente placeholder studente';
}
if ($service->unknownPlaceholders('{template}-{non_supportato}') !== ['{non_supportato}']) {
    $failures[] = 'validazione placeholder sconosciuti errata';
}
if ($service->effectiveVisibility('public', true) !== 'private'
    || $service->effectiveVisibility('public', false) !== 'public'
    || $service->effectiveVisibility('private', false) !== 'private') {
    $failures[] = 'vincolo visibilità privacy errato';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: naming e visibilità assignment verificati.\n");
