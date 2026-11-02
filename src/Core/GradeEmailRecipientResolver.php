<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

/** Resolves a student's email just in time from active provider rosters. */
final class GradeEmailRecipientResolver
{
    private TeachingGroupIntegrationRepository $integrations;
    private StudentIdentityRepository $identities;
    private RuntimeStudentNameService $runtimeRoster;

    /**
     * @param array<string,mixed> $config
     * @param callable(string,string):list<array<string,mixed>>|null $rosterLoader
     */
    public function __construct(
        DatabaseAdapterInterface $db,
        private string $userId,
        private array $config = [],
        ?callable $rosterLoader = null
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        $this->integrations = new TeachingGroupIntegrationRepository($db, $this->userId);
        $this->identities = new StudentIdentityRepository($db, $this->userId);
        $this->runtimeRoster = new RuntimeStudentNameService($db, $this->userId, $rosterLoader ?? $this->config);
    }

    /**
     * @return array{email:string,nome:string,cognome:string,source:string}|null
     */
    public function resolve(string $groupId, string $studentId): ?array
    {
        $groupId = trim($groupId);
        $studentId = trim($studentId);
        if ($groupId === '' || $studentId === '') {
            return null;
        }

        $identitiesByProvider = [];
        foreach ($this->identities->listForStudent($studentId) as $identity) {
            if (($identity['stato'] ?? 'attivo') === 'disattivo') {
                continue;
            }
            $provider = trim((string)($identity['provider'] ?? ''));
            $externalId = trim((string)($identity['external_user_id'] ?? ''));
            if ($provider === '' || $externalId === '') {
                continue;
            }
            $identitiesByProvider[$provider][] = $identity;
        }

        $seenContexts = [];
        $activeIntegrations = array_values(array_filter(
            $this->integrations->listForGroup($groupId),
            static fn(array $integration): bool => ($integration['stato'] ?? 'attivo') !== 'disattivo'
        ));
        usort($activeIntegrations, static function (array $left, array $right): int {
            $priority = ['google_classroom' => 0, 'classeviva' => 1, 'github_classroom' => 2];
            return ($priority[$left['provider'] ?? ''] ?? 99) <=> ($priority[$right['provider'] ?? ''] ?? 99);
        });

        foreach ($activeIntegrations as $integration) {
            $provider = trim((string)($integration['provider'] ?? ''));
            $contextId = trim((string)($integration['external_context_id'] ?? ''));
            if ($provider === '' || $contextId === '' || isset($seenContexts[$provider . ':' . $contextId])) {
                continue;
            }
            $seenContexts[$provider . ':' . $contextId] = true;

            foreach ($identitiesByProvider[$provider] ?? [] as $identity) {
                $identityContext = trim((string)($identity['external_context_id'] ?? ''));
                if ($identityContext !== '' && $identityContext !== $contextId) {
                    continue;
                }
                $externalId = trim((string)($identity['external_user_id'] ?? ''));
                foreach ($this->runtimeRoster->providerRoster($provider, $contextId) as $student) {
                    if (!is_array($student) || self::externalId($student) !== $externalId) {
                        continue;
                    }
                    $name = self::studentName($student);
                    $email = trim((string)($student['email'] ?? ''));
                    if (self::isEmail($email)) {
                        return [
                            'email' => $email,
                            'nome' => $name['nome'],
                            'cognome' => $name['cognome'],
                            'source' => $provider,
                        ];
                    }

                    if ($provider === 'classeviva') {
                        $generated = $this->generatedEmail($name['nome'], $name['cognome']);
                        if ($generated !== null) {
                            return [
                                'email' => $generated,
                                'nome' => $name['nome'],
                                'cognome' => $name['cognome'],
                                'source' => 'profile_template',
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $student */
    private static function externalId(array $student): string
    {
        return trim((string)(
            $student['external_user_id']
            ?? $student['studentId']
            ?? $student['userId']
            ?? $student['id']
            ?? ''
        ));
    }

    /** @param array<string,mixed> $student @return array{nome:string,cognome:string} */
    private static function studentName(array $student): array
    {
        $nome = trim((string)($student['nome'] ?? $student['firstName'] ?? ''));
        $cognome = trim((string)($student['cognome'] ?? $student['lastName'] ?? ''));
        if ($nome === '' && $cognome === '') {
            $nome = trim((string)($student['name'] ?? $student['display_name'] ?? ''));
        }

        return ['nome' => $nome, 'cognome' => $cognome];
    }

    private function generatedEmail(string $nome, string $cognome): ?string
    {
        $profile = is_array($this->config['user_profile'] ?? null) ? $this->config['user_profile'] : [];
        $domain = trim((string)($profile['school_email_domain'] ?? ''));
        if ($domain === '' || trim($nome) === '' || trim($cognome) === '') {
            return null;
        }

        $email = StudentEmailResolver::generate(
            (string)($profile['school_student_email_template'] ?? ''),
            $domain,
            $nome,
            $cognome
        );

        return self::isEmail($email) ? $email : null;
    }

    private static function isEmail(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
