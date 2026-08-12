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
    private const CLOCK_SKEW_LEEWAY_SECONDS = 30;

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
