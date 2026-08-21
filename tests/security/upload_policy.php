<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/UploadPolicy.php';
use App\Core\Security\UploadPolicy;
$path = tempnam(sys_get_temp_dir(), 'upload-policy-');
file_put_contents($path, 'not an xlsx');
try { UploadPolicy::assertValid('evil.xlsx', $path, 'spreadsheet'); exit(1); } catch (RuntimeException) { }
@unlink($path);
fwrite(STDOUT, "PASS: upload policy.\n");
