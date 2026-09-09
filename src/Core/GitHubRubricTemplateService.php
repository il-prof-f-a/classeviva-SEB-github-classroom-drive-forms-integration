<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Security\SpreadsheetPolicy;
use App\Core\Security\UploadPolicy;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Parser/validator unico per i template delle rubriche GitHub.
 *
 * Il formato pubblico è un foglio MASTER con una riga di intestazioni e una
 * riga per indicatore. Il servizio non scrive mai rubriche nel database: la
 * pagina decide se salvare le righe su un test o tenerle temporaneamente.
 */
final class GitHubRubricTemplateService
{
    public const DEFAULT_TEMPLATE_FILENAME = 'Rubrica valutazione github VUOTA.xlsx';

    private string $materialePath;

    public function __construct(?string $materialePath = null)
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        $this->materialePath = rtrim($materialePath ?? ($root . '/Materiale'), '/\\');
    }

    public function defaultTemplatePath(): string
    {
        return $this->materialePath . DIRECTORY_SEPARATOR . self::DEFAULT_TEMPLATE_FILENAME;
    }

    public function defaultTemplateUrl(): string
    {
        return app_url('Materiale/' . rawurlencode(self::DEFAULT_TEMPLATE_FILENAME));
    }

    /**
     * @return list<array{source:string,id:string,name:string,file_name:string,count:int,rows:list<array<string,string>>}>
     */
    public function listPersistentTemplates(): array
    {
        if (!is_dir($this->materialePath)) {
            return [];
        }

        $files = glob($this->materialePath . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $templates = [];

        foreach ($files as $path) {
            $name = basename($path);
            if (!preg_match('/^[a-zA-Z0-9_. -]+\.xlsx$/i', $name)) {
                continue;
            }

            try {
                $rows = $this->parseFile($path, '', '', $name);
            } catch (\Throwable $exception) {
                // Un file Materiale non conforme non deve rendere inutilizzabile
                // la pagina: viene semplicemente escluso dalla lista template.
                continue;
            }

            $templates[] = [
                'source' => 'file',
                'id' => 'file:' . $name,
                'name' => $name,
                'file_name' => $name,
                'count' => count($rows),
                'rows' => $rows,
            ];
        }

        return $templates;
    }

    /**
     * @return list<array<string,string>>
     */
    public function parseFile(string $filePath, string $rubricId = '', string $udaId = '', ?string $originalName = null): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Template Excel non trovato.');
        }

        $name = $originalName !== null && trim($originalName) !== '' ? basename($originalName) : basename($filePath);
        UploadPolicy::assertValid($name, $filePath, 'rubric');
        SpreadsheetPolicy::assertWithinLimits($filePath, 10, 2000, 30, 60000);

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Impossibile leggere il template Excel.', 0, $exception);
        }

        $sheet = $spreadsheet->getSheetByName('MASTER');
        if ($sheet === null) {
            throw new RuntimeException('Il template deve contenere un foglio chiamato MASTER.');
        }

        $header = $this->findHeader($sheet);
        $highestRow = min((int)$sheet->getHighestRow(), $header['row'] + 200);
        $rows = [];
        $orders = [];

        for ($rowNumber = $header['row'] + 1; $rowNumber <= $highestRow; $rowNumber++) {
            $values = [];
            foreach ($header['columns'] as $field => $column) {
                $values[$field] = $column > 0
                    ? $this->cellText($sheet->getCellByColumnAndRow($column, $rowNumber))
                    : '';
            }

            if ($this->rowIsEmpty($values)) {
                continue;
            }

            $indicator = trim($values['nome_indicatore']);
            if ($indicator === '') {
                throw new RuntimeException("Riga {$rowNumber}: indicatore mancante.");
            }

            $description = trim($values['descrizione']);
            $level1 = trim($values['livello_1_desc']);
            $level2 = trim($values['livello_2_desc']);
            $level3 = trim($values['livello_3_desc']);
            $level4 = trim($values['livello_4_desc']);
            if ($description === '' || $level1 === '' || $level2 === '' || $level3 === '' || $level4 === '') {
                throw new RuntimeException("Riga {$rowNumber}: descrizione indicatore e livelli 1-4 sono obbligatori.");
            }

            $weightText = trim($values['peso']);
            if ($weightText === '' || !is_numeric($weightText) || (float)$weightText < 0) {
                throw new RuntimeException("Riga {$rowNumber}: peso non valido.");
            }

            $orderText = trim($values['ordine']);
            if ($orderText === '' || filter_var($orderText, FILTER_VALIDATE_INT) === false || (int)$orderText < 1) {
                throw new RuntimeException("Riga {$rowNumber}: ordine non valido.");
            }
            $order = (int)$orderText;
            if (isset($orders[$order])) {
                throw new RuntimeException("Riga {$rowNumber}: ordine duplicato ({$order}).");
            }
            $orders[$order] = true;

            $rows[] = [
                'id_rubrica' => $rubricId,
                'id_uda' => $udaId,
                'nome_indicatore' => $indicator,
                'descrizione' => $description,
                'livello_1_desc' => $level1,
                'livello_2_desc' => $level2,
                'livello_3_desc' => $level3,
                'livello_4_desc' => $level4,
                'livello_5_desc' => trim($values['livello_5_desc']),
                'peso' => $this->normalizeNumber($weightText),
                'ordine' => (string)$order,
                'note' => trim($values['note']) !== '' ? trim($values['note']) : 'github_rubric',
                'pubblicato' => '0',
                'data_pubblicazione' => '',
                'id_annotazione_cv' => '',
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('Il template non contiene indicatori.');
        }

        usort($rows, static fn(array $a, array $b): int => (int)$a['ordine'] <=> (int)$b['ordine']);
        return $rows;
    }

    /**
     * Valida e normalizza righe ricevute da un POST temporaneo.
     * @param mixed $rawRows
     * @return list<array<string,string>>
     */
    public function validateRows(mixed $rawRows, string $rubricId = '', string $udaId = ''): array
    {
        if (!is_array($rawRows)) {
            throw new RuntimeException('Dati template temporaneo non validi.');
        }

        $rows = [];
        $orders = [];
        foreach (array_values($rawRows) as $index => $raw) {
            if (!is_array($raw)) {
                throw new RuntimeException('Riga template non valida.');
            }

            $row = [
                'id_rubrica' => $rubricId,
                'id_uda' => $udaId,
                'nome_indicatore' => trim((string)($raw['nome_indicatore'] ?? '')),
                'descrizione' => trim((string)($raw['descrizione'] ?? '')),
                'livello_1_desc' => trim((string)($raw['livello_1_desc'] ?? '')),
                'livello_2_desc' => trim((string)($raw['livello_2_desc'] ?? '')),
                'livello_3_desc' => trim((string)($raw['livello_3_desc'] ?? '')),
                'livello_4_desc' => trim((string)($raw['livello_4_desc'] ?? '')),
                'livello_5_desc' => trim((string)($raw['livello_5_desc'] ?? '')),
                'peso' => trim((string)($raw['peso'] ?? '')),
                'ordine' => trim((string)($raw['ordine'] ?? '')),
                'note' => trim((string)($raw['note'] ?? '')),
                'pubblicato' => in_array((string)($raw['pubblicato'] ?? '0'), ['0', '1'], true)
                    ? (string)($raw['pubblicato'] ?? '0')
                    : '0',
                'data_pubblicazione' => trim((string)($raw['data_pubblicazione'] ?? '')),
                'id_annotazione_cv' => trim((string)($raw['id_annotazione_cv'] ?? '')),
            ];

            if ($row['nome_indicatore'] === '' || $row['descrizione'] === ''
                || $row['livello_1_desc'] === '' || $row['livello_2_desc'] === ''
                || $row['livello_3_desc'] === '' || $row['livello_4_desc'] === '') {
                throw new RuntimeException('Riga ' . ($index + 1) . ': campi obbligatori mancanti.');
            }
            if ($row['peso'] === '' || !is_numeric($row['peso']) || (float)$row['peso'] < 0) {
                throw new RuntimeException('Riga ' . ($index + 1) . ': peso non valido.');
            }
            if ($row['ordine'] === '' || filter_var($row['ordine'], FILTER_VALIDATE_INT) === false || (int)$row['ordine'] < 1) {
                throw new RuntimeException('Riga ' . ($index + 1) . ': ordine non valido.');
            }
            $order = (int)$row['ordine'];
            if (isset($orders[$order])) {
                throw new RuntimeException('Ordine duplicato: ' . $order . '.');
            }
            $orders[$order] = true;
            $row['peso'] = $this->normalizeNumber($row['peso']);
            if ($row['note'] === '') {
                $row['note'] = 'github_rubric';
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('Il template non contiene indicatori.');
        }
        usort($rows, static fn(array $a, array $b): int => (int)$a['ordine'] <=> (int)$b['ordine']);
        return $rows;
    }

    /** @return array{row:int,columns:array<string,int>} */
    private function findHeader($sheet): array
    {
        $maxRow = min((int)$sheet->getHighestRow(), 20);
        $maxColumn = min(Coordinate::columnIndexFromString((string)$sheet->getHighestColumn()), 30);
        for ($row = 1; $row <= $maxRow; $row++) {
            $columns = [];
            for ($column = 1; $column <= $maxColumn; $column++) {
                $label = $this->normalizeHeader($this->cellText($sheet->getCellByColumnAndRow($column, $row)));
                if ($label === '') {
                    continue;
                }
                $field = $this->headerField($label);
                if ($field !== null && !isset($columns[$field])) {
                    $columns[$field] = $column;
                }
            }

            $required = ['nome_indicatore', 'descrizione', 'livello_1_desc', 'livello_2_desc', 'livello_3_desc', 'livello_4_desc', 'peso', 'ordine'];
            if (count(array_intersect($required, array_keys($columns))) === count($required)) {
                $columns['livello_5_desc'] ??= 0;
                $columns['note'] ??= 0;
                return ['row' => $row, 'columns' => $columns];
            }
        }

        throw new RuntimeException('Intestazioni template non riconosciute.');
    }

    private function headerField(string $label): ?string
    {
        return match (true) {
            in_array($label, ['indicatore', 'nome indicatore', 'nome_indicatore', 'nome'], true) => 'nome_indicatore',
            in_array($label, ['descrizione', 'descrizione indicatore', 'descrizione dell indicatore', 'descr'], true) => 'descrizione',
            in_array($label, ['livello 1', 'livello1', 'livello_1', 'livello 1 descrizione', 'livello_1_desc'], true) => 'livello_1_desc',
            in_array($label, ['livello 2', 'livello2', 'livello_2', 'livello 2 descrizione', 'livello_2_desc'], true) => 'livello_2_desc',
            in_array($label, ['livello 3', 'livello3', 'livello_3', 'livello 3 descrizione', 'livello_3_desc'], true) => 'livello_3_desc',
            in_array($label, ['livello 4', 'livello4', 'livello_4', 'livello 4 descrizione', 'livello_4_desc'], true) => 'livello_4_desc',
            in_array($label, ['livello 5', 'livello5', 'livello_5', 'livello 5 descrizione', 'livello_5_desc'], true) => 'livello_5_desc',
            in_array($label, ['peso', 'peso indicatore', 'weight'], true) => 'peso',
            in_array($label, ['ordine', 'ordine indicatore', 'order'], true) => 'ordine',
            in_array($label, ['note', 'nota'], true) => 'note',
            default => null,
        };
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return str_replace([':', '-', '–'], ['', ' ', ' '], $value);
    }

    private function cellText($cell): string
    {
        $value = $cell->getValue();
        if (is_object($value) && method_exists($value, 'getPlainText')) {
            $value = $value->getPlainText();
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            $value = (string)$value;
        }
        $value = is_scalar($value) || $value === null ? (string)$value : '';
        if (str_starts_with(trim($value), '=')) {
            throw new RuntimeException('Il template non può contenere formule nei dati della rubrica.');
        }
        return $value;
    }

    /** @param array<string,string> $values */
    private function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function normalizeNumber(string $value): string
    {
        $number = (float)$value;
        if (floor($number) === $number) {
            return (string)(int)$number;
        }
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }
}
