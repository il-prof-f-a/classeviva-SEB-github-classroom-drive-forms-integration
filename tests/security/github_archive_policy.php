<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/GitHubArchiveExtractor.php';
use App\Core\Security\GitHubArchiveExtractor;
if (!class_exists('ZipArchive')) { fwrite(STDOUT, "SKIP: ZipArchive non disponibile.\n"); exit(0); }
$zipPath = tempnam(sys_get_temp_dir(), 'archive-policy-');
$zip = new ZipArchive(); $zip->open($zipPath); $zip->addFromString('../escape.txt', 'x'); $zip->close();
$dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'archive-policy-' . bin2hex(random_bytes(4)); mkdir($dest);
try { GitHubArchiveExtractor::extract($zipPath, $dest); exit(1); } catch (RuntimeException) { }
@unlink($zipPath); @rmdir($dest);
fwrite(STDOUT, "PASS: archivio GitHub confinato.\n");
