<?php

namespace App\Integration;

use App\Core\GoogleTokenProvider;
use Google\Client;
use Google\Service\Forms;
use Google\Service\Drive;
use Google\Service\Forms\Form;
use Google\Service\Forms\Request;
use Google\Service\Forms\BatchUpdateFormRequest;
use Google\Service\Forms\BatchUpdateFormResponse;
use Google\Service\Forms\UpdateFormInfoRequest;
use Google\Service\Forms\Info;
use Google\Service\Forms\FormSettings;
use Google\Service\Forms\QuizSettings;
use Google\Service\Forms\UpdateSettingsRequest;
use Google\Service\Forms\Location;
use Google\Service\Forms\Question;
use Google\Service\Forms\Grading;
use Google\Service\Forms\CorrectAnswers;
use Google\Service\Forms\CorrectAnswer;
use Google\Service\Forms\ChoiceQuestion;
use Google\Service\Forms\Option;
use Google\Service\Forms\TextQuestion;
use Google\Service\Forms\QuestionItem;
use Google\Service\Forms\Item;
use Google\Service\Forms\CreateItemRequest;

/**
 * Costruttore condiviso per Google Forms (quiz) usato da generate_google_form.php e test_wizard.php.
 */
class GoogleFormsBuilder
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Crea un Google Form (quiz) a partire da un array di domande normalizzate.
     * Ritorna: ['formId' => ..., 'editUrl' => ..., 'responderUrl' => ...]
     */
    public function createForm(array $domande, string $titolo, string $descrizione = '', ?string $rootFolderId = null): array
    {
        $client = $this->buildClient();
        $driveService = new Drive($client);
        $formsService = new Forms($client);
        return $this->createFormInternal($formsService, $driveService, $domande, $titolo, $descrizione, $rootFolderId, null);
    }

    /**
     * Crea un Google Form con domande di confidenza abbinate (CBM)
     */
    public function createFormWithConfidence(
        array $domande,
        string $titolo,
        string $descrizione = '',
        ?string $rootFolderId = null,
        array $cbmLevels = []
    ): array {
        $client = $this->buildClient();
        $driveService = new Drive($client);
        $formsService = new Forms($client);

        return $this->createFormInternal($formsService, $driveService, $domande, $titolo, $descrizione, $rootFolderId, $cbmLevels);
    }

    /**
     * Corpo comune per creare il form; se $cbmLevels è fornito, aggiunge domande di confidenza
     * e restituisce anche il mapping domanda/confidenza.
     */
    private function createFormInternal(
        Forms $formsService,
        Drive $driveService,
        array $domande,
        string $titolo,
        string $descrizione = '',
        ?string $rootFolderId = null,
        ?array $cbmLevels = null
    ): array {
        $cbmEnabled = is_array($cbmLevels) && !empty($cbmLevels);
        $normalizedTitle = $this->normalizeDisplayedText($titolo);
        $normalizedDescription = $this->normalizeDisplayedText($descrizione);

        // Se configurato un template, duplica; altrimenti crea da zero
        $defaultTemplateId = trim($this->config['google']['forms']['template_id'] ?? '');
        $cbmTemplateId = trim($this->config['google']['forms']['template_id_cbm'] ?? '');
        $templateId = $cbmEnabled && $cbmTemplateId !== '' ? $cbmTemplateId : $defaultTemplateId;
        $forceRequired = !($cbmEnabled && $templateId !== '');
        if ($templateId !== '') {
            try {
                $copyMeta = new Drive\DriveFile();
                $copyMeta->setName($normalizedTitle);
                $copied = $driveService->files->copy($templateId, $copyMeta);
                $formId = $copied->getId();
            } catch (\Throwable $e) {
                // fallback: crea nuovo se copia fallisce
                $formId = null;
            }
        }

        if (empty($formId)) {
            $form = new Forms\Form();
            $info = new Forms\Info();
            $info->setTitle($normalizedTitle);
            $info->setDocumentTitle($normalizedTitle);
            $form->setInfo($info);

            $created = $formsService->forms->create($form);
            $formId = $created->getFormId();
        }

        // Richieste per info + quiz + domande
        $requests = [];

        // Imposta quiz
        $settingsReq = new Forms\Request();
        $formSettings = new Forms\FormSettings();
        $quizSettings = new Forms\QuizSettings();
        $quizSettings->setIsQuiz(true);
        $formSettings->setQuizSettings($quizSettings);
        // Raccogli indirizzi email verificate (se supportato)
        $formSettings->setEmailCollectionType('VERIFIED');
        $updateSettings = new Forms\UpdateSettingsRequest();
        $updateSettings->setSettings($formSettings);
        $updateSettings->setUpdateMask('quizSettings,emailCollectionType');
        $settingsReq->setUpdateSettings($updateSettings);
        $requests[] = $settingsReq;

        // Aggiorna titolo/descrizione
        $updateInfoReq = new Forms\Request();
        $updateInfo = new Forms\UpdateFormInfoRequest();
        $newInfo = new Forms\Info();
        $newInfo->setTitle($normalizedTitle);
        $newInfo->setDescription($normalizedDescription);
        $updateInfo->setInfo($newInfo);
        $updateInfo->setUpdateMask('title,description');
        $updateInfoReq->setUpdateFormInfo($updateInfo);
        $requests[] = $updateInfoReq;

        // Aggiungi domande (+ eventuale domanda confidenza CBM)
        $requestMap = []; // per recuperare itemId da replies
        $currentIndex = 0;
        foreach (array_values($domande) as $i => $d) {
            $req = $this->buildQuestionRequest($d, $currentIndex, $forceRequired);
            if ($req) {
                $requests[] = $req;
                $requestMap[] = ['type' => 'question', 'domanda' => $d];
                $currentIndex++;
            }
            if ($cbmEnabled) {
                $confReq = $this->buildConfidenceRequest($cbmLevels, $currentIndex, $d['domanda'] ?? "Domanda {$i}+1", $forceRequired);
                if ($confReq) {
                    $requests[] = $confReq;
                    $requestMap[] = ['type' => 'confidence', 'domanda' => $d];
                    $currentIndex++;
                }
            }
        }

        $batchResponse = null;
        if (!empty($requests)) {
            $batch = new Forms\BatchUpdateFormRequest();
            $batch->setRequests($requests);
            $batchResponse = $formsService->forms->batchUpdate($formId, $batch);
        }

        $result = [
            'formId' => $formId,
            'editUrl' => "https://docs.google.com/forms/d/{$formId}/edit",
            'responderUrl' => "https://docs.google.com/forms/d/{$formId}/viewform",
        ];

        // Se CBM, ricava mapping dalle replies
        if ($cbmEnabled && !empty($requests)) {
            $replies = $batchResponse instanceof BatchUpdateFormResponse ? $batchResponse->getReplies() : [];
            $mapping = [];
            $lastQuestionId = null;
            foreach ($replies as $idx => $rep) {
                $itemId = null;
                $createItemResp = $rep->getCreateItem();
                if (is_object($createItemResp) && method_exists($createItemResp, 'getItemId')) {
                    $itemId = $createItemResp->getItemId();
                } else {
                    $item = $createItemResp ? $createItemResp->getItem() : null;
                    if (is_object($item) && method_exists($item, 'getItemId')) {
                        $itemId = $item->getItemId();
                    }
                }
                if (!$itemId || !isset($requestMap[$idx])) {
                    continue;
                }
                if ($requestMap[$idx]['type'] === 'question') {
                    $lastQuestionId = $itemId;
                } elseif ($requestMap[$idx]['type'] === 'confidence' && $lastQuestionId) {
                    $mapping[$lastQuestionId] = $itemId;
                    $lastQuestionId = null;
                }
            }
            $result['cbm_config'] = [
                'mapping' => $mapping,
                'levels' => $cbmLevels
            ];
        }

        // Sposta il file nella cartella root se richiesto
        if (!empty($rootFolderId)) {
            try {
                $fileMeta = $driveService->files->get($formId, ['fields' => 'parents']);
                $prevParents = $fileMeta->getParents();
                $prevParentsStr = is_array($prevParents) ? implode(',', $prevParents) : '';
                $driveService->files->update($formId, new Drive\DriveFile(), [
                    'addParents' => $rootFolderId,
                    'removeParents' => $prevParentsStr
                ]);
                $result['movedToFolder'] = true;
            } catch (\Throwable $e) {
                $result['movedToFolder'] = false;
                $result['moveError'] = $e->getMessage();
            }
        }

        return $result;
    }

    private function buildClient(): Client
    {
        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setScopes([
            Forms::FORMS_BODY,
            Forms::FORMS_RESPONSES_READONLY,
            Drive::DRIVE_FILE
        ]);
        // Credenziali dal DB integrazioni (inline JSON) oppure da file di fallback
        $credentialsJson = $this->config['google']['credentials_json'] ?? null;
        $credentialsPath = $this->config['google']['credentials_file'] ?? ($this->config['credentials_file'] ?? 'config/google_credentials.json');
        $fullCredentialsPath = ROOT_PATH . '/' . ltrim($credentialsPath, '/');

        if ($credentialsJson) {
            // Decodifica e setAuthConfigFromJson
            if (is_string($credentialsJson)) {
                $decoded = json_decode($credentialsJson, true);
            } else {
                $decoded = $credentialsJson;
            }
            if (!is_array($decoded)) {
                throw new \RuntimeException("Credenziali Google non valide (JSON).");
            }
            $client->setAuthConfig($decoded);
        } elseif (file_exists($fullCredentialsPath)) {
            $client->setAuthConfig($fullCredentialsPath);
        } else {
            throw new \RuntimeException("Credenziali Google non trovate. Configura le integrazioni Google.");
        }

        $tokenData = GoogleTokenProvider::getToken($this->config);
        if (empty($tokenData)) {
            throw new \RuntimeException(
                "Token Google non trovato per l'utente corrente. " .
                "Completa l'autenticazione da google_auth.php."
            );
        }

        $client->setAccessToken($tokenData);
        return $client;
    }

    private function normalizeDisplayedText(?string $text): string
    {
        $text = (string)$text;
        if ($text === '') {
            return '';
        }
        $text = str_replace(["\r\n", "\n", "\r"], ' ', $text);
        return trim($text);
    }

    /**
     * Costruisce la richiesta CreateItem per una domanda.
     */
    private function buildQuestionRequest(array $domanda, int $index, bool $forceRequired = true): ?Forms\Request
    {
        $req = new Forms\Request();
        $location = new Forms\Location();
        $location->setIndex($index);

        $question = new Forms\Question();
        if ($forceRequired) {
            $question->setRequired(true);
        }

        $questionText = $this->normalizeDisplayedText($domanda['domanda'] ?? '');
        $tipo = $domanda['tipo'] ?? ($domanda['tipo_domanda'] ?? 'aperta');
        $punteggio = isset($domanda['punteggio']) ? floatval($domanda['punteggio']) : 1;

        // Impostazioni grading
        $grading = new Forms\Grading();
        $grading->setPointValue($punteggio);
        $correctAnswers = new Forms\CorrectAnswers();
        // General feedback (solo se presente testo, altrimenti il servizio rifiuta la richiesta)
        // General feedback disattivato: le API Forms rifiutano feedback su domande autovalutate (error 400).

        switch ($tipo) {
            case 'multipla':
            case 'multipla_multi':
                $isMulti = ($tipo === 'multipla_multi');
                $choiceQuestion = new Forms\ChoiceQuestion();
                $choiceQuestion->setType($isMulti ? 'CHECKBOX' : 'RADIO');
                $choiceQuestion->setShuffle(true); // ordine casuale delle risposte

                $options = [];
                $correctOptions = [];
                if (!empty($domanda['risposte']) && is_array($domanda['risposte'])) {
                    foreach ($domanda['risposte'] as $r) {
                        $value = $this->normalizeDisplayedText($r['testo'] ?? '');
                        if ($value === '') {
                            continue;
                        }
                        $opt = new Forms\Option();
                        $opt->setValue($value);
                        $options[] = $opt;
                        if ($r['corretta'] ?? false) {
                            $correctOptions[] = $value;
                        }
                    }
                } else {
                    // fallback da risposta_attesa con [CORRETTA]
                    $parts = explode(';', $domanda['risposta_attesa'] ?? '');
                    foreach ($parts as $p) {
                        $p = trim($p);
                        $isCorr = false;
                        if (stripos($p, '[CORRETTA]') === 0) {
                            $isCorr = true;
                            $p = trim(str_ireplace('[CORRETTA]', '', $p));
                        }
                        $p = $this->normalizeDisplayedText($p);
                        if ($p === '') continue;
                        $opt = new Forms\Option();
                        $opt->setValue($p);
                        $options[] = $opt;
                        if ($isCorr) {
                            $correctOptions[] = $p;
                        }
                    }
                }
                // Se non ci sono opzioni valide, salta la domanda per evitare errore API
                if (empty($options)) {
                    return null;
                }
                // Google richiede almeno una risposta corretta per le domande autovalutate
                if (empty($correctOptions)) {
                    $correctOptions[] = $options[0]->getValue();
                }
                $choiceQuestion->setOptions($options);
                $question->setChoiceQuestion($choiceQuestion);

                $caList = [];
                foreach ($correctOptions as $c) {
                    $ca = new Forms\CorrectAnswer();
                    $ca->setValue($c);
                    $caList[] = $ca;
                }
                if (!empty($caList)) {
                    $correctAnswers->setAnswers($caList);
                }
                break;

            case 'vero_falso':
                $choiceQuestion = new Forms\ChoiceQuestion();
                $choiceQuestion->setType('RADIO');
                $optTrue = new Forms\Option(); $optTrue->setValue('VERO');
                $optFalse = new Forms\Option(); $optFalse->setValue('FALSO');
                $choiceQuestion->setOptions([$optTrue, $optFalse]);
                $question->setChoiceQuestion($choiceQuestion);
                $ca = new Forms\CorrectAnswer();
                $ca->setValue(($domanda['risposta_corretta'] ?? false) ? 'VERO' : 'FALSO');
                $correctAnswers->setAnswers([$ca]);
                break;

            case 'breve':
                $textQuestion = new Forms\TextQuestion();
                $textQuestion->setParagraph(false);
                $question->setTextQuestion($textQuestion);
                $accettate = $domanda['risposte_accettate'] ?? [];
                $caList = [];
                foreach ($accettate as $acc) {
                    $acc = $this->normalizeDisplayedText((string)$acc);
                    if ($acc === '') {
                        continue;
                    }
                    $ca = new Forms\CorrectAnswer();
                    $ca->setValue($acc);
                    $caList[] = $ca;
                }
                if (!empty($caList)) {
                    $correctAnswers->setAnswers($caList);
                }
                break;

            case 'numerica':
                $textQuestion = new Forms\TextQuestion();
                $textQuestion->setParagraph(false);
                $question->setTextQuestion($textQuestion);
                $ca = new Forms\CorrectAnswer();
                $ca->setValue($this->normalizeDisplayedText((string)($domanda['valore_corretto'] ?? '')));
                $correctAnswers->setAnswers([$ca]);
                break;

            default: // aperta
                $textQuestion = new Forms\TextQuestion();
                $textQuestion->setParagraph(true);
                $question->setTextQuestion($textQuestion);
                break;
        }

        $grading->setCorrectAnswers($correctAnswers);
        $question->setGrading($grading);

        $questionItem = new Forms\QuestionItem();
        $questionItem->setQuestion($question);

        $item = new Forms\Item();
        $item->setTitle($questionText);
        $item->setDescription($this->normalizeDisplayedText($domanda['argomento'] ?? ''));
        $item->setQuestionItem($questionItem);

        $createItem = new Forms\CreateItemRequest();
        $createItem->setItem($item);
        $createItem->setLocation($location);

        $req->setCreateItem($createItem);
        return $req;
    }

    /**
     * Costruisce la domanda di confidenza CBM (scelta singola) subito dopo una domanda principale.
     */
    private function buildConfidenceRequest(array $cbmLevels, int $index, string $label = '', bool $forceRequired = true): ?Forms\Request
    {
        $req = new Request();
        $location = new Location();
        $location->setIndex($index);

        $question = new Question();
        if ($forceRequired) {
            $question->setRequired(true);
        }

        $choiceQuestion = new ChoiceQuestion();
        $choiceQuestion->setType('RADIO');
        $options = [];
        foreach ($cbmLevels as $code => $def) {
            $opt = new Option();
            $opt->setValue($this->normalizeDisplayedText($def['label'] ?? strtoupper($code)));
            $options[] = $opt;
        }
        if (empty($options)) {
            return null;
        }
        $choiceQuestion->setOptions($options);
        $question->setChoiceQuestion($choiceQuestion);

        $qi = new QuestionItem();
        $qi->setQuestion($question);

        $item = new Item();
        $item->setTitle('Quanto sei sicuro della risposta?');
        $item->setDescription($this->normalizeDisplayedText($label));
        $item->setQuestionItem($qi);

        $create = new CreateItemRequest();
        $create->setItem($item);
        $create->setLocation($location);

        $req->setCreateItem($create);
        return $req;
    }
}
