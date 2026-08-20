<?php

declare(strict_types=1);

namespace App\Core;

/** Stable ordering for Classroom course pickers. */
final class ClassroomCourseOrdering
{
    /**
     * @param list<array<string,mixed>> $courses
     * @param list<string> $mappedCourseIds
     * @return list<array<string,mixed>>
     */
    public static function mappedFirst(array $courses, array $mappedCourseIds): array
    {
        $mapped = [];
        foreach ($mappedCourseIds as $courseId) {
            $courseId = trim((string)$courseId);
            if ($courseId !== '') {
                $mapped[$courseId] = true;
            }
        }

        $decorated = [];
        foreach (array_values($courses) as $index => $course) {
            $id = trim((string)($course['id'] ?? ''));
            $decorated[] = [
                'mapped' => isset($mapped[$id]) ? 0 : 1,
                'index' => $index,
                'course' => $course,
            ];
        }

        usort($decorated, static function (array $left, array $right): int {
            return [$left['mapped'], $left['index']] <=> [$right['mapped'], $right['index']];
        });

        return array_values(array_map(static fn(array $entry): array => $entry['course'], $decorated));
    }
}
