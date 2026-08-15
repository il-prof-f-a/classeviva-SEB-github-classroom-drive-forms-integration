<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class StudentIdentityResolver
{
    public function __construct(
        private StudentRepository $students,
        private StudentIdentityRepository $identities,
        private GroupStudentRepository $memberships,
        private StudentResourceRepository $resources
    ) {
    }

    public function resolve(string $provider, string $externalUserId): ?array
    {
        $identity = $this->identities->findByExternal($provider, $externalUserId);
        return $identity === null ? null : $this->students->findById((string)$identity['id_studente']);
    }

    public function resolveOrCreate(string $provider, string $externalUserId): array
    {
        $resolved = $this->resolve($provider, $externalUserId);
        if ($resolved !== null) {
            return $resolved;
        }
        $student = $this->students->create();
        $this->identities->attach((string)$student['id_studente'], [
            'provider' => $provider,
            'external_user_id' => $externalUserId,
        ]);
        return $student;
    }

    public function merge(string $sourceStudentId, string $targetStudentId): bool
    {
        if ($sourceStudentId === $targetStudentId) {
            return true;
        }
        if ($this->students->findById($sourceStudentId) === null || $this->students->findById($targetStudentId) === null) {
            throw new RuntimeException('Studente sorgente o target non trovato');
        }
        foreach ($this->identities->listForStudent($sourceStudentId) as $identity) {
            $existing = $this->identities->findByExternal((string)$identity['provider'], (string)$identity['external_user_id']);
            if ($existing !== null && (string)$existing['id_studente'] !== $sourceStudentId && (string)$existing['id_studente'] !== $targetStudentId) {
                throw new RuntimeException('Conflitto durante il merge di identità esterne');
            }
            if ($existing === null || (string)$existing['id_studente'] === $sourceStudentId) {
                $this->identities->reassign((string)$identity['id_identita'], $targetStudentId);
            }
        }
        foreach ($this->memberships->listForStudent($sourceStudentId) as $membership) {
            $existing = array_filter(
                $this->memberships->listForGroup((string)$membership['id_gruppo']),
                static fn(array $row): bool => (string)$row['id_studente'] === $targetStudentId
            );
            if ($existing === []) {
                $this->memberships->reassign((string)$membership['id_iscrizione'], $targetStudentId);
            } else {
                $this->memberships->delete((string)$membership['id_iscrizione']);
            }
        }
        foreach ($this->resources->listForStudent($sourceStudentId) as $resource) {
            $this->resources->reassign((string)$resource['id_risorsa'], $targetStudentId);
        }
        return $this->students->delete($sourceStudentId);
    }
}
