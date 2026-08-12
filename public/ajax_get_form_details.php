<?php
/**
 * Endpoint AJAX per leggere i dettagli di un Google Form
 */

// Avvia buffer output e disabilita error display
ob_start();
error_reporting(0);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\GoogleTokenProvider;
use App\Core\Database\DatabaseFactory;
use App\Core\UserIntegrationManager;
use Google\Client as GoogleClient;
use Google\Service\Forms;

// Pulisci eventuali output precedenti e invia header
ob_clean();
header('Content-Type: application/json');

try {
    $url = $_GET['url'] ?? '';
    
    if (empty($url)) {
        throw new Exception("URL non fornito");
    }
    
    // Estrai ID dal URL
    $formId = null;
    
    if (preg_match('/forms\/d\/([a-zA-Z0-9_-]+)\/edit/', $url, $matches)) {
        $formId = $matches[1];
    } elseif (preg_match('/forms\/d\/e\/([a-zA-Z0-9_-]+)\/viewform/', $url, $matches)) {
        $formId = $matches[1];
    } elseif (preg_match('/forms\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $formId = $matches[1];
    }
    
    if (!$formId) {
        throw new Exception("Impossibile estrarre l'ID del form dall'URL. Usa il link di modifica (con /edit).");
    }
    
    // Inizializza Google Client
    $client = new GoogleClient();
    $client->setApplicationName('UDA System');
    $client->setScopes([Forms::FORMS_BODY_READONLY, Forms::FORMS_RESPONSES_READONLY]);

    // Usa percorsi assoluti
    $credentialsPath = ROOT_PATH . '/' . $config['google']['credentials_file'];
    $tokenPath = ROOT_PATH . '/' . $config['google']['token_file'];

    if (!file_exists($credentialsPath)) {
        throw new Exception("File credenziali Google non trovato. Configura le credenziali Google API.");
    }

    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');

    $tokenData = GoogleTokenProvider::getToken($config);
    if (empty($tokenData['access_token'])) {
        throw new Exception("Token Google non trovato. Autorizza l'applicazione prima di usare questa funzione.");
    }

    $client->setAccessToken($tokenData);

    // Verifica se il token è scaduto e refresha se necessario
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $updatedToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (!empty($updatedToken['access_token'])) {
                $mergedToken = array_merge($tokenData, $updatedToken);
                if (empty($mergedToken['refresh_token'])) {
                    $mergedToken['refresh_token'] = $client->getRefreshToken();
                }
                $mergedToken['created'] = time();

                if (!empty($_SESSION['user_id'])) {
                    $db = DatabaseFactory::createWithInitialization($config, true);
                    $uim = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
                    $googleCfg = $uim->getConfig('google');
                    if (!is_array($googleCfg)) {
                        $googleCfg = [];
                    }
                    $googleCfg['token'] = $mergedToken;
                    $uim->saveConfig('google', $googleCfg, true);
                }

                $tokenData = $mergedToken;
            }
        } else {
            throw new Exception("Token scaduto. Riautorizza l'applicazione.");
        }
    }
    
    // Leggi form
    $formsService = new Forms($client);
    $form = $formsService->forms->get($formId);
    
    // Conta domande (escludi sezioni e separatori)
    $numDomande = 0;
    if ($form->getItems()) {
        foreach ($form->getItems() as $item) {
            if ($item->getQuestionItem()) {
                $numDomande++;
            }
        }
    }
    
    // Calcola punteggio massimo se quiz
    $punteggioMax = 100;
    $isQuiz = false;
    if ($form->getSettings() && $form->getSettings()->getQuizSettings()) {
        $isQuiz = $form->getSettings()->getQuizSettings()->getIsQuiz();
    }
    
    if ($isQuiz && $form->getItems()) {
        $totalPoints = 0;
        foreach ($form->getItems() as $item) {
            if ($item->getQuestionItem()) {
                $question = $item->getQuestionItem()->getQuestion();
                if ($question && method_exists($question, 'getGrading') && $question->getGrading()) {
                    $grading = $question->getGrading();
                    if (method_exists($grading, 'getPointValue')) {
                        $totalPoints += $grading->getPointValue();
                    }
                }
            }
        }
        if ($totalPoints > 0) {
            $punteggioMax = $totalPoints;
        }
    }
    
    // Genera URL pubblico dal formId
    $urlPubblico = "https://docs.google.com/forms/d/{$formId}/viewform";
    
    // Risposta JSON
    echo json_encode([
        'success' => true,
        'data' => [
            'nome' => $form->getInfo()->getTitle(),
            'descrizione' => $form->getInfo()->getDescription() ?: '',
            'num_domande' => $numDomande,
            'punteggio_max' => $punteggioMax,
            'id_esterno' => $formId,
            'url_pubblico' => $urlPubblico,
            'is_quiz' => $isQuiz
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

ob_end_flush();
