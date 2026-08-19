<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

/**
 * Costruisce l'elenco studenti di un assignment GitHub Classroom in modo
 * provider-neutral.
 *
 * La fonte primaria è il roster del gruppo didattico (studenti con identità
 * GitHub in STUDENTI_IDENTITA_ESTERNE); gli accepted assignments restituiti
 * dalle API GitHub Classroom arricchiscono repository/commit. La tabella
 * GITHUB_ASSIGNMENT_STUDENT_LINKS è usata come overlay per associazioni manuali
 * o repository sovrascritte.
 *
 * In questo modo la revisione dei risultati non dipende dalla vecchia mappatura
 * ClasseViva né dalla presenza di accepted assignments (che possono mancare se
 * gli studenti non hanno ancora accettato o se il token non ha accesso alle API
 * Classroom).
 */
final class GitHubAssignmentRosterService
{
    private StudentIdentityResolver $resolver;
    private StudentIdentityRepository $identities;
    private GroupStudentRepository $memberships;

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        $this->identities = new StudentIdentityRepository($this->db, $this->userId);
        $this->memberships = new GroupStudentRepository($this->db, $this->userId);
        $this->resolver = new StudentIdentityResolver(
            new StudentRepository($this->db, $this->userId),
            $this->identities,
            $this->memberships,
            new StudentResourceRepository($this->db, $this->userId)
        );
    }

    /**
     * @param list<array<string,mixed>> $acceptedAssignments roster dalle API GitHub Classroom
     * @param string $assignmentId id assignment GitHub (API)
     * @param string|null $groupId gruppo didattico da cui derivare il roster studenti
     * @return list<array<string,mixed>> righe con id_studente, github_username,
     *                                  roster_identifier, student_repository_url
     */
    public function buildStudentMap(array $acceptedAssignments, string $assignmentId, ?string $groupId = null): array
    {
        // 1. Overlay manuale/legacy da GITHUB_ASSIGNMENT_STUDENT_LINKS.
        $linkByStudent = [];
        $linkByUsername = [];
        if ($assignmentId !== '') {
            foreach ($this->db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', [
                'id_assignment' => $assignmentId,
                'id_utente' => $this->userId,
            ]) as $link) {
                $studentId = trim((string)($link['id_studente'] ?? ''));
                if ($studentId === '') {
                    continue;
                }
                $linkByStudent[$studentId] = $link;
                $username = $this->githubUsernameForStudent($studentId);
                if ($username !== '') {
                    $linkByUsername[strtolower($username)] = $link;
                }
            }
        }

        // 2. Roster del gruppo didattico: studenti con identità GitHub.
        $groupRows = [];
        if ($groupId !== null && trim($groupId) !== '') {
            foreach ($this->memberships->listForGroup(trim($groupId)) as $membership) {
                $studentId = trim((string)($membership['id_studente'] ?? ''));
                if ($studentId === '') {
                    continue;
                }
                $username = $this->githubUsernameForStudent($studentId);
                if ($username === '') {
                    continue;
                }
                $groupRows[$studentId] = [
                    'id_studente' => $studentId,
                    'github_username' => $username,
                    'roster_identifier' => '',
                    'student_repository_url' => '',
                ];
            }
        }

        $rows = [];
        $seenStudents = [];
        $seenUsernames = [];

        // 3. Accepted assignments (API): arricchiscono repo e possono aggiungere
        //    studenti non ancora presenti nel gruppo.
        foreach ($acceptedAssignments as $item) {
            if (!is_array($item)) {
                continue;
            }
            $username = $this->extractUsername($item);
            $usernameKey = strtolower(trim($username));
            $rosterId = trim((string)($item['roster_identifier'] ?? ''));
            $repoUrl = $this->extractRepositoryUrl($item);

            $studentId = '';
            if ($username !== '') {
                $resolved = $this->resolver->resolve('github_classroom', $username);
                if ($resolved !== null) {
                    $studentId = trim((string)($resolved['id_studente'] ?? ''));
                }
            }
            // Fallback: match con il roster del gruppo (case-insensitive).
            if ($studentId === '' && $usernameKey !== '') {
                foreach ($groupRows as $gStudentId => $gRow) {
                    if (strtolower((string)$gRow['github_username']) === $usernameKey) {
                        $studentId = $gStudentId;
                        break;
                    }
                }
            }

            $link = null;
            if ($studentId !== '' && isset($linkByStudent[$studentId])) {
                $link = $linkByStudent[$studentId];
            } elseif ($usernameKey !== '' && isset($linkByUsername[$usernameKey])) {
                $link = $linkByUsername[$usernameKey];
            }
            if ($link !== null) {
                $linkStudentId = trim((string)($link['id_studente'] ?? ''));
                if ($linkStudentId !== '') {
                    $studentId = $linkStudentId;
                }
                $linkRepo = trim((string)($link['student_repository_url'] ?? ''));
                if ($linkRepo !== '') {
                    $repoUrl = $linkRepo;
                }
            }

            if ($studentId !== '' && isset($seenStudents[$studentId])) {
                continue;
            }
            if ($usernameKey !== '' && isset($seenUsernames[$usernameKey])) {
                continue;
            }
            if ($studentId !== '') {
                $seenStudents[$studentId] = true;
            }
            if ($usernameKey !== '') {
                $seenUsernames[$usernameKey] = true;
            }

            $rows[] = [
                'id_studente' => $studentId,
                'github_username' => $username,
                'roster_identifier' => $rosterId,
                'student_repository_url' => $repoUrl,
            ];
        }

        // 4. Studenti del gruppo non già coperti dagli accepted assignments.
        foreach ($groupRows as $studentId => $gRow) {
            if (isset($seenStudents[$studentId])) {
                continue;
            }
            $usernameKey = strtolower(trim((string)$gRow['github_username']));
            if ($usernameKey !== '' && isset($seenUsernames[$usernameKey])) {
                continue;
            }
            $repoUrl = '';
            if (isset($linkByStudent[$studentId])) {
                $repoUrl = trim((string)($linkByStudent[$studentId]['student_repository_url'] ?? ''));
            }
            $seenStudents[$studentId] = true;
            if ($usernameKey !== '') {
                $seenUsernames[$usernameKey] = true;
            }
            $rows[] = [
                'id_studente' => $studentId,
                'github_username' => (string)$gRow['github_username'],
                'roster_identifier' => (string)$gRow['roster_identifier'],
                'student_repository_url' => $repoUrl,
            ];
        }

        // 5. Link senza accepted assignment né studente del gruppo (associazioni manuali).
        foreach ($linkByStudent as $studentId => $link) {
            if (isset($seenStudents[$studentId])) {
                continue;
            }
            $username = $this->githubUsernameForStudent($studentId);
            $seenStudents[$studentId] = true;
            $rows[] = [
                'id_studente' => $studentId,
                'github_username' => $username,
                'roster_identifier' => '',
                'student_repository_url' => trim((string)($link['student_repository_url'] ?? '')),
            ];
        }

        return $rows;
    }

    private function githubUsernameForStudent(string $studentId): string
    {
        foreach ($this->identities->listForStudent($studentId) as $identity) {
            if (($identity['provider'] ?? '') === 'github_classroom') {
                $username = trim((string)($identity['external_user_id'] ?? ''));
                if ($username !== '') {
                    return $username;
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $item */
    private function extractUsername(array $item): string
    {
        if (!empty($item['students'][0]['login']) && is_scalar($item['students'][0]['login'])) {
            return (string)$item['students'][0]['login'];
        }
        if (!empty($item['student']['login']) && is_scalar($item['student']['login'])) {
            return (string)$item['student']['login'];
        }
        if (!empty($item['github_username']) && is_scalar($item['github_username'])) {
            return (string)$item['github_username'];
        }
        return '';
    }

    /** @param array<string,mixed> $item */
    private function extractRepositoryUrl(array $item): string
    {
        if (!empty($item['repository']['html_url']) && is_scalar($item['repository']['html_url'])) {
            return (string)$item['repository']['html_url'];
        }
        if (!empty($item['repository_url']) && is_scalar($item['repository_url'])) {
            return (string)$item['repository_url'];
        }
        return '';
    }
}
