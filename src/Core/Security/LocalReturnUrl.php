<?php

declare(strict_types=1);

namespace App\Core\Security;

final class LocalReturnUrl
{
    /** @param list<string> $allowedScripts */
    public static function normalize(?string $candidate, array $allowedScripts, ?string $currentHost = null): string
    {
        $candidate = trim((string)$candidate);
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate)
            || str_starts_with($candidate, '//')) {
            return '';
        }
        $parts = parse_url($candidate);
        if (!is_array($parts) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['port'])) {
            return '';
        }
        if (!empty($parts['scheme']) || !empty($parts['host'])) {
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $currentHost === null
                || !hash_equals(strtolower($currentHost), $host)) {
                return '';
            }
            $candidate = (string)($parts['path'] ?? '');
            if (isset($parts['query'])) $candidate .= '?' . $parts['query'];
            if (isset($parts['fragment'])) $candidate .= '#' . $parts['fragment'];
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '' || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            return '';
        }
        $allowed = array_map('strtolower', array_map('basename', $allowedScripts));
        return in_array(strtolower(basename($path)), $allowed, true) ? $candidate : '';
    }
}
