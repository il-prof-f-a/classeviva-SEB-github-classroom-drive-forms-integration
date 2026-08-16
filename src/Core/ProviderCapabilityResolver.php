<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Risoluzione di capability provider-neutral: gruppo + provider + operazione.
 *
 * Sostituisce il gate globale REQUIRES_CLASSEVIVA per le decisioni puntuali:
 * un'operazione è supportata solo quando il gruppo ha un collegamento attivo
 * verso il provider richiesto e l'operazione appartiene a quel provider.
 */
final class ProviderCapabilityResolver
{
    public const PROVIDER_CLASSEVIVA = 'classeviva';
    public const PROVIDER_GOOGLE_CLASSROOM = 'google_classroom';
    public const PROVIDER_GITHUB_CLASSROOM = 'github_classroom';

    /**
     * Operazioni riconosciute per provider.
     *
     * @var array<string,list<string>>
     */
    private const OPERATIONS = [
        self::PROVIDER_CLASSEVIVA => [
            'sync_roster',
            'list_grades',
            'import_grades',
            'publish_grade',
            'delete_grade',
            'publish_annotation',
        ],
        self::PROVIDER_GOOGLE_CLASSROOM => [
            'list_courses',
            'sync_roster',
            'import_grades',
            'publish_test',
        ],
        self::PROVIDER_GITHUB_CLASSROOM => [
            'list_assignments',
            'sync_roster',
            'import_grades',
            'review_assignment',
            'publish_test',
        ],
    ];

    /**
     * @param list<array<string,mixed>> $groupLinks  collegamenti (provider, stato, ...) di un gruppo
     */
    public static function supports(array $groupLinks, string $provider, string $operation): bool
    {
        if (!self::providerSupports($provider, $operation)) {
            return false;
        }

        return self::isLinked($groupLinks, $provider);
    }

    /**
     * @param list<array<string,mixed>> $groupLinks
     */
    public static function isLinked(array $groupLinks, string $provider): bool
    {
        foreach ($groupLinks as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['provider'] ?? '')) !== $provider) {
                continue;
            }
            if (($row['stato'] ?? 'attivo') === 'attivo') {
                return true;
            }
        }

        return false;
    }

    public static function providerSupports(string $provider, string $operation): bool
    {
        $operations = self::OPERATIONS[$provider] ?? [];

        return in_array($operation, $operations, true);
    }
}
