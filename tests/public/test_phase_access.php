<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$callback = file_get_contents($root . '/public/oauth_callback.php') ?: '';
$login = file_get_contents($root . '/index.php') ?: '';
$bootstrap = file_get_contents($root . '/bootstrap.php') ?: '';
$failures = [];

$gatePosition = strpos($callback, 'TestAccessPolicy::isAllowed');
$databasePosition = strpos($callback, 'DatabaseFactory::createWithInitialization');
if ($gatePosition === false || $databasePosition === false || $gatePosition > $databasePosition) {
    $failures[] = 'allowlist non applicata prima della creazione/aggiornamento utente';
}
foreach ([['request_test_update', $login], ['privacy-policy.html', $login], ['csrf', $login], ['TEST_ACCESS_NOTIFICATION_EMAIL', $bootstrap]] as [$required, $source]) {
    if (stripos($source, $required) === false) {
        $failures[] = "modulo accesso test incompleto: {$required}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: accesso test filtrato e modulo aggiornamenti presente.\n");
