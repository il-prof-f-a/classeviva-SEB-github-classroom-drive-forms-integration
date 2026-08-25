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
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://accounts.google.com https://github.com; script-src 'self' https://cdn.jsdelivr.net https://apis.google.com https://accounts.google.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://api.openai.com https://generativelanguage.googleapis.com https://www.googleapis.com; frame-src 'self' https://docs.google.com https://apis.google.com https://accounts.google.com",
        ];
        if ($https) $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        if ($integrationPage) $headers['Cache-Control'] = 'no-store';
        return $headers;
    }

    /** @param array<string,mixed> $server */
    public static function requestIsHttps(array $server, bool $configuredHttps = false): bool
    {
        return $configuredHttps
            || (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off');
    }

    public static function apply(array $server, string $script = '', bool $configuredHttps = false): void
    {
        if (PHP_SAPI === 'cli') return;
        header_remove('X-Powered-By');
        $https = self::requestIsHttps($server, $configuredHttps);
        $normalizedScript = strtolower(str_replace('\\', '/', $script));
        $integration = str_contains($normalizedScript, 'integration')
            || str_contains($normalizedScript, 'api_')
            || str_contains($normalizedScript, '/api/');
        foreach (self::headers($https, $integration) as $name => $value) header($name . ': ' . $value);
    }
}
