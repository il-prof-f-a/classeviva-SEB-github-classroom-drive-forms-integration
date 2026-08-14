<?php

declare(strict_types=1);

namespace App\Integration;

final class ClassroomAssignmentCatalog
{
    /** @return array<string,mixed> */
    public static function normalize(mixed $courseWork): array
    {
        $id = (string)self::value($courseWork, ['id', 'getId'], '');
        $title = trim((string)self::value($courseWork, ['title', 'getTitle'], 'Compito Classroom'));
        $description = trim((string)self::value($courseWork, ['description', 'getDescription'], ''));
        $state = (string)self::value($courseWork, ['state', 'getState'], '');
        $workType = (string)self::value($courseWork, ['workType', 'getWorkType'], '');
        $maxPoints = self::value($courseWork, ['maxPoints', 'getMaxPoints'], null);
        $topicId = self::value($courseWork, ['topicId', 'getTopicId'], null);
        $link = trim((string)self::value($courseWork, ['alternateLink', 'getAlternateLink'], ''));
        $creationTime = (string)self::value($courseWork, ['creationTime', 'getCreationTime'], '');
        $updateTime = (string)self::value($courseWork, ['updateTime', 'getUpdateTime'], '');

        return [
            'id' => $id,
            'title' => $title !== '' ? $title : 'Compito Classroom senza titolo',
            'description' => $description,
            'state' => $state,
            'work_type' => $workType,
            'max_points' => $maxPoints === null || $maxPoints === '' ? 100 : (float)$maxPoints,
            'due_date' => self::normalizeDueDate($courseWork),
            'topic_id' => $topicId !== null ? (string)$topicId : null,
            'link' => $link,
            'student_url' => $link,
            'teacher_url' => $link,
            'creation_time' => $creationTime,
            'update_time' => $updateTime,
            'source' => 'google-classroom',
        ];
    }

    /** @param array<string,mixed> $assignment */
    public static function isImportable(array $assignment): bool
    {
        return in_array(strtoupper((string)($assignment['work_type'] ?? '')), ['ASSIGNMENT', 'QUIZ_ASSIGNMENT'], true);
    }

    /** @param list<array<string,mixed>> $assignments @return list<array<string,mixed>> */
    public static function filter(array $assignments, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return array_values($assignments);
        }

        return array_values(array_filter($assignments, static function (array $assignment) use ($needle): bool {
            $haystack = mb_strtolower(implode(' ', [
                (string)($assignment['title'] ?? ''),
                (string)($assignment['description'] ?? ''),
                (string)($assignment['state'] ?? ''),
                (string)($assignment['due_date'] ?? ''),
                (string)($assignment['update_time'] ?? ''),
            ]));
            return str_contains($haystack, $needle);
        }));
    }

    private static function normalizeDueDate(mixed $courseWork): ?string
    {
        $date = self::value($courseWork, ['dueDate', 'getDueDate'], null);
        if ($date === null || $date === '') {
            return null;
        }

        $year = self::value($date, ['year', 'getYear'], null);
        $month = self::value($date, ['month', 'getMonth'], null);
        $day = self::value($date, ['day', 'getDay'], null);
        if ($year === null || $month === null || $day === null) {
            return is_scalar($date) ? (string)$date : null;
        }

        $result = sprintf('%04d-%02d-%02d', (int)$year, (int)$month, (int)$day);
        $time = self::value($courseWork, ['dueTime', 'getDueTime'], null);
        $hours = self::value($time, ['hours', 'getHours'], null);
        $minutes = self::value($time, ['minutes', 'getMinutes'], null);
        if ($hours !== null || $minutes !== null) {
            $result .= sprintf(' %02d:%02d', (int)($hours ?? 23), (int)($minutes ?? 59));
        }
        return $result;
    }

    private static function value(mixed $source, array $keys, mixed $fallback): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
            if (is_object($source)) {
                if (method_exists($source, (string)$key)) {
                    return $source->{$key}();
                }
                if (isset($source->{$key})) {
                    return $source->{$key};
                }
            }
        }
        return $fallback;
    }
}
