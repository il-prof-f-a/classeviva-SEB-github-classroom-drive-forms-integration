<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Logica pura per gli assignment GitHub provider-neutral.
 *
 * - Naming deterministico e testabile (prefisso repo, slug, codice accettazione).
 * - Risoluzione degli studenti (id_studente -> email) a partire da matrix del
 *   gruppo + roster dei provider, SENZA persistere email (pseudo-anonimizzazione).
 */
final class GitHubAssignmentService
{
    public function __construct(
        private string $emailTemplate,
        private string $emailDomain
    ) {
    }

    /** Prefisso comune delle repo di un assignment (slug del nome, troncato). */
    public static function repoPrefix(string $name): string
    {
        return substr(self::slugify($name), 0, 50);
    }

    /** Slug univoco dell'assignment (usato nel link generico di classe). */
    public static function assignmentSlug(string $name): string
    {
        return self::repoPrefix($name) . '-' . bin2hex(random_bytes(4));
    }

    /** Codice di accettazione personale (link per studente). */
    public static function generateAcceptanceCode(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Nome repo per studente: {prefisso}-stud-{6 cifre} univoco nel batch. */
    public static function repoName(string $name, array &$usedCodes): string
    {
        $base = self::repoPrefix($name);
        do {
            $code = (string) random_int(100000, 999999);
        } while (isset($usedCodes[$code]));
        $usedCodes[$code] = true;
        return $base . '-stud-' . $code;
    }

    public static function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = self::transliterate($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s === null ? '' : $s, '-');
    }

    private static function transliterate(string $v): string
    {
        $map = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
            'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i',
            'î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o',
            'ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y',
            'ÿ'=>'y','ß'=>'ss','œ'=>'oe',
        ];
        return strtr($v, $map);
    }

    /**
     * @param list<array<string,mixed>> $matrix            output di TeachingGroupStudentService::matrix()
     * @param array<string,list<array<string,mixed>>> $rosterByProvider es. ['google_classroom'=>[{id,name,email}], 'classeviva'=>[{id,nome,cognome}]]
     * @param string $listaPreferita provider da provare per primo: 'classeviva' (default) oppure 'google_classroom'
     * @return list<array{id_studente:string,email:string,nome:string}>
     */
    public function resolveStudents(array $matrix, array $rosterByProvider, string $listaPreferita = 'classeviva'): array
    {
        $result = [];
        foreach ($matrix as $row) {
            $idStudente = trim((string)($row['id_studente'] ?? ''));
            if ($idStudente === '') {
                continue;
            }
            $email = '';
            $nome = '';
            // Ordine di priorità: prima $listaPreferita, poi gli altri provider.
            // Di default parte da ClasseViva; per usare l'email Google reale passa 'google_classroom'.
            $order = ['classeviva', 'google_classroom'];
            if ($listaPreferita !== '' && in_array($listaPreferita, $order, true)) {
                $order = array_values(array_unique(array_merge([$listaPreferita], $order)));
            }
            foreach ($order as $preferredProvider) {
                foreach (($row['identities'] ?? []) as $identity) {
                    if ((string)($identity['provider'] ?? '') !== $preferredProvider) {
                        continue;
                    }
                    $externalId = trim((string)($identity['external_user_id'] ?? ''));
                    if ($externalId === '') {
                        continue;
                    }
                    $found = false;
                    foreach ($rosterByProvider[$preferredProvider] ?? [] as $entry) {
                        if ((string)($entry['id'] ?? '') !== $externalId) {
                            continue;
                        }
                        if ($preferredProvider === 'google_classroom') {
                            $email = trim((string)($entry['email'] ?? ''));
                            $nome = trim((string)($entry['name'] ?? ''));
                        } else {
                            $email = StudentEmailResolver::generate(
                                $this->emailTemplate,
                                $this->emailDomain,
                                (string)($entry['nome'] ?? ''),
                                (string)($entry['cognome'] ?? '')
                            );
                            $nome = trim(trim((string)($entry['cognome'] ?? '') . ' ' . (string)($entry['nome'] ?? '')));
                        }
                        if ($email !== '') {
                            $found = true;
                            break;
                        }
                    }
                    if ($found) {
                        break;
                    }
                }
                if ($email !== '') {
                    break;
                }
            }
            $result[] = ['id_studente' => $idStudente, 'email' => $email, 'nome' => $nome];
        }
        return $result;
    }
}
