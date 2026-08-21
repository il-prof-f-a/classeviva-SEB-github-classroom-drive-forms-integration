<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 2) . '/public/user_integrations.php') ?: '';
foreach (['smtp_password', 'ai_api_key', 'github_client_secret'] as $field) {
    if (preg_match('/name=["\']' . preg_quote($field, '/') . '["\'][^>]*value=["\']<\?=/i', $source)) {
        fwrite(STDERR, "FAIL: segreto renderizzato nel campo {$field}\n");
        exit(1);
    }
}
fwrite(STDOUT, "PASS: i campi segreti non vengono renderizzati nel DOM.\n");
