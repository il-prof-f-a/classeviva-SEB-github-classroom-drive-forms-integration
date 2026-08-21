<?php

declare(strict_types=1);

namespace App\Core\Security;

final class SecurityHeaders
{
    /** @return array<string,string> */
    public static function headers(bool $https, bool $integrationPage = false): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy-Report-Only' => "default-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://accounts.google.com https://github.com; script-src 'self' https://cdn.jsdelivr.net https://apis.google.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; connect-src 'self' https://api.openai.com https://generativelanguage.googleapis.com",
        ];
        if ($https) $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        if ($integrationPage) $headers['Cache-Control'] = 'no-store';
        return $headers;
    }

    public static function apply(array $server, string $script = ''): void
    {
        if (PHP_SAPI === 'cli') return;
        $forwarded = strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') || $forwarded === 'https';
        $integration = str_contains(strtolower($script), 'integration') || str_contains(strtolower($script), 'api_');
        foreach (self::headers($https, $integration) as $name => $value) header($name . ': ' . $value);
    }
}
