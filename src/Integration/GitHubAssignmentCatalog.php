<?php

declare(strict_types=1);

namespace App\Integration;

final class GitHubAssignmentCatalog
{
    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    public static function normalize(array $assignment, ?string $classroomId = null): array
    {
        $id = trim((string)($assignment['id'] ?? $assignment['assignment_id'] ?? ''));
        $title = trim((string)($assignment['title'] ?? $assignment['name'] ?? $assignment['assignment_title'] ?? 'Assignment GitHub'));
        $slug = trim((string)($assignment['slug'] ?? ''));
        $studentUrl = trim((string)($assignment['invite_link']
            ?? $assignment['invitation_link']
            ?? $assignment['invite_url']
            ?? $assignment['invitation_url']
            ?? ''));
        $teacherUrl = trim((string)($assignment['html_url'] ?? $assignment['url'] ?? $assignment['teacher_url'] ?? ''));
        if ($teacherUrl === '' && $slug !== '') {
            // L'API Classroom non espone sempre html_url: ricostruisci l'URL docente
            // (lista repo studenti accettati) da classroom.url / id+nome / id.
            $classroom = is_array($assignment['classroom'] ?? null) ? $assignment['classroom'] : [];
            $classroomUrl = trim((string)($classroom['url'] ?? ''));
            $nestedClassroomId = trim((string)($classroom['id'] ?? ''));
            $classroomName = trim((string)($classroom['name'] ?? ''));
            if ($classroomUrl === '' && $nestedClassroomId !== '' && $classroomName !== '') {
                $classroomUrl = 'https://classroom.github.com/classrooms/' . $nestedClassroomId . '-' . $classroomName;
            }
            if ($classroomUrl !== '') {
                $teacherUrl = rtrim($classroomUrl, '/') . '/assignments/' . $slug;
            } elseif ($classroomId !== '') {
                // Il classroom_id richiesto (parametro) è sempre disponibile: usalo
                // come fallback affidabile quando l'oggetto classroom non è incluso.
                $teacherUrl = 'https://classroom.github.com/classrooms/' . $classroomId . '/assignments/' . $slug;
            } elseif ($nestedClassroomId !== '') {
                $teacherUrl = 'https://classroom.github.com/classrooms/' . $nestedClassroomId . '/assignments/' . $slug;
            }
        }
        $resolvedClassroomId = trim((string)($assignment['classroom_id'] ?? $classroomId ?? ''));
        if ($resolvedClassroomId === '') {
            $resolvedClassroomId = self::classroomIdFromUrl($teacherUrl);
        }

        return [
            'id' => $id,
            'title' => $title !== '' ? $title : ($slug !== '' ? $slug : 'Assignment GitHub senza titolo'),
            'slug' => $slug,
            'description' => trim((string)($assignment['description'] ?? '')),
            'state' => trim((string)($assignment['state'] ?? '')),
            'student_url' => $studentUrl,
            'teacher_url' => $teacherUrl,
            'url_assignment_student' => $studentUrl,
            'url_assignment_teacher' => $teacherUrl,
            'github_classroom_id' => $resolvedClassroomId,
            'github_assignment_id' => $id,
            'source' => 'github',
        ];
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
                (string)($assignment['slug'] ?? ''),
                (string)($assignment['description'] ?? ''),
                (string)($assignment['state'] ?? ''),
            ]));
            return str_contains($haystack, $needle);
        }));
    }

    private static function classroomIdFromUrl(string $url): string
    {
        if (preg_match('#classroom\.github\.com/classrooms/(\d+)(?:/|$)#i', $url, $matches)) {
            return (string)$matches[1];
        }
        return '';
    }
}
