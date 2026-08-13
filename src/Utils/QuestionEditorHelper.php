<?php

declare(strict_types=1);

namespace App\Utils;

final class QuestionEditorHelper
{
    /** @return list<string> */
    public static function normalizeKeywords(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[,;]+/', (string)$value);
        $result = [];
        foreach ($values ?: [] as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($item, 'UTF-8') : strtolower($item);
            if (!isset($result[$key])) {
                $result[$key] = $item;
            }
        }
        return array_values($result);
    }

    /** @return list<array{testo:string,corretta:bool}> */
    public static function parseOptions(mixed $value): array
    {
        if (is_array($value)) {
            $raw = $value;
        } else {
            $text = trim((string)$value);
            if ($text === '') {
                return [];
            }
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $raw = $decoded['risposte'] ?? $decoded;
            } else {
                $parts = preg_split('/\s*\|\s*|\s*;\s*/', $text) ?: [];
                $raw = array_map(static function (string $part): array {
                    $correct = str_ends_with(trim($part), '*');
                    return ['testo' => trim(rtrim($part, '*')), 'corretta' => $correct];
                }, $parts);
            }
        }

        $options = [];
        foreach ($raw as $option) {
            if (is_string($option)) {
                $text = trim($option);
                $correct = false;
            } elseif (is_array($option)) {
                $text = trim((string)($option['testo'] ?? $option['text'] ?? ''));
                $correct = !empty($option['corretta']) || !empty($option['correct']);
            } else {
                continue;
            }
            if ($text !== '') {
                $options[] = ['testo' => $text, 'corretta' => $correct];
            }
        }
        return $options;
    }

    /** @return array{argomento:string,domanda:string,difficolta:int,tipo_domanda:string,risposta_attesa:string,parole_chiave:string,opzioni:list<array{testo:string,corretta:bool}>} */
    public static function normalizePayload(array $post): array
    {
        $type = strtolower(trim((string)($post['tipo_domanda'] ?? $post['domanda_tipo'] ?? $post['tipo'] ?? 'aperta')));
        $type = in_array($type, ['multipla', 'multipla_multi', 'chiusa'], true) ? 'multipla' : 'aperta';
        $rawOptions = $post['risposte'] ?? $post['domanda_risposte'] ?? '';
        if ($rawOptions === '' || $rawOptions === null || $rawOptions === []) {
            $rawOptions = $post['risposta_attesa'] ?? '';
        }
        $options = self::parseOptions($rawOptions);
        $keywords = self::normalizeKeywords($post['parole_chiave'] ?? $post['domanda_parole'] ?? '');
        $difficulty = max(1, min(5, (int)($post['difficolta'] ?? $post['domanda_livello'] ?? 3)));

        if ($type === 'multipla') {
            $validOptions = array_values(array_filter($options, static fn(array $option): bool => $option['testo'] !== ''));
            if (count($validOptions) < 2) {
                throw new \InvalidArgumentException('Una domanda multipla richiede almeno due opzioni.');
            }
            if (!array_filter($validOptions, static fn(array $option): bool => $option['corretta'])) {
                throw new \InvalidArgumentException('Seleziona almeno una risposta corretta.');
            }
            $answer = json_encode(['risposte' => $validOptions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return [
                'argomento' => trim((string)($post['argomento'] ?? $post['domanda_argomento'] ?? '')),
                'domanda' => trim((string)($post['domanda'] ?? $post['domanda_testo'] ?? '')),
                'difficolta' => $difficulty,
                'tipo_domanda' => 'multipla',
                'risposta_attesa' => $answer,
                'parole_chiave' => implode(',', $keywords),
                'opzioni' => $validOptions,
            ];
        }

        return [
            'argomento' => trim((string)($post['argomento'] ?? $post['domanda_argomento'] ?? '')),
            'domanda' => trim((string)($post['domanda'] ?? $post['domanda_testo'] ?? '')),
            'difficolta' => $difficulty,
            'tipo_domanda' => 'aperta',
            'risposta_attesa' => trim((string)($post['risposta_attesa'] ?? $post['domanda_suggerimenti'] ?? '')),
            'parole_chiave' => implode(',', $keywords),
            'opzioni' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function filterObjectives(array $catalog, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $catalog;
        }
        $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        return array_values(array_filter($catalog, static function (array $objective) use ($needle): bool {
            $haystack = implode(' ', [
                $objective['codice'] ?? '',
                $objective['descrizione'] ?? '',
                $objective['competenza'] ?? '',
                $objective['parole_chiave'] ?? '',
            ]);
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            return str_contains($haystack, $needle);
        }));
    }
}
