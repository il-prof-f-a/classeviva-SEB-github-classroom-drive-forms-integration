<?php

namespace App\Integration;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Exception;

/**
 * GoogleDriveAPI - Integrazione con Google Drive
 */
class GoogleDriveAPI
{
    private Client $client;
    private Drive $service;
    private array $config;
    /**
     * Configurazione applicativa completa (serve per eventuale refresh/salvataggio token)
     * Può includere google.oauth_token impostato da bootstrap.php per l'utente corrente.
     *
     * @var array
     */
    private array $appConfig = [];

    public function __construct(array $config)
    {
        $this->appConfig = $config;
        $this->config = $config['google'] ?? [];
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $this->client = new Client();
        $this->client->setApplicationName('UDA System');
        $this->client->setScopes([Drive::DRIVE_FILE]);
        $this->client->setAuthConfig(ROOT_PATH . '/' . $this->config['credentials_file']);
        $this->client->setAccessType('offline');

        // Imposta redirect URI per OAuth
        $redirectUri = $this->config['redirect_uri'] ?? $_ENV['GOOGLE_REDIRECT_URI'];
        $this->client->setRedirectUri($redirectUri);

        // Carica token OAuth:
        // 1) preferisce il token specifico dell'utente corrente (config['google']['oauth_token'])
        // 2) in mancanza, usa il vecchio file globale config/google_token.json come fallback legacy
        //$tokenData = null;
        //if (!empty($this->appConfig['google']['oauth_token']) && is_array($this->appConfig['google']['oauth_token'])) {
            $tokenData = $this->appConfig['google']['oauth_token'];
        //} else {
        //    $tokenPath = ROOT_PATH . '/' . ($this->config['token_file'] ?? 'config/google_token.json');
        //    if (file_exists($tokenPath)) {
        //        $tokenData = json_decode(file_get_contents($tokenPath), true);
        //    }
        //}

        if ($tokenData === null) {
            throw new Exception(
                "Token Google Drive non trovato per l'utente corrente. " .
                "Esegui la procedura di autenticazione da google_auth.php."
            );
        }

        $this->client->setAccessToken($tokenData);

        // Refresh token se necessario
        if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
            $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            // Il nuovo access token viene usato in memoria per questa richiesta.
            // La persistenza per-utente è gestita principalmente da google_auth.php.
        }

        $this->service = new Drive($this->client);
    }

    /**
     * Crea struttura cartelle per UDA
     */
    public function createUDAFolderStructure(string $udaTitle, string $year): array
    {
        $rootFolderId = $this->config['drive']['root_folder_id'] ?? null;

        // Crea cartella anno se non esiste
        $yearFolder = $this->createFolder("UDA_{$year}", $rootFolderId);

        // Crea cartella UDA
        $udaFolder = $this->createFolder($udaTitle, $yearFolder['id']);

        // Crea sottocartelle
        $structure = $this->config['drive']['folder_structure'] ?? [];
        $subfolders = [];

        foreach ($structure as $folderDef) {
            if (is_array($folderDef) && isset($folderDef['name'])) {
                $subfolder = $this->createFolder($folderDef['name'], $udaFolder['id']);
                $subfolders[$folderDef['name']] = $subfolder;

                // Sottocartelle annidate
                if (isset($folderDef['create_subfolders'])) {
                    foreach ($folderDef['create_subfolders'] as $subName) {
                        $this->createFolder($subName, $subfolder['id']);
                    }
                }
            } elseif (is_string($folderDef)) {
                $subfolders[$folderDef] = $this->createFolder($folderDef, $udaFolder['id']);
            }
        }

        return [
            'root' => $udaFolder,
            'subfolders' => $subfolders
        ];
    }

    /**
     * Crea una cartella
     */
    public function createFolder(string $name, ?string $parentId = null): array
    {
        $fileMetadata = new DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);

        if ($parentId) {
            $fileMetadata->setParents([$parentId]);
        }

        $folder = $this->service->files->create($fileMetadata, [
            'fields' => 'id, name, webViewLink'
        ]);

        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'link' => $folder->webViewLink
        ];
    }

    /**
     * Upload file su Drive
     */
    public function uploadFile(string $localPath, string $fileName, ?string $folderId = null, string $mimeType = null): array
    {
        if (!file_exists($localPath)) {
            throw new Exception("File non trovato: {$localPath}");
        }

        $fileMetadata = new DriveFile([
            'name' => $fileName,
        ]);
        if (!empty($folderId)) {
            $fileMetadata->setParents([$folderId]);
        }

        $content = file_get_contents($localPath);
        $file = $this->service->files->create($fileMetadata, [
            'data' => $content,
            'mimeType' => $mimeType ?? mime_content_type($localPath),
            'uploadType' => 'multipart',
            'fields' => 'id, name, mimeType, webViewLink, webContentLink'
        ]);

        return [
            'id' => $file->id,
            'name' => $file->name,
            'mimeType' => $file->mimeType,
            'viewLink' => $file->webViewLink,
            'downloadLink' => $file->webContentLink
        ];
    }

    /**
     * Elimina un file da Google Drive
     *
     * @param string $fileId ID del file da eliminare
     * @return bool True se eliminato con successo
     * @throws Exception
     */
    public function deleteFile(string $fileId): bool
    {
        try {
            $this->service->files->delete($fileId);
            return true;
        } catch (Exception $e) {
            throw new Exception("Errore eliminazione file da Drive: " . $e->getMessage());
        }
    }

    /**
     * Lista file e cartelle in una cartella specifica
     *
     * @param string|null $folderId ID della cartella (null = root)
     * @param string|null $query Query aggiuntiva (es. "mimeType = 'application/vnd.google-apps.folder'")
     * @param int $pageSize Numero massimo di risultati
     * @return array Lista di file con id, name, mimeType
     */
    public function listFiles(?string $folderId = null, ?string $query = null, int $pageSize = 100): array
    {
        try {
            $q = "trashed = false";
            if ($folderId) {
                $q .= " and '{$folderId}' in parents";
            }
            if ($query) {
                $q .= " and ({$query})";
            }

            $response = $this->service->files->listFiles([
                'q' => $q,
                'pageSize' => $pageSize,
                'fields' => 'files(id, name, mimeType, webViewLink, createdTime)',
                'orderBy' => 'name'
            ]);

            $files = [];
            foreach ($response->getFiles() as $file) {
                $files[] = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                    'webViewLink' => $file->getWebViewLink(),
                    'createdTime' => $file->getCreatedTime()
                ];
            }

            return $files;
        } catch (Exception $e) {
            throw new Exception("Errore lista file da Drive: " . $e->getMessage());
        }
    }

    /**
     * Ottiene URL di autenticazione OAuth
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    // La gestione completa dell'OAuth (ottenimento/salvataggio token) è delegata a public/google_auth.php
    /**
     * Rende temporaneamente pubblico (lettura anyone) un file Drive e restituisce l'ID del permesso creato.
     * L'ID va passato a revertPublicPermission per ripristinare la visibilita originale.
     */
    public function makeFileTemporarilyPublic(string $fileId): ?string
    {
        $permission = new \Google\Service\Drive\Permission();
        $permission->setType('anyone');
        $permission->setRole('reader');

        $created = $this->service->permissions->create($fileId, $permission, ['fields' => 'id']);
        return $created->id ?? null;
    }

    /**
     * Recupera metadati di un file Drive (id, name, webViewLink, webContentLink, permissions).
     */
    public function getFileMetadata(string $fileId): array
    {
        try {
            $file = $this->service->files->get($fileId, ['fields' => 'id, name, webViewLink, webContentLink, permissions']);
            return [
                'id' => $file->id,
                'name' => $file->name,
                'viewLink' => $file->webViewLink,
                'downloadLink' => $file->webContentLink,
                'permissions' => $file->permissions,
            ];
        } catch (Exception $e) {
            throw new Exception("Errore lettura metadati file Drive {$fileId}: " . $e->getMessage());
        }
    }

    /**
     * Scarica un file Drive su path locale.
     */
    public function downloadFile(string $fileId, string $destinationPath): void
    {
        try {
            $response = $this->service->files->get($fileId, ['alt' => 'media']);
            $content = $response->getBody()->getContents();
            file_put_contents($destinationPath, $content);
        } catch (Exception $e) {
            throw new Exception("Errore download file Drive {$fileId}: " . $e->getMessage());
        }
    }

    /**
     * Scarica un file Drive come .xlsx. Supporta Google Sheets (export) e file Excel gia' presenti.
     *
     * @return array{id:string,name:string,mimeType:string}
     */
    public function downloadFileAsXlsx(string $fileId, string $destinationPath): array
    {
        try {
            $file = $this->service->files->get($fileId, ['fields' => 'id, name, mimeType']);
            $mimeType = $file->getMimeType();

            if ($mimeType === 'application/vnd.google-apps.spreadsheet') {
                $response = $this->service->files->export(
                    $fileId,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ['alt' => 'media']
                );
            } else {
                $response = $this->service->files->get($fileId, ['alt' => 'media']);
            }

            $content = $response->getBody()->getContents();
            file_put_contents($destinationPath, $content);

            return [
                'id' => $file->id,
                'name' => $file->name,
                'mimeType' => $mimeType
            ];
        } catch (Exception $e) {
            throw new Exception("Errore download file Drive {$fileId}: " . $e->getMessage());
        }
    }

    /**
     * Rimuove il permesso pubblico precedentemente aggiunto con makeFileTemporaneamentePublic.
     */
    public function revertPublicPermission(string $fileId, ?string $permissionId): void
    {
        if (empty($permissionId)) {
            return;
        }

        try {
            $this->service->permissions->delete($fileId, $permissionId);
        } catch (Exception $e) {
            error_log("GoogleDriveAPI revertPublicPermission failed for {$fileId}/{$permissionId}: " . $e->getMessage());
        }
    }
}

