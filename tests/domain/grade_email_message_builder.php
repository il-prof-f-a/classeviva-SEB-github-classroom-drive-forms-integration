<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/GradeEmailMessageBuilder.php';

use App\Core\GradeEmailMessageBuilder;

$failures = [];
$student = ['nome' => 'Mario', 'cognome' => 'Rossi'];
$grades = [[
    'id_voto' => 'VOTO_PRIVATO',
    'id_uda' => 'UDA_PRIVATA',
    'id_gruppo' => 'GRP_PRIVATO',
    'voto' => '8',
    'tipo_valutazione' => 'orale',
    'data_valutazione' => '2026-09-14',
    'giudizio' => 'Ha collegato correttamente i concetti trattati.',
    'descrizione' => 'Descrizione tecnica di fallback',
]];

$message = GradeEmailMessageBuilder::build('Matematica', $student, $grades);

foreach ([
    ['Valutazione', $message['subject']],
    ['Mario Rossi', $message['html']],
    ['8', $message['html']],
    ['Interrogazione orale', $message['html']],
    ['Ha collegato correttamente i concetti trattati.', $message['html']],
] as [$needle, $haystack]) {
    if (!str_contains($haystack, $needle)) {
        $failures[] = "contenuto mancante: {$needle}";
    }
}

if (str_contains($message['html'], 'Descrizione tecnica di fallback')) {
    $failures[] = 'la descrizione interna non deve essere inclusa';
}

$withoutFamilyNote = GradeEmailMessageBuilder::build('Matematica', $student, [[
    'voto' => 7,
    'giudizio' => '',
    'descrizione' => 'Nota interna non destinata alla famiglia',
]]);
if (!str_contains($withoutFamilyNote['html'], 'Nessuna nota aggiuntiva.')) {
    $failures[] = 'il giudizio vuoto deve produrre il testo neutro';
}
if (str_contains($withoutFamilyNote['html'], 'Nota interna non destinata alla famiglia')) {
    $failures[] = 'la nota interna non deve essere usata come fallback';
}

foreach (['VOTO_PRIVATO', 'UDA_PRIVATA', 'GRP_PRIVATO'] as $secret) {
    if (str_contains($message['html'], $secret) || str_contains($message['text'], $secret)) {
        $failures[] = "identificativo tecnico esposto: {$secret}";
    }
}

if (!str_contains($message['text'], 'Note per la famiglia')) {
    $failures[] = 'sezione Note per la famiglia assente dal testo alternativo';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: builder email valutazioni.\n");
