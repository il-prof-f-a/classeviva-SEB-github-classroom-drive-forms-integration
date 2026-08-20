<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$serviceFile = $root . '/src/Core/RuntimeStudentNameResolver.php';
$pageFile = $root . '/public/rubrica_orale_v2.php';

if (!is_file($serviceFile) || !is_file($pageFile)) {
    fwrite(STDERR, "FAIL: resolver runtime o pagina rubrica mancanti\n");
    exit(1);
}

require_once $serviceFile;

$names = \App\Core\RuntimeStudentNameResolver::resolveNames(
    [['id_studente' => 'STU_INTERNAL_1']],
    [
        'STU_INTERNAL_1' => [
            ['provider' => 'classeviva', 'external_user_id' => 'CV_123'],
        ],
    ],
    [
        'classeviva' => [
            ['id' => 'CV_123', 'nome' => 'Mario', 'cognome' => 'Rossi'],
        ],
    ]
);

$pageSource = file_get_contents($pageFile) ?: '';
$ok = ($names['STU_INTERNAL_1'] ?? '') === 'Rossi Mario'
    && str_contains($pageSource, 'RuntimeStudentNameResolver')
    && str_contains($pageSource, 'uda_view.php?id=');

if (!$ok) {
    fwrite(STDERR, "FAIL: nome runtime o link di ritorno all'UDA assente\n");
    exit(1);
}

fwrite(STDOUT, "PASS: rubrica risolve il nome runtime e contiene il ritorno all'UDA.\n");
