<?php

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Utils\EncryptionHelper;

/**
 * UserIntegrationManager
 *
 * Gestisce le integrazioni per utente (tabella INTEGRAZIONI_UTENTE).
 * I dati sensibili in config_json vengono criptati automaticamente.
 */
class UserIntegrationManager
{
    private DatabaseAdapterInterface $db;
    private string $userId;

    public function __construct(DatabaseAdapterInterface $db, string $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
    }

    /**
     * Recupera una singola integrazione per provider.
     */
    public function getIntegration(string $provider): ?array
    {
        $rows = $this->db->findWhere('INTEGRAZIONI_UTENTE', [
            'provider' => $provider,
        ]);

        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Restituisce la config JSON decodificata per un provider.
     * Decripta automaticamente i dati se sono criptati.
     */
    public function getConfig(string $provider): array
    {
        $row = $this->getIntegration($provider);
        if (!$row) {
            return [];
        }

        $configData = $row['config_json'] ?? '';
        if (!$configData) {
            return [];
        }

        // Prova prima a decriptare (dati nuovi)
        try {
            $decrypted = EncryptionHelper::decrypt($configData);
            if (is_array($decrypted)) {
                if (!EncryptionHelper::isVersioned($configData)) {
                    $this->saveConfig($provider, $decrypted, (bool)($row['attivo'] ?? true));
                }
                return $decrypted;
            }
        } catch (\Exception $e) {
            // Se fallisce, prova come JSON non criptato (backward compatibility)
            error_log("Tentativo decriptazione fallito per provider {$provider}, provo JSON plain: " . $e->getMessage());
        }

        // Fallback: JSON non criptato (vecchi dati)
        try {
            $data = json_decode($configData, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                // Migra automaticamente a formato criptato
                $this->saveConfig($provider, $data, (bool)($row['attivo'] ?? true));
                return $data;
            }
        } catch (\Throwable $e) {
            error_log("Config provider {$provider} non leggibile (fallback JSON): " . $e->getMessage());
        }

        return [];
    }

    /**
     * Salva o aggiorna la config per un provider.
     * I dati vengono criptati automaticamente prima del salvataggio.
     */
    public function saveConfig(string $provider, array $config, bool $attivo = true): void
    {
        $row = $this->getIntegration($provider);

        // Cripta i dati sensibili
        $encryptedConfig = EncryptionHelper::encrypt($config);

        $data = [
            'id_utente'   => $this->userId,
            'provider'    => $provider,
            'config_json' => $encryptedConfig,
            'updated_at'  => date('Y-m-d H:i:s'),
            'attivo'      => $attivo ? 1 : 0,
        ];

        if ($row) {
            $data['id_integrazione'] = $row['id_integrazione'];
            $this->db->updateRow('INTEGRAZIONI_UTENTE', 'id_integrazione', $row['id_integrazione'], $data);
        } else {
            $data['id_integrazione'] = 'INT_' . uniqid();
            $data['created_at']      = date('Y-m-d H:i:s');
            $this->db->insertRow('INTEGRAZIONI_UTENTE', $data);
        }
    }
}
