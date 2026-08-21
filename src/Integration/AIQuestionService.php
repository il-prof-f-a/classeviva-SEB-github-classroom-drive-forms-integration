<?php

namespace App\Integration;

use App\Core\FileManager;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AIQuestionService
 *
 * Fornisce funzioni di supporto per la generazione domande via AI
 * (list models fallback, gestione allegati temporanei, stub risposta).
 */
class AIQuestionService
{
    private array $config;
    private array $aiConfig;
    private FileManager $fileManager;
    private GoogleDriveAPI $drive;
    private Client $http;
    private const MAX_QUESTIONS = 30;
    private const MAX_ATTACH_MB = 15;
    private const MAX_TOKENS = 4000;

    /**
    * Modelli fallback statici per provider (sostituibili da list-models reali)
    */
    private const FALLBACK_MODELS = [
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-pro'],
        'openai' => ['gpt-4o', 'gpt-4o-mini'],
        'claude' => ['claude-3-5-sonnet', 'claude-3-haiku'],
        'openrouter' => ['openrouter/gpt-4o-mini', 'openrouter/claude-3-haiku'],
    ];

    public function __construct(array $config, array $aiConfig = [])
    {
        $this->config = $config;
        $this->aiConfig = $aiConfig;
        $this->fileManager = new FileManager($config);
        $this->drive = new GoogleDriveAPI($config);
        $this->http = new Client(['timeout' => 120]);
    }

    public function listModels(string $provider): array
    {
        return self::FALLBACK_MODELS[$provider] ?? [];
    }

    /**
     * TODO: integrare list-models reali per provider; per ora fallback.
     */
    public function listModelsWithMeta(string $provider): array
    {
        $hasKey = !empty($this->aiConfig['api_key']);
        $source = 'fallback';
        $models = $this->listModels($provider);

        if ($hasKey) {
            try {
                $models = $this->fetchModelsFromProvider($provider, $this->aiConfig['api_key']);
                $source = 'provider';
            } catch (Exception $e) {
                // fallback silenzioso
                $source = 'fallback';
            }
        }

        return [
            'status' => 'ok',
            'provider' => $provider,
            'models' => $models,
            'source' => $source,
            'has_key' => $hasKey
        ];
    }

    /**
     * Stub di generazione: prepara allegati e ritorna payload di risposta.
     * La logica di chiamata al provider va implementata in seguito.
     */
    public function previewPrompt(array $payload): array
    {
        $numAperte = (int)($payload["num_open"] ?? 0);
        $numChiuseM = (int)($payload["num_closed_medium"] ?? 5);
        $numChiuseD = (int)($payload["num_closed_hard"] ?? 5);
        $numChiuseX = (int)($payload["num_closed_expert"] ?? 5);
        $overridePrompt = trim($payload["override_prompt"] ?? "");
        $params = $payload["params"] ?? [];
        $usePublicLinks = !empty($payload["use_public_links"]);
        $params["use_public_links"] = $usePublicLinks;

        $prompt = $overridePrompt !== "" ? $overridePrompt : $this->buildPrompt($params, [
            "open" => $numAperte,
            "closed_medium" => $numChiuseM,
            "closed_hard" => $numChiuseD,
            "closed_expert" => $numChiuseX,
        ]);

        return [
            "status" => "ok",
            "prompt" => $prompt,
            "requested" => [
                "open" => $numAperte,
                "closed_medium" => $numChiuseM,
                "closed_hard" => $numChiuseD,
                "closed_expert" => $numChiuseX,
            ],
            "params" => $params,
        ];
    }

    public function generateDraft(array $payload): array
    {
        $provider = $payload["provider"] ?? null;
        $model = $payload["model"] ?? null;
        $allegati = $payload["attachments"] ?? [];
        $usePublicLinks = !empty($payload["use_public_links"]);
        $useTempFiles = !empty($payload["download_attachments"]);
        $params = $payload["params"] ?? [];
        $numAperte = (int)($payload["num_open"] ?? 0);
        $numChiuseM = (int)($payload["num_closed_medium"] ?? 5);
        $numChiuseD = (int)($payload["num_closed_hard"] ?? 5);
        $numChiuseX = (int)($payload["num_closed_expert"] ?? 5);
        $overridePrompt = trim($payload["override_prompt"] ?? "");

        if (!$provider || !$model) {
            throw new Exception("provider e model sono obbligatori");
        }
        $totDomande = $numAperte + $numChiuseM + $numChiuseD + $numChiuseX;
        if ($totDomande > self::MAX_QUESTIONS) {
            throw new Exception("Numero di domande richiesto troppo alto ({$totDomande}), max " . self::MAX_QUESTIONS);
        }

        $tmpInfo = null;
        $drivePermissionIds = [];
        $downloadedFiles = [];

        // Gestione allegati: download opzionale + apertura temporanea su Drive
        if (!empty($allegati)) {
            if ($useTempFiles) {
                $tmpInfo = $this->fileManager->createAITempFolder();
                $downloadedFiles = $this->downloadAttachmentsToTmp($allegati, $tmpInfo["path"]);
            }
            if ($usePublicLinks) {
                foreach ($allegati as $item) {
                    if (!empty($item["drive_file_id"])) {
                        $permId = $this->drive->makeFileTemporarilyPublic($item["drive_file_id"]);
                        if ($permId) {
                            $drivePermissionIds[] = [
                                "fileId" => $item["drive_file_id"],
                                "permissionId" => $permId,
                            ];
                        }
                    } elseif (!empty($item["url"])) {
                        $driveId = $this->extractDriveIdFromUrl($item["url"]);
                        if ($driveId) {
                            $permId = $this->drive->makeFileTemporarilyPublic($driveId);
                            if ($permId) {
                                $drivePermissionIds[] = [
                                    "fileId" => $driveId,
                                    "permissionId" => $permId,
                                ];
                            }
                        }
                    }
                }
            }
        }

        $params['use_public_links'] = $usePublicLinks;
        $prompt = $overridePrompt !== "" ? $overridePrompt : $this->buildPrompt($params, [
            "open" => $numAperte,
            "closed_medium" => $numChiuseM,
            "closed_hard" => $numChiuseD,
            "closed_expert" => $numChiuseX,
        ]);

        $aiRaw = $this->callProviderSafe($provider, $model, $prompt, $downloadedFiles);

        $response = [
            "status" => empty($aiRaw["error"]) ? "ok" : "error",
            "message" => $aiRaw["error"] ?? "OK",
            "provider" => $provider,
            "model" => $model,
            "attachments_tmp" => $tmpInfo,
            "attachments_public" => $drivePermissionIds,
            "attachments_downloaded" => $downloadedFiles,
            "params" => $params,
            "prompt" => $prompt,
            "requested" => [
                "open" => $numAperte,
                "closed_medium" => $numChiuseM,
                "closed_hard" => $numChiuseD,
                "closed_expert" => $numChiuseX,
            ],
            "ai_raw" => $aiRaw,
            "permissions_info" => [
                "made_public" => count($drivePermissionIds),
                "reverted" => 0
            ]
        ];

        if (!empty($drivePermissionIds)) {
            $rev = 0;
            foreach ($drivePermissionIds as $perm) {
                if (!empty($perm["fileId"]) && !empty($perm["permissionId"])) {
                    $this->drive->revertPublicPermission($perm["fileId"], $perm["permissionId"]);
                    $rev++;
                }
            }
            $response["permissions_info"]["reverted"] = $rev;
        }

        if (!empty($tmpInfo["uid"])) {
            $this->fileManager->cleanupAITempFolder($tmpInfo["uid"]);
        }

        return $response;
    }

    /**
     * Lista modelli dal provider (best effort).
     */
    private function fetchModelsFromProvider(string $provider, string $apiKey): array
    {
        switch ($provider) {
            case 'openai':
                $resp = $this->http->get('https://api.openai.com/v1/models', [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey]
                ]);
                $data = json_decode($resp->getBody(), true);
                return array_values(array_filter(array_map(fn($m) => $m['id'] ?? null, $data['data'] ?? [])));
            case 'claude':
                $resp = $this->http->get('https://api.anthropic.com/v1/models', [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01'
                    ]
                ]);
                $data = json_decode($resp->getBody(), true);
                return array_values(array_filter(array_map(fn($m) => $m['id'] ?? null, $data['data'] ?? [])));
            case 'openrouter':
                $resp = $this->http->get('https://openrouter.ai/api/v1/models', [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey]
                ]);
                $data = json_decode($resp->getBody(), true);
                return array_values(array_filter(array_map(fn($m) => $m['id'] ?? null, $data['data'] ?? [])));
            case 'gemini':
                // AI Studio list models
                $resp = $this->http->get('https://generativelanguage.googleapis.com/v1beta/models', [
                    'query' => ['key' => $apiKey]
                ]);
                $data = json_decode($resp->getBody(), true);
                return array_values(array_filter(array_map(function ($m) {
                    $name = $m['name'] ?? null;
                    if ($name && str_starts_with($name, 'models/')) {
                        $name = substr($name, 7);
                    }
                    return $name;
                }, $data['models'] ?? [])));
            default:
                return $this->listModels($provider);
        }
    }

    /**
     * Chiamata AI protetta: ritorna testo o errore.
     */
    private function callProviderSafe(string $provider, string $model, string $prompt, array $attachments = []): array
    {
        try {
            $apiKey = $this->aiConfig['api_key'] ?? null;
            if (empty($apiKey)) {
                return ['error' => 'API key non configurata per il provider selezionato.'];
            }
            $uploadInfo = ['file_ids' => [], 'errors' => []];
            $promptWithAttachments = $prompt;
            if (!empty($attachments) && $provider === 'openai') {
                $uploadInfo = $this->uploadAttachmentsToOpenAI($apiKey, $attachments);
                if (!empty($uploadInfo['file_ids'])) {
                    $promptWithAttachments .= "\n\nFile allegati (ID OpenAI): " . implode(', ', $uploadInfo['file_ids']);
                }
            }
            $content = '';
            switch ($provider) {
                case 'openai':
                    $resp = $this->http->post('https://api.openai.com/v1/chat/completions', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type' => 'application/json'
                        ],
                        'json' => [
                            'model' => $model,
                            'max_tokens' => self::MAX_TOKENS,
                            'temperature' => 0.7,
                            'messages' => [
                                ['role' => 'user', 'content' => $promptWithAttachments]
                            ]
                        ]
                    ]);
                    $data = json_decode($resp->getBody(), true);
                    $content = $data['choices'][0]['message']['content'] ?? '';
                    break;
                case 'claude':
                    $resp = $this->http->post('https://api.anthropic.com/v1/messages', [
                        'headers' => [
                            'x-api-key' => $apiKey,
                            'anthropic-version' => '2023-06-01',
                            'content-type' => 'application/json'
                        ],
                        'json' => [
                            'model' => $model,
                            'max_tokens' => self::MAX_TOKENS,
                            'temperature' => 0.7,
                            'messages' => [
                                ['role' => 'user', 'content' => $promptWithAttachments]
                            ]
                        ]
                    ]);
                    $data = json_decode($resp->getBody(), true);
                    $content = $data['content'][0]['text'] ?? '';
                    break;
                case 'openrouter':
                    $resp = $this->http->post('https://openrouter.ai/api/v1/chat/completions', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type' => 'application/json'
                        ],
                        'json' => [
                            'model' => $model,
                            'max_tokens' => self::MAX_TOKENS,
                            'temperature' => 0.7,
                            'messages' => [
                                ['role' => 'user', 'content' => $promptWithAttachments]
                            ]
                        ]
                    ]);
                    $data = json_decode($resp->getBody(), true);
                    $content = $data['choices'][0]['message']['content'] ?? '';
                    break;
                case 'gemini':
                    $variants = [];
                    // modelli prioritari
                    $variants[] = $model;
                    // fallback flash
                    $variants[] = 'gemini-2.5-flash';
                    //$variants[] = 'gemini-2.5-flash-latest';
                    // rimuovi duplicati
                    $variants = array_values(array_unique($variants));

                    $geminiResp = null;
                    $lastErr = null;
                    foreach ($variants as $mid) {
                        foreach (['v1beta', 'v1'] as $apiVer) {
                            $m = $mid;
                            if (strpos($m, 'models/') !== 0) {
                                $m = 'models/' . $m;
                            }
                            try {
                                $resp = $this->http->post("https://generativelanguage.googleapis.com/{$apiVer}/{$m}:generateContent?key={$apiKey}", [
                                    'headers' => ['Content-Type' => 'application/json'],
                                    'json' => [
                                        'contents' => [
                                            [
                                        'parts' => [
                                            ['text' => $promptWithAttachments]
                                        ]
                                    ]
                                ]
                            ]
                        ]);
                                $geminiResp = json_decode($resp->getBody(), true);
                                $content = $geminiResp['candidates'][0]['content']['parts'][0]['text'] ?? '';
                                $lastErr = null;
                                break 2; // esce da entrambi i loop
                            } catch (GuzzleException | Exception $ge) {
                                $lastErr = $ge;
                                continue;
                            }
                        }
                    }
                    if ($lastErr !== null && $geminiResp === null) {
                        throw $lastErr;
                    }
                    break;
                default:
                    return ['error' => 'Provider non supportato per la chiamata AI.'];
            }
            return ['content' => $content, 'upload_info' => $uploadInfo];
        } catch (GuzzleException | Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Carica i file sul provider OpenAI (endpoint files, purpose=assistants) e restituisce gli ID.
     */
    private function uploadAttachmentsToOpenAI(string $apiKey, array $attachments): array
    {
        $fileIds = [];
        $errors = [];
        foreach ($attachments as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            try {
                $resp = $this->http->post('https://api.openai.com/v1/files', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'multipart' => [
                        [
                            'name' => 'purpose',
                            'contents' => 'assistants'
                        ],
                        [
                            'name' => 'file',
                            'contents' => fopen($path, 'rb'),
                            'filename' => basename($path)
                        ]
                    ]
                ]);
                $data = json_decode($resp->getBody(), true);
                if (!empty($data['id'])) {
                    $fileIds[] = $data['id'];
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        return ['file_ids' => $fileIds, 'errors' => $errors];
    }

    /**
     * Scarica gli allegati (url) nella cartella temporanea e ritorna lista file locali.
     */
    private function downloadAttachmentsToTmp(array $allegati, string $tmpPath): array
    {
        $saved = [];
        foreach ($allegati as $item) {
            $driveId = $item['drive_file_id'] ?? null;
            try {
                if (is_string($driveId) && preg_match('/^[A-Za-z0-9_-]{10,}$/', $driveId)) {
                    $name = $driveId . '.bin';
                    $dest = rtrim($tmpPath, '/\\') . DIRECTORY_SEPARATOR . $name;
                    $this->drive->downloadFile($driveId, $dest);
                } else {
                    continue;
                }
                $tmpReal = realpath($tmpPath);
                $destReal = realpath($dest);
                if ($tmpReal === false || $destReal === false
                    || !str_starts_with($destReal . DIRECTORY_SEPARATOR, $tmpReal . DIRECTORY_SEPARATOR)) {
                    @unlink($dest);
                    continue;
                }
                $size = filesize($destReal);
                if ($size === false) continue;
                $sizeMb = $size / (1024 * 1024);
                if ($sizeMb > self::MAX_ATTACH_MB) {
                    @unlink($destReal);
                    continue;
                }
                $saved[] = $destReal;
            } catch (Exception $e) {
                // ignora singolo errore, prosegue
                continue;
            }
        }
        return $saved;
    }

    /**
     * Costruisce un prompt di generazione coerente con il formato di import (JSON domande).
     */
    private function buildPrompt(array $params, array $counts): string
    {
        $argomento     = trim($params['argomento_text'] ?? '');
        $disciplina    = trim($params['disciplina_text'] ?? '');
        $destinatari   = trim($params['destinatari_text'] ?? '');
        $metodologia   = trim($params['metodologia_text'] ?? '');
        $objsSelected  = $params['obiettivi_selected'] ?? [];
        $allegatiSel   = $params['attachments_selected'] ?? [];
        $profName      = trim($params['prof_name'] ?? '');
        $usePublic     = !empty($params['use_public_links']);

        $parts = [];
        if (!empty($params['include_prof']) && $profName !== '') {
            $parts[] = "Sono il prof. {$profName} e devo generare un quiz con";
        }

        $totOpen = (int)$counts['open'];
        $totCM   = (int)$counts['closed_medium'];
        $totCH   = (int)$counts['closed_hard'];
        $totCX   = (int)$counts['closed_expert'];

        $blocchi = [];
        if ($totOpen > 0) {
            $blocchi[] = "{$totOpen} domande aperte";
        }
        if ($totCM > 0) {
            $blocchi[] = "{$totCM} domande a risposta multipla (difficoltà media)";
        }
        if ($totCH > 0) {
            $blocchi[] = "{$totCH} domande a risposta multipla (difficoltà difficile)";
        }
        if ($totCX > 0) {
            $blocchi[] = "{$totCX} domande a risposta multipla (difficoltà molto difficile)";
        }
        if (!empty($blocchi)) {
            $parts[] = "esattamente " . implode(', ', $blocchi) . ".";
        } else {
            $parts[] = "domande chiare e ben formulate.";
        }

        if (!empty($params['include_destinatari']) && $destinatari !== '') {
            $parts[] = "I destinatari sono: {$destinatari}.";
        }
        if (!empty($params['include_disciplina']) && $disciplina !== '') {
            $parts[] = "È un quiz di {$disciplina}.";
        }
        if (!empty($params['include_metodologia']) && $metodologia !== '') {
            $parts[] = "Metodologia utilizzata durante l'attività di preparazione: {$metodologia}.";
        }
        if (!empty($params['include_argomento']) && $argomento !== '') {
            $parts[] = "Argomento: {$argomento}.";
        }

        if (!empty($params['include_obiettivi']) && !empty($objsSelected)) {
            $parts[] = "I miei obiettivi didattici/disciplinari sono:\n - " . implode("\n - ", $objsSelected);
        }

        if (!empty($params['include_allegati']) && !empty($allegatiSel)) {
            $attLines = array_map(function ($a) use ($usePublic) {
                $name = $a['name'] ?? ($a['url'] ?? ($a['drive_file_id'] ?? 'allegato'));
                if ($usePublic) {
                    $link = $a['url'] ?? (!empty($a['drive_file_id']) ? "https://drive.google.com/file/d/{$a['drive_file_id']}/view?usp=drivesdk" : '');
                    return $link ? "{$name} [{$link}]" : $name;
                }
                return $name;
            }, $allegatiSel);
            $intro = $usePublic
                ? "Qui di seguito trovi i materiali (usali per creare le domande):"
                : "In allegato trovi i materiali (usali per creare le domande):";
            $parts[] = $intro . "\n - " . implode("\n - ", $attLines);
        }

        $exampleJson = <<<JSON
{
  "id_uda": "UDA_XXX",
  "metadata": {
    "titolo": "Titolo della banca domande",
    "descrizione": "Descrizione opzionale",
    "autore": "Nome Docente",
    "data_creazione": "2025-01-15",
    "versione": "1.0"
  },
  "domande": [
    {
      "argomento": "Nome Argomento",
      "tipo": "aperta",
      "domanda": "Testo della domanda a risposta aperta?",
      "risposta_attesa": "Risposta che ti aspetti dallo studente. Più dettagliata possibile per valutazione oggettiva.",
      "parole_chiave": ["parola1", "parola2", "concetto3"],
      "difficolta": 3,
      "tempo_risposta_min": 5,
      "ordine_consigliato": 1,
      "note": "Note opzionali per l'insegnante"
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "multipla",
      "domanda": "Domanda a scelta multipla (una sola risposta corretta)?",
      "risposte": [
        {"testo": "Prima opzione (errata)", "corretta": false},
        {"testo": "Seconda opzione (corretta)", "corretta": true},
        {"testo": "Terza opzione (errata)", "corretta": false},
        {"testo": "Quarta opzione (errata)", "corretta": false}
      ],
      "spiegazione": "Spiegazione del perché la risposta corretta è quella giusta",
      "parole_chiave": ["concetto", "tema"],
      "difficolta": 2,
      "tempo_risposta_min": 2,
      "ordine_consigliato": 2
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "multipla_multi",
      "domanda": "Domanda con più risposte corrette. Quali delle seguenti affermazioni sono vere?",
      "risposte": [
        {"testo": "Affermazione 1 (vera)", "corretta": true},
        {"testo": "Affermazione 2 (falsa)", "corretta": false},
        {"testo": "Affermazione 3 (vera)", "corretta": true},
        {"testo": "Affermazione 4 (falsa)", "corretta": false}
      ],
      "spiegazione": "Spiegazione delle risposte corrette",
      "difficolta": 4,
      "tempo_risposta_min": 4,
      "ordine_consigliato": 3
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "vero_falso",
      "domanda": "Affermazione da valutare come vera o falsa",
      "risposta_corretta": true,
      "spiegazione": "Spiegazione del perché è vero/falso",
      "parole_chiave": ["concetto"],
      "difficolta": 1,
      "tempo_risposta_min": 1,
      "ordine_consigliato": 4
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "breve",
      "domanda": "Domanda che richiede una risposta breve (una parola o frase corta)?",
      "risposte_accettate": ["risposta1", "sinonimo1", "variante1"],
      "case_sensitive": false,
      "parole_chiave": ["termine", "definizione"],
      "difficolta": 2,
      "tempo_risposta_min": 2,
      "ordine_consigliato": 5
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "numerica",
      "domanda": "Domanda che richiede un valore numerico come risposta?",
      "valore_corretto": 42.5,
      "tolleranza": 0.5,
      "unita_misura": "secondi",
      "spiegazione": "Spiegazione del calcolo",
      "difficolta": 3,
      "tempo_risposta_min": 5,
      "ordine_consigliato": 6
    }
  ]
}
JSON;

        $parts[] = "Il formato del test è un json strutturato in questo modo (rispetta esattamente le chiavi): \n\"\"\"\n" . $exampleJson . "\n\"\"\"";

        $promptParts = [];
        $promptParts[] = implode("\n", $parts);
        //$promptParts[] = "Scrivi come un docente che prepara un compito: frasi naturali, niente gergo tecnico da sviluppatore.";
        //$promptParts[] = "Rispetta rigorosamente il formato JSON dell'esempio seguente (stesse chiavi, stessi tipi).";
        //$promptParts[] = $exampleJson;

        return implode("\n\n", $promptParts);
    }

    /**
     * Estrae l'ID di un file Google Drive da un URL (se presente).
     */
    private function extractDriveIdFromUrl(string $url): ?string
    {
        if (!$url) {
            return null;
        }
        $patterns = [
            '#/d/([a-zA-Z0-9_-]+)#',
            '#id=([a-zA-Z0-9_-]+)#',
            '#file/d/([a-zA-Z0-9_-]+)#'
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $url, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
