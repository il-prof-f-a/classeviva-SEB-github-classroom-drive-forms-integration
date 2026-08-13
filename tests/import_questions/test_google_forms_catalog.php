<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$helperPath = $root . '/src/Integration/GoogleFormsCatalog.php';
if (!is_file($helperPath)) {
    fwrite(STDERR, "FAIL: GoogleFormsCatalog non presente\n");
    exit(1);
}
require_once $helperPath;

use App\Integration\GoogleFormsCatalog;

$scopes = GoogleFormsCatalog::requiredScopes();
if (!in_array('https://www.googleapis.com/auth/drive.metadata.readonly', $scopes, true)) {
    fwrite(STDERR, "FAIL: scope Drive metadata mancante\n");
    exit(1);
}
if (!in_array('https://www.googleapis.com/auth/forms.responses.readonly', $scopes, true)) {
    fwrite(STDERR, "FAIL: scope Forms responses mancante\n");
    exit(1);
}

$normalized = GoogleFormsCatalog::normalizeFile([
    'id' => 'form-1',
    'name' => 'Verifica Processi',
    'createdTime' => '2026-08-01T10:30:00Z',
    'webViewLink' => 'https://docs.google.com/forms/d/form-1/edit',
    'owners' => [['displayName' => 'Docente Test', 'emailAddress' => 'email@email.it']],
], 12);

foreach (['id', 'title', 'teacher_url', 'author', 'created_at', 'response_count'] as $key) {
    if (!array_key_exists($key, $normalized)) {
        fwrite(STDERR, "FAIL: metadato Forms mancante: {$key}\n");
        exit(1);
    }
}
if ($normalized['response_count'] !== 12 || $normalized['author'] !== 'Docente Test') {
    fwrite(STDERR, "FAIL: metadati Forms non normalizzati\n");
    exit(1);
}

$filtered = GoogleFormsCatalog::filter([
    $normalized,
    ['id' => 'form-2', 'title' => 'Rubrica valutazione', 'author' => 'Altra Persona', 'created_at' => '2026-07-01', 'response_count' => 0, 'teacher_url' => ''],
], 'processi');
if (count($filtered) !== 1 || $filtered[0]['id'] !== 'form-1') {
    fwrite(STDERR, "FAIL: filtro catalogo Forms\n");
    exit(1);
}

echo "PASS: Google Forms catalog\n";
