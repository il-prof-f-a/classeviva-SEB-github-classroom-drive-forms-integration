<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;

/**
 * Single entry point for runtime student names in a teaching group.
 * Names are read from active provider rosters and are never persisted.
 */
final class RuntimeStudentNameService
{
    /** @var array<string,list<array<string,mixed>>> */
    private static array $requestRosterCache = [];

    /** @var callable(string,string):list<array<string,mixed>> */
    private $rosterLoader;

    /** @param callable(string,string):list<array<string,mixed>>|array<string,mixed>|null $source */
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId,
        callable|array|null $source = null
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        if (is_callable($source)) {
            $this->rosterLoader = $source;
        } else {
            $config = is_array($source) ? $source : [];
            $this->rosterLoader = function (string $provider, string $contextId) use ($config): array {
                return $this->loadProviderRoster($provider, $contextId, $config);
            };
        }
    }

    /**
     * @return list<array{id_studente:string,id_studente_internal:string,nome:string,cognome:string,nome_completo:string,provider:string,external_ids:array<string,list<string>>}>
     */
    public function resolveGroupStudents(string $groupId): array
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            return [];
        }

        $memberships = (new GroupStudentRepository($this->db, $this->userId))->listForGroup($groupId);

        $integrations = array_values(array_filter(
            (new TeachingGroupIntegrationRepository($this->db, $this->userId))->listForGroup($groupId),
            static fn(array $integration): bool => ($integration['stato'] ?? 'attivo') !== 'disattivo'
        ));
        $providerPriority = $this->providerPriority($integrations);

        // Carica i roster dei provider nell'ordine di priorità, anche in assenza di
        // membership interne: il gruppo può essere collegato solo a Google Classroom
        // o solo a GitHub Classroom, senza alcuna mappatura ClasseViva.
        $providerRosters = [];
        $loadedContexts = [];
        foreach ($integrations as $integration) {
            $provider = trim((string)($integration['provider'] ?? ''));
            $contextId = trim((string)($integration['external_context_id'] ?? ''));
            if ($provider === '' || $contextId === '' || isset($loadedContexts[$provider][$contextId])) {
                continue;
            }
            $loadedContexts[$provider][$contextId] = true;
            try {
                $providerRosters[$provider] = array_merge(
                    $providerRosters[$provider] ?? [],
                    $this->providerRoster($provider, $contextId)
                );
            } catch (\Throwable $exception) {
                error_log('Errore roster runtime ' . $provider . ': ' . $exception->getMessage());
            }
        }

        // Nessuna membership interna: costruisci la lista direttamente dal roster
        // del primo provider disponibile (priorità ClasseViva → Google → GitHub).
        if ($memberships === []) {
            foreach ($providerPriority as $provider) {
                $roster = $providerRosters[$provider] ?? [];
                if ($roster === []) {
                    continue;
                }
                $result = [];
                $seen = [];
                $identitiesForRoster = new StudentIdentityRepository($this->db, $this->userId);
                foreach ($roster as $student) {
                    if (!is_array($student)) {
                        continue;
                    }
                    $externalId = trim((string)($student['external_user_id'] ?? $student['id'] ?? ''));
                    $name = trim((string)($student['display_name'] ?? $student['name'] ?? ''));
                    if ($externalId === '' || $name === '' || isset($seen[$externalId])) {
                        continue;
                    }
                    $seen[$externalId] = true;
                    // Risolvi l'id interno dall'identità del provider: il salvataggio
                    // usa l'id interno, quindi il roster deve restituirlo per restare
                    // coerente con le valutazioni salvate.
                    $internalId = '';
                    $identity = $identitiesForRoster->findByExternal($provider, $externalId);
                    if ($identity !== null) {
                        $internalId = trim((string)($identity['id_studente'] ?? ''));
                    }
                    $result[] = [
                        'id_studente' => $internalId !== '' ? $internalId : $externalId,
                        'id_studente_internal' => $internalId,
                        'nome' => $name,
                        'cognome' => '',
                        'nome_completo' => $name,
                        'provider' => $provider,
                        // Un assignment può essere stato creato prima della
                        // sincronizzazione del gruppo e conservare l'ID
                        // esterno nel proprio link. Manteniamo quindi anche
                        // l'alias provider per consentire ai chiamanti di
                        // associare il nome alla stessa persona senza
                        // persistere dati anagrafici.
                        'external_ids' => [$provider => [$externalId]],
                    ];
                }
                return $result;
            }
            return [];
        }

        $identities = new StudentIdentityRepository($this->db, $this->userId);
        $identitiesByStudent = [];
        foreach ($memberships as $membership) {
            $studentId = trim((string)($membership['id_studente'] ?? ''));
            if ($studentId !== '') {
                $identitiesByStudent[$studentId] = $identities->listForStudent($studentId);
            }
        }

        $details = RuntimeStudentNameResolver::resolveDetails(
            $memberships,
            $identitiesByStudent,
            $providerRosters,
            $providerPriority
        );
        $result = [];
        foreach ($memberships as $membership) {
            $studentId = trim((string)($membership['id_studente'] ?? ''));
            if ($studentId === '') {
                continue;
            }
            $detail = $details[$studentId] ?? null;
            $name = is_array($detail) ? trim((string)($detail['name'] ?? '')) : '';
            $provider = is_array($detail) ? trim((string)($detail['provider'] ?? '')) : '';
            $externalIds = [];
            foreach ($identitiesByStudent[$studentId] ?? [] as $identity) {
                $identityProvider = trim((string)($identity['provider'] ?? ''));
                $externalId = trim((string)($identity['external_user_id'] ?? ''));
                if ($identityProvider === '' || $externalId === '') {
                    continue;
                }
                $externalIds[$identityProvider] ??= [];
                if (!in_array($externalId, $externalIds[$identityProvider], true)) {
                    $externalIds[$identityProvider][] = $externalId;
                }
            }
            $result[] = [
                'id_studente' => $studentId,
                'id_studente_internal' => $studentId,
                'nome' => $name,
                'cognome' => '',
                'nome_completo' => $name,
                'provider' => $provider,
                'external_ids' => $externalIds,
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function providerRoster(string $provider, string $contextId): array
    {
        $provider = trim($provider);
        $contextId = trim($contextId);
        if ($provider === '' || $contextId === '') {
            return [];
        }
        $cacheKey = $provider . ':' . $contextId;
        if (array_key_exists($cacheKey, self::$requestRosterCache)) {
            return self::$requestRosterCache[$cacheKey];
        }
        try {
            $roster = ($this->rosterLoader)($provider, $contextId);
            return self::$requestRosterCache[$cacheKey] = is_array($roster) ? $roster : [];
        } catch (\Throwable $exception) {
            error_log('Errore roster runtime ' . $provider . ': ' . $exception->getMessage());
            return self::$requestRosterCache[$cacheKey] = [];
        }
    }

    /** @param list<array<string,mixed>> $integrations @return list<string> */
    private function providerPriority(array $integrations): array
    {
        $known = RuntimeStudentNameResolver::PROVIDER_PRIORITY;
        $seen = [];
        $ordered = [];
        foreach ($known as $provider) {
            foreach ($integrations as $integration) {
                if ((string)($integration['provider'] ?? '') === $provider && !isset($seen[$provider])) {
                    $ordered[] = $provider;
                    $seen[$provider] = true;
                    break;
                }
            }
        }
        foreach ($integrations as $integration) {
            $provider = trim((string)($integration['provider'] ?? ''));
            if ($provider !== '' && !isset($seen[$provider])) {
                $ordered[] = $provider;
                $seen[$provider] = true;
            }
        }
        return $ordered;
    }

    /** @return list<array<string,mixed>> */
    private static function loadProviderRoster(string $provider, string $contextId, array $config): array
    {
        return match ($provider) {
            'classeviva' => self::loadClasseVivaRoster($config, $contextId),
            'google_classroom' => self::loadGoogleRoster($config, $contextId),
            default => [],
        };
    }

    /** @return list<array<string,mixed>> */
    private static function loadClasseVivaRoster(array $config, string $contextId): array
    {
        $result = [];
        foreach ((new ClasseVivaAPI($config))->getStudentiClasse($contextId) as $student) {
            if (!is_array($student)) {
                continue;
            }
            $id = trim((string)($student['id'] ?? $student['studentId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $result[] = [
                'external_user_id' => $id,
                'display_name' => trim((string)($student['cognome'] ?? $student['lastName'] ?? '') . ' ' . (string)($student['nome'] ?? $student['firstName'] ?? '')),
                'id' => $id,
                'nome' => (string)($student['nome'] ?? $student['firstName'] ?? ''),
                'cognome' => (string)($student['cognome'] ?? $student['lastName'] ?? ''),
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function loadGoogleRoster(array $config, string $contextId): array
    {
        $result = [];
        foreach ((new GoogleClassroomAPI($config))->getCourseStudents($contextId) as $student) {
            if (!is_array($student)) {
                continue;
            }
            $id = trim((string)($student['id'] ?? $student['userId'] ?? ''));
            $name = trim((string)($student['name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $result[] = [
                    'external_user_id' => $id,
                    'display_name' => $name,
                    'name' => $name,
                    'id' => $id,
                    'email' => trim((string)($student['email'] ?? '')),
                ];
            }
        }
        return $result;
    }

}
