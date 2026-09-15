<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Normalizza e valida i gruppi di lavoro di un assignment GitHub.
 *
 * I gruppi didattici del roster restano separati dai gruppi di lavoro: qui
 * vengono trattati esclusivamente gli id degli studenti già risolti dal
 * passaggio precedente.
 */
final class GitHubAssignmentTeamService
{
    /** @return 'single'|'group' */
    public function normalizeMode(mixed $mode): string
    {
        return strtolower(trim((string)$mode)) === 'group' ? 'group' : 'single';
    }

    /**
     * @param list<array<string,mixed>> $rawGroups
     * @param list<array<string,mixed>> $students
     * @return list<array{id:string,name:string,color:string,student_ids:list<string>}>
     */
    public function normalizeGroups(array $rawGroups, array $students): array
    {
        $allowed = [];
        foreach ($students as $student) {
            if (!is_array($student)) {
                continue;
            }
            $id = trim((string)($student['id_studente'] ?? ''));
            if ($id !== '') {
                $allowed[$id] = true;
            }
        }
        if ($allowed === []) {
            throw new InvalidArgumentException('Nessuno studente disponibile per la composizione dei gruppi.');
        }

        $groups = [];
        $groupIds = [];
        $assigned = [];
        $usedColors = [];
        foreach ($rawGroups as $index => $rawGroup) {
            if (!is_array($rawGroup)) {
                continue;
            }
            $groupId = self::safeId((string)($rawGroup['id'] ?? ''), $index + 1);
            if (isset($groupIds[$groupId])) {
                throw new InvalidArgumentException('Il gruppo di lavoro è duplicato.');
            }
            $groupIds[$groupId] = true;

            $memberIds = [];
            $rawMembers = $rawGroup['student_ids'] ?? [];
            if (!is_array($rawMembers)) {
                throw new InvalidArgumentException('Elenco studenti del gruppo non valido.');
            }
            foreach ($rawMembers as $rawStudentId) {
                if (!is_scalar($rawStudentId)) {
                    throw new InvalidArgumentException('Identificativo studente non valido.');
                }
                $studentId = trim((string)$rawStudentId);
                if ($studentId === '') {
                    continue;
                }
                if (!isset($allowed[$studentId])) {
                    throw new InvalidArgumentException('Il gruppo contiene uno studente non presente nel roster.');
                }
                if (isset($assigned[$studentId])) {
                    throw new InvalidArgumentException('Uno studente non può appartenere a più gruppi.');
                }
                $assigned[$studentId] = true;
                $memberIds[] = $studentId;
            }

            // Un gruppo vuoto è utile durante l'editing, ma non deve creare una
            // repository vuota quando il docente conferma l'assignment.
            if ($memberIds === []) {
                continue;
            }

            $color = self::normalizeColor((string)($rawGroup['color'] ?? ''), $index);
            if (isset($usedColors[$color])) {
                $color = self::paletteColor($index + count($usedColors) + 1);
            }
            $usedColors[$color] = true;
            $name = trim((string)($rawGroup['name'] ?? ''));
            if ($name === '') {
                $name = 'Gruppo ' . ($index + 1);
            }
            $groups[] = [
                'id' => $groupId,
                'name' => mb_substr($name, 0, 120, 'UTF-8'),
                'color' => $color,
                'student_ids' => $memberIds,
            ];
        }

        $missing = array_diff(array_keys($allowed), array_keys($assigned));
        if ($missing !== []) {
            throw new InvalidArgumentException('Ogni studente deve appartenere a un gruppo di lavoro.');
        }
        if ($groups === []) {
            throw new InvalidArgumentException('Crea almeno un gruppo con studenti assegnati.');
        }
        return array_values($groups);
    }

    /** @param list<array<string,mixed>> $students */
    public function defaultGroups(array $students): array
    {
        $studentIds = [];
        foreach ($students as $student) {
            $id = trim((string)($student['id_studente'] ?? ''));
            if ($id !== '') {
                $studentIds[] = $id;
            }
        }
        return [[
            'id' => 'team-1',
            'name' => 'Gruppo 1',
            'color' => self::paletteColor(0),
            'student_ids' => $studentIds,
        ]];
    }

    private static function safeId(string $value, int $index): string
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)) {
            return $value;
        }
        return 'team-' . max(1, $index);
    }

    private static function normalizeColor(string $value, int $index): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1
            ? $value
            : self::paletteColor($index);
    }

    private static function paletteColor(int $index): string
    {
        $palette = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#ede9fe', '#cffafe', '#ffedd5', '#e0e7ff'];
        return $palette[max(0, $index) % count($palette)];
    }
}
