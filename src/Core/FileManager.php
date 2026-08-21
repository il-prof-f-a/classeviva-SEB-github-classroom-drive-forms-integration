<?php

namespace App\Core;

use Exception;
use App\Core\Security\UploadPolicy;

/**
 * FileManager - Gestisce upload, download e organizzazione file
 *
 * Gestisce la struttura delle cartelle UDA e l'integrazione con Google Drive
 */
class FileManager
{
    private array $config;
    private string $storagePath;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->storagePath = ROOT_PATH . '/' . $config['paths']['storage'];
    }

    /**
     * Crea la struttura di cartelle per una nuova UDA
     *
     * @param string $udaId ID dell'UDA
     * @param string $year Anno scolastico (es: 2024-25)
     * @return string Path completo della cartella UDA
     */
    public function createUDAFolderStructure(string $udaId, string $year): string
    {
        $basePath = $this->storagePath . "uda/{$year}/{$udaId}";

        $folders = [
            $basePath,
            "{$basePath}/materiali",
            "{$basePath}/test",
            "{$basePath}/presentazioni",
            "{$basePath}/voti",
            "{$basePath}/voti/rubriche",
            "{$basePath}/voti/export"
        ];

        foreach ($folders as $folder) {
            if (!is_dir($folder)) {
                if (!mkdir($folder, 0755, true)) {
                    throw new Exception("Impossibile creare la cartella: {$folder}");
                }
            }
        }

        return $basePath;
    }

    /**
     * Upload di un file nella cartella UDA
     *
     * @param array $file File da $_FILES
     * @param string $udaId ID dell'UDA
     * @param string $category Categoria (materiali, test, presentazioni, voti)
     * @return array Info sul file caricato
     */
    public function uploadFile(array $file, string $udaId, string $category = 'materiali'): array
    {
        // Validazione file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Errore durante l'upload del file");
        }

        // Validazione tipo file
        $allowedTypes = $this->config['security']['allowed_file_types'] ?? [];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!$this->isFileTypeAllowed($extension, $allowedTypes)) {
            throw new Exception("Tipo di file non permesso: {$extension}");
        }

        // Validazione dimensione
        $maxSize = $this->parseSize($this->config['security']['max_upload_size'] ?? '50M');
        if ($file['size'] > $maxSize) {
            throw new Exception("File troppo grande. Massimo: " . $this->config['security']['max_upload_size']);
        }
        UploadPolicy::assertValid((string)$file['name'], (string)$file['tmp_name'], 'document');

        // Determina path di destinazione
        $year = $this->config['academic_year']['current'];
        $basePath = $this->storagePath . "uda/{$year}/{$udaId}/{$category}";

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        // Nome file sicuro
        $safeFileName = $this->generateSafeFileName($file['name']);
        $destinationPath = "{$basePath}/{$safeFileName}";

        // Sposta file
        if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
            throw new Exception("Impossibile salvare il file");
        }

        return [
            'original_name' => $file['name'],
            'safe_name' => $safeFileName,
            'path' => $destinationPath,
            'relative_path' => str_replace(ROOT_PATH . '/', '', $destinationPath),
            'size' => $file['size'],
            'type' => $file['type'],
            'extension' => $extension,
            'uploaded_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Copia un file da un template o da un'altra UDA
     *
     * @param string $sourcePath Path del file sorgente
     * @param string $udaId ID dell'UDA di destinazione
     * @param string $category Categoria di destinazione
     * @return array Info sul file copiato
     */
    public function copyFile(string $sourcePath, string $udaId, string $category = 'materiali'): array
    {
        if (!file_exists($sourcePath)) {
            throw new Exception("File sorgente non trovato: {$sourcePath}");
        }

        $year = $this->config['academic_year']['current'];
        $basePath = $this->storagePath . "uda/{$year}/{$udaId}/{$category}";

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        $fileName = basename($sourcePath);
        $destinationPath = "{$basePath}/{$fileName}";

        if (!copy($sourcePath, $destinationPath)) {
            throw new Exception("Impossibile copiare il file");
        }

        return [
            'original_name' => $fileName,
            'path' => $destinationPath,
            'relative_path' => str_replace(ROOT_PATH . '/', '', $destinationPath),
            'size' => filesize($destinationPath),
            'copied_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Elimina un file
     */
    public function deleteFile(string $filePath): bool
    {
        if (file_exists($filePath) && is_file($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Ottiene la lista di file di una UDA
     */
    public function getUDAFiles(string $udaId, ?string $category = null): array
    {
        $year = $this->config['academic_year']['current'];
        $basePath = $this->storagePath . "uda/{$year}/{$udaId}";

        if ($category) {
            $basePath .= "/{$category}";
        }

        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = [
                    'name' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'relative_path' => str_replace(ROOT_PATH . '/', '', $file->getPathname()),
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                ];
            }
        }

        return $files;
    }

    /**
     * Crea una cartella temporanea per uso AI (es. allegati verso provider) e restituisce info utili.
     *
     * @return array{uid:string,path:string,relative_path:string}
     */
    public function createAITempFolder(): array
    {
        $uid = uniqid('ai_', true);
        $base = rtrim($this->storagePath, '/\\') . '/tmp_ai/' . $uid;

        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new Exception("Impossibile creare la cartella temporanea AI: {$base}");
        }

        return [
            'uid' => $uid,
            'path' => $base,
            'relative_path' => str_replace(ROOT_PATH . '/', '', $base)
        ];
    }

    /**
     * Cancella ricorsivamente la cartella temporanea AI identificata da UID.
     */
    public function cleanupAITempFolder(string $uid): void
    {
        $base = rtrim($this->storagePath, '/\\') . '/tmp_ai/' . $uid;
        if (is_dir($base)) {
            $this->deleteDirectoryRecursive($base);
        }
    }

    /**
     * Rimozione ricorsiva di una directory.
     */
    private function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Genera un nome file sicuro
     */
    private function generateSafeFileName(string $fileName): string
    {
        $pathInfo = pathinfo($fileName);
        $extension = $pathInfo['extension'] ?? '';
        $baseName = $pathInfo['filename'];

        // Rimuovi caratteri non sicuri
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        $safeName = substr($safeName, 0, 100); // Limita lunghezza

        // Aggiungi timestamp per unicità
        $safeName .= '_' . time();

        return $extension ? "{$safeName}.{$extension}" : $safeName;
    }

    /**
     * Verifica se un tipo di file è permesso
     */
    private function isFileTypeAllowed(string $extension, array $allowedTypes): bool
    {
        foreach ($allowedTypes as $category => $types) {
            if (is_array($types) && in_array($extension, $types)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Converte dimensione file (es: "50M" -> 52428800)
     */
    private function parseSize(string $size): int
    {
        $unit = strtoupper(substr($size, -1));
        $value = (int)substr($size, 0, -1);

        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return (int)$size;
        }
    }
}

