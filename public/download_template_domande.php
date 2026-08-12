<?php
/**
 * Download sicuro dei template domande (JSON/CSV/XLSX).
 * Evita l'accesso diretto alla cartella database/ protetta da Apache.
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

// Mappa formati consentiti -> file fisico
$allowed = [
    'json' => ROOT_PATH . '/database/templates/template_domande.json',
    'csv'  => ROOT_PATH . '/database/templates/template_domande.csv',
    'xlsx' => ROOT_PATH . '/database/templates/template_domande.xlsx',
];

$format = strtolower($_GET['format'] ?? '');

if (!isset($allowed[$format])) {
    http_response_code(400);
    echo "Formato non supportato.";
    exit;
}

$filePath = $allowed[$format];
if (!file_exists($filePath)) {
    http_response_code(404);
    echo "File non trovato.";
    exit;
}

$mime = match ($format) {
    'json' => 'application/json',
    'csv'  => 'text/csv',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    default => 'application/octet-stream'
};

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="template_domande.' . $format . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
