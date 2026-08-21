<?php

declare(strict_types=1);

namespace App\Core\Security;

use finfo;
use RuntimeException;

final class UploadPolicy
{
    private const MAX_BYTES = ['spreadsheet' => 10_485_760, 'document' => 52_428_800, 'rubric' => 10_485_760];
    private const MIME = [
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'pdf' => ['application/pdf'],
    ];

    public static function assertValid(string $name, string $path, string $profile = 'document'): void
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Upload non leggibile');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) throw new RuntimeException('Estensione non consentita');
        $size = filesize($path);
        if ($size === false || $size > (self::MAX_BYTES[$profile] ?? self::MAX_BYTES['document'])) throw new RuntimeException('Upload troppo grande');
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($detected, self::MIME[$ext], true)) throw new RuntimeException('MIME non coerente con estensione');
    }
}
