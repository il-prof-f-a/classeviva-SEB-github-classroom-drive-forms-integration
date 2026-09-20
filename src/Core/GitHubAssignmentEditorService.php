<?php

declare(strict_types=1);

namespace App\Core;

/** Validazione pura dei valori modificabili nell'editor degli assignment GitHub. */
final class GitHubAssignmentEditorService
{
    /** @return array{owner:string,repo:string}|null */
    public static function parseRepositoryUrl(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'github.com'
            || array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts)) !== []) {
            return null;
        }

        $path = trim((string)($parts['path'] ?? ''), '/');
        $segments = explode('/', $path);
        if (count($segments) !== 2) {
            return null;
        }
        [$owner, $repo] = $segments;
        if (self::isGitHubSlug($owner) !== true || self::isGitHubSlug($repo) !== true) {
            return null;
        }

        return ['owner' => $owner, 'repo' => $repo];
    }

    public static function normalizeEmail(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $value;
    }

    public static function normalizeUsername(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9_.-]{1,39}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    private static function isGitHubSlug(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $value) === 1;
    }
}
