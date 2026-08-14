<?php

namespace App\Integration;

use App\Core\Database\DatabaseFactory;
use App\Core\GoogleTokenProvider;
use App\Core\UserIntegrationManager;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Forms;
use RuntimeException;

final class GoogleFormsCatalog
{
    public const FORM_MIME_TYPE = 'application/vnd.google-apps.form';
    public const ERROR_TOKEN_MISSING = 'token_missing';
    public const ERROR_TOKEN_EXPIRED = 'token_expired';
    public const ERROR_SCOPE_MISSING = 'scope_missing';

    /** @return list<string> */
    public static function requiredScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/drive.metadata.readonly',
            'https://www.googleapis.com/auth/forms.body.readonly',
            'https://www.googleapis.com/auth/forms.responses.readonly',
        ];
    }

    /** @return list<string> */
    public static function missingScopes(array $token): array
    {
        $scopeValue = $token['scope'] ?? '';
        if ($scopeValue === '') {
            return [];
        }
        $granted = is_array($scopeValue) ? $scopeValue : preg_split('/\s+/', trim((string)$scopeValue));
        $granted = array_values(array_filter(array_map('strval', $granted ?: [])));
        $hasDriveMetadata = in_array('https://www.googleapis.com/auth/drive.metadata.readonly', $granted, true)
            || in_array('https://www.googleapis.com/auth/drive.readonly', $granted, true)
            || in_array('https://www.googleapis.com/auth/drive', $granted, true);
        $hasFormsBody = in_array('https://www.googleapis.com/auth/forms.body.readonly', $granted, true)
            || in_array('https://www.googleapis.com/auth/forms.body', $granted, true);
        $hasFormsResponses = in_array('https://www.googleapis.com/auth/forms.responses.readonly', $granted, true)
            || in_array('https://www.googleapis.com/auth/forms.responses', $granted, true);
        $missing = [];
        if (!$hasDriveMetadata) $missing[] = self::requiredScopes()[0];
        if (!$hasFormsBody) $missing[] = self::requiredScopes()[1];
        if (!$hasFormsResponses) $missing[] = self::requiredScopes()[2];
        return $missing;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public static function normalizeFile(mixed $file, ?int $responseCount = null, array $metadata = []): array
    {
        $owners = is_array($file) ? ($file['owners'] ?? []) : self::objectValue($file, 'getOwners', []);
        $owner = is_array($owners) && isset($owners[0]) ? $owners[0] : [];
        $author = is_array($owner)
            ? trim((string)($owner['displayName'] ?? $owner['emailAddress'] ?? ''))
            : self::objectValue($owner, 'getDisplayName', self::objectValue($owner, 'getEmailAddress', ''));
        $id = (string)(is_array($file) ? ($file['id'] ?? '') : self::objectValue($file, 'getId', ''));
        $title = trim((string)(is_array($file) ? ($file['name'] ?? 'Google Form') : self::objectValue($file, 'getName', 'Google Form')));
        $created = (string)(is_array($file) ? ($file['createdTime'] ?? '') : self::objectValue($file, 'getCreatedTime', ''));
        $webViewLink = trim((string)(is_array($file) ? ($file['webViewLink'] ?? '') : self::objectValue($file, 'getWebViewLink', '')));
        $description = trim((string)($metadata['description']
            ?? (is_array($file) ? ($file['description'] ?? '') : self::objectValue($file, 'getDescription', ''))));
        $responderUri = trim((string)($metadata['responder_uri']
            ?? (is_array($file) ? ($file['responderUri'] ?? '') : '')));
        if ($webViewLink === '' && $id !== '') {
            $webViewLink = 'https://docs.google.com/forms/d/' . rawurlencode($id) . '/edit';
        }
        if ($responderUri === '' && $id !== '') {
            // Fallback compatibile con gli ID restituiti da Drive quando Forms API
            // non espone responderUri (ad esempio nei fixture o in una risposta parziale).
            $responderUri = 'https://docs.google.com/forms/d/' . rawurlencode($id) . '/viewform';
        }

        return [
            'id' => $id,
            'title' => $title !== '' ? $title : 'Google Form senza titolo',
            'teacher_url' => $webViewLink,
            'student_url' => $responderUri,
            'description' => $description,
            'author' => $author !== '' ? $author : 'Autore non disponibile',
            'created_at' => $created,
            'response_count' => $responseCount,
        ];
    }

    /** @param list<array<string,mixed>> $forms @return list<array<string,mixed>> */
    public static function filter(array $forms, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return array_values($forms);
        }
        return array_values(array_filter($forms, static function (array $form) use ($needle): bool {
            $haystack = mb_strtolower(implode(' ', [
                (string)($form['title'] ?? ''),
                (string)($form['author'] ?? ''),
                (string)($form['created_at'] ?? ''),
                (string)($form['description'] ?? ''),
            ]));
            return str_contains($haystack, $needle);
        }));
    }

    /** @return list<array<string,mixed>> */
    public static function listForUser(array &$config): array
    {
        $token = GoogleTokenProvider::getToken($config);
        if (empty($token['access_token'])) {
            throw new RuntimeException('Token Google non presente. Autorizza Google Drive e Forms.', 401);
        }
        $missing = self::missingScopes($token);
        if ($missing !== []) {
            throw new RuntimeException('L’autorizzazione Google non include Drive metadata. Riautorizza Google.', 403);
        }

        $credentialsFile = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');
        if (!is_file($credentialsFile)) {
            throw new RuntimeException('File credenziali Google non trovato.', 500);
        }

        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setAuthConfig($credentialsFile);
        $client->setAccessType('offline');
        $client->setScopes(self::requiredScopes());
        $client->setAccessToken($token);
        if ($client->isAccessTokenExpired()) {
            if (!$client->getRefreshToken()) {
                throw new RuntimeException('Token Google scaduto. Riautorizza Google.', 401);
            }
            $refreshed = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (empty($refreshed['access_token'])) {
                throw new RuntimeException('Token Google scaduto. Riautorizza Google.', 401);
            }
            $token = array_merge($token, $refreshed, ['created' => time()]);
            if (empty($token['refresh_token'])) {
                $token['refresh_token'] = $client->getRefreshToken();
            }
            self::saveToken($config, $token);
            $client->setAccessToken($token);
        }

        $drive = new Drive($client);
        $forms = new Forms($client);
        $files = [];
        $pageToken = null;
        do {
            $params = [
                'q' => "trashed = false and mimeType = '" . self::FORM_MIME_TYPE . "'",
                'pageSize' => 100,
                'orderBy' => 'createdTime desc',
                'fields' => 'nextPageToken,files(id,name,createdTime,webViewLink,owners(displayName,emailAddress))',
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $response = $drive->files->listFiles($params);
            foreach ($response->getFiles() as $file) {
                $responseCount = null;
                $metadata = [];
                try {
                    $formId = (string)$file->getId();
                    $form = $forms->forms->get($formId);
                    $metadata = [
                        'responder_uri' => method_exists($form, 'getResponderUri')
                            ? (string)($form->getResponderUri() ?? '')
                            : '',
                        'description' => method_exists($form, 'getInfo') && $form->getInfo()
                            ? (string)($form->getInfo()->getDescription() ?? '')
                            : '',
                    ];
                } catch (\Throwable) {
                    // Un form può essere visibile in Drive ma non leggibile da Forms API.
                    // Il catalogo resta utilizzabile con i metadati di Drive e i fallback URL.
                }
                try {
                    $responseCount = self::countResponses($forms, (string)$file->getId());
                } catch (\Throwable) {
                    $responseCount = null;
                }
                $files[] = self::normalizeFile($file, $responseCount, $metadata);
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $files;
    }

    private static function countResponses(Forms $forms, string $formId): int
    {
        $count = 0;
        $pageToken = null;
        do {
            $params = ['pageSize' => 500];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $response = $forms->forms_responses->listFormsResponses($formId, $params);
            $count += count($response->getResponses() ?? []);
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);
        return $count;
    }

    private static function saveToken(array &$config, array $token): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $db = DatabaseFactory::createWithInitialization($config, true);
        $manager = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
        $googleConfig = $manager->getConfig('google');
        $googleConfig['token'] = $token;
        $manager->saveConfig('google', $googleConfig, true);
    }

    private static function objectValue(mixed $object, string $method, mixed $fallback): mixed
    {
        return is_object($object) && method_exists($object, $method) ? ($object->{$method}() ?? $fallback) : $fallback;
    }
}
