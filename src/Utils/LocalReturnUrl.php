<?php

declare(strict_types=1);

namespace App\Utils;

final class LocalReturnUrl
{
    public static function sanitize(?string $candidate, string $fallback): string
    {
        $fallback = self::normalizeFallback($fallback);
        $candidate = trim((string)$candidate);
        if ($candidate === '' || preg_match('/[\r\n]/', $candidate)) {
            return $fallback;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $candidate) || str_starts_with($candidate, '//')) {
            return $fallback;
        }
        if (str_starts_with($candidate, '/') || str_contains($candidate, '\\')) {
            return $fallback;
        }

        $path = (string)parse_url($candidate, PHP_URL_PATH);
        if ($path === '' || $path !== ltrim($path, '/')) {
            return $fallback;
        }
        return $candidate;
    }

    private static function normalizeFallback(string $fallback): string
    {
        $fallback = trim($fallback);
        if ($fallback === '' || preg_match('/[\r\n]/', $fallback) || str_contains($fallback, '\\')) {
            return 'index.php';
        }
        return ltrim($fallback, '/');
    }
}
