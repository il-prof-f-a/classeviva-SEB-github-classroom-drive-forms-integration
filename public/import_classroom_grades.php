<?php
/**
 * Import Voti da Google Classroom Assignment
 * Importa i voti degli studenti da un compito di Google Classroom
 */

error_reporting(E_ALL);

try {
    $config = require_once __DIR__ . '/../bootstrap.php';
} catch (Exception $e) {
    die("Errore caricamento configurazione: " . $e->getMessage());
}

use App\Core\Database\DatabaseFactory;
use App\Integration\GoogleClassroomAPI;
use App\Integration\ClasseVivaAPI;

$cvConfig = $config['classeviva'] ?? [];
$cvTokenPayload = is_array($cvConfig['token'] ?? null) ? $cvConfig['token'] : [];
$cvTokenValid = $cvConfig['token_valid'] ?? false;
$cvTokenError = $cvConfig['token_error'] ?? null;
$cvEnabled = !empty($cvConfig['enabled']);
$cvHasToken = !empty($cvTokenPayload['token']);
$cvReady = $cvEnabled && $cvHasToken && $cvTokenValid;
$cvTokenNotice = null;

if (!$cvReady) {
    if (!$cvEnabled) {
        $cvTokenNotice = 'ClasseViva disabilitato: abilita l’integrazione per leggere gli studenti.';
    } elseif (!$cvHasToken) {
        $cvTokenNotice = 'Token ClasseViva non presente. Autorizza la sessione da Integrazioni.';
    } else {
        $cvTokenNotice = $cvTokenError
            ? "Token ClasseViva non valido: {$cvTokenError}"
            : 'Token ClasseViva scaduto o non valido. Rigeneralo nelle Integrazioni.';
    }
}

$classeVivaAPI = $cvReady ? new ClasseVivaAPI($config) : null;

try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
} catch (Exception $e) {
    die("Errore inizializzazione database: " . $e->getMessage());
}

/**
 * Calcola il voto più vicino nella scala ClasseViva
 * @param float $rawGrade Voto grezzo (0-100)
 * @param float $maxPoints Punteggio massimo
 * @return string Voto arrotondato (es: "6.5", "7", etc)
 */
function getNearestClasseVivaGrade($rawGrade, $maxPoints) {
    if ($rawGrade === null) {
        return 'skip';
    }

    // Converti a scala 0-10
    $voto = ($rawGrade / $maxPoints) * 10;

    // Arrotonda al mezzo punto più vicino
    $voto = round($voto * 2) / 2;

    // Limita tra 1 e 10
    $voto = max(1, min(10, $voto));

    return number_format($voto, 1);
}

/**
 * Genera l'array di voti possibili per ClasseViva
 * @return array Array di voti possibili
 */
function getClasseVivaGrades() {
    $grades = [];

    // Voti da 1 a 10 con mezzi punti
    for ($i = 1; $i <= 10; $i += 0.5) {
        $grades[] = number_format($i, 1);
    }

    // Aggiungi voti speciali
    $grades[] = 'i'; // impreparato
    $grades[] = 'a'; // assente
    $grades[] = 'skip'; // non importare

    return $grades;
}

function normalizeNameTokens(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return [];
    }

    if (function_exists('mb_strtolower')) {
        $name = mb_strtolower($name);
    } else {
        $name = strtolower($name);
    }

    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($translit !== false && $translit !== null) {
        $name = $translit;
    }

    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
    $parts = array_filter(explode(' ', $name), static fn($part) => $part !== '');
    $stopwords = ['prof', 'prof.', 'professore', 'professoressa', 'docente'];
    $filtered = array_filter($parts, static fn($part) => !in_array($part, $stopwords, true));

    return array_values(array_unique($filtered));
}

function normalizeComparableString(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text);
    } else {
        $text = strtolower($text);
    }

    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($translit !== false && $translit !== null) {
        $text = $translit;
    }

    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = trim($text);

    return $text;
}

function textMatchesTokens(string $text, array $tokens): bool
{
    if ($text === '' || empty($tokens)) {
        return false;
    }

    $normalized = normalizeComparableString($text);
    if ($normalized === '') {
        return false;
    }

    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
        if (strpos($normalized, $token) !== false) {
            return true; // match parziale (any token)
        }
    }

    return false;
}

function emailMatchesTokens(string $email, array $tokens): bool
{
    if ($email === '' || empty($tokens)) {
        return false;
    }

    $email = strtolower($email);
    $localPart = strstr($email, '@', true);
    $haystack = $localPart !== false ? $localPart : $email;

    return textMatchesTokens($haystack, $tokens);
}

function getLatestTeacherComment(
    array $comments,
    GoogleClassroomAPI $classroomAPI,
    array $profTokens,
    ?string $teacherUserId
): ?string
{
    $latestText = null;
    $latestTs = null;
    $latestAnyText = null;
    $latestAnyTs = null;

    foreach ($comments as $comment) {
        $authorId = $comment['author_user_id'] ?? '';
        if ($authorId === '') {
            continue;
        }

        if ($teacherUserId && $authorId === $teacherUserId) {
            $timestamp = $comment['update_time'] ?? $comment['create_time'] ?? null;
            $ts = $timestamp ? strtotime($timestamp) : 0;
            if ($latestTs === null || $ts > $latestTs) {
                $latestTs = $ts;
                $latestText = $comment['text'] ?? '';
            }
            continue;
        }

        $profile = $classroomAPI->getUserProfile($authorId);
        $authorEmail = $profile['email'] ?? '';
        $authorName = $profile['name'] ?? '';

        $matches = false;
        if (!empty($profTokens)) {
            $matches = emailMatchesTokens($authorEmail, $profTokens)
                || textMatchesTokens($authorName, $profTokens);
        }

        if (!$matches) {
            $timestampAny = $comment['update_time'] ?? $comment['create_time'] ?? null;
            $tsAny = $timestampAny ? strtotime($timestampAny) : 0;
            if ($latestAnyTs === null || $tsAny > $latestAnyTs) {
                $latestAnyTs = $tsAny;
                $latestAnyText = $comment['text'] ?? '';
            }
            continue;
        }

        $timestamp = $comment['update_time'] ?? $comment['create_time'] ?? null;
        $ts = $timestamp ? strtotime($timestamp) : 0;

        if ($latestTs === null || $ts > $latestTs) {
            $latestTs = $ts;
            $latestText = $comment['text'] ?? '';
        }
    }

    if ($latestText !== null) {
        return $latestText;
    }

    // Fallback: se non matcha il docente ma esistono commenti, prendi il più recente
    return $latestAnyText;
}

// Parametri
$testId = $_GET['test_id'] ?? null;
$step = $_GET['step'] ?? 'start';

if (!$testId) {
    die("ID test mancante");
}

// Carica test
$test = $dbAdapter->findOne('TEST', 'id_test', $testId);
if (!$test) {
    die("Test non trovato");
}

// Verifica che sia un assignment Classroom
$isClassroom = str_contains(strtolower($test['piattaforma'] ?? ''), 'classroom');
if (!$isClassroom) {
    die("Questo test non è un compito di Google Classroom");
}
function decodeGoogleClassroomId(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    // Già numerico (forma API)
    if (ctype_digit($value)) {
        return $value;
    }

    // Nei link classroom.google.com spesso viene usato base64 URL-safe senza padding
    $b64 = strtr($value, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    $decoded = base64_decode($b64, true);
    if ($decoded === false) {
        return $value;
    }

    $decoded = trim($decoded);
    if ($decoded !== '' && ctype_digit($decoded)) {
        return $decoded;
    }

    return $value;
}

function extractClassroomIdsFromUrl(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['', ''];
    }

    // Esempi:
    // https://classroom.google.com/c/COURSE/a/ASSIGNMENT/details
    // https://classroom.google.com/u/0/c/COURSE/a/ASSIGNMENT/details
    if (preg_match('#classroom\\.google\\.com/(?:u/\\d+/)?c/([^/]+)/a/([^/]+)#i', $url, $m)) {
        $course = decodeGoogleClassroomId($m[1]);
        $assignment = decodeGoogleClassroomId($m[2]);
        return [$course, $assignment];
    }

    return ['', ''];
}

// Fallback: se mancano gli ID, prova a ricavarli dal link salvato nel test
if (empty($test['classroom_course_id']) || empty($test['classroom_assignment_id'])) {
    $candidateUrls = [
        $test['url_docente'] ?? null,
        $test['url'] ?? null,
        $test['url_studenti'] ?? null,
        $test['url_gestione'] ?? null,
    ];

    $derivedCourseId = '';
    $derivedAssignmentId = '';
    foreach ($candidateUrls as $u) {
        if (empty($u)) {
            continue;
        }
        [$c, $a] = extractClassroomIdsFromUrl((string)$u);
        if ($c !== '' && $a !== '') {
            $derivedCourseId = $c;
            $derivedAssignmentId = $a;
            break;
        }
    }

    if ($derivedCourseId !== '' && $derivedAssignmentId !== '') {
        try {
            $dbAdapter->updateRow('TEST', 'id_test', $testId, [
                'classroom_course_id' => $derivedCourseId,
                'classroom_assignment_id' => $derivedAssignmentId,
            ]);
            $test['classroom_course_id'] = $derivedCourseId;
            $test['classroom_assignment_id'] = $derivedAssignmentId;
        } catch (Exception $e) {
            // non bloccare
        }
    }
}

if (empty($test['classroom_course_id']) || empty($test['classroom_assignment_id'])) {
    $errorMessage = "Compito Classroom non configurato: manca course_id o assignment_id. Controlla il test.";
}

$successMessage = null;
// conserva eventuale errore già impostato sopra
if (!isset($errorMessage)) {
    $errorMessage = null;
}
$submissions = [];
$matchedStudents = [];
$profName = trim($config['user_profile']['prof_name'] ?? '');
$profTokens = normalizeNameTokens($profName);
$selectedMappingId = $_GET['mapping_id'] ?? $_POST['mapping_id'] ?? '';
$mappingOptions = [];
$studentsByMapping = [];
try {
    if (!empty($test['classroom_course_id'])) {
        $mappingOptions = $dbAdapter->findWhere('CLASSROOM_MAPPINGS', [
            'id_corso_gc' => $test['classroom_course_id']
        ]);
    }
} catch (\Exception $e) {
    $mappingOptions = [];
}
$selectedMappingId = $_GET['mapping_id'] ?? $_POST['mapping_id'] ?? '';
$mappingOptions = [];
try {
    if (!empty($test['classroom_course_id'])) {
        $mappingOptions = $dbAdapter->findWhere('CLASSROOM_MAPPINGS', [
            'id_corso_gc' => $test['classroom_course_id']
        ]);
    }
} catch (\Exception $e) {
    $mappingOptions = [];
}

// STEP 1: Carica submissions da Classroom
if ($step === 'load_submissions') {
    try {
        if (empty($test['classroom_course_id']) || empty($test['classroom_assignment_id'])) {
            throw new Exception('course_id o assignment_id non configurati per questo compito.');
        }
        $classroomAPI = new GoogleClassroomAPI($config);
        $teacherProfile = $classroomAPI->getUserProfile('me');
        $teacherUserId = $teacherProfile['id'] ?? null;

        // Trova l'associazione Classroom -> ClasseViva
        $mappings = $mappingOptions;

        if (empty($mappings)) {
            throw new Exception("Nessuna associazione trovata tra Classroom e ClasseViva per questo corso. Vai su classroom_mapping.php per configurare l'associazione.");
        }

        // Carica studenti per ogni mapping
        if (!$cvReady || !$classeVivaAPI) {
            throw new Exception($cvTokenNotice ?? 'Token ClasseViva non disponibile per caricare studenti.');
        }

        foreach ($mappings as $m) {
            $mId = $m['id_mapping'] ?? '';
            if (!$mId) {
                continue;
            }
            $cvClass = $m['id_classe_cv'] ?? '';
            try {
                $students = $classeVivaAPI->getStudentiClasse($cvClass);
            } catch (Exception $e) {
                $students = [];
            }
            $studentsByMapping[$mId] = $students;
        }

        if (!$selectedMappingId && !empty($mappings)) {
            $selectedMappingId = $mappings[0]['id_mapping'] ?? '';
        }

        // Seleziona mapping per primo match automatico
        $mapping = null;
        foreach ($mappings as $m) {
            if (($m['id_mapping'] ?? '') === $selectedMappingId) {
                $mapping = $m;
                break;
            }
        }
        if (!$mapping) {
            $mapping = $mappings[0];
        }

        $idClasse = $mapping['id_classe_cv'];
        $idMateria = $mapping['id_materia_cv'];

        if (!$cvReady || !$classeVivaAPI) {
            throw new Exception($cvTokenNotice ?? 'Token ClasseViva non disponibile per caricare studenti.');
        }

        // Carica studenti da ClasseViva per quella classe
        $studentiClasseViva = $classeVivaAPI->getStudentiClasse($idClasse);

        if (empty($studentiClasseViva)) {
            throw new Exception("Nessuno studente trovato in ClasseViva per la classe ID: $idClasse");
        }

        // Carica submissions da Classroom
        $submissions = $classroomAPI->getAssignmentSubmissions(
            $test['classroom_course_id'],
            $test['classroom_assignment_id']
        );

        // Match studenti Classroom con studenti ClasseViva per nome
        foreach ($submissions as $submission) {
            // Carica dati studente da Classroom
            try {
                $studenteClassroom = $classroomAPI->getStudente(
                    $test['classroom_course_id'],
                    $submission['user_id']
                );

                // Cerca match con studenti ClasseViva per nome
                $matched = null;
                $nomeClassroom = strtolower(trim($studenteClassroom['name']));

                foreach ($studentiClasseViva as $studente) {
                    $nomeCV = strtolower(trim($studente['cognome'] . ' ' . $studente['nome']));
                    $nomeCVInv = strtolower(trim($studente['nome'] . ' ' . $studente['cognome']));

                    // Match esatto o con levenshtein
                    if ($nomeClassroom === $nomeCV || $nomeClassroom === $nomeCVInv ||
                        levenshtein($nomeClassroom, $nomeCV) <= 3 ||
                        levenshtein($nomeClassroom, $nomeCVInv) <= 3) {
                        $matched = $studente;
                        break;
                    }
                }

                $matchedStudents[] = [
                    'submission' => $submission,
                    'classroom_student' => $studenteClassroom,
                    'local_student' => $matched,
                    'match_confidence' => $matched ? 'high' : 'none',
                    'teacher_comment' => null
                ];

            } catch (Exception $e) {
                // Studente non trovato in Classroom
                $matchedStudents[] = [
                    'submission' => $submission,
                    'classroom_student' => null,
                    'local_student' => null,
                    'match_confidence' => 'none',
                    'error' => $e->getMessage(),
                    'teacher_comment' => null
                ];
            }
        }

        $step = 'review';

    } catch (Exception $e) {
        $errorMessage = "Errore caricamento submissions: " . $e->getMessage();
        $step = 'start';
    }
}

// STEP 2: Importa voti
if ($step === 'import_grades' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // DEBUG: Log POST data
        $debugLog = "=== DEBUG IMPORT POST ===\n";
        $debugLog .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        $debugLog .= "POST Data:\n";
        $debugLog .= print_r($_POST, true);
        $debugLog .= "\n==================\n\n";
        @file_put_contents(__DIR__ . '/../storage/logs/import_debug.log', $debugLog, FILE_APPEND);

        // Leggi dati POST dal form
        $studentIds = $_POST['student_id'] ?? [];
        $submissionTimes = $_POST['submission_time'] ?? [];
        $importGrades = $_POST['import_grade'] ?? [];
        $commenti = $_POST['commento'] ?? [];
        $tipoVoto = $_POST['tipo_voto'] ?? 'scritto';

        if (empty($studentIds) || empty($importGrades)) {
            throw new Exception("Nessun dato di import ricevuto");
        }

        // Trova l'associazione Classroom -> ClasseViva
        $mappings = $dbAdapter->findWhere('CLASSROOM_MAPPINGS', [
            'id_corso_gc' => $test['classroom_course_id']
        ]);

        if (empty($mappings)) {
            throw new Exception("Nessuna associazione trovata tra Classroom e ClasseViva per questo corso.");
        }

        $chosenMappingId = $_POST['mapping_id'] ?? '';
        $mapping = null;
        if ($chosenMappingId) {
            foreach ($mappings as $m) {
                if (($m['id_mapping'] ?? '') === $chosenMappingId) {
                    $mapping = $m;
                    break;
                }
            }
        }
        if (!$mapping) {
            $mapping = $mappings[0];
        }
        $idClasse = $mapping['id_classe_cv'];
        $idMateria = $mapping['id_materia_cv'];

        $imported = 0;
        $linkOrigine = app_url('public/import_classroom_grades.php?test_id=' . urlencode((string)$testId));
        $skipped = 0;
        $errors = [];
        $testDateRaw = $test['data_somministrazione'] ?? ($test['data_creazione'] ?? null);

        // Itera sui voti selezionati
        if (!$cvReady || !$classeVivaAPI) {
            throw new Exception($cvTokenNotice ?? 'Token ClasseViva non disponibile per importare voti.');
        }

        $studentiClasseViva = $classeVivaAPI->getStudentiClasse($idClasse);
        $studentiClasseMap = [];
        foreach ($studentiClasseViva as $studente) {
            $studentiClasseMap[$studente['id']] = $studente;
        }

        foreach ($studentIds as $idx => $studentId) {
            $selectedGrade = $importGrades[$idx] ?? 'skip';
            $commento = trim((string)($commenti[$idx] ?? ''));

            // Salta se "-non importare voto-"
            if ($selectedGrade === 'skip') {
                $skipped++;
                continue;
            }

            try {
                // Assicurati che lo studente esista nella tabella STUDENTI
                $studenteEsistente = $dbAdapter->findOne('STUDENTI', 'id_studente_cv', $studentId);

                if (!$studenteEsistente) {
                    // Carica info studente da ClasseViva
                    $studenteInfo = $studentiClasseMap[$studentId] ?? null;

                    if ($studenteInfo) {
                        // Aggiungi lo studente alla tabella STUDENTI
                        $studenteData = [
                            'id_studente_cv' => $studentId,
                            'id_classe_cv' => $idClasse,
                            'nome_classe' => $mapping['nome_classe_cv'] ?? '',
                            'data_sincronizzazione' => date('Y-m-d H:i:s'),
                            'attivo' => 1
                        ];

                        try {
                            $dbAdapter->insertRow('STUDENTI', $studenteData);
                        } catch (Exception $e) {
                            // Studente già esistente, continua
                        }
                    }
                }

                // Prepara voto/giudizio
                $voto = null;
                $giudizio = '';

                if ($selectedGrade === 'i') {
                    $giudizio = 'Impreparato';
                } elseif ($selectedGrade === 'a') {
                    $giudizio = 'Assente';
                } else {
                    // Voto numerico
                    $voto = floatval($selectedGrade);
                }

                // DEBUG: Log voto data
                $votoDebug = "=== DEBUG VOTO DATA ===\n";
                $votoDebug .= "Index: $idx\n";
                $votoDebug .= "Student ID from POST: " . var_export($studentId, true) . "\n";
                $votoDebug .= "Selected Grade: $selectedGrade\n";
                $votoDebug .= "Voto numerico: " . var_export($voto, true) . "\n";
                $votoDebug .= "Giudizio: " . var_export($giudizio, true) . "\n";
                $votoDebug .= "ID Classe: $idClasse\n";
                $votoDebug .= "ID Materia: $idMateria\n";
                $votoDebug .= "\n";
                @file_put_contents(__DIR__ . '/../storage/logs/import_debug.log', $votoDebug, FILE_APPEND);

                // Salva voto
                $descrizione = 'Importato da Google Classroom: ' . $test['nome'];
                if ($commento !== '') {
                    $descrizione .= "\nCommento: " . $commento;
                }

                $dataValutazione = null;
                $rawSubmission = $submissionTimes[$idx] ?? null;
                if (!empty($rawSubmission)) {
                    $ts = strtotime((string)$rawSubmission);
                    if ($ts) {
                        $dataValutazione = date('Y-m-d', $ts);
                    }
                }
                if (!$dataValutazione && !empty($testDateRaw)) {
                    $ts = strtotime((string)$testDateRaw);
                    if ($ts) {
                        $dataValutazione = date('Y-m-d', $ts);
                    }
                }
                if (!$dataValutazione) {
                    $dataValutazione = date('Y-m-d');
                }

                $votoData = [
                    'id_voto' => 'VOTO_' . uniqid(),
                    'id_uda' => $test['id_uda'],
                    'id_studente_cv' => $studentId,
                    'id_classe_cv' => $idClasse,
                    'id_materia_cv' => $idMateria,
                    'tipo_voto' => $tipoVoto,
                    'voto' => $voto,
                    'giudizio' => $giudizio,
                    'descrizione' => $descrizione,
                    'data_valutazione' => $dataValutazione,
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'pubblicato' => 0,
                    'id_annotazione_cv' => null,
                    'num_evidenze_positive' => 0,
                    'num_evidenze_negative' => 0,
                    'num_evidenze_totali' => 0,
                    'link_origine' => $linkOrigine
                ];

                // DEBUG: Log final voto data
                $votoDebug2 = "Final votoData array:\n";
                $votoDebug2 .= print_r($votoData, true);
                $votoDebug2 .= "\n==================\n\n";
                @file_put_contents(__DIR__ . '/../storage/logs/import_debug.log', $votoDebug2, FILE_APPEND);

                $dbAdapter->insertRow('VOTI', $votoData);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "Errore per studente ID " . $studentId . ": " . $e->getMessage();
                $skipped++;
            }
        }

        // Marca test come importato
        $dbAdapter->updateRow('TEST', 'id_test', $testId, ['risultati_importati' => 'SI']);

        $successMessage = "Importati $imported voti. $skipped saltati.";
        if (!empty($errors)) {
            $errorMessage = "Alcuni errori: " . implode(', ', array_slice($errors, 0, 5));
        }

        $step = 'complete';

    } catch (Exception $e) {
        $errorMessage = "Errore importazione: " . $e->getMessage();
        $step = 'start';
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Voti Classroom - <?= htmlspecialchars($test['nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-download"></i> Import Voti Google Classroom';
    $pageSubtitle = 'Compito: ' . ($test['nome'] ?? '');
    $headerActions = '<a class="nav-link" href="uda_tests.php?id=' . urlencode($test['id_uda']) . '">'
        . '<i class="bi bi-arrow-left"></i> Torna ai Test</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <!-- Messaggi -->
        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($cvTokenNotice): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <br>
                <small class="text-muted">
                    Vai su <a href="user_integrations.php#classeviva-section">Integrazioni</a> per aggiornare la sessione.
                </small>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>

        <!-- STEP START -->
        <?php if ($step === 'start'): ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Passo 1: Carica Consegne</h5>
                    <p>Carica le consegne degli studenti da Google Classroom. La classe di ClasseViva verrà scelta nel passo successivo.</p>

                    <?php if (!empty($mappingOptions)): ?>
                        <form method="get" class="row g-3 align-items-end">
                            <input type="hidden" name="test_id" value="<?= htmlspecialchars($testId) ?>">
                            <input type="hidden" name="step" value="load_submissions">
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-download"></i> Carica Consegne da Classroom
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            Nessuna mappatura trovata per questo corso Classroom. Configurala in <a href="classroom_mapping.php">classroom_mapping.php</a>.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- STEP REVIEW -->
        <?php if ($step === 'review'): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Passo 2: Revisiona e Correggi Voti</h5>
                    <p>Controlla che gli studenti siano stati matchati correttamente e seleziona il voto da importare per ciascuno.</p>

                    <form method="POST" action="?test_id=<?= urlencode($testId) ?>&step=import_grades">
                        <?php
                        $defaultMappingId = $selectedMappingId ?: ($mappingOptions[0]['id_mapping'] ?? '');
                        if ($defaultMappingId):
                        ?>
                            <input type="hidden" id="mappingIdInput" name="mapping_id" value="<?= htmlspecialchars($defaultMappingId) ?>">
                        <?php endif; ?>
                        <!-- Selezione Tipo Voto -->
                        <div class="mb-3">
                            <label class="form-label"><strong>Tipo di Valutazione:</strong></label>
                            <select name="tipo_voto" class="form-select" style="max-width: 300px;" required>
                                <option value="scritto" selected>Scritto</option>
                                <option value="orale">Orale</option>
                                <option value="pratico">Pratico</option>
                            </select>
                            <small class="text-muted">Questo tipo verrà applicato a tutti i voti importati</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><strong>Classe ClasseViva per assegnare i voti:</strong></label>
                            <?php if (!empty($mappingOptions)): ?>
                                <select id="mappingSelector" class="form-select" style="max-width: 420px;">
                                    <?php foreach ($mappingOptions as $opt): ?>
                                        <?php
                                        $cvClass = ($opt['nome_classe_cv'] ?? '') ?: ($opt['id_classe_cv'] ?? '');
                                        $cvSubject = ($opt['nome_materia_cv'] ?? '') ?: ($opt['id_materia_cv'] ?? '');
                                        $optId = $opt['id_mapping'] ?? '';
                                        $isSelected = ($optId === $defaultMappingId) ? 'selected' : '';
                                        ?>
                                        <option value="<?= htmlspecialchars($optId) ?>" <?= $isSelected ?>>
                                            <?= htmlspecialchars($cvClass) ?> - <?= htmlspecialchars($cvSubject) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">La scelta della classe riassegna automaticamente gli studenti in base al nome.</small>
                            <?php else: ?>
                                <div class="alert alert-warning">Nessuna mappatura trovata per questo corso Classroom.</div>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Studente Classroom</th>
                                        <th>Studente ClasseViva</th>
                                        <th>Voto Compito</th>
                                        <th>Voto Importato</th>
                                        <th>Commento Docente</th>
                                        <th>Stato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $maxPoints = floatval($test['punteggio_max'] ?? 100);
                                    if ($maxPoints == 0) $maxPoints = 100;
                                    $availableGrades = getClasseVivaGrades();

                                    foreach ($matchedStudents as $idx => $match):
                                        $rawGrade = $match['submission']['assigned_grade'];
                                        $suggestedGrade = getNearestClasseVivaGrade($rawGrade, $maxPoints);
                                        $submittedAt = $match['submission']['turned_in_time']
                                            ?? ($match['submission']['updated_time'] ?? ($match['submission']['created_time'] ?? null));
                                        $submittedLabel = $match['submission']['turned_in_time'] ? 'Consegnato' : ($match['submission']['updated_time'] ? 'Aggiornato' : ($match['submission']['created_time'] ? 'Creato' : null));
                                        $submittedAtFormatted = $submittedAt ? date('d/m/Y H:i', strtotime($submittedAt)) : null;
                                        $studentId = $match['local_student']['id'] ?? '';
                                    ?>
                                        <tr class="student-row" data-classroom-name="<?= htmlspecialchars($match['classroom_student']['name'] ?? '') ?>">
                                            <td>
                                                <?php if ($match['classroom_student']): ?>
                                                    <?= htmlspecialchars($match['classroom_student']['name']) ?>
                                                    <?php if ($submittedAtFormatted): ?>
                                                        <br><small class="text-muted"><?= $submittedLabel ?>: <?= $submittedAtFormatted ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <em class="text-muted">Non disponibile</em>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="local-student">
                                                    <?php if ($match['local_student']): ?>
                                                        <span class="badge bg-success local-badge">OK</span>
                                                        <div class="local-name"><?= htmlspecialchars($match['local_student']['nome'] . ' ' . $match['local_student']['cognome']) ?></div>
                                                        <small class="text-muted local-id">ID: <?= htmlspecialchars($studentId) ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark local-badge">Da associare</span>
                                                        <div class="local-name">Da associare</div>
                                                        <small class="text-muted local-id d-none"></small>
                                                    <?php endif; ?>
                                                    <input type="hidden" name="student_id[<?= $idx ?>]" class="student-id-input" value="<?= htmlspecialchars($studentId) ?>">
                                                    <input type="hidden" name="submission_time[<?= $idx ?>]" value="<?= htmlspecialchars($submittedAt ?? '') ?>">
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                if ($rawGrade !== null) {
                                                    echo '<strong>' . htmlspecialchars($rawGrade) . '</strong> / ' . htmlspecialchars($maxPoints);
                                                } else {
                                                    echo '<em class="text-muted">Non consegnato</em>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <select name="import_grade[<?= $idx ?>]" class="form-select form-select-sm grade-select" style="width: 150px;" <?= $match['local_student'] ? '' : 'disabled' ?>>
                                                    <?php foreach ($availableGrades as $grade): ?>
                                                        <?php
                                                        $label = $grade;
                                                        if ($grade === 'i') $label = 'i (impreparato)';
                                                        elseif ($grade === 'a') $label = 'a (assente)';
                                                        elseif ($grade === 'skip') $label = '-non importare voto-';

                                                        $selected = ($grade === $suggestedGrade) ? 'selected' : '';
                                                        ?>
                                                        <option value="<?= htmlspecialchars($grade) ?>" <?= $selected ?>>
                                                            <?= htmlspecialchars($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <textarea name="commento[<?= $idx ?>]" class="form-control form-control-sm comment-input" rows="2"
                                                          placeholder="Commento del docente (opzionale)" <?= $match['local_student'] ? '' : 'disabled' ?>><?= htmlspecialchars($match['teacher_comment'] ?? '') ?></textarea>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($match['submission']['state']) ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i>
                            <strong>Nota:</strong> I voti sono stati approssimati automaticamente alla scala ClasseViva (mezzi punti da 1.0 a 10.0).
                            Puoi modificare manualmente ciascun voto prima dell'importazione. Gli studenti con "-non importare voto-" verranno saltati.
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Importa Voti Selezionati
                        </button>
                        <a href="uda_tests.php?id=<?= urlencode($test['id_uda']) ?>" class="btn btn-secondary">
                            Annulla
                        </a>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- STEP COMPLETE -->
        <?php if ($step === 'complete'): ?>
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle text-success" style="font-size: 48px;"></i>
                    <h3 class="mt-3">Importazione Completata!</h3>
                    <p>I voti sono stati importati con successo nel sistema.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="uda_grades.php?id=<?= urlencode($test['id_uda']) ?>" class="btn btn-success">
                            <i class="bi bi-eye"></i> Visualizza Voti
                        </a>
                        <a href="uda_tests.php?id=<?= urlencode($test['id_uda']) ?>" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i> Torna ai Test
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($step === 'review'): ?>
    <script>
        (function () {
            const studentsByMapping = <?= json_encode($studentsByMapping ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            const mappingSelector = document.getElementById('mappingSelector');
            const mappingIdInput = document.getElementById('mappingIdInput');
            const rows = Array.from(document.querySelectorAll('.student-row'));

            function normalize(str) {
                return (str || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            }

            function levenshtein(a, b) {
                const m = a.length;
                const n = b.length;
                const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
                for (let i = 0; i <= m; i++) dp[i][0] = i;
                for (let j = 0; j <= n; j++) dp[0][j] = j;
                for (let i = 1; i <= m; i++) {
                    for (let j = 1; j <= n; j++) {
                        const cost = a[i - 1] === b[j - 1] ? 0 : 1;
                        dp[i][j] = Math.min(
                            dp[i - 1][j] + 1,
                            dp[i][j - 1] + 1,
                            dp[i - 1][j - 1] + cost
                        );
                    }
                }
                return dp[m][n];
            }

            function bestMatch(name, students) {
                const target = normalize(name);
                if (!target) return null;
                let best = null;
                let bestScore = Infinity;

                for (const st of students) {
                    const candidate = normalize((st.nome || '') + ' ' + (st.cognome || ''));
                    if (!candidate) continue;

                    if (candidate === target) {
                        return { student: st, score: 0 };
                    }

                    if (candidate.includes(target) || target.includes(candidate)) {
                        const score = Math.abs(candidate.length - target.length);
                        if (score < bestScore) {
                            best = { student: st, score };
                            bestScore = score;
                        }
                        continue;
                    }

                    const dist = levenshtein(target, candidate);
                    if (dist < bestScore) {
                        best = { student: st, score: dist };
                        bestScore = dist;
                    }
                }

                return best && best.score <= 3 ? best : null;
            }

            function remapRows() {
                const mappingId = mappingSelector ? mappingSelector.value : '';
                if (mappingIdInput) {
                    mappingIdInput.value = mappingId;
                }
                const students = studentsByMapping[mappingId] || [];

                rows.forEach((row) => {
                    const classroomName = row.dataset.classroomName || '';
                    const match = bestMatch(classroomName, students);
                    const localNameEl = row.querySelector('.local-name');
                    const localIdEl = row.querySelector('.local-id');
                    const badgeEl = row.querySelector('.local-badge');
                    const hiddenInput = row.querySelector('.student-id-input');
                    const gradeSelect = row.querySelector('.grade-select');
                    const commentInput = row.querySelector('.comment-input');

                    if (match) {
                        const st = match.student;
                        if (hiddenInput) hiddenInput.value = st.id || '';
                        if (localNameEl) localNameEl.textContent = `${st.nome || ''} ${st.cognome || ''}`.trim();
                        if (localIdEl) {
                            if (st.id) {
                                localIdEl.textContent = `ID: ${st.id}`;
                                localIdEl.classList.remove('d-none');
                            } else {
                                localIdEl.textContent = '';
                                localIdEl.classList.add('d-none');
                            }
                        }
                        if (badgeEl) {
                            badgeEl.classList.remove('bg-warning', 'text-dark');
                            badgeEl.classList.add('bg-success');
                            badgeEl.textContent = 'OK';
                        }
                        if (gradeSelect) gradeSelect.disabled = false;
                        if (commentInput) commentInput.disabled = false;
                    } else {
                        if (hiddenInput) hiddenInput.value = '';
                        if (localNameEl) localNameEl.textContent = 'Da associare';
                        if (localIdEl) {
                            localIdEl.textContent = '';
                            localIdEl.classList.add('d-none');
                        }
                        if (badgeEl) {
                            badgeEl.classList.remove('bg-success');
                            badgeEl.classList.add('bg-warning', 'text-dark');
                            badgeEl.textContent = 'Da associare';
                        }
                        if (gradeSelect) gradeSelect.disabled = true;
                        if (commentInput) commentInput.disabled = true;
                    }
                });
            }

            if (mappingSelector) {
                mappingSelector.addEventListener('change', remapRows);
            }
            remapRows();
        })();
    </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
