<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/LocalReturnUrl.php';

use App\Core\Security\LocalReturnUrl;

$allowed = ['uda_view.php', 'import_questions.php', 'user_integrations.php'];
$valid = [
    'uda_view.php?id=UDA_1#top',
    '/uda-system/public/import_questions.php?id=UDA_1',
    'user_integrations.php#classeviva-section',
];
foreach ($valid as $candidate) {
    if (LocalReturnUrl::normalize($candidate, $allowed) !== $candidate) {
        throw new RuntimeException('Return URL locale rifiutato: ' . $candidate);
    }
}
foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test/a', 'https://evil.test/a', "uda_view.php\r\nX-Test: x", '..\\evil.php', '/etc/passwd', 'unknown.php'] as $candidate) {
    if (LocalReturnUrl::normalize($candidate, $allowed) !== '') {
        throw new RuntimeException('Return URL ostile accettato: ' . $candidate);
    }
}
$callbackSource = file_get_contents(dirname(__DIR__, 2) . '/public/github_callback.php') ?: '';
if (!str_contains($callbackSource, 'LocalReturnUrl::normalize')) {
    throw new RuntimeException('Callback GitHub non usa il validatore centralizzato dei redirect');
}
fwrite(STDOUT, "PASS: URL di ritorno limitati a pagine locali note.\n");
