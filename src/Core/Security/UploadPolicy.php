<?php

declare(strict_types=1);

namespace App\Core\Security;

use finfo;
use RuntimeException;
use ZipArchive;

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
        self::assertSignature($ext, $path);
    }

    private static function assertSignature(string $extension, string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Upload non leggibile');
        $prefix = (string)fread($handle, 8);
        fclose($handle);

        if ($extension === 'pdf' && !str_starts_with($prefix, '%PDF-')) {
            throw new RuntimeException('Firma PDF non valida');
        }
        if ($extension === 'xls' && $prefix !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Firma XLS non valida');
        }
        if ($extension === 'xlsx') {
            if (!class_exists(ZipArchive::class)) throw new RuntimeException('Supporto ZIP non disponibile');
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) throw new RuntimeException('Contenitore XLSX non valido');
            $valid = $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();
            if (!$valid) throw new RuntimeException('Struttura XLSX non valida');
        }
    }
}
