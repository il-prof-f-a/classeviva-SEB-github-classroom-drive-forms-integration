<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

/**
 * Builds the provider-neutral group catalog consumed by the UDA wizard.
 */
final class TeachingGroupCatalogService
{
    private const PROVIDERS = [
        'classeviva',
        'google_classroom',
        'github_classroom',
    ];

    private TeachingGroupRepository $groups;
    private TeachingGroupIntegrationRepository $integrations;

    public function __construct(DatabaseAdapterInterface $db, string $userId)
    {
        $this->groups = new TeachingGroupRepository($db, $userId);
        $this->integrations = new TeachingGroupIntegrationRepository($db, $userId);
    }

    /** @return list<array<string,mixed>> */
    public function listForWizard(bool $activeOnly = true): array
    {
        $groups = $activeOnly ? $this->groups->listActive() : $this->groups->listAll();
        $catalog = [];
        foreach ($groups as $group) {
            $catalog[] = $this->toWizardRow($group);
        }
        usort($catalog, static function (array $left, array $right): int {
            $nameOrder = strnatcasecmp((string)$left['nome_gruppo'], (string)$right['nome_gruppo']);
            return $nameOrder !== 0
                ? $nameOrder
                : strcmp((string)$left['id_gruppo'], (string)$right['id_gruppo']);
        });
        return $catalog;
    }

    /** @return array<string,mixed>|null */
    public function findForWizard(string $groupId): ?array
    {
        $group = $this->groups->findById($groupId);
        return $group === null ? null : $this->toWizardRow($group);
    }

    /** @param array<string,mixed> $group @return array<string,mixed> */
    private function toWizardRow(array $group): array
    {
        $providers = array_fill_keys(self::PROVIDERS, null);
        $groupId = (string)($group['id_gruppo'] ?? '');
        foreach ($this->integrations->listForGroup($groupId) as $integration) {
            if (($integration['stato'] ?? 'attivo') === 'disattivo') {
                continue;
            }
            $provider = (string)($integration['provider'] ?? '');
            if (!array_key_exists($provider, $providers) || $providers[$provider] !== null) {
                continue;
            }
            $providers[$provider] = [
                'external_context_id' => (string)($integration['external_context_id'] ?? ''),
                'external_subject_id' => (string)($integration['external_subject_id'] ?? ''),
                'external_name' => (string)($integration['external_name'] ?? ''),
            ];
        }

        return [
            'id_gruppo' => $groupId,
            'nome_gruppo' => (string)($group['nome_gruppo'] ?? ''),
            'nome_classe' => (string)($group['nome_classe'] ?? ''),
            'nome_materia' => (string)($group['nome_materia'] ?? ''),
            'anno_scolastico' => (string)($group['anno_scolastico'] ?? ''),
            'stato' => (string)($group['stato'] ?? 'attivo'),
            'providers' => $providers,
        ];
    }
}
