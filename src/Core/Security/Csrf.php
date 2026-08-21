<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

final class Csrf
{
    private const SESSION_KEY = '_app_csrf_token';

    /** @param array<string,mixed> $session */
    public static function token(array &$session): string
    {
        $existing = $session[self::SESSION_KEY] ?? null;
        if (!is_string($existing) || !preg_match('/^[a-f0-9]{64}$/', $existing)) {
            $existing = bin2hex(random_bytes(32));
            $session[self::SESSION_KEY] = $existing;
        }
        return $existing;
    }

    /** @param array<string,mixed> $session */
    public static function assertValid(array $session, mixed $provided): void
    {
        $expected = $session[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || !is_string($provided) || $provided === ''
            || !hash_equals($expected, $provided)) {
            throw new RuntimeException('Token CSRF non valido. Ricarica la pagina e riprova.');
        }
    }
}
