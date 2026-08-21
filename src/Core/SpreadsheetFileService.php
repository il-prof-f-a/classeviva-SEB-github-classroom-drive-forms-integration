<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use App\Core\Security\UploadPolicy;

/**
 * Gestisce file Excel/CSV usati come import, export o template.
 *
 * La persistenza applicativa resta responsabilità degli adapter SQL: questa
 * classe non conosce tabelle, righe o configurazioni del database.
 */
final class SpreadsheetFileService
{
    /** @var list<string> */
    private array $allowedRoots;

    /**
     * Costruisce il servizio con le directory applicative per file e template.
     */
    public static function fromConfig(array $config): self
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
        $paths = $config['paths'] ?? [];
        $templates = (string)($paths['templates'] ?? 'database/templates/');
        $storage = (string)($paths['storage'] ?? 'storage/');
        $uploads = (string)($paths['uploads'] ?? 'storage/uploads/');
        $temp = (string)($paths['temp'] ?? 'storage/temp/');

        return new self([
            $root . '/' . ltrim($templates, '/\\'),
            $root . '/Materiale',
            $root . '/' . ltrim($storage, '/\\'),
            $root . '/' . ltrim($uploads, '/\\'),
            $root . '/' . ltrim($temp, '/\\'),
        ]);
    }

    /**
     * @param list<string> $allowedRoots Directory dalle quali è consentito
     *                                   leggere file tabellari.
     */
    public function __construct(array $allowedRoots)
    {
        $roots = [];
        foreach ($allowedRoots as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && is_dir($realRoot)) {
                $roots[] = rtrim(str_replace('\\', '/', $realRoot), '/');
            }
        }

        if ($roots === []) {
            throw new RuntimeException('Nessuna directory autorizzata per i file tabellari.');
        }

        $this->allowedRoots = array_values(array_unique($roots));
    }

    /**
     * Carica un file Excel/CSV esterno già autorizzato.
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function loadExternalFile(string $filePath)
    {
        $realPath = $this->validateFilePath($filePath);
        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $allowedExtensions = ['xlsx', 'xls', 'csv'];

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException("Tipo file non supportato: {$extension}");
        }
        UploadPolicy::assertValid(basename($realPath), $realPath, 'spreadsheet');

        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($realPath);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Impossibile caricare file tabellare: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Carica un template cercandolo nelle directory autorizzate.
     *
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public function loadTemplate(string $templateName)
    {
        if (!preg_match('/^[a-zA-Z0-9_. -]+\.xlsx?$/i', $templateName)) {
            throw new RuntimeException("Nome template non valido: {$templateName}");
        }

        foreach ($this->allowedRoots as $root) {
            $candidate = $root . '/' . $templateName;
            if (is_file($candidate)) {
                return $this->loadExternalFile($candidate);
            }
        }

        throw new RuntimeException("Template non trovato: {$templateName}");
    }

    private function validateFilePath(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("File non trovato o non valido: {$filePath}");
        }

        $maxSize = 50 * 1024 * 1024;
        $size = filesize($filePath);
        if ($size === false || $size > $maxSize) {
            throw new RuntimeException('File troppo grande o non leggibile (limite 50MB).');
        }

        $realPath = realpath($filePath);
        if ($realPath === false) {
            throw new RuntimeException("Path non valido: {$filePath}");
        }
        $normalized = str_replace('\\', '/', $realPath);

        foreach ($this->allowedRoots as $root) {
            if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
                return $realPath;
            }
        }

        throw new RuntimeException('Accesso negato: file fuori dalle directory autorizzate.');
    }
}
