<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class GoogleFormScoreNormalizer
{
    /**
     * @param iterable<object> $items
     * @param array<int, string> $confidenceQuestionIds
     * @return array<string, float>
     */
    public static function extractQuestionWeights(iterable $items, array $confidenceQuestionIds): array
    {
        $confidenceLookup = array_fill_keys(array_map('strval', $confidenceQuestionIds), true);
        $weights = [];

        foreach ($items as $item) {
            $questionItem = is_object($item) && method_exists($item, 'getQuestionItem')
                ? $item->getQuestionItem()
                : null;
            $question = is_object($questionItem) && method_exists($questionItem, 'getQuestion')
                ? $questionItem->getQuestion()
                : null;
            if (!is_object($question) || !method_exists($question, 'getQuestionId')) {
                continue;
            }

            $questionId = trim((string)$question->getQuestionId());
            $itemId = method_exists($item, 'getItemId') ? trim((string)$item->getItemId()) : '';
            if (
                $questionId === ''
                || isset($confidenceLookup[$questionId])
                || ($itemId !== '' && isset($confidenceLookup[$itemId]))
            ) {
                continue;
            }

            $grading = method_exists($question, 'getGrading') ? $question->getGrading() : null;
            if (!is_object($grading) || !method_exists($grading, 'getPointValue')) {
                continue;
            }

            $pointValue = (float)$grading->getPointValue();
            if ($pointValue <= 0.0) {
                throw new RuntimeException("La domanda {$questionId} non ha un punteggio positivo configurato.");
            }

            $weights[$questionId] = $pointValue;
        }

        self::totalPoints($weights);

        return $weights;
    }

    /** @param array<string, float|int|string> $weights */
    public static function totalPoints(array $weights): float
    {
        $total = array_sum(array_map('floatval', $weights));
        if ($weights === [] || $total <= 0.0) {
            throw new RuntimeException('Il Google Form non contiene domande valutate con punti positivi.');
        }

        return $total;
    }

    /**
     * @param array<int, array<string, mixed>> $mappingRows
     * @return array<string, float>
     */
    public static function extractPersistedWeights(array $mappingRows): array
    {
        $weights = [];

        foreach ($mappingRows as $mappingRow) {
            $questionId = trim((string)($mappingRow['form_item_id'] ?? ''));
            if ($questionId === '') {
                $questionId = trim((string)($mappingRow['id_domanda'] ?? ''));
            }
            if ($questionId === '') {
                continue;
            }

            $weight = (float)($mappingRow['max_score'] ?? 0);
            if ($weight <= 0.0) {
                $weight = (float)($mappingRow['punteggio_domanda'] ?? 0);
            }
            if ($weight > 0.0) {
                $weights[$questionId] = $weight;
            }
        }

        return $weights;
    }

    /**
     * @param array<string, float|int|string> $weights
     * @return array{form_max: float, classic_percent: float, cbm_percent: float}
     */
    public static function normalize(float $classicTotal, float $cbmTotal, array $weights): array
    {
        $formMax = self::totalPoints($weights);

        return [
            'form_max' => $formMax,
            'classic_percent' => ($classicTotal / $formMax) * 100.0,
            'cbm_percent' => ($cbmTotal / $formMax) * 100.0,
        ];
    }
}
