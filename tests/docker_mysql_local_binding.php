<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$compose = (string)file_get_contents($root . '/compose.yaml');

if (!preg_match('/^\s*-\s*"127\.0\.0\.1:3307:3306"\s*$/m', $compose)) {
    fwrite(STDERR, "FAIL: MySQL non è pubblicato esclusivamente su 127.0.0.1:3307.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: MySQL pubblicato solo su 127.0.0.1:3307.\n");
