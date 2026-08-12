<?php

namespace App\Integration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Helper per generare file Excel compatibili con il template
 * Kahoot a partire dalle domande UDA.
 *
 * Il template utilizzato è `templates/KahootQuizTemplate.xlsx`.
 */
class KahootQuizBuilder
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Costruisce lo Spreadsheet Kahoot partendo da un array di domande.
     *
     * Ogni elemento di $domande è un array con almeno:
     * - domanda
     * - risposta_attesa (per vero/falso o fallback)
     * - tipo / tipo_domanda (opzionale: multipla, multipla_multi, vero_falso, breve, numerica, aperta)
     *
     * @param array  $domande  Domande dell'UDA
     * @param string $quizName Nome del quiz (non usato direttamente dal template standard, ma tenuto per futuro)
     */
    public function buildSpreadsheet(array $domande, string $quizName): Spreadsheet
    {
        $templatePath = ROOT_PATH . '/templates/KahootQuizTemplate.xlsx';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('Template Kahoot non trovato: ' . $templatePath);
        }

        /** @var Spreadsheet $spreadsheet */
        $spreadsheet = IOFactory::load($templatePath);

        $sheet = $spreadsheet->getActiveSheet();

        // Il template Kahoot ha l'intestazione alla riga 8 e il primo esempio alla riga 9.
        // Sovrascriviamo dalla riga 9 in avanti.
        $row = 9;
        $index = 1;

        foreach ($domande as $d) {
            $questionText = trim($d['domanda'] ?? '');
            if ($questionText === '') {
                continue;
            }

            $tipo = strtolower($d['tipo'] ?? ($d['tipo_domanda'] ?? 'aperta'));

            // Estrae opzioni e indici corretti
            [$options, $correctIndexes] = $this->buildOptionsForKahoot($d, $tipo);
            if (empty($options)) {
                // Se non abbiamo nemmeno un'opzione valida, saltiamo
                continue;
            }

            // Numerazione domanda
            $sheet->setCellValueByColumnAndRow(1, $row, $index);
            $sheet->setCellValueByColumnAndRow(2, $row, $this->truncate($questionText, 120));

            // Risposte (max 4, colonne C-F)
            for ($i = 0; $i < 4; $i++) {
                $answerText = $options[$i] ?? '';
                $sheet->setCellValueByColumnAndRow(3 + $i, $row, $this->truncate($answerText, 75));
            }

            // Time limit (colonna G) - impostiamo un default di 60 secondi
            $sheet->setCellValueByColumnAndRow(7, $row, 60);

            // Indici risposte corrette (colonna H) - "1", "2", "1,3", ...
            if (empty($correctIndexes)) {
                $correctIndexes = [1];
            }
            $sheet->setCellValueByColumnAndRow(8, $row, implode(',', $correctIndexes));

            $row++;
            $index++;
        }

        return $spreadsheet;
    }

    /**
     * Costruisce le opzioni per Kahoot in base al tipo di domanda.
     *
     * @return array{0: string[], 1: int[]} [options, correctIndexes]
     */
    private function buildOptionsForKahoot(array $domanda, string $tipo): array
    {
        $tipo = strtolower($tipo);

        // Domande a scelta multipla
        if ($tipo === 'multipla' || $tipo === 'multipla_multi') {
            [$options, $correct] = $this->extractMultipleChoiceOptions($domanda, 4);
            return [$options, $correct];
        }

        // Vero/Falso
        if ($tipo === 'vero_falso') {
            $options = ['VERO', 'FALSO'];
            $attesa = strtoupper(trim($domanda['risposta_attesa'] ?? 'VERO'));
            $correctIndex = ($attesa === 'FALSO') ? 2 : 1;
            return [$options, [$correctIndex]];
        }

        // Altri tipi: generiamo opzioni placeholder, docente le potrà modificare dopo l'import in Kahoot.
        $options = ['Opzione 1', 'Opzione 2', 'Opzione 3', 'Opzione 4'];
        return [$options, [1]];
    }

    /**
     * Estrae opzioni e indici corretti dalle domande multipla.
     *
     * @param array $domanda
     * @param int   $maxOptions Numero massimo di opzioni (Kahoot: 4)
     * @return array{0: string[], 1: int[]} [options, correctIndexes]
     */
    private function extractMultipleChoiceOptions(array $domanda, int $maxOptions): array
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
                if (count($items) >= $maxOptions) {
                    break;
                }
            }
        } else {
            // fallback da risposta_attesa con [CORRETTA]
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
                if (count($items) >= $maxOptions) {
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
        // Randomizza l'ordine
        if (count($items) > 1) {
            shuffle($items);
        }
        $options = [];
        $correctIndexes = [];
        foreach ($items as $idx => $item) {
            if ($idx >= $maxOptions) {
                break;
            }
            $options[] = $item['text'];
            if (!empty($item['correct'])) {
                $correctIndexes[] = $idx + 1;
            }
        }
        if (empty($correctIndexes) && !empty($options)) {
            $correctIndexes[] = 1;
        }
        return [$options, $correctIndexes];
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
