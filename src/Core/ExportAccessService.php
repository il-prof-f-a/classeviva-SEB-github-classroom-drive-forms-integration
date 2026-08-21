<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ExportAccessService
{
    private const SESSION_KEY = '_app_authorized_exports';
    private string $root;

    public function __construct(string $exportDirectory, private int $ttlSeconds = 1800)
    {
        $root = realpath($exportDirectory);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Directory esportazioni non disponibile');
        }
        $this->root = rtrim($root, '/\\');
        $this->ttlSeconds = max(60, $this->ttlSeconds);
    }

    /** @param array<string,mixed> $session */
    public function register(array &$session, string $userId, string $udaId, string $filePath, ?int $now = null): string
    {
        $resolved = $this->resolveContainedFile($filePath);
        if ($resolved === null || $userId === '' || $udaId === '') {
            throw new RuntimeException('Esportazione non autorizzabile');
        }

        $now ??= time();
        $this->prune($session, $now);
        $token = bin2hex(random_bytes(32));
        $session[self::SESSION_KEY][$token] = [
            'user_id' => $userId,
            'uda_id' => $udaId,
            'file' => basename($resolved),
            'expires_at' => $now + $this->ttlSeconds,
        ];
        return $token;
    }

    /** @param array<string,mixed> $session */
    public function resolve(array $session, string $token, string $userId, string $udaId, ?int $now = null): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $entry = $session[self::SESSION_KEY][$token] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $now ??= time();
        if ((int)($entry['expires_at'] ?? 0) < $now
            || !hash_equals((string)($entry['user_id'] ?? ''), $userId)
            || !hash_equals((string)($entry['uda_id'] ?? ''), $udaId)) {
            return null;
        }
        return $this->resolveContainedFile($this->root . DIRECTORY_SEPARATOR . basename((string)($entry['file'] ?? '')));
    }

    /** @param array<string,mixed> $session */
    private function prune(array &$session, int $now): void
    {
        $entries = $session[self::SESSION_KEY] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }
        $session[self::SESSION_KEY] = array_filter(
            $entries,
            static fn(mixed $entry): bool => is_array($entry) && (int)($entry['expires_at'] ?? 0) >= $now
        );
    }

    private function resolveContainedFile(string $path): ?string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }
        $prefix = $this->root . DIRECTORY_SEPARATOR;
        return strncasecmp($resolved, $prefix, strlen($prefix)) === 0 ? $resolved : null;
    }
}
