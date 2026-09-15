<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Trasforma i payload read-only di GitHub in dati sicuri e prevedibili per la
 * Review degli assignment. Non esegue chiamate HTTP e non persiste nulla.
 */
final class GitHubReviewMetadata
{
    /**
     * Estrae riferimenti del tipo #12 o owner/repository#12 da un messaggio.
     * L'ordine è quello della prima occorrenza e i riferimenti sono deduplicati.
     */
    public static function extractIssueReferences(string $text, string $defaultOwner, string $defaultRepo): array
    {
        $defaultOwner = self::normaliseSlugPart($defaultOwner);
        $defaultRepo = self::normaliseSlugPart($defaultRepo);
        if ($defaultOwner === '' || $defaultRepo === '') {
            return [];
        }

        preg_match_all(
            '~(?:(?<qualified>[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}))?#(?<number>[1-9][0-9]{0,8})\\b~',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        $references = [];
        $seen = [];
        foreach ($matches as $match) {
            $qualified = trim((string)($match['qualified'] ?? ''));
            $owner = $defaultOwner;
            $repo = $defaultRepo;
            if ($qualified !== '') {
                [$qualifiedOwner, $qualifiedRepo] = array_pad(explode('/', $qualified, 2), 2, '');
                $owner = self::normaliseSlugPart($qualifiedOwner);
                $repo = self::normaliseSlugPart($qualifiedRepo);
            }
            $number = (int)($match['number'] ?? 0);
            if ($owner === '' || $repo === '' || $number < 1) {
                continue;
            }
            $key = strtolower($owner . '/' . $repo . '#' . $number);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $references[] = [
                'owner' => $owner,
                'repo' => $repo,
                'number' => $number,
                'label' => '#' . $number,
                'url' => self::issueUrl($owner, $repo, $number),
                'icon' => 'bi bi-exclamation-circle',
            ];
        }

        return $references;
    }

    /**
     * Indicizza i tag per SHA di commit, mantenendo ordine e nomi distinti.
     */
    public static function mapTagsBySha(array $tags): array
    {
        $mapped = [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $sha = self::normaliseSha($tag['commit']['sha'] ?? null);
            $name = trim((string)($tag['name'] ?? ''));
            if ($sha === '' || $name === '') {
                continue;
            }
            $mapped[$sha] ??= [];
            if (!in_array($name, $mapped[$sha], true)) {
                $mapped[$sha][] = $name;
            }
        }
        return $mapped;
    }

    /**
     * Normalizza issue e commit collegati dalla timeline. Le pull request sono
     * escluse perché GitHub le espone anche dall'endpoint Issues.
     *
     * @param array<int,mixed> $issues
     * @param array<int|string,array<int,mixed>> $timelineByNumber
     */
    public static function normalizeIssues(
        array $issues,
        array $timelineByNumber,
        string $owner,
        string $repo
    ): array {
        $owner = self::normaliseSlugPart($owner);
        $repo = self::normaliseSlugPart($repo);
        if ($owner === '' || $repo === '') {
            return [];
        }

        $normalised = [];
        $seenIssues = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || isset($issue['pull_request'])) {
                continue;
            }
            $number = (int)($issue['number'] ?? 0);
            if ($number < 1) {
                continue;
            }
            if (isset($seenIssues[$number])) {
                continue;
            }
            $seenIssues[$number] = true;
            $timeline = $timelineByNumber[$number] ?? $timelineByNumber[(string)$number] ?? [];
            $commitMap = [];
            foreach (is_array($timeline) ? $timeline : [] as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $sha = self::normaliseSha($event['commit_id'] ?? null);
                if ($sha === '') {
                    continue;
                }
                $commitMap[$sha] = [
                    'sha' => $sha,
                    'url' => self::commitUrl($owner, $repo, $sha),
                ];
            }

            $normalised[] = [
                'number' => $number,
                'title' => trim((string)($issue['title'] ?? '')),
                'author_login' => trim((string)($issue['user']['login'] ?? '')) ?: null,
                'author_email' => trim((string)($issue['user']['email'] ?? '')) ?: null,
                'state' => strtolower(trim((string)($issue['state'] ?? ''))) === 'closed' ? 'closed' : 'open',
                'created_at' => trim((string)($issue['created_at'] ?? '')),
                'closed_at' => trim((string)($issue['closed_at'] ?? '')) ?: null,
                'body' => (string)($issue['body'] ?? ''),
                'url' => self::issueUrl($owner, $repo, $number),
                'commits' => array_values($commitMap),
            ];
        }

        return $normalised;
    }

    /**
     * Determina quale informazione di branch può essere sostenuta dall'API.
     */
    public static function resolveBranchOrigin(array $pullRequests, array $headBranches): array
    {
        $pullRequestBranches = [];
        foreach ($pullRequests as $pullRequest) {
            if (!is_array($pullRequest)) {
                continue;
            }
            $branch = trim((string)($pullRequest['head']['ref'] ?? ''));
            if ($branch !== '' && !in_array($branch, $pullRequestBranches, true)) {
                $pullRequestBranches[] = $branch;
            }
        }
        if ($pullRequestBranches !== []) {
            return [
                'label' => implode(', ', $pullRequestBranches),
                'source' => 'pull_request',
                'branches' => $pullRequestBranches,
            ];
        }

        $branches = [];
        foreach ($headBranches as $headBranch) {
            $branch = is_array($headBranch) ? trim((string)($headBranch['name'] ?? '')) : trim((string)$headBranch);
            if ($branch !== '' && !in_array($branch, $branches, true)) {
                $branches[] = $branch;
            }
        }
        if ($branches !== []) {
            return [
                'label' => implode(', ', $branches),
                'source' => 'head_branches',
                'branches' => $branches,
            ];
        }

        return [
            'label' => 'Origine non determinabile',
            'source' => 'unknown',
            'branches' => [],
        ];
    }

    /**
     * Normalizza i branch restituiti dall'endpoint /branches.
     */
    public static function normalizeBranches(array $branches): array
    {
        $normalised = [];
        $seen = [];
        foreach ($branches as $branch) {
            $name = is_array($branch) ? trim((string)($branch['name'] ?? '')) : trim((string)$branch);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $normalised[] = $name;
        }
        return $normalised;
    }

    public static function issueUrl(string $owner, string $repo, int $number): string
    {
        return 'https://github.com/' . self::normaliseSlugPart($owner) . '/' . self::normaliseSlugPart($repo) . '/issues/' . max(1, $number);
    }

    public static function commitUrl(string $owner, string $repo, string $sha): string
    {
        return 'https://github.com/' . self::normaliseSlugPart($owner) . '/' . self::normaliseSlugPart($repo) . '/commit/' . self::normaliseSha($sha);
    }

    private static function normaliseSlugPart($value): string
    {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $value) === 1 ? $value : '';
    }

    private static function normaliseSha($value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[0-9a-f]{7,64}$/', $value) === 1 ? $value : '';
    }
}
