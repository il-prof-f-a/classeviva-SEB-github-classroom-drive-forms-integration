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
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|[\\/])\.\.?([\\/]|$)#', $name)) { $zip->close(); throw new RuntimeException('Percorso archivio non consentito'); }
            $total += (int)($stat['size'] ?? 0);
            if ($total > $maxBytes) { $zip->close(); throw new RuntimeException('Archivio espanso troppo grande'); }
            $target = realpath($root . DIRECTORY_SEPARATOR . dirname($name));
            if ($target !== false && !str_starts_with($target . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) { $zip->close(); throw new RuntimeException('Percorso archivio non consentito'); }
        }
        if (!$zip->extractTo($root)) { $zip->close(); throw new RuntimeException('Estrazione archivio fallita'); }
        $zip->close();
    }
}
