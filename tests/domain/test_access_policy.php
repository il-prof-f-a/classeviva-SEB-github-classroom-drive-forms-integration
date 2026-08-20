<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/src/Core/TestAccessPolicy.php';
if (!is_file($file)) {
    fwrite(STDERR, "FAIL: policy accesso test mancante\n");
    exit(1);
}
require_once $file;

$config = [
    'security' => [
        'test_access' => [
            'allowed_emails' => 'email@email.it, email@email.it',
        ],
    ],
];

$cases = [
    ['email@email.it', true],
    ['email@email.it', true],
    ['email@email.it', false],
    ['', false],
];
foreach ($cases as [$email, $expected]) {
    $actual = \App\Core\TestAccessPolicy::isAllowed($email, $config);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: risultato allowlist inatteso per {$email}\n");
        exit(1);
    }
}

$failClosedConfig = [
    'security' => [
        'test_access' => [
            'allowed_emails' => '',
        ],
    ],
];
if (\App\Core\TestAccessPolicy::isAllowed('email@email.it', $failClosedConfig)) {
    fwrite(STDERR, "FAIL: allowlist vuota non deve autorizzare account\n");
    exit(1);
}

$invalidEntriesConfig = [
    'security' => [
        'test_access' => [
            'allowed_emails' => 'not-an-email, email@email.it, , not-an-email',
        ],
    ],
];
if (!\App\Core\TestAccessPolicy::isAllowed('email@email.it', $invalidEntriesConfig)) {
    fwrite(STDERR, "FAIL: normalizzazione allowlist inattesa\n");
    exit(1);
}

fwrite(STDOUT, "PASS: allowlist accesso test case-insensitive e restrittiva.\n");
