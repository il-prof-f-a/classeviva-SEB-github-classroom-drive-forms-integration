<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

/** Collega identità studente esterne senza memorizzare nomi o indirizzi. */
final class StudentProviderMappingService
{
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
        $this->students = new StudentRepository($db, $this->userId);
        $this->identities = new StudentIdentityRepository($db, $this->userId);
        $this->memberships = new GroupStudentRepository($db, $this->userId);
        $this->resources = new StudentResourceRepository($db, $this->userId);
        $this->resolver = new StudentIdentityResolver(
            $this->students,
            $this->identities,
            $this->memberships,
            $this->resources
        );
    }

    /**
     * @return array{student_id:string,created:bool}
     */
    public function link(string $groupId, string $classeVivaId, string $googleUserId, string $googleCourseId = ''): array
    {
        $classeVivaId = trim($classeVivaId);
        $googleUserId = trim($googleUserId);
        if ($groupId === '' || $classeVivaId === '' || $googleUserId === '') {
            throw new RuntimeException('Gruppo e identificativi esterni sono obbligatori.');
        }

        $cv = $this->resolver->resolveOrCreate('classeviva', $classeVivaId);
        $google = $this->resolver->resolveOrCreate('google_classroom', $googleUserId);
        $cvId = (string)$cv['id_studente'];
        $googleId = (string)$google['id_studente'];
        if ($googleId !== $cvId) {
            $this->resolver->merge($googleId, $cvId);
        }

        $this->memberships->add($groupId, $cvId, [
            'provider_origine' => 'classeviva',
            'external_context_id' => $groupId,
        ]);
        if ($googleCourseId !== '') {
            $this->resources->attach($cvId, [
                'provider' => 'google_classroom',
                'external_context_id' => $googleCourseId,
                'external_resource_id' => $googleUserId,
                'tipo_risorsa' => 'course_student',
            ]);
        }

        return ['student_id' => $cvId, 'created' => $googleId !== $cvId];
    }

    /** @return array<string,string> classeviva_id => google_user_id */
    public function listForGroup(string $groupId): array
    {
        $result = [];
        foreach ($this->memberships->listForGroup($groupId) as $membership) {
            $studentId = (string)($membership['id_studente'] ?? '');
            if ($studentId === '') {
                continue;
            }
            $cvId = null;
            $googleId = null;
            foreach ($this->identities->listForStudent($studentId) as $identity) {
                if (($identity['provider'] ?? '') === 'classeviva') {
                    $cvId = (string)$identity['external_user_id'];
                } elseif (($identity['provider'] ?? '') === 'google_classroom') {
                    $googleId = (string)$identity['external_user_id'];
                }
            }
            if ($cvId !== null && $googleId !== null) {
                $result[$cvId] = $googleId;
            }
        }
        return $result;
    }
}
