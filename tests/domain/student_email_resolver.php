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
    ['{cognome}.{nome}@{domain}', 'franchettisalviani.net', 'Mario', 'Rossi', 'email@email.it'],
    ['{nome}.{cognome}@{domain}', 'franchettisalviani.net', 'Luca', 'De Rossi', 'email@email.it'],
    ['', 'franchettisalviani.net', 'Anna', 'Bianchi', 'email@email.it'],
    ['{cognome}.{nome}@{domain}', 'istituto.it', 'Giò', "D'Angelo", 'email@email.it'],
    ['{nome}{cognome}@{domain}', 'scuola.edu', 'Jean', 'Martin-Vidal', 'email@email.it'],
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
