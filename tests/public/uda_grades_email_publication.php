<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_grades.php') ?: '';
$failures = [];

foreach ([
    'publish_to_email',
    'GradeEmailRecipientResolver',
    'sendGradesEmail',
    'Csrf::assertValid',
    'Csrf::hiddenField',
    'giudizio',
    'Email non disponibile',
] as $required) {
    if (!str_contains($source, $required)) {
        $failures[] = "uda_grades.php: riferimento mancante {$required}";
    }
}

if (preg_match('/publish_to_email.*?\$dbAdapter->updateRow/s', $source, $match)
    && str_contains($match[0], "'pubblicato'")) {
    $failures[] = 'la pubblicazione email modifica lo stato pubblicato del registro';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: pagina voti con pubblicazione email alternativa.\n");
