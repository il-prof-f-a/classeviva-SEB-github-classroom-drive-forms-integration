<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;
use ZipArchive;

final class GitHubArchiveExtractor
{
    public static function extract(string $zipPath, string $destination, int $maxEntries = 10000, int $maxBytes = 262144000): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new RuntimeException('Archivio non valido');
        if ($zip->numFiles > $maxEntries) { $zip->close(); throw new RuntimeException('Archivio con troppi file'); }
        $root = realpath($destination);
        if ($root === false) { $zip->close(); throw new RuntimeException('Destinazione non valida'); }
        $declaredTotal = 0;
        $writtenTotal = 0;
        $seen = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                self::assertSafeEntry($zip, $i, $name);
                $key = strtolower(rtrim($name, '/'));
                if (isset($seen[$key])) throw new RuntimeException('Entry archivio duplicata');
                $seen[$key] = true;

                $declaredTotal += max(0, (int)($stat['size'] ?? 0));
                if ($declaredTotal > $maxBytes) throw new RuntimeException('Archivio espanso troppo grande');

                $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($name, '/'));
                if (str_ends_with($name, '/')) {
                    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                        throw new RuntimeException('Creazione directory archivio fallita');
                    }
                    self::assertContained($root, $target);
                    continue;
                }

                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException('Creazione directory archivio fallita');
                }
                self::assertContained($root, $parent);
                $input = $zip->getStream((string)($stat['name'] ?? $name));
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('Estrazione entry fallita');
                }
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 65536);
                        if ($chunk === false) throw new RuntimeException('Lettura entry fallita');
                        $writtenTotal += strlen($chunk);
                        if ($writtenTotal > $maxBytes) throw new RuntimeException('Archivio espanso troppo grande');
                        if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Scrittura entry fallita');
                        }
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                self::assertContained($root, $target);
            }
        } finally {
            $zip->close();
        }
    }

    private static function assertSafeEntry(ZipArchive $zip, int $index, string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:\//', $name)
            || preg_match('#(^|/)\.\.?(/|$)#', $name)) {
            throw new RuntimeException('Percorso archivio non consentito');
        }
        $opsys = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            $type = ($attributes >> 16) & 0170000;
            if ($type === 0120000) throw new RuntimeException('Link simbolico non consentito');
        }
    }

    private static function assertContained(string $root, string $path): void
    {
        $resolved = realpath($path);
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        if ($resolved === false || ($resolved !== $root && strncasecmp($resolved, $prefix, strlen($prefix)) !== 0)) {
            throw new RuntimeException('Percorso archivio non consentito');
        }
    }
}
