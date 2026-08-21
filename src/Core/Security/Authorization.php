<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

final class Authorization
{
    /** @param array<string,mixed> $session */
    public static function assertAuthenticated(array $session): void
    {
        if (!is_string($session['user_id'] ?? null) || trim($session['user_id']) === '') {
            throw new RuntimeException('Autenticazione richiesta');
        }
    }

    /** @param array<string,mixed> $session @param list<string> $adminEmails */
    public static function assertAdmin(array $session, array $adminEmails): void
    {
        self::assertAuthenticated($session);
        $email = strtolower(trim((string)($session['user_email'] ?? '')));
        $allowed = array_map(static fn(string $value): string => strtolower(trim($value)), $adminEmails);
        if ($email === '' || !in_array($email, $allowed, true)) {
            throw new RuntimeException('Autorizzazione amministratore richiesta');
        }
    }
}
