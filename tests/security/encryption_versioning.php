<?php

declare(strict_types=1);

putenv('APP_ENV=testing'); putenv('ENCRYPTION_KEY=' . bin2hex(random_bytes(32)));
require_once dirname(__DIR__, 2) . '/src/Utils/EncryptionHelper.php';
use App\Utils\EncryptionHelper;
$encoded = EncryptionHelper::encrypt(['answer' => 'ok']);
$payload = json_decode($encoded, true);
if (($payload['v'] ?? 0) !== 2 || EncryptionHelper::decrypt($encoded)['answer'] !== 'ok') exit(1);
fwrite(STDOUT, "PASS: cifratura versionata.\n");
