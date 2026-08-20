<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

/**
 * Risoluzione provider-neutral degli studenti per gli import voti.
 *
 * Traduce identità esterne (Google Classroom, GitHub Classroom, ...) in
 * id_studente interni tramite il roster del gruppo didattico, senza alcuna
 * dipendenza dalla coppia ClasseViva classe/materia. La chiave canonica resta
 * sempre l'id_studente interno.
 */
final class GradeImportStudentService
{
    private TeachingGroupStudentService $students;
    private TeachingGroupIntegrationRepository $integrations;
    private StudentIdentityRepository $identities;

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
        $this->userId = trim($this->userId) !== '' ? trim($this->userId) : 'system';
        $this->students = new TeachingGroupStudentService($this->db, $this->userId);
        $this->integrations = new TeachingGroupIntegrationRepository($this->db, $this->userId);
        $this->identities = new StudentIdentityRepository($this->db, $this->userId);
    }

    /**
     * Restituisce l'id_gruppo collegato a un contesto provider, o lancia
     * RuntimeException quando nessun gruppo attivo è collegato.
     */
    public function resolveGroupId(string $provider, string $contextId): string
    {
        $integration = $this->integrations->findByContext($provider, $contextId);
        if ($integration === null) {
            throw new RuntimeException('Nessun gruppo didattico collegato a questo contesto provider.');
        }
        $groupId = trim((string)($integration['id_gruppo'] ?? ''));
        if ($groupId === '') {
            throw new RuntimeException('Gruppo didattico collegato senza id_gruppo.');
        }

        return $groupId;
    }

    /**
     * @param list<array<string,mixed>> $externalRows  righe con external_user_id più campi opzionali (display_name, email, voto...)
     * @return array{group_id:string, rows:list<array<string,mixed>>}
     */
    public function resolve(string $provider, string $contextId, array $externalRows): array
    {
        $groupId = $this->resolveGroupId($provider, $contextId);

        $roster = [];
        foreach ($externalRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $externalId = trim((string)($row['external_user_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $entry = ['external_user_id' => $externalId];
            foreach (['display_name', 'email'] as $label) {
                if (isset($row[$label]) && is_scalar($row[$label])) {
                    $entry[$label] = (string)$row[$label];
                }
            }
            $roster[] = $entry;
        }

        $synced = $this->students->syncRoster($groupId, $provider, $contextId, $roster);
        $map = [];
        foreach ($synced as $syncedRow) {
            $externalId = trim((string)($syncedRow['external_user_id'] ?? ''));
            $studentId = trim((string)($syncedRow['id_studente'] ?? ''));
            if ($externalId !== '' && $studentId !== '') {
                $map[$externalId] = $studentId;
            }
        }

        $rows = [];
        foreach ($externalRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $externalId = trim((string)($row['external_user_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $out = $row;
            $studentId = $map[$externalId] ?? null;
            $out['id_studente'] = $studentId;
            $out['cv_id'] = is_string($studentId) && $studentId !== ''
                ? $this->classeVivaIdForStudent($studentId)
                : null;
            $rows[] = $out;
        }

        return ['group_id' => $groupId, 'rows' => $rows];
    }

    /**
     * Restituisce l'id ClasseViva (external_user_id classeviva) di uno studente
     * interno già risolto, oppure null quando lo studente non è mappato in
     * ClasseViva.
     */
    private function classeVivaIdForStudent(string $studentId): ?string
    {
        foreach ($this->identities->listForStudent($studentId) as $identity) {
            if (($identity['provider'] ?? '') === 'classeviva') {
                $cvId = trim((string)($identity['external_user_id'] ?? ''));
                if ($cvId !== '') {
                    return $cvId;
                }
            }
        }

        return null;
    }

    /**
     * Risolve nomi (es. giocatori Kahoot) verso id_studente interni, matchando
     * i nomi contro un roster provider vivo (normalizzazione + levenshtein <= 3).
     *
     * @param list<array<string,mixed>> $roster  righe con external_user_id, display_name, email
     * @param list<string> $names  nomi da risolvere
     * @return array{group_id:string, matches:array<string,array{external_user_id:string,id_studente:string|null}>, unmatched:list<string>}
     */
    public function resolveByName(string $provider, string $contextId, array $roster, array $names): array
    {
        $rosterIndex = [];
        foreach ($roster as $row) {
            if (!is_array($row)) {
                continue;
            }
            $externalId = trim((string)($row['external_user_id'] ?? ''));
            $displayName = trim((string)($row['display_name'] ?? ''));
            if ($externalId === '' || $displayName === '') {
                continue;
            }
            $rosterIndex[] = [
                'external_user_id' => $externalId,
                'display_name' => $displayName,
                'normalized' => self::normalizeComparable($displayName),
            ];
        }

        $externalRows = [];
        $matches = [];
        $unmatched = [];
        foreach ($names as $rawName) {
            if (!is_scalar($rawName)) {
                continue;
            }
            $name = trim((string)$rawName);
            if ($name === '') {
                continue;
            }
            $normalized = self::normalizeComparable($name);
            $best = null;
            $bestDistance = PHP_INT_MAX;
            foreach ($rosterIndex as $candidate) {
                if ($normalized === '' || $candidate['normalized'] === '') {
                    continue;
                }
                $distance = levenshtein($normalized, $candidate['normalized']);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $candidate;
                }
            }
            if ($best !== null && $bestDistance <= 3) {
                $externalRows[] = [
                    'external_user_id' => $best['external_user_id'],
                    'display_name' => $best['display_name'],
                ];
                $matches[$name] = ['external_user_id' => $best['external_user_id']];
            } else {
                $unmatched[] = $name;
            }
        }

        $resolved = $this->resolve($provider, $contextId, $externalRows);
        $byExternal = [];
        foreach ($resolved['rows'] as $row) {
            $externalId = trim((string)($row['external_user_id'] ?? ''));
            $studentId = trim((string)($row['id_studente'] ?? ''));
            if ($externalId !== '' && $studentId !== '') {
                $byExternal[$externalId] = $studentId;
            }
        }
        foreach ($matches as $name => $match) {
            $matches[$name]['id_studente'] = $byExternal[$match['external_user_id']] ?? null;
        }

        return [
            'group_id' => $resolved['group_id'],
            'matches' => $matches,
            'unmatched' => array_values($unmatched),
        ];
    }

    private static function normalizeComparable(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $text = mb_strtolower($text);
        } else {
            $text = strtolower($text);
        }
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($translit !== false && $translit !== null) {
            $text = $translit;
        }
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim($text);
    }
}
