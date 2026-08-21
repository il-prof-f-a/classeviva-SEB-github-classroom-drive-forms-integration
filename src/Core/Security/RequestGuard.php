<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

final class RequestGuard
{
    /** @param array<string,mixed> $session */
    public static function assertPost(array $server, array $session, mixed $csrfToken = null): void
    {
        if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            throw new RuntimeException('Metodo HTTP non consentito');
        }
        Authorization::assertAuthenticated($session);
        if ($csrfToken !== null) {
            Csrf::assertValid($session, $csrfToken);
        }
    }

    /** @param array<string,mixed> $server @param array<string,mixed> $session @param array<string,mixed> $post */
    public static function assertMutation(array $server, array $session, array $post): void
    {
        if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            throw new RuntimeException('Metodo HTTP non consentito');
        }
        Authorization::assertAuthenticated($session);
        Csrf::assertValid($session, Csrf::providedToken($post, $server));
    }

    /** @param array<string,mixed> $server */
    public static function assertCliOrAdmin(array $server, array $session, array $adminEmails): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            throw new RuntimeException('Operazione distruttiva consentita solo via POST');
        }
        Authorization::assertAdmin($session, $adminEmails);
    }
}
