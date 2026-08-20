<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resolves display names from provider rosters without persisting personal data.
 */
final class RuntimeStudentNameResolver
{
    /** @var list<string> */
    public const PROVIDER_PRIORITY = [
        'classeviva',
        'google_classroom',
        'github_classroom',
    ];

    /**
     * @param list<array<string,mixed>> $memberships
     * @param array<string,list<array<string,mixed>>> $identitiesByStudent
     * @param array<string,list<array<string,mixed>>> $providerRosters
     * @param list<string> $providerPriority
     * @return array<string,string> internal student ID => runtime display name
     */
    public static function resolveNames(
        array $memberships,
        array $identitiesByStudent,
        array $providerRosters,
        array $providerPriority = self::PROVIDER_PRIORITY
    ): array {
        $details = self::resolveDetails($memberships, $identitiesByStudent, $providerRosters, $providerPriority);
        $resolved = [];
        foreach ($details as $studentId => $detail) {
            $resolved[$studentId] = (string)($detail['name'] ?? '');
        }
        return $resolved;
    }

    /**
     * Risolve nome e provider scelto mantenendo la stessa priorità di
     * resolveNames(). È usato dal servizio centralizzato per evitare che le
     * pagine ricostruiscano la precedenza in modo diverso.
     *
     * @return array<string,array{name:string,provider:string}>
     */
    public static function resolveDetails(
        array $memberships,
        array $identitiesByStudent,
        array $providerRosters,
        array $providerPriority = self::PROVIDER_PRIORITY
    ): array {
        $rosterNames = [];
        foreach ($providerPriority as $provider) {
            $provider = trim((string)$provider);
            if ($provider === '') {
                continue;
            }
            foreach (($providerRosters[$provider] ?? []) as $student) {
                $externalId = self::externalId($student);
                $name = self::displayName($student);
                if ($externalId !== '' && $name !== '') {
                    $rosterNames[$provider][$externalId] = $name;
                }
            }
        }

        $resolved = [];
        foreach ($memberships as $membership) {
            $internalId = trim((string)($membership['id_studente'] ?? ''));
            if ($internalId === '') {
                continue;
            }
            foreach ($providerPriority as $provider) {
                foreach (($identitiesByStudent[$internalId] ?? []) as $identity) {
                    if ((string)($identity['provider'] ?? '') !== $provider) {
                        continue;
                    }
                    $externalId = trim((string)($identity['external_user_id'] ?? ''));
                    $name = $rosterNames[$provider][$externalId] ?? '';
                    if ($externalId !== '' && $name !== '') {
                        $resolved[$internalId] = ['name' => $name, 'provider' => $provider];
                        break 2;
                    }
                }
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $student */
    private static function externalId(array $student): string
    {
        return trim((string)(
            $student['external_user_id']
            ?? $student['studentId']
            ?? $student['userId']
            ?? $student['id']
            ?? ''
        ));
    }

    /** @param array<string,mixed> $student */
    private static function displayName(array $student): string
    {
        $name = trim((string)($student['name'] ?? ($student['display_name'] ?? '')));
        if ($name !== '') {
            return $name;
        }

        $firstName = trim((string)($student['firstName'] ?? $student['nome'] ?? ''));
        $lastName = trim((string)($student['lastName'] ?? $student['cognome'] ?? ''));
        return trim($lastName . ' ' . $firstName);
    }
}
