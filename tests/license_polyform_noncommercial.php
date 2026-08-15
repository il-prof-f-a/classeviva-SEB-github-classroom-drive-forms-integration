<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
if (($composer['license'] ?? null) !== 'PolyForm-Noncommercial-1.0.0') {
    $failures[] = 'composer.json non dichiara PolyForm-Noncommercial-1.0.0';
}

$readme = (string)file_get_contents($root . '/README.md');
if (!str_contains($readme, 'PolyForm Noncommercial License 1.0.0')
    || !str_contains($readme, 'https://polyformproject.org/licenses/noncommercial/1.0.0')) {
    $failures[] = 'README.md non documenta la licenza PolyForm Noncommercial 1.0.0';
}

$license = (string)file_get_contents($root . '/LICENSE');
if (!str_contains($license, 'PolyForm Noncommercial License 1.0.0')
    || !str_contains($license, 'https://polyformproject.org/licenses/noncommercial/1.0.0')) {
    $failures[] = 'LICENSE non contiene il testo PolyForm Noncommercial 1.0.0';
}

if (str_contains($readme, 'CC BY-NC-SA') || str_contains($composer['license'] ?? '', 'CC-BY-NC-SA')) {
    $failures[] = 'è rimasto un riferimento alla licenza CC BY-NC-SA';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: licenza PolyForm Noncommercial 1.0.0 coerente.\n");
