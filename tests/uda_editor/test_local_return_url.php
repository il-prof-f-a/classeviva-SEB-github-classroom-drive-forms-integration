<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/LocalReturnUrl.php';

use App\Utils\LocalReturnUrl;

function assertReturnValue(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

assertReturnValue('uda_create.php', LocalReturnUrl::sanitize('uda_create.php', 'map_classes.php'), 'ritorno relativo');
assertReturnValue('uda_create.php?integration_updated=1', LocalReturnUrl::sanitize('uda_create.php?integration_updated=1', 'map_classes.php'), 'query locale');
assertReturnValue('map_classes.php', LocalReturnUrl::sanitize('https://evil.example/', 'map_classes.php'), 'schema esterno rifiutato');
assertReturnValue('map_classes.php', LocalReturnUrl::sanitize('//evil.example/', 'map_classes.php'), 'redirect protocol-relative rifiutato');
assertReturnValue('map_classes.php', LocalReturnUrl::sanitize('/uda_create.php', 'map_classes.php'), 'percorso assoluto rifiutato');
assertReturnValue('map_classes.php', LocalReturnUrl::sanitize("uda_create.php\r\nLocation: https://evil.example", 'map_classes.php'), 'newline rifiatto');

echo "PASS: local return URL\n";
