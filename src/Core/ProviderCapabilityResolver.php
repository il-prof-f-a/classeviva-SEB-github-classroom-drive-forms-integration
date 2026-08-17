<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

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
            'list_classes',
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
     * Risolve i collegamenti attivi del gruppo e valuta la capability.
     */
    public static function supportsForGroup(
        DatabaseAdapterInterface $db,
        string $userId,
        string $groupId,
        string $provider,
        string $operation
    ): bool {
        $links = (new TeachingGroupIntegrationRepository($db, $userId))->listForGroup($groupId);

        return self::supports($links, $provider, $operation);
    }

    /**
     * Verifica una capability ClasseViva derivando il gruppo dalla coppia
     * classe/materia. Comodo per le pagine CV-only che ricevono id_classe_cv/id_materia_cv.
     */
    public static function supportsCvForPair(
        DatabaseAdapterInterface $db,
        string $userId,
        string $classId,
        string $subjectId,
        string $operation
    ): bool {
        $integration = (new TeachingGroupIntegrationRepository($db, $userId))->findByExternal(
            'classeviva',
            trim($classId),
            trim($subjectId)
        );
        if ($integration === null) {
            // Classe legacy non ancora migrata: nessun vincolo di gruppo; il check
            // token (gestito dal chiamante) resta l'unico vincolo. Backward-compat.
            return true;
        }
        $groupId = trim((string)($integration['id_gruppo'] ?? ''));
        if ($groupId === '') {
            return true;
        }

        return self::supportsForGroup($db, $userId, $groupId, 'classeviva', $operation);
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
