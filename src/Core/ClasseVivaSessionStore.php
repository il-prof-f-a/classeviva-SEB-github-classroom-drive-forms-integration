<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Conserva i token ClasseViva esclusivamente nella sessione PHP corrente.
 * Username e password non sono campi ammessi e vengono sempre scartati.
 */
final class ClasseVivaSessionStore
{
    private const AUTH_KEY = 'classeviva_auth';
    private const REAUTH_KEY = 'classeviva_reauthentication_required';

    /** @var string[] */
    private const ALLOWED_TOKEN_FIELDS = [
        'token',
        'user_id',
        'ident',
        'created',
        'expire',
        'expires_in',
        'release',
        'php_session_id',
    ];

    private array $session;

    public function __construct(array &$session)
    {
        $this->session =& $session;
    }

    public function storeTokenPayload(array $payload, ?int $now = null): void
    {
        $token = trim((string)($payload['token'] ?? ''));
        if ($token === '') {
            throw new InvalidArgumentException('Token ClasseViva mancante.');
        }

        $sanitized = [];
        foreach (self::ALLOWED_TOKEN_FIELDS as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                $sanitized[$field] = $payload[$field];
            }
        }

        $now ??= time();
        $createdAt = (int)($this->session[self::AUTH_KEY]['created_at'] ?? $now);
        $this->session[self::AUTH_KEY] = [
            'token_payload' => $sanitized,
            'created_at' => $createdAt,
            'last_activity_at' => $now,
        ];
        unset($this->session[self::REAUTH_KEY]);
    }

    public function getTokenPayload(int $idleTimeout, ?int $now = null): ?array
    {
        $auth = $this->session[self::AUTH_KEY] ?? null;
        if (!is_array($auth) || empty($auth['token_payload']['token'])) {
            return null;
        }

        $now ??= time();
        $lastActivityAt = (int)($auth['last_activity_at'] ?? $auth['created_at'] ?? 0);
        if ($idleTimeout > 0 && $lastActivityAt > 0 && ($now - $lastActivityAt) > $idleTimeout) {
            $this->clearAuth(true);
            return null;
        }

        $this->session[self::AUTH_KEY]['last_activity_at'] = $now;
        return $auth['token_payload'];
    }

    public function mergeIntoConfig(array $config, int $idleTimeout, ?int $now = null): array
    {
        $payload = $this->getTokenPayload($idleTimeout, $now);
        unset($config['classeviva']['token']);
        if ($payload !== null) {
            $config['classeviva']['token'] = $payload;
        }

        return $config;
    }

    public function clearPhpSessionId(): void
    {
        if (isset($this->session[self::AUTH_KEY]['token_payload']['php_session_id'])) {
            unset($this->session[self::AUTH_KEY]['token_payload']['php_session_id']);
            $this->session[self::AUTH_KEY]['last_activity_at'] = time();
        }
    }

    public function clearAuth(bool $requireReauthentication = false): void
    {
        unset($this->session[self::AUTH_KEY], $this->session['cv_web_ping_at']);
        if ($requireReauthentication) {
            $this->session[self::REAUTH_KEY] = true;
        } else {
            unset($this->session[self::REAUTH_KEY]);
        }
    }

    public function markReauthenticationRequired(): void
    {
        $this->clearAuth(true);
    }

    public function requiresReauthentication(): bool
    {
        return !empty($this->session[self::REAUTH_KEY]);
    }
}
