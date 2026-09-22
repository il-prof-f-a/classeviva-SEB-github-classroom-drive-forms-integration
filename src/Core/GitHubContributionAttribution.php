<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Classifica il contributo GitHub senza effettuare chiamate HTTP o scritture.
 *
 * L'identità del commit viene valutata prima tramite login GitHub e, solo
 * quando il login non è disponibile, tramite gli alias email già verificati
 * dal roster. Un dato mancante o ambiguo resta unknown e non viene attribuito.
 */
final class GitHubContributionAttribution
{
    /**
     * @return array{logins: array<int,string>, emails: array<int,string>}
     */
    public static function normalizeIdentities(array $logins, array $emails): array
    {
        return [
            'logins' => self::uniqueLowercase($logins),
            'emails' => self::uniqueLowercase($emails),
        ];
    }

    /**
     * @return 'student'|'other'|'unknown'
     */
    public static function commitOwner(array $commit, array $identities): string
    {
        $directOwner = self::directCommitOwner($commit, $identities);
        if ($directOwner === 'student') {
            return 'student';
        }

        // GitHub stores co-authors in the commit message instead of exposing
        // them as a second API author. A matching co-author therefore owns the
        // commit for this student's review too, even when the direct author is
        // another student.
        foreach (self::coauthorIdentities($commit) as $coauthor) {
            $login = self::normalise($coauthor['login'] ?? null);
            if ($login !== '' && self::matches($login, $identities['logins'] ?? [])) {
                return 'student';
            }
            $email = self::normaliseEmail($coauthor['email'] ?? null);
            if ($email !== '' && self::matches($email, $identities['emails'] ?? [])) {
                return 'student';
            }
        }

        return $directOwner;
    }

    /**
     * @return 'student'|'other'|'unknown'
     */
    private static function directCommitOwner(array $commit, array $identities): string
    {
        $authorLogin = self::normalise($commit['author_login'] ?? null);
        if ($authorLogin !== '') {
            return self::matches($authorLogin, $identities['logins'] ?? []) ? 'student' : 'other';
        }

        $committerLogin = self::normalise($commit['committer_login'] ?? null);
        if ($committerLogin !== '') {
            return self::matches($committerLogin, $identities['logins'] ?? []) ? 'student' : 'other';
        }

        $authorEmail = self::normaliseEmail($commit['author_email'] ?? null);
        if ($authorEmail !== '') {
            return self::matches($authorEmail, $identities['emails'] ?? []) ? 'student' : 'other';
        }

        $committerEmail = self::normaliseEmail($commit['committer_email'] ?? null);
        if ($committerEmail !== '') {
            return self::matches($committerEmail, $identities['emails'] ?? []) ? 'student' : 'other';
        }

        return 'unknown';
    }

    /**
     * Extracts Git's conventional ``Co-authored-by: Name <email>`` trailers.
     * The optional array form also supports normalized payloads received from
     * the metadata endpoint, while keeping the parser deterministic.
     *
     * @return array<int,array{name:string,email:string,login:string}>
     */
    public static function coauthorIdentities(array $commit): array
    {
        $result = [];
        $seen = [];
        $add = static function ($name, $email, $login = '') use (&$result, &$seen): void {
            $name = trim((string)$name);
            $email = strtolower(trim((string)$email));
            $login = strtolower(trim((string)$login));
            if ($email === '' && $login === '' && $name === '') {
                return;
            }
            $key = $email !== '' ? 'email:' . $email : 'name:' . strtolower($name);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $result[] = ['name' => $name, 'email' => $email, 'login' => $login];
        };

        foreach ((array)($commit['coauthors'] ?? []) as $coauthor) {
            if (is_array($coauthor)) {
                $add($coauthor['name'] ?? '', $coauthor['email'] ?? '', $coauthor['login'] ?? '');
            }
        }

        $message = (string)($commit['message'] ?? '');
        if ($message !== '' && preg_match_all(
            '/^\s*Co-authored-by:\s*(.*?)\s*<([^>\s]+)>\s*$/im',
            $message,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $name = trim((string)($match[1] ?? ''));
                $email = trim((string)($match[2] ?? ''));
                $login = preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $name) === 1 ? $name : '';
                $add($name, $email, $login);
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     commits: array<int,array<string,mixed>>,
     *     owned_shas: array<int,string>,
     *     student_additions: int,
     *     global: array<string,int>,
     *     partial: bool,
     *     warnings: array<int,string>
     * }
     */
    public static function attributeCommits(
        array $commits,
        array $identities,
        array $globalLoc,
        bool $partial = false
    ): array {
        $ownedShas = [];
        $studentAdditions = 0;
        $rows = [];

        foreach ($commits as $commit) {
            if (!is_array($commit)) {
                continue;
            }

            $owner = self::commitOwner($commit, $identities);
            $row = $commit;
            $row['student_owned'] = $owner === 'student';
            $row['attribution_state'] = $owner;
            $sha = trim((string)($commit['sha'] ?? ''));
            if ($owner === 'student') {
                if ($sha !== '') {
                    $ownedShas[] = $sha;
                }
                $studentAdditions += max(0, (int)($commit['additions'] ?? 0));
            }
            $rows[] = $row;
        }

        $warnings = [];
        if ($partial) {
            $warnings[] = 'Attribuzione parziale: la cronologia GitHub è stata troncata o alcuni dettagli non sono disponibili.';
        }

        return [
            'commits' => $rows,
            'owned_shas' => array_values(array_unique($ownedShas)),
            'student_additions' => $studentAdditions,
            'global' => [
                'total' => max(0, (int)($globalLoc['total'] ?? 0)),
                'blank' => max(0, (int)($globalLoc['blank'] ?? 0)),
                'comment' => max(0, (int)($globalLoc['comment'] ?? 0)),
                'code' => max(0, (int)($globalLoc['code'] ?? 0)),
                'files' => max(0, (int)($globalLoc['files'] ?? 0)),
            ],
            'partial' => $partial,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function attributeBranches(array $branches, array $identities): array
    {
        return self::attributeRows($branches, $identities, [
            'first_unique_commit_author_login',
            'author_login',
        ], [
            'first_unique_commit_author_email',
            'author_email',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function attributeIssues(array $issues, array $identities): array
    {
        return self::attributeRows($issues, $identities, ['author_login'], ['author_email']);
    }

    private static function attributeRows(array $rows, array $identities, array $loginKeys, array $emailKeys): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $owner = self::rowOwner($row, $identities, $loginKeys, $emailKeys);
            $row['student_owned'] = $owner === 'student';
            $row['attribution_state'] = $owner;
            $result[] = $row;
        }
        return $result;
    }

    private static function rowOwner(array $row, array $identities, array $loginKeys, array $emailKeys): string
    {
        foreach ($loginKeys as $key) {
            $login = self::normalise($row[$key] ?? null);
            if ($login !== '') {
                return self::matches($login, $identities['logins'] ?? []) ? 'student' : 'other';
            }
        }
        foreach ($emailKeys as $key) {
            $email = self::normaliseEmail($row[$key] ?? null);
            if ($email !== '') {
                return self::matches($email, $identities['emails'] ?? []) ? 'student' : 'other';
            }
        }
        return 'unknown';
    }

    private static function uniqueLowercase(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $normalised = self::normalise($value);
            if ($normalised !== '' && !in_array($normalised, $result, true)) {
                $result[] = $normalised;
            }
        }
        return $result;
    }

    private static function matches(string $value, array $candidates): bool
    {
        return in_array(strtolower($value), array_map(static fn ($candidate): string => strtolower((string)$candidate), $candidates), true);
    }

    private static function normalise($value): string
    {
        return strtolower(trim((string)$value));
    }

    private static function normaliseEmail($value): string
    {
        return strtolower(trim((string)$value));
    }
}
