<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\UdaClassroomPublishService;

$failures = [];

function checkEq(mixed $expected, mixed $actual, string $label, array &$failures): void
{
    if ($expected === $actual) {
        return;
    }
    $failures[] = sprintf('%s: atteso %s, trovato %s', $label, var_export($expected, true), var_export($actual, true));
}

// testTitle: il nome reale ha la precedenza sul tipo.
checkEq('Metodologie di sviluppo', UdaClassroomPublishService::testTitle(['nome' => 'Metodologie di sviluppo', 'tipo_test' => 'prerequisiti']), 'testTitle usa il nome', $failures);
checkEq('Test Prerequisiti', UdaClassroomPublishService::testTitle(['tipo_test' => 'prerequisiti']), 'testTitle fallback prerequisiti', $failures);
checkEq('Test Intermedio', UdaClassroomPublishService::testTitle(['tipo_test' => 'intermedio']), 'testTitle fallback intermedio', $failures);
checkEq('Test Finale', UdaClassroomPublishService::testTitle(['tipo_test' => 'finale']), 'testTitle fallback finale', $failures);
checkEq('Test Sondaggio', UdaClassroomPublishService::testTitle(['tipo_test' => 'sondaggio']), 'testTitle fallback altro', $failures);
checkEq('Test Prerequisiti', UdaClassroomPublishService::testTitle(['nome' => '   ', 'tipo_test' => 'prerequisiti']), 'testTitle ignora nome vuoto', $failures);

// materialDescription: descrizione, note e obiettivi raggruppati.
$obiettivi = [
    ['descrizione' => 'Conoscere il ciclo di sviluppo', 'tipo_obiettivo' => 'conoscenze', 'livello_tassonomia' => 'ricordare'],
    ['descrizione' => 'Applicare il TDD', 'tipo_obiettivo' => 'abilità', 'livello_tassonomia' => 'applicare'],
    ['descrizione' => 'Lavorare in team', 'tipo_obiettivo' => 'competenze', 'livello_tassonomia' => ''],
    ['descrizione' => '', 'tipo_obiettivo' => 'conoscenze'],
];
$desc = UdaClassroomPublishService::materialDescription('Test UDA', 'Nota opzionale', $obiettivi);
checkEq(true, str_contains($desc, "Descrizione:
Test UDA"), 'materialDescription include descrizione', $failures);
checkEq(true, str_contains($desc, "Note:
Nota opzionale"), 'materialDescription include note', $failures);
checkEq(true, str_contains($desc, 'Obiettivi didattici e disciplinari:'), 'materialDescription include sezione obiettivi', $failures);
checkEq(true, str_contains($desc, "Conoscenze:
- Conoscere il ciclo di sviluppo (ricordare)"), 'materialDescription raggruppa conoscenze', $failures);
checkEq(true, str_contains($desc, "Abilità:
- Applicare il TDD (applicare)"), 'materialDescription raggruppa abilità', $failures);
checkEq(true, str_contains($desc, "Competenze:
- Lavorare in team"), 'materialDescription raggruppa competenze', $failures);

$descNoNote = UdaClassroomPublishService::materialDescription('UDA', '', []);
checkEq(false, str_contains($descNoNote, 'Note:'), 'materialDescription omette note vuote', $failures);
checkEq(false, str_contains($descNoNote, 'Obiettivi didattici'), 'materialDescription omette obiettivi vuoti', $failures);

// attachableMaterials: file Drive, link Drive, link generico, esclusione file locali.
$materiali = [
    ['nome' => 'Doc Drive', 'file_id_drive' => 'DRIVE_1', 'url_drive' => 'https://drive/doc1'],
    ['nome' => 'Link Drive', 'url_drive' => 'https://drive/doc2'],
    ['nome' => 'Link web', 'url' => 'https://example.com'],
    ['nome' => 'File locale', 'file_path' => '/tmp/doc.pdf'],
];
$attach = UdaClassroomPublishService::attachableMaterials($materiali);
checkEq(3, count($attach), 'attachableMaterials conta solo collegabili', $failures);
checkEq('drive_file', $attach[0]['type'] ?? '', 'attachableMaterials tipo drive_file', $failures);
checkEq('DRIVE_1', $attach[0]['drive_file_id'] ?? '', 'attachableMaterials drive_file_id', $failures);
checkEq('https://drive/doc2', $attach[1]['url'] ?? '', 'attachableMaterials url_drive', $failures);
checkEq('https://example.com', $attach[2]['url'] ?? '', 'attachableMaterials url generico', $failures);
checkEq([], UdaClassroomPublishService::attachableMaterials([]), 'attachableMaterials vuoto', $failures);

// googleFormDocenteUrl: preimposta "Importa voti".
checkEq('https://docs.google.com/forms/d/ID/edit', UdaClassroomPublishService::googleFormDocenteUrl(['url_docente' => 'https://docs.google.com/forms/d/ID/edit']), 'url docente esistente mantenuto', $failures);
checkEq('https://docs.google.com/forms/d/ID/edit', UdaClassroomPublishService::googleFormDocenteUrl(['url_docente' => '', 'url_studenti' => 'https://docs.google.com/forms/d/ID/viewform']), 'viewform -> edit', $failures);
checkEq('https://docs.google.com/forms/d/ID/edit', UdaClassroomPublishService::googleFormDocenteUrl(['url_docente' => '', 'url_studenti' => 'https://docs.google.com/forms/d/ID/viewform?usp=sf_link']), 'viewform con query -> edit', $failures);
checkEq('', UdaClassroomPublishService::googleFormDocenteUrl(['url_docente' => '', 'url_studenti' => '']), 'url docente vuoto senza studenti', $failures);
checkEq('https://docs.google.com/forms/d/ID/edit', UdaClassroomPublishService::googleFormDocenteUrl(['url_docente' => '', 'url_studenti' => '', 'url' => 'https://docs.google.com/forms/d/ID/viewform']), 'fallback su url', $failures);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: servizio pubblicazione Classroom (titolo, descrizione, materiali, importa voti).
");
