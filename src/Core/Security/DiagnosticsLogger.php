<?php

declare(strict_types=1);

namespace App\Core\Security;

use Throwable;

/**
 * Logger strutturato per diagnosi operative non destinate all'utente.
 *
 * Il logger non registra token, sorgenti o payload completi. È pensato per
 * ricostruire i confini tra portale e provider esterni quando il log PHP di
 * Aruba non è disponibile all'applicazione via FTP.
 */
final class DiagnosticsLogger
{
    private const MAX_STRING_LENGTH = 512;
    private const SENSITIVE_KEY_PATTERN = '/(?:token|password|secret|api[_-]?key|authorization|cookie)/i';

    /** @param array<string,mixed> $context */
    public static function log(string $channel, string $event, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate('c'),
            'channel' => self::sanitizeString($channel),
            'event' => self::sanitizeString($event),
            'context' => self::sanitizeValue($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            $line = '{"channel":"diagnostics","event":"serialization_error"}';
        }

        $root = defined('ROOT_PATH') ? (string)ROOT_PATH : dirname(__DIR__, 3);
        $configuredDirectory = function_exists('env')
            ? (string)env('PATH_LOGS', 'storage/logs')
            : 'storage/logs';
        $directory = self::resolveDirectory($root, $configuredDirectory);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'github_review_diagnostics.log';
        if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            // Non bloccare mai la risposta della review se il log applicativo
            // non è scrivibile: il logger PHP resta il secondo canale.
            error_log('[diagnostics] ' . $line);
        }
    }

    /** @param array<string,mixed> $context */
    public static function exception(string $channel, string $event, Throwable $error, array $context = []): void
    {
        $context['exception_class'] = get_class($error);
        $context['exception_message'] = $error->getMessage();
        self::log($channel, $event, $context);
    }

    private static function resolveDirectory(string $root, string $configuredDirectory): string
    {
        if ($configuredDirectory === '') {
            return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        }
        $isAbsolute = str_starts_with($configuredDirectory, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\/]/', $configuredDirectory) === 1;
        return $isAbsolute
            ? $configuredDirectory
            : $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configuredDirectory), DIRECTORY_SEPARATOR);
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace(
            '/\b(token|password|secret|api[_-]?key|authorization|cookie)\s*[=:]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $value
        ) ?? '[unavailable]';
        if (strlen($value) > self::MAX_STRING_LENGTH) {
            return substr($value, 0, self::MAX_STRING_LENGTH) . '…';
        }
        return $value;
    }

    private static function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 3) {
            return '[truncated]';
        }
        if (is_string($value)) {
            return self::sanitizeString($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $keyString = (string)$key;
                $result[$keyString] = preg_match(self::SENSITIVE_KEY_PATTERN, $keyString) === 1
                    ? '[redacted]'
                    : self::sanitizeValue($item, $depth + 1);
            }
            return $result;
        }
        return self::sanitizeString((string)$value);
    }
}
