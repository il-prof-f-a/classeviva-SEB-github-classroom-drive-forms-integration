<?php

declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/public/user_integrations.php';
$source = file_get_contents($sourcePath);

if ($source === false) {
    fwrite(STDERR, "Impossibile leggere public/user_integrations.php\n");
    exit(1);
}

$expectedSafeLookups = [
    "\$profileSchoolHint = \$shortenHint(\$profileConfig['school_name'] ?? '');",
    "\$mailFromHint = \$shortenHint(\$mailConfig['from_address'] ?? '');",
    "\$githubClientHint = \$shortenHint(\$githubConfig['client_id'] ?? '');",
];

$expectedFallbacks = [
    "\$profileSchoolHint !== '' ? \$profileSchoolHint : 'Nessuna scuola impostata'",
    "\$mailFromHint !== '' ? \$mailFromHint : 'Mittente non impostato'",
    "\$githubClientHint !== '' ? \$githubClientHint : 'N/D'",
];

$failures = [];
foreach (array_merge($expectedSafeLookups, $expectedFallbacks) as $expected) {
    if (!str_contains($source, $expected)) {
        $failures[] = $expected;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Rendering sicuro incompleto per configurazioni vuote:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: riepiloghi integrazioni sicuri con configurazioni vuote\n";
