<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/OutputEncoder.php';

use App\Core\Security\OutputEncoder;

$payload = "</script><script>window.pwned=1</script>'\"&<";
if (str_contains(OutputEncoder::html($payload), '<script>')) exit(1);
$json = OutputEncoder::json($payload);
foreach (['</script>', '<script>', "'"] as $needle) {
    if (str_contains($json, $needle)) exit(1);
}
fwrite(STDOUT, "PASS: output HTML/JSON codificato.\n");
