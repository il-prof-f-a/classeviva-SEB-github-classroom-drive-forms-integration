<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Aggrega le righe della snapshot corrente usando gli intervalli restituiti da
 * GitHub GraphQL blame. Non effettua chiamate HTTP e non salva dati.
 */
final class GitHubBlameLocAttributor
{
    private const KINDS = ['code', 'comment', 'blank'];

    /**
     * @param array<int,array<string,mixed>> $files
     * @param array{logins?:array<int,string>,emails?:array<int,string>} $identities
     * @param array{max_files?:int,max_lines?:int,max_bytes?:int} $limits
     * @return array<string,mixed>
     */
    public static function aggregate(array $files, array $identities, array $limits = []): array
    {
        $maxFiles = max(1, (int)($limits['max_files'] ?? 250));
        $maxLines = max(1, (int)($limits['max_lines'] ?? 100_000));
        $maxBytes = max(1, (int)($limits['max_bytes'] ?? 8_000_000));

        if (count($files) > $maxFiles) {
            return self::disabled('file_limit', ['files' => count($files), 'max_files' => $maxFiles]);
        }

        $lineCount = 0;
        $byteCount = 0;
        foreach ($files as $file) {
            $lineTypes = is_array($file['line_types'] ?? null) ? $file['line_types'] : [];
            $lineCount += count($lineTypes);
            $byteCount += max(0, (int)($file['bytes'] ?? 0));
        }
        if ($lineCount > $maxLines) {
            return self::disabled('line_limit', ['lines' => $lineCount, 'max_lines' => $maxLines]);
        }
        if ($byteCount > $maxBytes) {
            return self::disabled('byte_limit', ['bytes' => $byteCount, 'max_bytes' => $maxBytes]);
        }

        $totals = self::zeroMetrics(count($files));
        $student = self::zeroMetrics(0);
        $byLanguage = [];

        foreach ($files as $file) {
            $language = trim((string)($file['language'] ?? '')) ?: 'OTHER';
            $lineTypes = is_array($file['line_types'] ?? null) ? array_values($file['line_types']) : [];
            $fileTotals = self::zeroMetrics(1);
            $fileStudent = self::zeroMetrics(0);

            foreach ($lineTypes as $kind) {
                $kind = self::normaliseKind($kind);
                $fileTotals[$kind]++;
            }
            $fileTotals['total'] = $fileTotals['code'] + $fileTotals['comment'] + $fileTotals['blank'];
            $totals['total'] += $fileTotals['total'];
            $totals['code'] += $fileTotals['code'];
            $totals['comment'] += $fileTotals['comment'];
            $totals['blank'] += $fileTotals['blank'];

            foreach ((array)($file['ranges'] ?? []) as $range) {
                if (!is_array($range)) {
                    continue;
                }
                $commit = is_array($range['commit'] ?? null) ? $range['commit'] : [];
                if (GitHubContributionAttribution::commitOwner($commit, $identities) !== 'student') {
                    continue;
                }
                $start = max(1, (int)($range['starting_line'] ?? 0));
                $end = min(count($lineTypes), (int)($range['ending_line'] ?? 0));
                for ($line = $start; $line <= $end; $line++) {
                    $kind = self::normaliseKind($lineTypes[$line - 1] ?? 'code');
                    $fileStudent[$kind]++;
                }
            }
            $fileStudent['total'] = $fileStudent['code'] + $fileStudent['comment'] + $fileStudent['blank'];

            $student['total'] += $fileStudent['total'];
            $student['code'] += $fileStudent['code'];
            $student['comment'] += $fileStudent['comment'];
            $student['blank'] += $fileStudent['blank'];

            if (!isset($byLanguage[$language])) {
                $byLanguage[$language] = [
                    'total' => self::zeroMetrics(0),
                    'student' => self::zeroMetrics(0),
                ];
            }
            foreach (['total', 'code', 'comment', 'blank', 'files'] as $key) {
                $byLanguage[$language]['total'][$key] += $fileTotals[$key];
                $byLanguage[$language]['student'][$key] += $fileStudent[$key];
            }
        }

        ksort($byLanguage);
        return [
            'enabled' => true,
            'reason' => '',
            'limits' => ['max_files' => $maxFiles, 'max_lines' => $maxLines, 'max_bytes' => $maxBytes],
            'totals' => $totals,
            'student' => $student,
            'by_language' => $byLanguage,
        ];
    }

    /**
     * @param array<string,int> $details
     * @return array<string,mixed>
     */
    public static function disabled(string $reason, array $details = []): array
    {
        return [
            'enabled' => false,
            'reason' => trim($reason) !== '' ? trim($reason) : 'disabled',
            'details' => $details,
            'totals' => self::zeroMetrics(0),
            'student' => self::zeroMetrics(0),
            'by_language' => [],
        ];
    }

    /** @return list<string> */
    public static function lineTypesForText(string $path, string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $blockStart = null;
        $blockEnd = null;
        if (in_array($ext, ['php', 'js', 'ts', 'jsx', 'tsx', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'css', 'scss', 'sql'], true)) {
            $blockStart = '/*';
            $blockEnd = '*/';
        } elseif (in_array($ext, ['html', 'htm', 'xml'], true)) {
            $blockStart = '<!--';
            $blockEnd = '-->';
        } elseif ($ext === 'py') {
            $blockStart = '"""';
            $blockEnd = '"""';
        }

        $inBlock = false;
        $types = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $types[] = 'blank';
                continue;
            }

            $isComment = false;
            if ($blockStart !== null && $blockEnd !== null) {
                if ($inBlock) {
                    $isComment = true;
                    if (strpos($trim, $blockEnd) !== false) {
                        $inBlock = false;
                    }
                } elseif (strpos($trim, $blockStart) === 0) {
                    $isComment = true;
                    if (strpos($trim, $blockEnd) === false || $blockStart === $blockEnd) {
                        if ($blockStart === $blockEnd) {
                            $inBlock = substr_count($trim, $blockStart) === 1;
                        } else {
                            $inBlock = true;
                        }
                    }
                }
            }
            if (!$isComment) {
                if (in_array($ext, ['php', 'js', 'ts', 'jsx', 'tsx', 'java', 'c', 'cpp', 'h', 'hpp', 'cs'], true) && str_starts_with($trim, '//')) {
                    $isComment = true;
                } elseif (in_array($ext, ['php', 'py', 'sh', 'yml', 'yaml'], true) && str_starts_with($trim, '#')) {
                    $isComment = true;
                } elseif ($ext === 'sql' && str_starts_with($trim, '--')) {
                    $isComment = true;
                }
            }
            $types[] = $isComment ? 'comment' : 'code';
        }
        return $types;
    }

    public static function languageForPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'php' => 'PHP', 'js' => 'JavaScript', 'ts' => 'TypeScript',
            'jsx' => 'JavaScript', 'tsx' => 'TypeScript', 'py' => 'Python',
            'java' => 'Java', 'c' => 'C', 'h' => 'C/C++ Header',
            'cpp' => 'C++', 'hpp' => 'C++ Header', 'cs' => 'C#',
            'html' => 'HTML', 'htm' => 'HTML', 'css' => 'CSS',
            'scss' => 'SCSS', 'json' => 'JSON', 'xml' => 'XML',
            'yml' => 'YAML', 'yaml' => 'YAML', 'md' => 'Markdown',
            'sql' => 'SQL', 'sh' => 'Shell', 'bat' => 'Batch', 'ps1' => 'PowerShell',
        ];
        return $map[$ext] ?? strtoupper($ext !== '' ? $ext : 'OTHER');
    }

    /** @return array<string,int> */
    private static function zeroMetrics(int $files): array
    {
        return ['total' => 0, 'code' => 0, 'comment' => 0, 'blank' => 0, 'files' => $files];
    }

    private static function normaliseKind(mixed $kind): string
    {
        $kind = strtolower(trim((string)$kind));
        return in_array($kind, self::KINDS, true) ? $kind : 'code';
    }
}
