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
            'allowed_emails' => 'teacher@example.test, admin@example.test',
        ],
    ],
];

$cases = [
    ['teacher@example.test', true],
    ['ADMIN@EXAMPLE.TEST', true],
    ['other@example.test', false],
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
if (\App\Core\TestAccessPolicy::isAllowed('teacher@example.test', $failClosedConfig)) {
    fwrite(STDERR, "FAIL: allowlist vuota non deve autorizzare account\n");
    exit(1);
}

$invalidEntriesConfig = [
    'security' => [
        'test_access' => [
            'allowed_emails' => 'not-an-email, ADMIN@EXAMPLE.TEST, , not-an-email',
        ],
    ],
];
if (!\App\Core\TestAccessPolicy::isAllowed('admin@example.test', $invalidEntriesConfig)) {
    fwrite(STDERR, "FAIL: normalizzazione allowlist inattesa\n");
    exit(1);
}

fwrite(STDOUT, "PASS: allowlist accesso test case-insensitive e restrittiva.\n");
