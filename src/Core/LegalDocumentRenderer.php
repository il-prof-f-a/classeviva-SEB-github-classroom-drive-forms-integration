<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

final class LegalDocumentRenderer
{
    public const CONTACT_PLACEHOLDER =
        '<span data-legal-admin-contact>Contattare l\'amministratore dell\'istanza.</span>';

    private const DOCUMENTS = [
        'privacy-policy' => 'privacy-policy.html',
        'termini-servizio' => 'termini-servizio.html',
    ];

    public function __construct(private readonly string $rootPath)
    {
    }

    public static function firstValidAdminEmail(?string $configured): ?string
    {
        foreach (explode(',', (string) $configured) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    public function render(string $documentKey, ?string $adminEmails, bool $bodyOnly = false): string
    {
        if (!array_key_exists($documentKey, self::DOCUMENTS)) {
            throw new InvalidArgumentException('Documento legale non riconosciuto.');
        }

        $templatePath = rtrim($this->rootPath, '/\\')
            . DIRECTORY_SEPARATOR
            . self::DOCUMENTS[$documentKey];
        $template = is_file($templatePath) ? file_get_contents($templatePath) : false;
        if ($template === false) {
            throw new RuntimeException('Documento legale non disponibile.');
        }
        if (substr_count($template, self::CONTACT_PLACEHOLDER) !== 1) {
            throw new RuntimeException('Marcatore del contatto legale non valido.');
        }

        $rendered = str_replace(
            self::CONTACT_PLACEHOLDER,
            self::contactMarkup($adminEmails),
            $template
        );

        if (!$bodyOnly) {
            return $rendered;
        }
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $rendered, $matches) !== 1) {
            throw new RuntimeException('Struttura del documento legale non valida.');
        }

        return $matches[1];
    }

    private static function contactMarkup(?string $adminEmails): string
    {
        $email = self::firstValidAdminEmail($adminEmails);
        if ($email === null) {
            return self::CONTACT_PLACEHOLDER;
        }

        $escaped = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a href="mailto:' . $escaped . '">' . $escaped . '</a>';
    }
}
