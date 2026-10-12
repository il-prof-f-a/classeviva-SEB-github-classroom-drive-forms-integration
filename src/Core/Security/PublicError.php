<?php

declare(strict_types=1);

namespace App\Core\Security;

use Throwable;

final class PublicError
{
    public static function message(Throwable $error, string $context = 'application'): string
    {
        $id = bin2hex(random_bytes(8));
        $rawMessage = trim($error->getMessage());
        // Messaggio statico e non sensibile: deve arrivare all'utente quando
        // una funzione ClasseViva legacy è stata dismessa intenzionalmente.
        $unsupportedClasseViva = 'funzione non più supportata a causa di incompatibilità con il sistema';
        if ($rawMessage === $unsupportedClasseViva) {
            error_log('[' . $id . '] ' . $context . ': ' . $rawMessage);
            return $unsupportedClasseViva;
        }
        $detail = preg_replace(
            '/\b(token|password|secret|api[_-]?key|authorization)\s*[=:]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $error->getMessage()
        ) ?? 'errore non disponibile';
        error_log('[' . $id . '] ' . $context . ': ' . $detail);
        return 'Si e verificato un errore. Riferimento: ' . $id;
    }

    /** @return array{success:false,error:string} */
    public static function json(Throwable $error, string $context = 'application'): array
    {
        return ['success' => false, 'error' => self::message($error, $context)];
    }
}
