<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/SecurityHeaders.php';
use App\Core\Security\SecurityHeaders;
$headers = SecurityHeaders::headers(true, true);
foreach (['Strict-Transport-Security','X-Content-Type-Options','Referrer-Policy','Permissions-Policy','Content-Security-Policy-Report-Only','Cache-Control'] as $name) if (empty($headers[$name])) exit(1);
if (!str_contains($headers['Content-Security-Policy-Report-Only'], 'frame-ancestors')) exit(1);
fwrite(STDOUT, "PASS: header di sicurezza.\n");
