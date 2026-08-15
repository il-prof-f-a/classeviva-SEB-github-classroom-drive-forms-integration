<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Utils/UdaIntegrationResolver.php';

use App\Utils\UdaIntegrationResolver;

$rows = [
    ['id_gruppo' => 'GRP_1', 'provider' => 'classeviva', 'external_context_id' => 'CV_1', 'external_subject_id' => 'SUB_1'],
    ['id_gruppo' => 'GRP_1', 'provider' => 'google_classroom', 'external_context_id' => 'GC_1'],
    ['id_gruppo' => 'GRP_2', 'provider' => 'github_classroom', 'external_context_id' => 'GH_2'],
];
$failures = [];
$google = UdaIntegrationResolver::providerForGroup('GRP_1', 'google_classroom', $rows);
if (($google['context_id'] ?? '') !== 'GC_1') {
    $failures[] = 'collegamento Google non risolto dal gruppo';
}
if (UdaIntegrationResolver::providerForGroup('GRP_1', 'github_classroom', $rows) !== null) {
    $failures[] = 'provider GitHub inesistente risolto erroneamente';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: resolver provider-neutral per gruppo.\n");
