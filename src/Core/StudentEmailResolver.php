<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Genera l'email istituzionale di uno studente a partire dal template configurato
 * in "Template email studenti" (Integrazioni -> Profilo), che usa i segnaposto:
 *   {nome}, {cognome}, {domain}
 *
 * Es. template: "{cognome}.{nome}@{domain}" con nome "Mario", cognome "Rossi",
 * domain "istituto.it" -> "email@email.it".
 *
 * Usato per gli studenti con SOLO ClasseViva mappato (ClasseViva non espone email).
 */
final class StudentEmailResolver
{
    public const DEFAULT_TEMPLATE = '{cognome}.{nome}@{domain}';

    public static function generate(string $template, string $domain, string $nome, string $cognome): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = self::DEFAULT_TEMPLATE;
        }

        $domain = trim($domain, " \t\n\r\0\x0B@.");

        return strtr($template, [
            '{nome}' => self::normalizeLocalPart($nome),
            '{cognome}' => self::normalizeLocalPart($cognome),
            '{domain}' => $domain,
        ]);
    }

    public static function normalizeLocalPart(string $part): string
    {
        $part = mb_strtolower(trim($part), 'UTF-8');
        $part = self::transliterate($part);
        // Mantiene solo caratteri a-z0-9: rimuove spazi, apostrofi, trattini, punti.
        $part = preg_replace('/[^a-z0-9]+/', '', $part);
        return $part ?? '';
    }

    private static function transliterate(string $value): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
            'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y',
            'ÿ' => 'y', 'ß' => 'ss', 'œ' => 'oe',
        ];
        return strtr($value, $map);
    }
}
