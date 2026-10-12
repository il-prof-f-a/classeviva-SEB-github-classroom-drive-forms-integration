<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
require $root . '/src/Core/StudentEmailResolver.php';

use App\Core\StudentEmailResolver;

$failures = [];

$cases = [
    // template, domain, nome, cognome, expected
    ['{cognome}.{nome}@{domain}', 'franchettisalviani.test', 'Mario', 'Rossi', 'rossi.mario@franchettisalviani.test'],
    ['{nome}.{cognome}@{domain}', 'franchettisalviani.test', 'Luca', 'De Rossi', 'luca.derossi@franchettisalviani.test'],
    ['', 'franchettisalviani.test', 'Anna', 'Bianchi', 'bianchi.anna@franchettisalviani.test'],
    ['{cognome}.{nome}@{domain}', 'istituto.test', 'Giò', "D'Angelo", 'dangelo.gio@istituto.test'],
    ['{nome}{cognome}@{domain}', 'scuola.test', 'Jean', 'Martin-Vidal', 'jeanmartinvidal@scuola.test'],
];

foreach ($cases as $case) {
    [$template, $domain, $nome, $cognome, $expected] = $case;
    $got = StudentEmailResolver::generate($template, $domain, $nome, $cognome);
    if ($got !== $expected) {
        $failures[] = "generate('{$template}', '{$domain}', '{$nome}', '{$cognome}') => {$got} (atteso {$expected})";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: StudentEmailResolver genera email dal template (nome/cognome/dominio) con normalizzazione.\n");
