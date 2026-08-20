<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Restrictive allowlist used while the application is in test phase.
 */
final class TestAccessPolicy
{
    /** @return list<string> */
    public static function allowedEmails(array $config = []): array
    {
        $configured = $config['security']['test_access']['allowed_emails'] ?? '';
        if ($configured === '' && function_exists('env')) {
            $configured = env('TEST_ALLOWED_EMAILS', '');
        }
        if (is_array($configured)) {
            $values = $configured;
        } else {
            $values = explode(',', (string)$configured);
        }

        $emails = [];
        foreach ($values as $value) {
            $email = strtolower(trim((string)$value));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }
        return array_keys($emails);
    }

    public static function isAllowed(string $email, array $config = []): bool
    {
        $candidate = strtolower(trim($email));
        return $candidate !== '' && in_array($candidate, self::allowedEmails($config), true);
    }
}
