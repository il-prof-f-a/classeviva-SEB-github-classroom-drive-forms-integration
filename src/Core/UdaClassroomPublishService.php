<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Costruisce il payload di pubblicazione su Google Classroom per una UDA in
 * modo provider-neutral e testabile in isolamento.
 *
 * Raccoglie la logica pura (nessuna I/O) usata da public/uda_publish.php:
 * titolo visualizzato dei test, descrizione con obiettivi didattici, filtraggio
 * dei materiali "Materiale classroom" e preparazione dell'URL docente dei Google
 * Form ("Importa voti").
 */
final class UdaClassroomPublishService
{
    /**
     * Titolo visualizzato di un test su Classroom: usa il nome reale, con
     * fallback sul tipo (prerequisiti/intermedio/finale).
     *
     * @param array<string,mixed> $test
     */
    public static function testTitle(array $test): string
    {
        $nome = trim((string)($test['nome'] ?? ''));
        if ($nome !== '') {
            return $nome;
        }

        $tipo = strtolower((string)($test['tipo_test'] ?? 'test'));
        if ($tipo === 'prerequisiti') {
            return 'Test Prerequisiti';
        }
        if ($tipo === 'intermedio') {
            return 'Test Intermedio';
        }
        if ($tipo === 'finale') {
            return 'Test Finale';
        }

        return 'Test ' . ucfirst($tipo);
    }

    /**
     * Costruisce la descrizione testuale del materiale Classroom: descrizione,
     * note e l'elenco completo degli obiettivi didattici e disciplinari
     * (raggruppati per tipo_obiettivo).
     *
     * @param array<int,array<string,mixed>> $obiettivi
     */
    public static function materialDescription(string $descrizione, string $note, array $obiettivi): string
    {
        $text = "Descrizione:\n" . $descrizione . "\n\n";

        if ($note !== '') {
            $text .= "Note:\n" . $note . "\n\n";
        }

        if ($obiettivi !== []) {
            $groups = [];
            foreach ($obiettivi as $ob) {
                $obDesc = trim((string)($ob['descrizione'] ?? ''));
                if ($obDesc === '') {
                    continue;
                }
                $line = '- ' . $obDesc;
                if (!empty($ob['livello_tassonomia'])) {
                    $line .= ' (' . $ob['livello_tassonomia'] . ')';
                }
                $key = strtolower(trim((string)($ob['tipo_obiettivo'] ?? '')));
                if ($key === '') {
                    $key = 'competenze';
                }
                $groups[$key][] = $line;
            }

            $labels = ['conoscenze' => 'Conoscenze', 'abilità' => 'Abilità', 'competenze' => 'Competenze'];
            $text .= "Obiettivi didattici e disciplinari:\n";
            foreach (['conoscenze', 'abilità', 'competenze'] as $key) {
                if (empty($groups[$key])) {
                    continue;
                }
                $text .= "\n" . $labels[$key] . ":\n" . implode("\n", $groups[$key]) . "\n";
            }
            foreach ($groups as $key => $items) {
                if (isset($labels[$key])) {
                    continue;
                }
                $text .= "\n" . ucfirst($key) . ":\n" . implode("\n", $items) . "\n";
            }
            $text .= "\n";
        }

        return $text;
    }

    /**
     * Filtra i materiali dell'UDA restituendo solo quelli collegabili a
     * Classroom ("Materiale classroom"): file Drive o link. I file locali
     * (solo file_path) vengono esclusi.
     *
     * @param array<int,array<string,mixed>> $materiali
     * @return array<int,array<string,mixed>>
     */
    public static function attachableMaterials(array $materiali): array
    {
        $attach = [];
        foreach ($materiali as $mat) {
            $fileIdDrive = trim((string)($mat['file_id_drive'] ?? ''));
            $urlDrive = trim((string)($mat['url_drive'] ?? ''));
            $url = trim((string)($mat['url'] ?? ''));

            if ($fileIdDrive !== '') {
                $attach[] = [
                    'type' => 'drive_file',
                    'drive_file_id' => $fileIdDrive,
                    'title' => $mat['nome'] ?? $mat['titolo'] ?? 'Documento',
                ];
            } elseif ($urlDrive !== '') {
                $attach[] = ['url' => $urlDrive];
            } elseif ($url !== '') {
                $attach[] = ['url' => $url];
            }
        }

        return $attach;
    }

    /**
     * Restituisce l'URL docente di un Google Form (modulo modificabile) per
     * preimpostare "Importa voti". Se url_docente è vuoto lo deriva da
     * url_studenti trasformando /viewform in /edit.
     *
     * @param array<string,mixed> $test
     */
    public static function googleFormDocenteUrl(array $test): string
    {
        $urlDocente = trim((string)($test['url_docente'] ?? ''));
        if ($urlDocente !== '') {
            return $urlDocente;
        }

        $urlStudenti = trim((string)($test['url_studenti'] ?? ''));
        if ($urlStudenti === '') {
            $urlStudenti = trim((string)($test['url'] ?? ''));
        }
        if ($urlStudenti === '') {
            return '';
        }

        $edited = preg_replace('#/viewform(\?.*)?$#', '/edit', $urlStudenti);
        return is_string($edited) ? $edited : $urlStudenti;
    }
}
