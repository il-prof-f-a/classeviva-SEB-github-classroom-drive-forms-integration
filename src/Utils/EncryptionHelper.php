<?php

namespace App\Utils;

/**
 * Helper per criptare/decriptare dati sensibili
 * Usa AES-256-GCM per encryption sicura
 */
class EncryptionHelper
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    /**
     * Ottiene la chiave di encryption dall'ambiente
     * Se non esiste, ne genera una e avvisa l'utente
     */
    private static function getEncryptionKey(): string
    {
        $key = function_exists('env') ? env('ENCRYPTION_KEY') : getenv('ENCRYPTION_KEY');

        if (empty($key) && (function_exists('env') ? env('APP_ENV', 'production') : getenv('APP_ENV')) !== 'testing') {
            throw new \RuntimeException('ENCRYPTION_KEY obbligatoria fuori dall ambiente di test');
        }
        if (empty($key)) $key = 'testing-only-key';

        // La chiave deve essere esattamente 32 bytes per AES-256
        return hash('sha256', $key, true);
    }

    /**
     * Cripta un valore
     *
     * @param mixed $value Valore da criptare (verrà serializzato)
     * @return string Stringa criptata in formato base64
     * @throws \Exception Se la criptazione fallisce
     */
    public static function encrypt($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $key = self::getEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';

        $serialized = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $encrypted = openssl_encrypt(
            $serialized,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            throw new \Exception('Errore durante la criptazione');
        }

        return json_encode([
            'v' => 2,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($encrypted),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Decripta un valore
     *
     * @param string $encryptedValue Stringa criptata in formato base64
     * @return mixed Valore originale (deserializzato)
     * @throws \Exception Se la decriptazione fallisce
     */
    public static function decrypt(string $encryptedValue)
    {
        if ($encryptedValue === '' || $encryptedValue === null) {
            return null;
        }

        $key = self::getEncryptionKey();
        $decodedPayload = json_decode($encryptedValue, true);
        if (is_array($decodedPayload) && (int)($decodedPayload['v'] ?? 0) === 2) {
            $iv = base64_decode((string)($decodedPayload['iv'] ?? ''), true);
            $tag = base64_decode((string)($decodedPayload['tag'] ?? ''), true);
            $encrypted = base64_decode((string)($decodedPayload['ciphertext'] ?? ''), true);
            if ($iv === false || $tag === false || $encrypted === false) throw new \Exception('Formato dati criptati non valido');
            $decrypted = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($decrypted === false) throw new \Exception('Errore durante la decriptazione. Chiave di encryption errata?');
            return json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
        }

        // Compatibilità con il formato legacy base64(IV|tag|serialize(value)).
        $data = base64_decode($encryptedValue, true);

        if ($data === false) {
            throw new \Exception('Formato dati criptati non valido');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        // Estrai IV, tag e dati criptati
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, self::TAG_LENGTH);
        $encrypted = substr($data, $ivLength + self::TAG_LENGTH);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \Exception('Errore durante la decriptazione. Chiave di encryption errata?');
        }

        // Deserializza il valore
        return unserialize($decrypted, ['allowed_classes' => false]);
    }

    /**
     * Genera una nuova chiave di encryption sicura
     * Utile per la configurazione iniziale
     *
     * @return string Chiave in formato esadecimale (64 caratteri)
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Verifica se l'encryption è configurata correttamente
     *
     * @return bool True se ENCRYPTION_KEY è configurata
     */
    public static function isConfigured(): bool
    {
        $key = function_exists('env') ? env('ENCRYPTION_KEY') : getenv('ENCRYPTION_KEY');
        return is_string($key) && $key !== '';
    }
}
