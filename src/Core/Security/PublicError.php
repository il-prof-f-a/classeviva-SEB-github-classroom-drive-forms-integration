<?php

declare(strict_types=1);

namespace App\Core\Security;

use Throwable;

final class PublicError
{
    public static function message(Throwable $error, string $context = 'application'): string
    {
        $id = bin2hex(random_bytes(8));
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
