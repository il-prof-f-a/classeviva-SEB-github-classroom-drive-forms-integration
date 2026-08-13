<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/import_questions.php') ?: '';

foreach ([
    'json_content',
    'Mostra anteprima',
    'Carica nella textarea',
    'formsCatalogSearch',
    'formsCatalogList',
    'user_integrations.php?return_to=',
] as $required) {
    if (!str_contains($source, $required)) {
        fwrite(STDERR, "FAIL: UI import domande mancante: {$required}\n");
        exit(1);
    }
}

if (str_contains($source, 'name="file_json"')) {
    fwrite(STDERR, "FAIL: JSON usa ancora upload server-side diretto\n");
    exit(1);
}

$js = file_get_contents($root . '/public/assets/js/import-questions.js') ?: '';
foreach (['FileReader', 'jsonContent', 'ajax_list_google_forms.php', 'formsCatalogSearch'] as $required) {
    if (!str_contains($js, $required)) {
        fwrite(STDERR, "FAIL: JavaScript import domande mancante: {$required}\n");
        exit(1);
    }
}

$integrations = file_get_contents($root . '/public/user_integrations.php') ?: '';
$googleAuth = file_get_contents($root . '/public/google_auth.php') ?: '';
foreach (['Drive::DRIVE_METADATA_READONLY', 'google_integration_oauth_state', 'google_return_to'] as $required) {
    if (!str_contains($integrations, $required)) {
        fwrite(STDERR, "FAIL: flusso OAuth Google incompleto: {$required}\n");
        exit(1);
    }
}
if (!str_contains($googleAuth, 'user_integrations_flash') || !str_contains($googleAuth, 'google_return_to')) {
    fwrite(STDERR, "FAIL: ritorno OAuth Google verso integrazioni/import mancante\n");
    exit(1);
}

echo "PASS: import questions markup\n";
