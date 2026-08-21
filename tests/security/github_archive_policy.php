<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/GitHubArchiveExtractor.php';
use App\Core\Security\GitHubArchiveExtractor;
if (!class_exists('ZipArchive')) { fwrite(STDERR, "FAIL: ZipArchive non disponibile.\n"); exit(1); }
$rejects = static function (callable $build, int $maxEntries = 10000, int $maxBytes = 262144000): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'archive-policy-');
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $build($zip);
    $zip->close();
    $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'archive-policy-' . bin2hex(random_bytes(4));
    mkdir($dest);
    try {
        GitHubArchiveExtractor::extract($zipPath, $dest, $maxEntries, $maxBytes);
    } catch (RuntimeException) {
        @unlink($zipPath);
        @rmdir($dest);
        return;
    }
    throw new RuntimeException('Archivio ostile accettato');
};

$rejects(static fn(ZipArchive $zip) => $zip->addFromString('../escape.txt', 'x'));
$rejects(static fn(ZipArchive $zip) => $zip->addFromString('/absolute.txt', 'x'));
$rejects(static function (ZipArchive $zip): void {
    $zip->addFromString('link', 'target');
    $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
});
$rejects(static fn(ZipArchive $zip) => $zip->addFromString('large.txt', str_repeat('x', 1025)), 10000, 1024);
fwrite(STDOUT, "PASS: archivio GitHub confinato.\n");
