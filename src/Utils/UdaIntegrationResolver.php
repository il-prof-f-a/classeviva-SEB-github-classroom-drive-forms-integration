<?php

declare(strict_types=1);

namespace App\Utils;

final class UdaIntegrationResolver
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    public static function indexClassroomMappings(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $classId = self::firstValue($row, ['id_classe_cv', 'classeviva_class_id']);
            $subjectId = self::firstValue($row, ['id_materia_cv', 'classeviva_subject_id']);
            $courseId = self::firstValue($row, ['id_corso_gc', 'google_course_id']);
            if ($classId === '' || $subjectId === '' || $courseId === '') {
                continue;
            }
            $index[self::key($classId, $subjectId)] = [
                ...$row,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'course_id' => $courseId,
                'course_name' => self::firstValue($row, ['nome_corso_gc', 'google_course_name']),
            ];
        }
        return $index;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    public static function indexGithubMappings(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $classId = self::firstValue($row, ['id_classe_cv']);
            $subjectId = self::firstValue($row, ['id_materia_cv']);
            $classroomId = self::firstValue($row, ['github_classroom_id']);
            if ($classId === '' || $subjectId === '' || $classroomId === '') {
                continue;
            }
            $index[self::key($classId, $subjectId)] = [
                ...$row,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'classroom_id' => $classroomId,
                'classroom_name' => self::firstValue($row, ['classroom_name']),
            ];
        }
        return $index;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    public static function classroomForPair(string $classId, string $subjectId, array $rows): ?array
    {
        return self::indexClassroomMappings($rows)[self::key($classId, $subjectId)] ?? null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    public static function githubForPair(string $classId, string $subjectId, array $rows): ?array
    {
        return self::indexGithubMappings($rows)[self::key($classId, $subjectId)] ?? null;
    }

    /**
     * @param list<array<string,mixed>> $selectedRows
     */
    public static function hasIntegration(array $selectedRows): bool
    {
        foreach ($selectedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (self::firstValue($row, ['course_id', 'id_corso_gc', 'google_course_id', 'classroom_id', 'github_classroom_id']) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function key(string $classId, string $subjectId): string
    {
        return trim($classId) . '|' . trim($subjectId);
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function firstValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
