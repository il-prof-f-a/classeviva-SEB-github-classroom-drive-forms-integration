<?php

declare(strict_types=1);

namespace App\Core;

use Firebase\JWT\JWT;
use Google\Client;

/**
 * Verifica un ID token Google tollerando un clock skew minimo e circoscritto.
 */
final class GoogleIdTokenVerifier
{
    // 300s = tolleranza OIDC standard: i container/VM possono avere un clock
    // leggermente sfalsato (anche ~1 min) rispetto ai server Google.
    private const CLOCK_SKEW_LEEWAY_SECONDS = 300;

    public static function verify(Client $client, string $idToken): array|false
    {
        $previousLeeway = JWT::$leeway;

        try {
            JWT::$leeway = self::CLOCK_SKEW_LEEWAY_SECONDS;
            return $client->verifyIdToken($idToken);
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }
}
