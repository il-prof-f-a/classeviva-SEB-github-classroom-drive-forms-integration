<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use PDO;
use RuntimeException;

/** Provider-neutral roster and student identity operations for a teaching group. */
final class TeachingGroupStudentService
{
    private const PROVIDERS = [
        'classeviva',
        'google_classroom',
        'github_classroom',
    ];

    private TeachingGroupRepository $groups;
    private StudentRepository $students;
    private StudentIdentityRepository $identities;
    private GroupStudentRepository $memberships;
    private StudentResourceRepository $resources;
    private StudentIdentityResolver $resolver;

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        $this->groups = new TeachingGroupRepository($this->db, $this->userId);
        $this->students = new StudentRepository($this->db, $this->userId);
        $this->identities = new StudentIdentityRepository($this->db, $this->userId);
        $this->memberships = new GroupStudentRepository($this->db, $this->userId);
        $this->resources = new StudentResourceRepository($this->db, $this->userId);
        $this->resolver = new StudentIdentityResolver(
            $this->students,
            $this->identities,
            $this->memberships,
            $this->resources
        );
    }

    /** @param list<array<string,mixed>> $roster @return list<array<string,mixed>> */
    public function syncRoster(string $groupId, string $provider, string $contextId, array $roster): array
    {
        $groupId = trim($groupId);
        $provider = $this->provider($provider);
        $contextId = trim($contextId);
        $this->requireGroup($groupId);
        if ($contextId === '') {
            throw new RuntimeException('external_context_id obbligatorio');
        }

        // Validate the complete batch before its first write.
        $entries = [];
        foreach ($roster as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('riga roster non valida');
            }
            $externalRaw = $entry['external_user_id'] ?? '';
            if (!is_scalar($externalRaw)) {
                continue;
            }
            $externalId = trim((string)$externalRaw);
            if ($externalId === '') {
                continue;
            }
            $entries[] = [$externalId, $entry];
        }

        $result = [];
        $this->transaction(function () use ($groupId, $provider, $contextId, $entries, &$result): void {
            foreach ($entries as [$externalId, $entry]) {
                $student = $this->resolver->resolveOrCreate($provider, $externalId);
                $studentId = (string)$student['id_studente'];
                $this->identities->updateContext($provider, $externalId, $contextId);
                $this->memberships->add($groupId, $studentId, [
                    'provider_origine' => $provider,
                    'external_context_id' => $contextId,
                ]);

                // Provider labels are deliberately returned to the caller only;
                // they are never passed to a repository write.
                $row = [
                    'id_studente' => $studentId,
                    'provider' => $provider,
                    'external_context_id' => $contextId,
                    'external_user_id' => $externalId,
                ];
                foreach (['display_name', 'email'] as $label) {
                    if (array_key_exists($label, $entry) && is_scalar($entry[$label])) {
                        $row[$label] = (string)$entry[$label];
                    }
                }
                $result[] = $row;
            }
        });

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function matrix(string $groupId): array
    {
        $groupId = trim($groupId);
        $this->requireGroup($groupId);
        $rows = [];
        $memberships = $this->memberships->listForGroup($groupId);
        $allowedContexts = [];
        foreach ($memberships as $membership) {
            $provider = trim((string)($membership['provider_origine'] ?? ''));
            if ($provider === '') {
                continue;
            }
            $contextId = trim((string)($membership['external_context_id'] ?? ''));
            $allowedContexts[$provider] ??= [];
            if ($contextId !== '') {
                $allowedContexts[$provider][$contextId] = true;
            }
        }
        foreach ($memberships as $membership) {
            $studentId = trim((string)($membership['id_studente'] ?? ''));
            if ($studentId === '') {
                continue;
            }
            if (!isset($rows[$studentId])) {
                $rows[$studentId] = [
                    'id_studente' => $studentId,
                    'identities' => [],
                    'external_ids' => [],
                    'provider_ids' => [],
                    'memberships' => [],
                ];
            }
            // Keep only provider-neutral membership fields.
            $rows[$studentId]['memberships'][] = [
                'id_iscrizione' => (string)($membership['id_iscrizione'] ?? ''),
                'provider_origine' => (string)($membership['provider_origine'] ?? ''),
                'external_context_id' => (string)($membership['external_context_id'] ?? ''),
                'stato' => (string)($membership['stato'] ?? ''),
            ];
            foreach ($this->identities->listForStudent($studentId) as $identity) {
                $provider = (string)($identity['provider'] ?? '');
                $externalId = (string)($identity['external_user_id'] ?? '');
                if ($provider === '' || $externalId === '') {
                    continue;
                }
                $identityContext = trim((string)($identity['external_context_id'] ?? ''));
                if (!isset($allowedContexts[$provider])
                    || ($identityContext !== '' && !isset($allowedContexts[$provider][$identityContext]))) {
                    continue;
                }
                $identityKey = $provider . ':' . $externalId;
                $known = array_column($rows[$studentId]['identities'], null, 'provider_external_key');
                if (isset($known[$identityKey])) {
                    continue;
                }
                $rows[$studentId]['identities'][] = [
                    'provider' => $provider,
                    'external_user_id' => $externalId,
                    'external_context_id' => (string)($identity['external_context_id'] ?? ''),
                    'stato' => (string)($identity['stato'] ?? ''),
                    'provider_external_key' => $identityKey,
                ];
                $rows[$studentId]['external_ids'][$provider][] = $externalId;
                $rows[$studentId]['provider_ids'][$provider][] = $externalId;
            }
        }

        foreach ($rows as &$row) {
            foreach (['external_ids', 'provider_ids'] as $field) {
                foreach ($row[$field] as $provider => $ids) {
                    $row[$field][$provider] = count($ids) === 1 ? $ids[0] : array_values($ids);
                }
            }
            foreach ($row['identities'] as &$identity) {
                unset($identity['provider_external_key']);
            }
            unset($identity);
        }
        unset($row);

        return array_values($rows);
    }

    /** @param list<array<string,mixed>> $matches */
    public function linkIdentities(string $groupId, array $matches): int
    {
        $groupId = trim($groupId);
        $this->requireGroup($groupId);
        $groupStudentIds = [];
        foreach ($this->memberships->listForGroup($groupId) as $membership) {
            $id = trim((string)($membership['id_studente'] ?? ''));
            if ($id !== '') {
                $groupStudentIds[$id] = true;
            }
        }

        $operations = [];
        $seenSources = [];
        foreach ($matches as $match) {
            if (!is_array($match)) {
                throw new RuntimeException('match identità non valido');
            }
            $anchor = $this->identityFromInput($match);
            $anchorIdentity = $this->identities->findByExternal($anchor['provider'], $anchor['external_user_id']);
            if ($anchorIdentity === null) {
                throw new RuntimeException('identità ancora non trovata');
            }
            $targetId = (string)$anchorIdentity['id_studente'];
            if (!isset($groupStudentIds[$targetId])) {
                throw new RuntimeException('identità ancora non presente nel gruppo');
            }

            $candidates = $match['matches'] ?? [];
            if (!is_array($candidates)) {
                throw new RuntimeException('matches deve essere un array');
            }
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    throw new RuntimeException('identità candidata non valida');
                }
                $source = $this->identityFromInput($candidate);
                $sourceIdentity = $this->identities->findByExternal($source['provider'], $source['external_user_id']);
                if ($sourceIdentity === null) {
                    throw new RuntimeException('identità candidata non trovata');
                }
                $sourceId = (string)$sourceIdentity['id_studente'];
                if (!isset($groupStudentIds[$sourceId])) {
                    throw new RuntimeException('identità candidata non presente nel gruppo');
                }
                if ($sourceId === $targetId) {
                    continue;
                }
                if (isset($seenSources[$sourceId]) && $seenSources[$sourceId] !== $targetId) {
                    throw new RuntimeException('conflitto tra target di identità esterne');
                }
                $seenSources[$sourceId] = $targetId;
                $operations[$sourceId] = $targetId;
            }
        }

        // Reject chains/cycles up front: applying one merge could otherwise
        // change the target of a later merge in the same request.
        foreach ($operations as $sourceId => $targetId) {
            if (isset($operations[$targetId])) {
                throw new RuntimeException('conflitto tra target di identità esterne');
            }
        }

        $count = 0;
        $this->transaction(function () use ($operations, &$count): void {
            foreach ($operations as $sourceId => $targetId) {
                $this->resolver->merge($sourceId, $targetId);
                $count++;
            }
        });
        return $count;
    }

    public function unlinkIdentity(string $studentId, string $provider, string $externalUserId): bool
    {
        $studentId = trim($studentId);
        $provider = $this->provider($provider);
        $externalUserId = trim($externalUserId);
        if ($studentId === '' || $externalUserId === '') {
            return false;
        }
        $identity = $this->identities->findByExternal($provider, $externalUserId);
        if ($identity === null || (string)$identity['id_studente'] !== $studentId) {
            return false;
        }
        return $this->identities->detach($provider, $externalUserId);
    }

    /** @return array{provider:string,external_user_id:string} */
    private function identityFromInput(array $input): array
    {
        $provider = $this->provider((string)($input['provider'] ?? ''));
        $externalId = trim((string)($input['external_user_id'] ?? ''));
        if ($externalId === '') {
            throw new RuntimeException('external_user_id obbligatorio');
        }
        return ['provider' => $provider, 'external_user_id' => $externalId];
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('provider non consentito');
        }
        return $provider;
    }

    private function requireGroup(string $groupId): void
    {
        if ($groupId === '' || $this->groups->findById($groupId) === null) {
            throw new RuntimeException('Gruppo didattico non trovato');
        }
    }

    /** Execute a mutation atomically when the adapter exposes PDO. */
    private function transaction(callable $operation): void
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof PDO) {
            throw new RuntimeException('operazione roster atomica non supportata dall’adapter');
        }
        $ownTransaction = !$connection->inTransaction();
        if ($ownTransaction) {
            $connection->beginTransaction();
        }
        try {
            $operation();
            if ($ownTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }
}
