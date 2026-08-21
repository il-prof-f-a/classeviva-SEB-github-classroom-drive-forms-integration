<?php

declare(strict_types=1);

use App\Core\LegalDocumentRenderer;
use App\Core\Security\SecurityHeaders;

$legalRoot = dirname(__DIR__);
$legalAutoload = $legalRoot . '/vendor/autoload.php';
if (is_file($legalAutoload)) {
    require_once $legalAutoload;
}
require_once $legalRoot . '/src/Core/LegalDocumentRenderer.php';
require_once $legalRoot . '/src/Core/Security/SecurityHeaders.php';

if (!function_exists('legal_document_env')) {
    function legal_document_env(string $key): string
    {
        $processValue = getenv($key);
        if ($processValue !== false && $processValue !== '') {
            return (string) $processValue;
        }

        foreach ([$_ENV, $_SERVER] as $source) {
            $value = $source[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }
}

try {
    if (class_exists(Dotenv\Dotenv::class)) {
        foreach ([$legalRoot, $legalRoot . '/config'] as $envPath) {
            if (is_file($envPath . '/.env')) {
                Dotenv\Dotenv::createImmutable($envPath)->safeLoad();
            }
        }
    }

    $configuredScheme = strtolower(
        (string) (parse_url(legal_document_env('APP_URL'), PHP_URL_SCHEME) ?? '')
    );
    SecurityHeaders::apply($_SERVER, (string) ($_SERVER['SCRIPT_NAME'] ?? ''), $configuredScheme === 'https');
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    $document = (string) ($_GET['document'] ?? '');
    if (!in_array($document, ['privacy-policy', 'termini-servizio'], true)) {
        http_response_code(404);
        echo 'Documento non disponibile.';
        return;
    }

    $fragment = (string) ($_GET['fragment'] ?? '') === '1';
    $renderer = new LegalDocumentRenderer($legalRoot);
    $content = $renderer->render($document, legal_document_env('ADMIN_EMAILS'), $fragment);
    http_response_code(200);

    if ($fragment) {
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '<div class="container-fluid p-3">' . $content . '</div>';
        return;
    }

    echo $content;
} catch (Throwable $error) {
    $correlationId = bin2hex(random_bytes(8));
    error_log('[' . $correlationId . '] legal-document: ' . $error::class);
    http_response_code(500);
    echo 'Documento temporaneamente non disponibile.';
}
