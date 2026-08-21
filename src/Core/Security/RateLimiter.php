<?php

declare(strict_types=1);

namespace App\Core\Security;

final class RateLimiter
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory)) @mkdir($directory, 0700, true);
    }

    public function allow(string $key, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        $now ??= time();
        $path = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) return false;
        try {
            if (!flock($handle, LOCK_EX)) return false;
            rewind($handle);
            $state = json_decode((string)stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $events = array_values(array_filter(
                $state['events'] ?? [],
                static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - $windowSeconds
            ));
            $allowed = count($events) < max(1, $limit);
            if ($allowed) $events[] = $now;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(['events' => $events], JSON_THROW_ON_ERROR));
            fflush($handle);
            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->cleanupExpiredFiles($path, $now, $windowSeconds);
        }
    }

    private function cleanupExpiredFiles(string $currentPath, int $now, int $windowSeconds): void
    {
        $cutoff = $now - max(3600, $windowSeconds * 2);
        foreach (glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $candidate) {
            if ($candidate !== $currentPath && (filemtime($candidate) ?: 0) < $cutoff) @unlink($candidate);
        }
    }
}
