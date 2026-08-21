<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/SecurityHeaders.php';
use App\Core\Security\SecurityHeaders;
$headers = SecurityHeaders::headers(true, true);
foreach (['Strict-Transport-Security','X-Content-Type-Options','X-Frame-Options','Referrer-Policy','Permissions-Policy','Content-Security-Policy','Cache-Control'] as $name) if (empty($headers[$name])) exit(1);
if (!str_contains($headers['Content-Security-Policy'], 'frame-ancestors')) exit(1);
if (isset($headers['Content-Security-Policy-Report-Only'])) exit(1);
if ($headers['X-Frame-Options'] !== 'SAMEORIGIN') exit(1);
if (SecurityHeaders::requestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https'], false)) exit(1);
if (!SecurityHeaders::requestIsHttps([], true)) exit(1);
$headerSource = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Security/SecurityHeaders.php') ?: '';
if (!str_contains($headerSource, "header_remove('X-Powered-By')")) exit(1);

$downloadSource = file_get_contents(dirname(__DIR__, 2) . '/public/download_template_domande.php') ?: '';
if (!str_contains($downloadSource, "bootstrap.php")) exit(1);
fwrite(STDOUT, "PASS: header di sicurezza.\n");
