<?php

declare(strict_types=1);

// Verifica statica che i pulsanti amministrativi della dashboard siano visibili
// solo agli utenti autorizzati (is_admin_user), e che la colonna "Test & Debug"
// non venga renderizzata per gli utenti non amministratori.
$file = dirname(__DIR__, 2) . '/public/index.php';
$source = str_replace("\r\n", "\n", file_get_contents($file) ?: '');
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere public/index.php\n");
    exit(1);
}
$required = [
    "<?php if (\$isDebugUser): ?>" . "\n" . "                            <a href=\"database_manager.php\"",
    "<?php if (\$isDebugUser): ?>" . "\n" . "            <!-- Test & Debug -->",
];
foreach ($required as $marker) {
    if (strpos($source, $marker) === false) {
        $failures[] = "guard admin assente per: " . $marker;
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: " . $failure . "\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: i pulsanti amministrativi della dashboard sono riservati agli amministratori.\n");
