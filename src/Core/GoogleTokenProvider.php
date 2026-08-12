<?php

namespace App\Core;

use App\Core\Database\DatabaseFactory;
use App\Core\UserIntegrationManager;

class GoogleTokenProvider
{
    /**
     * Ottiene il token Google OAuth per l'utente corrente (sessione o env di fallback).
     * La configurazione ($config) viene aggiornata in loco per evitare richieste ripetute.
     *
     * @param array $config
     * @param string|null $userId Override dell'utente (utile in CLI/script)
     * @return array|null
     */
    public static function getToken(array &$config, ?string $userId = null): ?array
    {
        $existing = $config['google']['oauth_token'] ?? null;
        if (!empty($existing) && is_array($existing)) {
            return $existing;
        }

        if ($userId === null) {
            if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
                session_start();
            }
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (empty($userId)) {
            // fallback: permette di impostare un user_id via variabile d'ambiente per script CLI
            $userId = env('GOOGLE_TOKEN_USER_ID', null);
        }

        if (empty($userId)) {
            return null;
        }

        $db = DatabaseFactory::createWithInitialization($config, true);
        $uim = new UserIntegrationManager($db, (string)$userId);
        $googleCfg = $uim->getConfig('google') ?? [];
        $token = $googleCfg['token'] ?? null;

        if (!empty($token) && is_array($token)) {
            $config['google']['oauth_token'] = $token;
            return $token;
        }

        return null;
    }
}
