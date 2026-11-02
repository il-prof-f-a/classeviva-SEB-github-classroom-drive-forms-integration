<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$notification = file_get_contents($root . '/src/Core/NotificationManager.php') ?: '';
$failures = [];

foreach ([
    'sendGradesEmail',
    'GradeEmailMessageBuilder::build',
    'addAddress',
    'addCC',
    'filter_var',
] as $required) {
    if (!str_contains($notification, $required)) {
        $failures[] = "NotificationManager: riferimento mancante {$required}";
    }
}

if (preg_match('/public function sendGradesEmail\(.*?\n    }\n\n    \/\*\*/s', $notification, $match)) {
    if (str_contains($match[0], 'test_recipient')) {
        $failures[] = 'il nuovo invio usa il destinatario di test come fallback';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: invio SMTP valutazioni con CC docente.\n");
