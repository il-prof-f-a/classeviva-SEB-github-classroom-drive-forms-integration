<?php

namespace App\Utils;

use InvalidArgumentException;

final class QuestionImportParser
{
    /** @return list<array<string,mixed>> */
    public static function parseJsonContent(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $data = self::decode($content);

        if ($data === null) {
            $utf8 = @mb_convert_encoding($content, 'UTF-8', 'UTF-8,ISO-8859-1,WINDOWS-1252');
            $data = self::decode($utf8);
        }

        if ($data === null) {
            $wrapped = '[' . preg_replace('/}\s*{/', '},{', $content) . ']';
            $objects = self::decode($wrapped);
            if (is_array($objects)) {
                $merged = [];
                foreach ($objects as $object) {
                    if (is_array($object) && isset($object['domande']) && is_array($object['domande'])) {
                        $merged = array_merge($merged, $object['domande']);
                    }
                }
                $data = ['domande' => $merged];
            }
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('JSON non valido: ' . json_last_error_msg());
        }

        $rows = $data['domande'] ?? [];
        if (!is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('Nessuna domanda trovata nel JSON');
        }

        $questions = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $question = trim((string)($row['domanda'] ?? ''));
            $expected = trim((string)($row['risposta_attesa'] ?? ''));
            $duplicateKey = $question . '|' . $expected;
            if ($question !== '' && isset($seen[$duplicateKey])) {
                continue;
            }
            if ($question !== '') {
                $seen[$duplicateKey] = true;
            }

            $keywords = $row['parole_chiave'] ?? '';
            if (is_array($keywords)) {
                $keywords = implode(',', array_map(static fn($item): string => trim((string)$item), $keywords));
            }

            $questions[] = [
                'argomento' => trim((string)($row['argomento'] ?? 'Generale')),
                'tipo' => trim((string)($row['tipo'] ?? 'aperta')) ?: 'aperta',
                'domanda' => $question,
                'risposta_attesa' => $expected,
                'parole_chiave' => (string)$keywords,
                'difficolta' => (int)($row['difficolta'] ?? 3),
                'tempo_risposta_min' => (int)($row['tempo_risposta_min'] ?? 3),
                'ordine_consigliato' => (int)($row['ordine_consigliato'] ?? ($index + 1)),
                'note' => trim((string)($row['note'] ?? '')),
                'risposte' => is_array($row['risposte'] ?? null) ? $row['risposte'] : [],
                'risposta_corretta' => $row['risposta_corretta'] ?? null,
                'risposte_accettate' => is_array($row['risposte_accettate'] ?? null) ? $row['risposte_accettate'] : [],
                'valore_corretto' => $row['valore_corretto'] ?? null,
                'tolleranza' => $row['tolleranza'] ?? null,
                'unita_misura' => $row['unita_misura'] ?? '',
                'data_creazione' => date('Y-m-d H:i:s'),
            ];
        }

        if ($questions === []) {
            throw new InvalidArgumentException('Nessuna domanda trovata nel JSON');
        }
        return $questions;
    }

    private static function decode(string $content): ?array
    {
        $decoded = json_decode($content, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }
}
