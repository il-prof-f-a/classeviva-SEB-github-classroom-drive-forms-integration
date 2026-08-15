<?php

declare(strict_types=1);

namespace App\Core;

final class StudentRosterService
{
    public function __construct(
        private StudentIdentityResolver $resolver,
        private GroupStudentRepository $memberships
    ) {
    }

    /** @param list<array<string,mixed>> $roster */
    public function sync(string $groupId, string $provider, string $externalContextId, array $roster): array
    {
        $result = [];
        foreach ($roster as $entry) {
            $externalId = trim((string)($entry['external_user_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $student = $this->resolver->resolveOrCreate($provider, $externalId);
            $this->memberships->add((string)$groupId, (string)$student['id_studente'], [
                'provider_origine' => $provider,
                'external_context_id' => $externalContextId,
            ]);
            $result[] = [
                'id_studente' => $student['id_studente'],
                'external_user_id' => $externalId,
                'display_name' => (string)($entry['display_name'] ?? ''),
            ];
        }
        return $result;
    }
}
