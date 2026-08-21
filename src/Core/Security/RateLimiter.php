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
        $state = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        $state = is_array($state) ? $state : [];
        $events = array_values(array_filter($state['events'] ?? [], static fn($ts) => is_int($ts) && $ts > $now - $windowSeconds));
        if (count($events) >= $limit) { $state['events'] = $events; @file_put_contents($path, json_encode($state), LOCK_EX); return false; }
        $events[] = $now; @file_put_contents($path, json_encode(['events' => $events]), LOCK_EX); return true;
    }
}
