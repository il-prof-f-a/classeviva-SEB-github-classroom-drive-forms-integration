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
        return implode(', ', $names);
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
        $current = trim($current) !== '' ? trim($current) : 'bozza';
        return $imported && $current === 'bozza' ? 'attiva' : $current;
    }
}
