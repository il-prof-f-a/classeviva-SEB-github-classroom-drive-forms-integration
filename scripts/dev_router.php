<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$requestPath = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

if (
    str_contains($requestPath, "\0")
    || preg_match('~(?:^|/)\.~', $requestPath)
    || preg_match('~^/(?:config|database|src|storage|vendor)(?:/|$)~i', $requestPath)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Accesso negato.\n";
    return true;
}

$legalRoutes = [
    '/privacy-policy.html' => 'privacy-policy',
    '/termini-servizio.html' => 'termini-servizio',
];
if (isset($legalRoutes[$requestPath])) {
    $_GET['document'] = $legalRoutes[$requestPath];
    $_GET['fragment'] = '0';
    require $root . '/public/legal_document.php';
    return true;
}

$candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim($requestPath, '/'));
if ($candidate !== false && str_starts_with($candidate, $root) && is_file($candidate)) {
    return false;
}

return false;
