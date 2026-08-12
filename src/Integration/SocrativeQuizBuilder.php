<?php

namespace App\Integration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Helper per generare file Excel compatibili con il template
 * Socrative "Quick Quiz" a partire dalle domande UDA.
 *
 * Il template utilizzato è `templates/socrativeQuizTemplate.xlsx`.
 */
class SocrativeQuizBuilder
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Costruisce lo Spreadsheet Socrative partendo da un array di domande.
     *
     * Ogni elemento di $domande è un array con almeno:
     * - domanda
     * - risposta_attesa (opzionale, usata come spiegazione o per opzioni)
     * - parole_chiave (opzionale)
     * - tipo / tipo_domanda (opzionale: multipla, multipla_multi, vero_falso, breve, numerica, aperta)
     *
     * @param array  $domande  Domande dell'UDA
     * @param string $quizName Nome del quiz
     */
    public function buildSpreadsheet(array $domande, string $quizName): Spreadsheet
    {
        $templatePath = ROOT_PATH . '/templates/socrativeQuizTemplate.xlsx';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Template Socrative non trovato: ' . $templatePath);
        }

        /** @var Spreadsheet $spreadsheet */
        $spreadsheet = IOFactory::load($templatePath);

        $sheet = $spreadsheet->getSheetByName('Quick Quiz') ?: $spreadsheet->getActiveSheet();

        // Imposta il nome del quiz (riga 3, colonna B nel template standard)
        $sheet->setCellValueByColumnAndRow(2, 3, $this->truncate($quizName, 120));

        // Inserisce una riga per ogni domanda a partire dalla riga 7
        $row = 7;
        foreach ($domande as $d) {
            $questionText = trim($d['domanda'] ?? '');
            if ($questionText === '') {
                continue;
            }

            $tipo = strtolower($d['tipo'] ?? ($d['tipo_domanda'] ?? 'aperta'));
            $questionTypeLabel = $this->mapQuestionTypeToSocrative($tipo);

            // Colonna A: tipo domanda
            $sheet->setCellValueByColumnAndRow(1, $row, $questionTypeLabel);                     // 2. Question Type:
            // Colonna B: testo domanda
            $sheet->setCellValueByColumnAndRow(2, $row, $this->truncate($questionText, 255));    // 3. Question:

            // Colonne C-G: risposte (Answer A-E) e colonna H: lettere delle risposte corrette
            if ($tipo === 'multipla' || $tipo === 'multipla_multi') {
                [$answers, $correctLetters] = $this->buildOptionsForSocrative($d);

                if (!empty($answers)) {
                    // Colonne C-G (3-7): fino a 5 opzioni
                    for ($i = 0; $i < 5; $i++) {
                        $value = $answers[$i] ?? '';
                        $sheet->setCellValueByColumnAndRow(3 + $i, $row, $this->truncate($value, 75));
                    }

                    if (empty($correctLetters)) {
                        $correctLetters[] = 'A';
                    }

                    // Colonna H (8): lettere delle risposte corrette, separate da virgola
                    $sheet->setCellValueByColumnAndRow(8, $row, implode(',', $correctLetters));
                }
            }

            // Spiegazione opzionale (colonna 13 nel template: "6. Explanation (Optional):")
            $explanationParts = [];
            if (!empty($d['risposta_attesa'])) {
                $explanationParts[] = 'Risposta attesa: ' . $d['risposta_attesa'];
            }
            if (!empty($d['parole_chiave'])) {
                $explanationParts[] = 'Parole chiave: ' . $d['parole_chiave'];
            }
            if (!empty($explanationParts)) {
                $sheet->setCellValueByColumnAndRow(
                    13,
                    $row,
                    $this->truncate(implode(' | ', $explanationParts), 255)
                );
            }

            $row++;
        }

        return $spreadsheet;
    }

    private function mapQuestionTypeToSocrative(string $tipo): string
    {
        switch ($tipo) {
            case 'multipla':
            case 'multipla_multi':
                return 'Multiple choice';
            case 'vero_falso':
                return 'Open-ended';
            case 'breve':
            case 'numerica':
            case 'aperta':
            default:
                return 'Open-ended';
        }
    }

    /**
     * Estrae fino a 5 risposte e le lettere delle corrette (A-E) per Socrative.
     *
     * Usa:
     * - $domanda['risposte'] con chiavi 'testo' e 'corretta'
     * - oppure $domanda['risposta_attesa'] con sintassi "[CORRETTA] opzione"
     *
     * @param array $domanda
     * @return array{0: string[], 1: string[]} [answers, correctLetters]
     */
    private function buildOptionsForSocrative(array $domanda): array
    {
        $items = [];

        if (!empty($domanda['risposte']) && is_array($domanda['risposte'])) {
            foreach ($domanda['risposte'] as $r) {
                $text = trim($r['testo'] ?? '');
                if ($text === '') {
                    continue;
                }
                $items[] = [
                    'text' => $text,
                    'correct' => !empty($r['corretta']),
                ];
                if (count($items) >= 5) {
                    break;
                }
            }
        } else {
            // Fallback: parsare risposta_attesa tipo "[CORRETTA] opzione1; opzione2; ..."
            $parts = explode(';', $domanda['risposta_attesa'] ?? '');
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                $isCorr = false;
                if (stripos($p, '[CORRETTA]') === 0) {
                    $isCorr = true;
                    $p = trim(str_ireplace('[CORRETTA]', '', $p));
                }
                if ($p === '') {
                    continue;
                }
                $items[] = [
                    'text' => $p,
                    'correct' => $isCorr,
                ];
                if (count($items) >= 5) {
                    break;
                }
            }
        }

        if (empty($items)) {
            return [[], []];
        }

        // Se nessuna risposta è marcata corretta, imposta la prima come corretta
        $hasCorrect = false;
        foreach ($items as $item) {
            if (!empty($item['correct'])) {
                $hasCorrect = true;
                break;
            }
        }
        if (!$hasCorrect) {
            $items[0]['correct'] = true;
        }

        // Randomizza l'ordine delle risposte
        if (count($items) > 1) {
            shuffle($items);
        }

        $answers = [];
        $correctLetters = [];
        foreach ($items as $idx => $item) {
            if ($idx >= 5) {
                break;
            }
            $answers[] = $item['text'];
            if (!empty($item['correct'])) {
                $correctLetters[] = $this->indexToLetter($idx + 1);
            }
        }

        return [$answers, $correctLetters];
    }

    private function indexToLetter(int $index): string
    {
        $map = [
            1 => 'A',
            2 => 'B',
            3 => 'C',
            4 => 'D',
            5 => 'E',
        ];

        return $map[$index] ?? '';
    }

    private function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 3) {
            if (function_exists('mb_substr')) {
                return mb_substr($text, 0, $maxLength);
            }
            return substr($text, 0, $maxLength);
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $maxLength) {
                return $text;
            }
            return mb_substr($text, 0, $maxLength - 3) . '...';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength - 3) . '...';
    }
}
