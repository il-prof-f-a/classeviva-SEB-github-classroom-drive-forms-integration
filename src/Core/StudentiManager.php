<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Integration\ClasseVivaAPI;
use Exception;

/**
 * Facciata di sincronizzazione roster.
 *
 * Gli ID ClasseViva restano confinati in STUDENTI_IDENTITA_ESTERNE e nelle
 * integrazioni del gruppo; STUDENTI contiene esclusivamente l'identità interna.
 */
class StudentiManager
{
    private DatabaseAdapterInterface $db;
    private ?ClasseVivaAPI $api;
    private array $config;
    private string $userId;
    private StudentRepository $students;
    private StudentIdentityRepository $identities;
    private GroupStudentRepository $memberships;
    private StudentResourceRepository $resources;
    private StudentIdentityResolver $identityResolver;
    private StudentRosterService $rosterService;
    private array $studentiCache = [];

    public function __construct(DatabaseAdapterInterface $db, ?ClasseVivaAPI $api, array $config)
    {
        $this->db = $db;
        $this->api = $api;
        $this->config = $config;
        $this->userId = (string)($config['user_id'] ?? ($_SESSION['user_id'] ?? 'system'));
        $this->students = new StudentRepository($db, $this->userId);
        $this->identities = new StudentIdentityRepository($db, $this->userId);
        $this->memberships = new GroupStudentRepository($db, $this->userId);
        $this->resources = new StudentResourceRepository($db, $this->userId);
        $this->identityResolver = new StudentIdentityResolver(
            $this->students,
            $this->identities,
            $this->memberships,
            $this->resources
        );
        $this->rosterService = new StudentRosterService($this->identityResolver, $this->memberships);
    }

    /**
     * Sincronizza un roster già acquisito dal provider.
     * Le etichette anagrafiche restano nella risposta in memoria e non vengono
     * mai passate al repository.
     *
     * @param list<array<string,mixed>> $roster
     * @return array{sincronizzati:int,nuovi:int,disattivati:int}
     */
    public function sincronizzaRoster(
        string $groupId,
        string $provider,
        string $externalContextId,
        array $roster
    ): array {
        $new = 0;
        foreach ($roster as $entry) {
            $externalId = trim((string)($entry['id'] ?? $entry['external_user_id'] ?? ''));
            if ($externalId !== '' && $this->identityResolver->resolve($provider, $externalId) === null) {
                $new++;
            }
        }
        $normalized = array_map(static function (array $entry): array {
            return [
                'external_user_id' => (string)($entry['id'] ?? $entry['external_user_id'] ?? ''),
                'display_name' => trim((string)($entry['display_name'] ?? (($entry['cognome'] ?? '') . ' ' . ($entry['nome'] ?? '')))),
            ];
        }, $roster);
        $synced = $this->rosterService->sync($groupId, $provider, $externalContextId, $normalized);
        $this->clearCache();
        return [
            'sincronizzati' => count($synced),
            'nuovi' => $new,
            'disattivati' => 0,
        ];
    }

    public function sincronizzaStudentiClasse(string $idClasseCV, string $nomeClasse): array
    {
        if ($this->api === null) {
            throw new Exception('API ClasseViva non configurata');
        }
        $link = (new TeachingGroupIntegrationRepository($this->db, $this->userId))
            ->findByContext('classeviva', $idClasseCV);
        if ($link === null) {
            $group = (new TeachingGroupRepository($this->db, $this->userId))->create([
                'nome_gruppo' => $nomeClasse,
                'nome_classe' => $nomeClasse,
            ]);
            $link = (new TeachingGroupIntegrationRepository($this->db, $this->userId))->link([
                'id_gruppo' => $group['id_gruppo'],
                'provider' => 'classeviva',
                'tipo_risorsa' => 'classe',
                'external_context_id' => $idClasseCV,
                'external_name' => $nomeClasse,
            ]);
        }
        $roster = $this->api->getStudentiClasse($idClasseCV);
        return $this->sincronizzaRoster(
            (string)$link['id_gruppo'],
            'classeviva',
            $idClasseCV,
            is_array($roster) ? $roster : []
        );
    }

    public function sincronizzaTutteLeClassi(): array
    {
        if ($this->api === null) {
            throw new Exception('API ClasseViva non configurata');
        }
        $stats = ['classi_sincronizzate' => 0, 'studenti_sincronizzati' => 0, 'nuovi' => 0, 'disattivati' => 0];
        foreach ($this->api->getClasses() as $class) {
            $classId = (string)($class['id'] ?? $class['classId'] ?? '');
            if ($classId === '') {
                continue;
            }
            $result = $this->sincronizzaStudentiClasse($classId, (string)($class['name'] ?? $class['className'] ?? $classId));
            $stats['classi_sincronizzate']++;
            $stats['studenti_sincronizzati'] += $result['sincronizzati'];
            $stats['nuovi'] += $result['nuovi'];
            $stats['disattivati'] += $result['disattivati'];
        }
        return $stats;
    }

    public function getStudentiClasse(string $idClasseCV, bool $soloAttivi = true): array
    {
        $cacheKey = $idClasseCV . '_' . ($soloAttivi ? 'attivi' : 'tutti');
        if (isset($this->studentiCache[$cacheKey])) {
            return $this->studentiCache[$cacheKey];
        }
        $link = (new TeachingGroupIntegrationRepository($this->db, $this->userId))
            ->findByContext('classeviva', $idClasseCV);
        if ($link === null) {
            return [];
        }
        $groupId = (string)$link['id_gruppo'];
        $group = (new TeachingGroupRepository($this->db, $this->userId))->findById($groupId) ?? [];
        $students = [];
        foreach ($this->memberships->listForGroup($groupId) as $membership) {
            if ($soloAttivi && ($membership['stato'] ?? 'attivo') !== 'attivo') {
                continue;
            }
            $studentId = (string)$membership['id_studente'];
            $cvIdentity = null;
            foreach ($this->identities->listForStudent($studentId) as $identity) {
                if (($identity['provider'] ?? '') === 'classeviva') {
                    $cvIdentity = $identity;
                    break;
                }
            }
            if ($cvIdentity === null) {
                continue;
            }
            $externalId = (string)$cvIdentity['external_user_id'];
            $profile = $this->api?->getStudente($externalId) ?? [];
            $students[] = [
                'id' => $externalId,
                'nome' => (string)($profile['nome'] ?? ''),
                'cognome' => (string)($profile['cognome'] ?? ''),
                'nome_completo' => trim((string)($profile['cognome'] ?? '') . ' ' . (string)($profile['nome'] ?? '')),
                // Chiavi di presentazione legacy, non persistite.
                'id_classe_cv' => $idClasseCV,
                'nome_classe' => (string)($group['nome_classe'] ?? ''),
            ];
        }
        usort($students, static fn(array $a, array $b): int => strcmp($a['cognome'], $b['cognome']));
        return $this->studentiCache[$cacheKey] = $students;
    }

    public function getStudente(string $idStudenteCV): ?array
    {
        if ($this->api === null) {
            return null;
        }
        try {
            $profile = $this->api->getStudente($idStudenteCV);
            return [
                'id' => $idStudenteCV,
                'nome' => (string)($profile['nome'] ?? ''),
                'cognome' => (string)($profile['cognome'] ?? ''),
                'nome_completo' => trim((string)($profile['cognome'] ?? '') . ' ' . (string)($profile['nome'] ?? '')),
            ];
        } catch (Exception $exception) {
            error_log('Errore recupero studente ' . $idStudenteCV . ': ' . $exception->getMessage());
            return null;
        }
    }

    public function getClassiSincronizzate(): array
    {
        $result = [];
        $links = $this->db->findWhere('GRUPPI_INTEGRAZIONI', ['provider' => 'classeviva']);
        foreach ($links as $link) {
            $groupId = (string)($link['id_gruppo'] ?? '');
            $classId = (string)($link['external_context_id'] ?? '');
            $members = $this->memberships->listForGroup($groupId);
            $active = count(array_filter($members, static fn(array $row): bool => ($row['stato'] ?? 'attivo') === 'attivo'));
            $result[] = [
                'id_classe_cv' => $classId,
                'nome_classe' => (string)($link['external_name'] ?? ''),
                'studenti_attivi' => $active,
                'studenti_disattivati' => count($members) - $active,
            ];
        }
        return $result;
    }

    public function studenteEsiste(string $idStudenteCV): bool
    {
        $student = $this->identityResolver->resolve('classeviva', $idStudenteCV);
        return $student !== null && ($student['stato'] ?? 'attivo') === 'attivo';
    }

    public function clearCache(): void
    {
        $this->studentiCache = [];
    }
}
