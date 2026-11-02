<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Deterministic matching and presentation helpers for provider rosters.
 * Personal labels are kept in memory only and are never persisted.
 */
final class StudentRosterMatcher
{
    /** @param array<string,mixed> $entry */
    public static function displayName(array $entry): string
    {
        [$firstName, $lastName] = self::nameParts($entry);
        if ($firstName !== '' && $lastName !== '') {
            return trim($lastName . ' ' . $firstName);
        }

        $raw = self::rawName($entry);
        return self::collapseWhitespace($raw);
    }

    /** @param list<array<string,mixed>> $roster @return list<array<string,mixed>> */
    public static function sortRoster(array $roster): array
    {
        usort($roster, static function (array $left, array $right): int {
            $byName = strnatcasecmp(
                self::sortKey($left),
                self::sortKey($right)
            );
            if ($byName !== 0) {
                return $byName;
            }
            return strnatcasecmp(
                trim((string)($left['external_user_id'] ?? '')),
                trim((string)($right['external_user_id'] ?? ''))
            );
        });

        return array_values($roster);
    }

    /**
     * Suggests one-to-one mappings in three deterministic passes:
     * existing mapping, exact first/last-name match, unique surname fallback.
     *
     * @param list<array<string,mixed>> $anchors
     * @param list<array<string,mixed>> $targets
     * @param array<string,string> $existingMappings anchor external ID => target external ID
     * @return array<string,string>
     */
    public static function suggestMappings(array $anchors, array $targets, array $existingMappings = []): array
    {
        $targetById = [];
        $targetByName = [];
        $targetBySurname = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $targetId = trim((string)($target['external_user_id'] ?? ''));
            if ($targetId === '') {
                continue;
            }
            $targetById[$targetId] = $target;
            $nameKey = self::nameKey($target);
            if ($nameKey !== '') {
                $targetByName[$nameKey][] = $targetId;
            }
            $surnameKey = self::surnameKey($target);
            if ($surnameKey !== '') {
                $targetBySurname[$surnameKey][] = $targetId;
            }
        }

        $suggestions = [];
        $usedTargets = [];
        foreach ($anchors as $anchor) {
            if (!is_array($anchor)) {
                continue;
            }
            $anchorId = trim((string)($anchor['external_user_id'] ?? ''));
            $existingTarget = trim((string)($existingMappings[$anchorId] ?? ''));
            if ($anchorId === '' || $existingTarget === '' || !isset($targetById[$existingTarget])) {
                continue;
            }
            $suggestions[$anchorId] = $existingTarget;
            $usedTargets[$existingTarget] = true;
        }

        // Exact matching is evaluated for all rows before the surname fallback,
        // so a fallback can never steal a target from a stronger match below.
        foreach ($anchors as $anchor) {
            if (!is_array($anchor)) {
                continue;
            }
            $anchorId = trim((string)($anchor['external_user_id'] ?? ''));
            if ($anchorId === '' || isset($suggestions[$anchorId])) {
                continue;
            }
            $nameKey = self::nameKey($anchor);
            $candidates = array_values(array_filter(
                $targetByName[$nameKey] ?? [],
                static fn(string $targetId): bool => !isset($usedTargets[$targetId])
            ));
            if (count($candidates) !== 1) {
                continue;
            }
            $suggestions[$anchorId] = $candidates[0];
            $usedTargets[$candidates[0]] = true;
        }

        // Surname fallback is intentionally unique-only: equal surnames are
        // left for a human choice rather than being guessed.
        foreach ($anchors as $anchor) {
            if (!is_array($anchor)) {
                continue;
            }
            $anchorId = trim((string)($anchor['external_user_id'] ?? ''));
            if ($anchorId === '' || isset($suggestions[$anchorId])) {
                continue;
            }
            $surnameKey = self::surnameKey($anchor);
            $candidates = array_values(array_filter(
                $targetBySurname[$surnameKey] ?? [],
                static fn(string $targetId): bool => !isset($usedTargets[$targetId])
            ));
            if (count($candidates) !== 1) {
                continue;
            }
            $suggestions[$anchorId] = $candidates[0];
            $usedTargets[$candidates[0]] = true;
        }

        return $suggestions;
    }

    /** @param array<string,mixed> $entry @return array{0:string,1:string} */
    private static function nameParts(array $entry): array
    {
        $firstName = self::firstScalar($entry, ['nome', 'firstName', 'given_name']);
        $lastName = self::firstScalar($entry, ['cognome', 'lastName', 'family_name']);
        if ($firstName !== '' || $lastName !== '') {
            return [self::collapseWhitespace($firstName), self::collapseWhitespace($lastName)];
        }

        $raw = self::collapseWhitespace(self::rawName($entry));
        if ($raw === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/u', $raw) ?: [];
        if (count($parts) < 2) {
            return [self::collapseWhitespace($raw), ''];
        }
        $first = array_shift($parts);
        return [self::collapseWhitespace((string)$first), self::collapseWhitespace(implode(' ', $parts))];
    }

    /** @param array<string,mixed> $entry */
    private static function nameKey(array $entry): string
    {
        [$firstName, $lastName] = self::nameParts($entry);
        if ($firstName === '' && $lastName === '') {
            return '';
        }
        return self::normalize($firstName) . '|' . self::normalize($lastName);
    }

    /** @param array<string,mixed> $entry */
    private static function surnameKey(array $entry): string
    {
        [, $lastName] = self::nameParts($entry);
        return self::normalize($lastName);
    }

    /** @param array<string,mixed> $entry */
    private static function sortKey(array $entry): string
    {
        return self::normalize(self::displayName($entry));
    }

    /** @param array<string,mixed> $entry @param list<string> $keys */
    private static function firstScalar(array $entry, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key])) {
                $value = self::collapseWhitespace((string)$entry[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $entry */
    private static function rawName(array $entry): string
    {
        foreach (['display_name', 'name', 'nome_completo'] as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key])) {
                $value = trim((string)$entry[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function collapseWhitespace(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        return $collapsed === null ? trim($value) : $collapsed;
    }

    private static function normalize(string $value): string
    {
        return strtolower(self::collapseWhitespace($value));
    }
}
