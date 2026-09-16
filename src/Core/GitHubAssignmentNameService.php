<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Espande i placeholder usati per nominare le repository degli assignment GitHub.
 *
 * Il servizio è deliberatamente privo di dipendenze esterne: la pagina prepara
 * il contesto (gruppo, template, studente/team e data) e qui viene applicata
 * soltanto la trasformazione deterministica del pattern.
 */
final class GitHubAssignmentNameService
{
    /** @var array<string,string> */
    private const TOKENS = [
        'gruppo' => 'gruppo',
        'template' => 'template',
        'studente' => 'studente',
        'team' => 'team',
        'data' => 'data',
        'anno' => 'anno',
        'org' => 'org',
    ];

    /**
     * @param array<string,mixed> $context
     */
    public function expand(string $pattern, array $context): string
    {
        $data = trim((string)($context['data'] ?? ''));
        if ($data === '') {
            $data = date('Y-m-d');
        }
        if (trim((string)($context['anno'] ?? '')) === '') {
            $context['anno'] = substr($data, 0, 4);
        }
        $context['data'] = $data;

        $expanded = preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            static function (array $match) use ($context): string {
                $key = strtolower((string)$match[1]);
                if (!isset(self::TOKENS[$key])) {
                    return (string)$match[0];
                }
                return trim((string)($context[$key] ?? ''));
            },
            $pattern
        );

        return $expanded === null ? $pattern : $expanded;
    }

    public function containsStudentPlaceholder(string $pattern): bool
    {
        return preg_match('/\{studente\}/i', $pattern) === 1;
    }

    /** @return list<string> */
    public function unknownPlaceholders(string $pattern): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $pattern, $matches);
        $unknown = [];
        foreach ($matches[0] as $index => $token) {
            $key = strtolower((string)($matches[1][$index] ?? ''));
            if (!isset(self::TOKENS[$key])) {
                $unknown[] = (string)$token;
            }
        }
        return array_values(array_unique($unknown));
    }

    /**
     * Restituisce la visibilità effettiva. Un pattern con nome studente non può
     * mai produrre una repository pubblica, anche se il valore POST richiede
     * esplicitamente "public".
     */
    public function effectiveVisibility(string $requested, bool $studentNameIncluded): string
    {
        if ($studentNameIncluded || strtolower(trim($requested)) !== 'public') {
            return 'private';
        }
        return 'public';
    }

    /**
     * Restituisce i token validi ma incompatibili con la modalità scelta.
     * I token sconosciuti vengono lasciati alla validazione generale.
     *
     * @return list<string>
     */
    public function incompatiblePlaceholders(string $pattern, string $mode): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $pattern, $matches);
        $incompatible = [];
        $normalizedMode = strtolower(trim($mode)) === 'group' ? 'group' : 'single';
        foreach ($matches[0] as $index => $literal) {
            $key = strtolower((string)($matches[1][$index] ?? ''));
            if (($normalizedMode === 'group' && $key === 'studente')
                || ($normalizedMode === 'single' && $key === 'team')) {
                $incompatible[] = (string)$literal;
            }
        }
        return array_values(array_unique($incompatible));
    }

    /** @return array<string,string> */
    public function supportedTokens(): array
    {
        return self::TOKENS;
    }
}
