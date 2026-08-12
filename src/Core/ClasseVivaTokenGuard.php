<?php

declare(strict_types=1);

namespace App\Core;

class ClasseVivaTokenGuard
{
    public const CTA_INTEGRAZIONI = 'user_integrations.php#classeviva-section';

    /**
     * Ritorna lo stato riassuntivo del token ClasseViva.
     */
    public static function getTokenState(array $config): array
    {
        $cv = $config['classeviva'] ?? [];
        $enabled = filter_var($cv['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tokenMeta = is_array($cv['token'] ?? null) ? $cv['token'] : [];
        $token = trim((string) ($tokenMeta['token'] ?? ''));
        $valid = filter_var($cv['token_valid'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $error = $cv['token_error'] ?? null;

        $ready = $enabled && $token !== '' && $valid;

        $notice = null;
        if (!$enabled) {
            $notice = 'ClasseViva è disabilitato. Vai nelle Integrazioni per attivarlo.';
        } elseif ($token === '') {
            $notice = 'Token ClasseViva assente. Autorizza la sessione da Integrazioni.';
        } elseif (!$valid) {
            $notice = $error
                ? "Token ClasseViva non valido: {$error}"
                : 'Token ClasseViva scaduto o non valido. Rigeneralo nelle Integrazioni.';
        }

        return [
            'ready' => $ready,
            'enabled' => $enabled,
            'token' => $token,
            'valid' => $valid,
            'error' => $error,
            'notice' => $notice,
        ];
    }

    /**
     * Lancia una RuntimeException se il token non è pronto.
     */
    public static function requireToken(array $config): void
    {
        $state = self::getTokenState($config);
        if (!$state['ready']) {
            $message = $state['notice'] ?? 'Token ClasseViva non disponibile.';
            throw new \RuntimeException($message);
        }
    }
}

