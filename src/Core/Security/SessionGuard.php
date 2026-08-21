<?php

declare(strict_types=1);

namespace App\Core\Security;

final class SessionGuard
{
    /** @param array<string,mixed> $session @return array{expired:bool,rotate:bool} */
    public static function touch(array &$session, int $now, int $idleTimeout, int $rotationInterval): array
    {
        $last = (int)($session['_last_activity'] ?? $now);
        $created = (int)($session['_created_at'] ?? $now);
        if ($now - $last > max(60, $idleTimeout)) return ['expired' => true, 'rotate' => false];
        $rotate = ($now - $created) >= max(300, $rotationInterval);
        $session['_last_activity'] = $now;
        if ($rotate) $session['_created_at'] = $now;
        return ['expired' => false, 'rotate' => $rotate];
    }
}
