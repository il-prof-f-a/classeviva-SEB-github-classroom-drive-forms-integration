<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/ExportAccessService.php';

use App\Core\ExportAccessService;

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda-export-' . bin2hex(random_bytes(4));
mkdir($directory, 0700, true);
$file = $directory . DIRECTORY_SEPARATOR . 'report.docx';
file_put_contents($file, 'fixture');
$session = [];
$service = new ExportAccessService($directory, 600);
$token = $service->register($session, 'USR_A', 'UDA_A', $file, 1000);

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fwrite(STDERR, "FAIL: token export prevedibile o non valido\n");
    exit(1);
}
if ($service->resolve($session, $token, 'USR_A', 'UDA_A', 1001) !== realpath($file)) {
    fwrite(STDERR, "FAIL: proprietario non puo risolvere il proprio export\n");
    exit(1);
}
foreach ([['USR_B','UDA_A'], ['USR_A','UDA_B']] as [$userId, $udaId]) {
    if ($service->resolve($session, $token, $userId, $udaId, 1001) !== null) {
        fwrite(STDERR, "FAIL: export accessibile con owner o UDA errati\n");
        exit(1);
    }
}
if ($service->resolve($session, $token, 'USR_A', 'UDA_A', 1601) !== null) {
    fwrite(STDERR, "FAIL: export scaduto ancora accessibile\n");
    exit(1);
}
if ($service->resolve($session, str_repeat('a', 64), 'USR_A', 'UDA_A', 1001) !== null) {
    fwrite(STDERR, "FAIL: token sconosciuto accettato\n");
    exit(1);
}

@unlink($file);
@rmdir($directory);
fwrite(STDOUT, "PASS: esportazioni vincolate a token, owner e UDA.\n");
