<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/UploadPolicy.php';
use App\Core\Security\UploadPolicy;
$path = tempnam(sys_get_temp_dir(), 'upload-policy-');
file_put_contents($path, 'not an xlsx');
try { UploadPolicy::assertValid('evil.xlsx', $path, 'spreadsheet'); exit(1); } catch (RuntimeException) { }
@unlink($path);

if (class_exists('ZipArchive')) {
    $fakeZip = tempnam(sys_get_temp_dir(), 'upload-policy-zip-');
    $zip = new ZipArchive();
    $zip->open($fakeZip, ZipArchive::OVERWRITE);
    $zip->addFromString('payload.txt', 'not a spreadsheet');
    $zip->close();
    try { UploadPolicy::assertValid('evil.xlsx', $fakeZip, 'spreadsheet'); exit(1); } catch (RuntimeException) { }

    $validZip = tempnam(sys_get_temp_dir(), 'upload-policy-xlsx-');
    $zip = new ZipArchive();
    $zip->open($validZip, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook/>');
    $zip->close();
    UploadPolicy::assertValid('valid.xlsx', $validZip, 'spreadsheet');
    @unlink($fakeZip);
    @unlink($validZip);
}
fwrite(STDOUT, "PASS: upload policy.\n");
