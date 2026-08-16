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
use App\Core\GradeImportStudentService;
use App\Integration\GoogleClassroomAPI;

$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));

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
// STEP 1: Carica submissions da Classroom
if ($step === 'load_submissions') {
    try {
        if (empty($test['classroom_course_id']) || empty($test['classroom_assignment_id'])) {
            throw new Exception('course_id o assignment_id non configurati per questo compito.');
        }
        $classroomAPI = new GoogleClassroomAPI($config);

        // Carica submissions da Classroom
        $submissions = $classroomAPI->getAssignmentSubmissions(
            $test['classroom_course_id'],
            $test['classroom_assignment_id']
        );

        // Risolve le identità Google Classroom verso id_studente interni.
        $gradeImportService = new GradeImportStudentService($dbAdapter, $userId);
        $externalRows = [];
        $classroomProfiles = [];
        foreach ($submissions as $submission) {
            $googleUserId = trim((string)($submission['user_id'] ?? ''));
            if ($googleUserId === '') {
                continue;
            }
            $profile = null;
            try {
                $profile = $classroomAPI->getStudente($test['classroom_course_id'], $googleUserId);
            } catch (Exception $e) {
                $profile = null;
            }
            $classroomProfiles[$googleUserId] = $profile;
            $externalRows[] = [
                'external_user_id' => $googleUserId,
                'display_name' => (string)($profile['name'] ?? ''),
                'email' => (string)($profile['email'] ?? ''),
                'assigned_grade' => $submission['assigned_grade'] ?? null,
                'state' => $submission['state'] ?? '',
            ];
        }

        try {
            $resolved = $gradeImportService->resolve('google_classroom', (string)$test['classroom_course_id'], $externalRows);
        } catch (\RuntimeException $e) {
            throw new Exception($e->getMessage());
        }
        $resolvedByExternal = [];
        foreach ($resolved['rows'] as $resolvedRow) {
            $resolvedByExternal[(string)$resolvedRow['external_user_id']] = $resolvedRow;
        }

        foreach ($submissions as $submission) {
            $googleUserId = trim((string)($submission['user_id'] ?? ''));
            $resolvedRow = $resolvedByExternal[$googleUserId] ?? null;
            $internalId = ($resolvedRow['id_studente'] ?? null);
            $matchedStudents[] = [
                'submission' => $submission,
                'classroom_student' => $classroomProfiles[$googleUserId] ?? null,
                'local_student' => ($internalId !== null && $internalId !== '')
                    ? ['id' => $internalId]
                    : null,
                'match_confidence' => ($internalId !== null && $internalId !== '') ? 'high' : 'none',
                'teacher_comment' => null,
            ];
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
        // Leggi dati POST dal form
        $studentIds = $_POST['student_id'] ?? [];
        $submissionTimes = $_POST['submission_time'] ?? [];
        $importGrades = $_POST['import_grade'] ?? [];
        $commenti = $_POST['commento'] ?? [];
        $tipoVoto = $_POST['tipo_voto'] ?? 'scritto';

        if (empty($studentIds) || empty($importGrades)) {
            throw new Exception("Nessun dato di import ricevuto");
        }

        $gradeImportService = new GradeImportStudentService($dbAdapter, $userId);
        $groupId = $gradeImportService->resolveGroupId('google_classroom', (string)$test['classroom_course_id']);

        $imported = 0;
        $linkOrigine = app_url('public/import_classroom_grades.php?test_id=' . urlencode((string)$testId));
        $skipped = 0;
        $errors = [];
        $testDateRaw = $test['data_somministrazione'] ?? ($test['data_creazione'] ?? null);

        foreach ($studentIds as $idx => $studentId) {
            $studentId = trim((string)$studentId);
            $selectedGrade = $importGrades[$idx] ?? 'skip';
            $commento = trim((string)($commenti[$idx] ?? ''));

            // Salta se "-non importare voto-" o senza studente associato
            if ($selectedGrade === 'skip' || $studentId === '') {
                $skipped++;
                continue;
            }

            try {
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
                    'id_gruppo' => $groupId,
                    'id_studente' => $studentId,
                    'tipo_voto' => $tipoVoto,
                    'voto' => $voto,
                    'giudizio' => $giudizio,
                    'descrizione' => $descrizione,
                    'data_valutazione' => $dataValutazione,
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'pubblicato' => 0,
                    'provider_pubblicazione' => null,
                    'external_publication_id' => null,
                    'num_evidenze_positive' => 0,
                    'num_evidenze_negative' => 0,
                    'num_evidenze_totali' => 0,
                    'id_utente' => $userId,
                    'link_origine' => $linkOrigine,
                ];

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

        <!-- STEP START -->
        <?php if ($step === 'start'): ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Passo 1: Carica Consegne</h5>
                    <p>Carica le consegne degli studenti da Google Classroom e associale agli studenti del gruppo didattico.</p>

                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="test_id" value="<?= htmlspecialchars($testId) ?>">
                        <input type="hidden" name="step" value="load_submissions">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-download"></i> Carica Consegne da Classroom
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- STEP REVIEW -->


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
