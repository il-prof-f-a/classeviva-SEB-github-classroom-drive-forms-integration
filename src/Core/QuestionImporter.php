<?php

namespace App\Core;

use App\Core\Security\UploadPolicy;

/**
 * Import/preview helper per domande da file (JSON principalmente).
 * Riutilizzato da import_questions.php e test_wizard.php.
 */
class QuestionImporter
{
    /**
     * Parse JSON file and return normalized questions.
     */
    public static function parseJsonFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'File non trovato'];
        }

        $jsonContent = file_get_contents($path);
        // Rimuovi BOM
        $jsonContent = preg_replace('/^\xEF\xBB\xBF/', '', $jsonContent);

        $data = json_decode($jsonContent, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Riprova forzando UTF-8
            $jsonContentUtf8 = @mb_convert_encoding($jsonContent, 'UTF-8', 'UTF-8,ISO-8859-1,WINDOWS-1252');
            $data = json_decode($jsonContentUtf8, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Prova oggetti concatenati
                $wrapped = '[' . preg_replace('/}\\s*{/', '},{', $jsonContent) . ']';
                $data = json_decode($wrapped, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                    return ['success' => false, 'error' => 'JSON non valido: ' . json_last_error_msg()];
                }

                // Merge domande da più oggetti
                $mergedDomande = [];
                foreach ($data as $obj) {
                    if (isset($obj['domande']) && is_array($obj['domande'])) {
                        $mergedDomande = array_merge($mergedDomande, $obj['domande']);
                    }
                }
                $data = ['domande' => $mergedDomande];
            }
        }

        $domande = $data['domande'] ?? [];
        if (empty($domande)) {
            return ['success' => false, 'error' => 'Nessuna domanda trovata nel file'];
        }

        $questions = [];
        $seen = [];

        foreach ($domande as $index => $d) {
            $domandaTxt = trim($d['domanda'] ?? '');
            if ($domandaTxt === '') {
                continue;
            }

            $rispostaAttesa = self::convertRispostaAttesa($d);
            $dupKey = $domandaTxt . '|' . $rispostaAttesa;
            if (isset($seen[$dupKey])) {
                // skip duplicati identici
                continue;
            }
            $seen[$dupKey] = true;

            $questions[] = [
                'argomento' => trim($d['argomento'] ?? 'Generale'),
                'tipo' => $d['tipo'] ?? ($d['tipo_domanda'] ?? 'aperta'),
                'domanda' => $domandaTxt,
                'risposta_attesa' => $rispostaAttesa,
                'parole_chiave' => self::convertParoleChiave($d['parole_chiave'] ?? ''),
                'difficolta' => intval($d['difficolta'] ?? 3),
                'tempo_risposta_min' => intval($d['tempo_risposta_min'] ?? 3),
                'ordine_consigliato' => intval($d['ordine_consigliato'] ?? ($index + 1)),
                'note' => trim($d['note'] ?? ($d['spiegazione'] ?? '')),
                'collegata_a' => $d['collegata_a'] ?? ''
            ];
        }

        if (empty($questions)) {
            return ['success' => false, 'error' => 'Nessuna domanda valida trovata'];
        }

        return ['success' => true, 'questions' => $questions];
    }

    /**
     * Parse CSV file (headers like argomento,tipo,domanda,risposta_attesa,...)
     */
    public static function parseCsvFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'File non trovato'];
        }
        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Impossibile leggere il file CSV'];
        }

        $headers = fgetcsv($handle);
        if (!$headers || !in_array('domanda', $headers)) {
            fclose($handle);
            return ['success' => false, 'error' => 'CSV non valido: manca colonna "domanda"'];
        }

        $questions = [];
        $lineNumber = 1;
        $seen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            $data = array_combine($headers, $row);
            if (empty($data['domanda'])) {
                continue;
            }
            $domandaTxt = trim($data['domanda']);
            $rispostaTxt = trim($data['risposta_attesa'] ?? '');
            $dupKey = $domandaTxt . '|' . $rispostaTxt;
            if (isset($seen[$dupKey])) {
                continue;
            }
            $seen[$dupKey] = true;

            $questions[] = [
                'argomento' => trim($data['argomento'] ?? 'Generale'),
                'tipo' => $data['tipo'] ?? 'aperta',
                'domanda' => $domandaTxt,
                'risposta_attesa' => $rispostaTxt,
                'parole_chiave' => $data['parole_chiave'] ?? '',
                'difficolta' => intval($data['difficolta'] ?? 3),
                'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
                'ordine_consigliato' => intval($data['ordine_consigliato'] ?? $lineNumber),
                'note' => $data['note'] ?? '',
                'collegata_a' => $data['collegata_a'] ?? ''
            ];
        }
        fclose($handle);

        if (empty($questions)) {
            return ['success' => false, 'error' => 'Nessuna domanda valida trovata'];
        }
        return ['success' => true, 'questions' => $questions];
    }

    /**
     * Parse Excel file using PhpSpreadsheet.
     */
    public static function parseExcelFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'File non trovato'];
        }
        try {
            UploadPolicy::assertValid(basename($path), $path, 'spreadsheet');
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            if (empty($rows)) {
                return ['success' => false, 'error' => 'Excel vuoto'];
            }
            $headers = $rows[0];
            $questions = [];
            $seen = [];
            for ($i = 1; $i < count($rows); $i++) {
                $data = array_combine($headers, $rows[$i]);
                if (empty($data['domanda'])) {
                    continue;
                }
                $domandaTxt = trim($data['domanda']);
                $rispostaTxt = self::convertRispostaExcel($data);
                $dupKey = $domandaTxt . '|' . $rispostaTxt;
                if (isset($seen[$dupKey])) {
                    continue;
                }
                $seen[$dupKey] = true;
                $questions[] = [
                    'argomento' => $data['argomento'] ?? 'Generale',
                    'tipo' => $data['tipo'] ?? 'aperta',
                    'domanda' => $domandaTxt,
                    'risposta_attesa' => $rispostaTxt,
                    'parole_chiave' => $data['parole_chiave'] ?? '',
                    'difficolta' => intval($data['difficolta'] ?? 3),
                    'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
                    'ordine_consigliato' => intval($data['ordine_consigliato'] ?? ($i + 1)),
                    'note' => $data['note'] ?? '',
                    'collegata_a' => $data['collegata_a'] ?? ''
                ];
            }
            if (empty($questions)) {
                return ['success' => false, 'error' => 'Nessuna domanda valida trovata'];
            }
            return ['success' => true, 'questions' => $questions];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Errore lettura Excel: ' . $e->getMessage()];
        }
    }

    /**
     * Convertitore risposta per i tipi supportati.
     */
    public static function convertRispostaAttesa(array $domanda): string
    {
        $tipo = $domanda['tipo'] ?? ($domanda['tipo_domanda'] ?? 'aperta');

        switch ($tipo) {
            case 'multipla':
            case 'multipla_multi':
                $risposte = $domanda['risposte'] ?? [];
                $text = [];
                foreach ($risposte as $r) {
                    if ($r['corretta'] ?? false) {
                        $text[] = '[CORRETTA] ' . $r['testo'];
                    } else {
                        $text[] = $r['testo'];
                    }
                }
                return implode('; ', $text);

            case 'vero_falso':
                return ($domanda['risposta_corretta'] ?? false) ? 'VERO' : 'FALSO';

            case 'breve':
                $accettate = $domanda['risposte_accettate'] ?? [];
                return 'Risposte accettate: ' . implode(', ', $accettate);

            case 'numerica':
                $valore = $domanda['valore_corretto'] ?? 0;
                $tolleranza = $domanda['tolleranza'] ?? 0;
                $unita = $domanda['unita_misura'] ?? '';
                return "{$valore} +/- {$tolleranza} {$unita}";

            default:
                return $domanda['risposta_attesa'] ?? '';
        }
    }

    /**
     * Converte risposta da Excel con campi opzione_a... risposta_corretta.
     */
    public static function convertRispostaExcel(array $data): string
    {
        $tipo = $data['tipo'] ?? 'aperta';
        if ($tipo === 'multipla') {
            $opzioni = [];
            $corretta = strtoupper($data['risposta_corretta'] ?? 'A');
            foreach (['A' => 'opzione_a', 'B' => 'opzione_b', 'C' => 'opzione_c', 'D' => 'opzione_d', 'E' => 'opzione_e'] as $letter => $key) {
                if (!empty($data[$key])) {
                    $prefix = ($letter === $corretta) ? '[CORRETTA] ' : '';
                    $opzioni[] = $prefix . $data[$key];
                }
            }
            return implode('; ', $opzioni);
        }
        return $data['risposta_attesa'] ?? '';
    }

    /**
     * Converte array parole chiave in CSV.
     */
    public static function convertParoleChiave($parole): string
    {
        if (is_array($parole)) {
            return implode(',', $parole);
        }
        return $parole ?? '';
    }
}
