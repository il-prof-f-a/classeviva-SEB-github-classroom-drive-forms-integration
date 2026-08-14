<?php

declare(strict_types=1);

namespace App\Utils;

final class UdaMetadataHelper
{
    public static function classTargetFromAssignments(array $assignments): string
    {
        $names = [];
        foreach ($assignments as $assignment) {
            $name = trim((string)($assignment['nome_classe'] ?? $assignment['class_name'] ?? $assignment['classe'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return self::classTargetFromNames($names);
    }

    /**
     * Normalizza i nomi delle classi assegnate quando condividono anno e indirizzo.
     * Esempio: "4C Informatica", "4D Informatica" -> "4 Informatica".
     *
     * @param array<int|string,mixed> $names
     */
    public static function classTargetFromNames(array $names): string
    {
        $normalized = [];
        foreach ($names as $name) {
            $value = trim((string)$name);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return '';
        }
        if (count($normalized) === 1) {
            return $normalized[0];
        }

        $parts = [];
        foreach ($normalized as $name) {
            if (!preg_match('/^(\d+)\s*([[:alpha:]])(?:\s+(.*))?$/u', $name, $matches)) {
                return implode(', ', $normalized);
            }
            $parts[] = [
                'year' => $matches[1],
                'suffix' => trim($matches[3] ?? ''),
            ];
        }

        $years = array_values(array_unique(array_column($parts, 'year')));
        $suffixes = array_values(array_unique(array_column($parts, 'suffix')));
        if (count($years) !== 1 || count($suffixes) !== 1) {
            return implode(', ', $normalized);
        }

        $target = $years[0];
        if ($suffixes[0] !== '') {
            $target .= ' ' . $suffixes[0];
        }
        return $target;
    }

    /**
     * Restituisce la disciplina quando tutte le materie selezionate coincidono.
     *
     * @param array<int|string,mixed> $subjects
     */
    public static function disciplineFromSubjectNames(array $subjects): ?string
    {
        $unique = [];
        foreach ($subjects as $subject) {
            $value = trim((string)$subject);
            if ($value !== '' && !in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }
        return count($unique) === 1 ? $unique[0] : null;
    }

    public static function mergeClassroomNotes(string $existing, ?string $section, ?string $room): string
    {
        $section = trim((string)$section);
        $room = trim((string)$room);
        if ($section === '' && $room === '') {
            return trim($existing);
        }
        $lines = ['Import Classroom'];
        if ($section !== '') {
            $lines[] = 'Sezione: ' . $section;
        }
        if ($room !== '') {
            $lines[] = 'Aula: ' . $room;
        }
        $block = implode("\n", $lines);
        $existing = trim($existing);
        if ($existing === '') {
            return $block;
        }
        if (str_contains($existing, 'Import Classroom')) {
            return $existing;
        }
        return $existing . "\n\n" . $block;
    }

    public static function statusAfterClassroomImport(bool $imported, string $current): string
    {
        return self::statusAfterIntegrationSelection($imported, $current);
    }

    public static function statusAfterIntegrationSelection(bool $hasIntegration, string $current): string
    {
        $current = trim($current) !== '' ? trim($current) : 'bozza';
        return $hasIntegration && $current === 'bozza' ? 'attiva' : $current;
    }
}
