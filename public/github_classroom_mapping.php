<?php
/**
 * Mappatura GitHub Classroom
 * Collega Classe-Materia di ClasseViva a GitHub Classroom
 */

require_once '../bootstrap.php';

use App\Integration\GitHubIntegration;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

$pageTitle = "Mappatura GitHub Classroom";

// Inizializza servizi
$github = new GitHubIntegration($config);
$cv = new ClasseVivaAPI($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

// Carica token da sessione
$github->loadTokenFromSession();
$isAuthenticated = $github->isAuthenticated();

// Variabili
$githubUser = $_SESSION['github_user'] ?? null;
$successMessage = null;
$errorMessage = null;
$warningMessage = null;
$mapStudentsMode = (isset($_GET['action']) && $_GET['action'] === 'map_students');
$idUdaFromQuery = $_GET['id_uda'] ?? ($_POST['id_uda'] ?? null);
$testIdFromQuery = $_GET['test_id'] ?? ($_POST['test_id'] ?? null);
$testReviewUrl = null;
if (is_string($testIdFromQuery) && trim($testIdFromQuery) !== '') {
    $testReviewUrl = 'github_assignment_review.php?test_id=' . urlencode(trim($testIdFromQuery));
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $successMessage = "Associazioni studenti salvate.";
}

// Gestione logout GitHub
if (isset($_GET['action']) && $_GET['action'] === 'logout_github') {
    $github->logout();
    header('Location: github_classroom_mapping.php');
    exit;
}

// Gestione salvataggio nuova mappatura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mapping') {
    try {
        $idClasseCv = $_POST['id_classe_cv'] ?? '';
        $idMateriaCv = $_POST['id_materia_cv'] ?? '';
        $githubClassroomId = $_POST['github_classroom_id'] ?? '';
        $githubOrgName = $_POST['github_org_name'] ?? '';
        $classroomName = $_POST['classroom_name'] ?? '';
        $note = $_POST['note'] ?? '';

        if (!$idClasseCv || !$idMateriaCv || !$githubClassroomId) {
            throw new Exception("Classe, Materia e GitHub Classroom sono obbligatori");
        }

        // Verifica se mappatura esiste già
        $existing = $dbAdapter->findAll('GITHUB_CLASSROOMS');
        foreach ($existing as $map) {
            if (false) {
                throw new Exception("Mappatura già esistente per questa Classe-Materia");
            }
        }

        // Verifica duplicati: non permettere doppia mappatura
        // 1) stessa Classe-Materia
        $existingSameClassMateria = $dbAdapter->findWhere('GITHUB_CLASSROOMS', [
            'id_classe_cv' => $idClasseCv,
            'id_materia_cv' => $idMateriaCv
        ]);
        foreach ($existingSameClassMateria as $map) {
            $existingGh = (string)($map['github_classroom_id'] ?? '');
            if ($existingGh === (string)$githubClassroomId) {
                throw new Exception("Mappatura già esistente per questa Classe-Materia e questa GitHub Classroom.");
            }
            throw new Exception("Questa Classe-Materia risulta già mappata a un'altra GitHub Classroom. Elimina prima la mappatura esistente se vuoi sostituirla.");
        }

        // 2) stessa GitHub Classroom (non può essere associata a due classi diverse)
        $existingSameGithubClassroom = $dbAdapter->findWhere('GITHUB_CLASSROOMS', [
            'github_classroom_id' => $githubClassroomId
        ]);
        foreach ($existingSameGithubClassroom as $map) {
            $existingClass = (string)($map['id_classe_cv'] ?? '');
            $existingSubj = (string)($map['id_materia_cv'] ?? '');
            if ($existingClass !== (string)$idClasseCv || $existingSubj !== (string)$idMateriaCv) {
                throw new Exception("Questa GitHub Classroom risulta già mappata a un'altra Classe-Materia. Elimina prima la mappatura esistente se vuoi sostituirla.");
            }
        }

        // Crea nuova mappatura
        $mappingId = 'GHCL_' . uniqid();
        $newMapping = [
            'id_mapping' => $mappingId,
            'id_classe_cv' => $idClasseCv,
            'id_materia_cv' => $idMateriaCv,
            'github_classroom_id' => $githubClassroomId,
            'github_org_name' => $githubOrgName,
            'classroom_name' => $classroomName,
            'stato' => 'attivo',
            'data_creazione' => date('d/m/Y H:i:s'),
            'note' => $note
        ];

        $dbAdapter->insertRow('GITHUB_CLASSROOMS', $newMapping);
        $successMessage = "Mappatura creata con successo!";

    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

// Gestione eliminazione mappatura
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $dbAdapter->deleteRow('GITHUB_CLASSROOMS', $_GET['id'], 'id_mapping');
        $successMessage = "Mappatura eliminata con successo!";

        $query = $_GET;
        unset($query['action'], $query['id']);
        $redirect = 'github_classroom_mapping.php';
        if (!empty($query)) {
            $redirect .= '?' . http_build_query($query);
        }
        header('Location: ' . $redirect);
        exit;
    } catch (Exception $e) {
        $errorMessage = "Errore nell'eliminazione: " . $e->getMessage();
    }
}

// Carica mappature esistenti
$mappings = $dbAdapter->findAll('GITHUB_CLASSROOMS');
$selectedMappingId = $_GET['mapping_id'] ?? null;
$selectedAssignmentId = $_GET['assignment_id'] ?? null;
$selectedAssignmentSlug = $_GET['assignment_slug'] ?? null;
$filterGithubClassroomId = $_GET['github_classroom_id'] ?? null;
$originalFilterGithubClassroomId = $filterGithubClassroomId;
$filterRemapped = false;
$duplicateMappingTuples = [];
$seenTuples = [];
foreach ($mappings as $row) {
    $tuple = (string)($row['id_classe_cv'] ?? '') . '|' . (string)($row['id_materia_cv'] ?? '') . '|' . (string)($row['github_classroom_id'] ?? '');
    if (isset($seenTuples[$tuple])) {
        $duplicateMappingTuples[$tuple] = true;
    } else {
        $seenTuples[$tuple] = true;
    }
}
$selectedMapping = null;
foreach ($mappings as $m) {
    if (($m['id_mapping'] ?? null) === $selectedMappingId) {
        $selectedMapping = $m;
        break;
    }
}

// Precarica la mappatura se arrivi da una UDA e la Classe-Materia è univoca
if (!$selectedMapping && !$selectedMappingId && $idUdaFromQuery) {
    try {
        $udaAssignments = $dbAdapter->findAll('CLASSI_ASSEGNATE');
        $udaPairs = array_values(array_filter($udaAssignments, function ($a) use ($idUdaFromQuery) {
            return (string)($a['id_uda'] ?? '') === (string)$idUdaFromQuery;
        }));

        $candidates = [];
        foreach ($udaPairs as $a) {
            $classId = (string)($a['id_classe_cv'] ?? ($a['id_classe'] ?? ''));
            $subjId = (string)($a['id_materia_cv'] ?? '');
            if ($classId === '' || $subjId === '') continue;
            foreach ($mappings as $map) {
                if ((string)($map['id_classe_cv'] ?? '') === $classId && (string)($map['id_materia_cv'] ?? '') === $subjId) {
                    $candidates[] = $map;
                }
            }
        }

        if (count($candidates) === 1) {
            $selectedMapping = $candidates[0];
            $selectedMappingId = $selectedMapping['id_mapping'] ?? null;
        }
    } catch (Exception $e) {
        // non bloccare
    }
}

$lockMappingSelect = false;
$lockAssignmentSelect = false;

// Se arriva un filtro github_classroom_id non coerente con l'ID API salvato nelle mappature (es. id da slug UI),
// non nascondere tutto: prova a rimappare usando la mappatura univoca della UDA oppure ignora il filtro.
if ($filterGithubClassroomId) {
    $directMatches = array_values(array_filter($mappings, function ($row) use ($filterGithubClassroomId) {
        return (string)($row['github_classroom_id'] ?? '') === (string)$filterGithubClassroomId;
    }));

    if (empty($directMatches)) {
        if ($selectedMapping) {
            $filterGithubClassroomId = (string)($selectedMapping['github_classroom_id'] ?? '');
            $filterRemapped = $filterGithubClassroomId !== '' && (string)$filterGithubClassroomId !== (string)$originalFilterGithubClassroomId;
        } elseif ($mapStudentsMode) {
            $filterGithubClassroomId = null;
            $filterRemapped = true;
        }
    }
}

$mappingsForUi = $mappings;
if ($filterGithubClassroomId) {
    $mappingsForUi = array_values(array_filter($mappingsForUi, function ($row) use ($filterGithubClassroomId) {
        return (string)($row['github_classroom_id'] ?? '') === (string)$filterGithubClassroomId;
    }));
}
$isFiltered = (bool)($filterGithubClassroomId || !empty($_GET['mapping_id']));

// Se il filtro riduce a una sola mappatura, preselezionala automaticamente
if (!$selectedMapping && !$selectedMappingId && count($mappingsForUi) === 1) {
    $selectedMapping = $mappingsForUi[0];
    $selectedMappingId = $selectedMapping['id_mapping'] ?? null;
}

// In modalitÃ  "map_students", se la mappatura/assignment arrivano predefiniti, blocca le select per evitare modifiche accidentali.
if ($mapStudentsMode) {
    $lockMappingSelect = !empty($selectedMappingId) && ($originalFilterGithubClassroomId || $idUdaFromQuery || $testIdFromQuery);
    $lockAssignmentSelect = !empty($selectedAssignmentId) && (!empty($_GET['assignment_id']) || !empty($selectedAssignmentSlug));
}

// Carica studenti ClasseViva per la mappatura selezionata
$cvStudents = [];
if ($selectedMapping) {
    try {
        $cvStudents = $cv->getStudentiClasse($selectedMapping['id_classe_cv']);
    } catch (Exception $e) {
        $errorMessage = $errorMessage ?: "Errore nel caricamento studenti ClasseViva: " . $e->getMessage();
    }
}

// In alcuni casi l'ID classroom presente nello slug dell'URL (es. 90975891-...) non coincide con l'ID API usato dagli endpoint /classrooms/{id}.
// Se siamo in modalitÃ  mappatura studenti e abbiamo un test_id, prova a estrarre un classroom_id alternativo dal link docente del test e usalo come fallback.
$apiClassroomIdForAssignments = $selectedMapping['github_classroom_id'] ?? null;
$apiClassroomIdFallbacks = [];
if (!empty($originalFilterGithubClassroomId)) {
    $apiClassroomIdFallbacks[] = (string)$originalFilterGithubClassroomId;
}
if (!empty($testIdFromQuery)) {
    try {
        $testForContext = $dbAdapter->findOne('TEST', 'id_test', (string)$testIdFromQuery);
        $teacherUrl = trim((string)($testForContext['url_assignment_teacher'] ?? ($testForContext['url_docente'] ?? ($testForContext['url_gestione'] ?? ''))));
        if ($teacherUrl !== '') {
            $parsed = parse_url($teacherUrl);
            $path = $parsed['path'] ?? '';
            $parts = array_values(array_filter(explode('/', (string)$path)));
            $classroomsIdx = array_search('classrooms', $parts, true);
            if ($classroomsIdx !== false && isset($parts[$classroomsIdx + 1])) {
                if (preg_match('/^(\\d+)(?:-|$)/', (string)$parts[$classroomsIdx + 1], $m)) {
                    $apiClassroomIdFallbacks[] = (string)$m[1];
                }
            }
        }
    } catch (Exception $e) {
        // non bloccare
    }
}
$apiClassroomIdFallbacks = array_values(array_unique(array_filter($apiClassroomIdFallbacks, fn($v) => (string)$v !== '')));

// Carica assignments GitHub per la mappatura selezionata
$githubAssignments = [];
if ($selectedMapping && $isAuthenticated) {
    try {
        $list = $github->listAssignments((string)$apiClassroomIdForAssignments);
        $githubAssignments = $list['assignments'] ?? $list ?? [];
    } catch (Exception $e) {
        // Fallback: prova altri classroom_id (dal filtro originale o dal link docente del test)
        $last = $e;
        $recovered = false;
        foreach ($apiClassroomIdFallbacks as $altId) {
            if ((string)$altId === (string)$apiClassroomIdForAssignments) {
                continue;
            }
            try {
                $list = $github->listAssignments((string)$altId);
                $githubAssignments = $list['assignments'] ?? $list ?? [];
                $apiClassroomIdForAssignments = (string)$altId;
                $recovered = true;
                break;
            } catch (Exception $e2) {
                $last = $e2;
            }
        }

        if ($recovered) {
            $errorMessage = $errorMessage ?: "Nota: ho usato un classroom_id alternativo per leggere gli assignment GitHub (l'ID salvato nella mappatura non risponde alle API).";
        } else {
            $hint = '';
            if (stripos($last->getMessage(), 'not found') !== false) {
                $hint = " (Suggerimento: alcune API GitHub Classroom restituiscono 404 con token OAuth; configura un Fine-grained PAT in Integrazioni → GitHub.)";
            }
            $errorMessage = $errorMessage ?: "Errore nel caricamento assignment GitHub: " . $last->getMessage() . $hint;
        }
    }
}

if ($selectedMapping && $isAuthenticated && !$selectedAssignmentId && $selectedAssignmentSlug && !empty($githubAssignments)) {
    $target = strtolower(trim((string)$selectedAssignmentSlug));
    $slugify = function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = strtolower($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-');
    };

    foreach ($githubAssignments as $a) {
        $title = (string)($a['title'] ?? ($a['name'] ?? ''));
        $slug = (string)($a['slug'] ?? '');
        $titleSlug = $slugify($title);
        if (($slug !== '' && strtolower($slug) === $target) || ($titleSlug !== '' && $titleSlug === $target)) {
            $selectedAssignmentId = (string)($a['id'] ?? '');
            break;
        }
    }
}

// Carica roster/grades e accepted assignments se richiesto
$assignmentRoster = [];
if ($selectedMapping && $selectedAssignmentId && $isAuthenticated) {
    try {
        $grades = $github->getAssignmentGrades($selectedAssignmentId);
        $accepted = $github->listAcceptedAssignments($selectedAssignmentId);
        $gradeItems = $grades['grades'] ?? ($grades['data'] ?? $grades ?? []);
        $acceptedItems = $accepted['accepted_assignments'] ?? ($accepted['data'] ?? $accepted ?? []);

        $acceptedByUser = [];
        $acceptedByRoster = [];
        foreach ($acceptedItems as $item) {
            $user = '';
            if (!empty($item['students'][0]['login'])) {
                $user = (string)$item['students'][0]['login'];
            } elseif (!empty($item['student']['login'])) {
                $user = (string)$item['student']['login'];
            } elseif (!empty($item['github_username'])) {
                $user = (string)$item['github_username'];
            }
            $user = strtolower(trim($user));
            if ($user !== '') $acceptedByUser[$user] = $item;

            $rid = strtolower(trim((string)($item['roster_identifier'] ?? '')));
            if ($rid !== '') $acceptedByRoster[$rid] = $item;
        }

        $reposFound = 0;
        foreach ($gradeItems as $g) {
            $username = $g['github_username'] ?? '';
            $rosterId = $g['roster_identifier'] ?? '';
            $repoUrl = '';
            $defaultBranch = '';

            $match = null;
            $unameKey = strtolower(trim((string)$username));
            $ridKey = strtolower(trim((string)$rosterId));
            if ($unameKey !== '' && isset($acceptedByUser[$unameKey])) {
                $match = $acceptedByUser[$unameKey];
            } elseif ($ridKey !== '' && isset($acceptedByRoster[$ridKey])) {
                $match = $acceptedByRoster[$ridKey];
            }

            if ($match) {
                $repoUrl = (string)($match['repository']['html_url'] ?? ($match['repository_url'] ?? ''));
                $defaultBranch = (string)($match['repository']['default_branch'] ?? ($match['default_branch'] ?? ''));
            }

            // Fallback: alcune versioni possono restituire direttamente il repo URL nei grades.
            if ($repoUrl === '') {
                $repoUrl = (string)($g['student_repository_url'] ?? ($g['repository_url'] ?? ''));
            }

            if ($repoUrl !== '') {
                $reposFound++;
            }
            $assignmentRoster[] = [
                'github_username' => $username,
                'roster_identifier' => $rosterId,
                'student_repository_url' => $repoUrl,
                'default_branch' => $defaultBranch
            ];
        }

        $totalGrades = is_array($gradeItems) ? count($gradeItems) : 0;
        if ($totalGrades > 0 && $reposFound === 0) {
            $warningMessage = "Nessuna repository trovata per questo assignment. Possibili cause: gli studenti non hanno ancora accettato l'assignment (repo non creata) oppure il token GitHub non ha accesso alle API/alle repo della Classroom. Verifica in Integrazioni → GitHub (Token GitHub Classroom) o ripeti l'autenticazione.";
        }
    } catch (Exception $e) {
        $errorMessage = $errorMessage ?: "Errore nel caricamento roster assignment: " . $e->getMessage();
    }
}

// Salvataggio mapping studenti
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_student_map') {
    try {
        $mappingId = $_POST['mapping_id'] ?? '';
        $assignmentId = $_POST['github_assignment_id'] ?? '';
        $usernames = $_POST['github_username'] ?? [];
        $rosters = $_POST['roster_identifier'] ?? [];
        $repos = $_POST['student_repository_url'] ?? [];
        $studentsCv = $_POST['id_studente_cv'] ?? [];
        $confidence = $_POST['match_confidence'] ?? [];

        if (!$mappingId || !$assignmentId) {
            throw new Exception("Seleziona mappatura e assignment.");
        }

        // Evita duplicati: rimuove eventuali mapping esistenti per questo assignment
        $existing = $dbAdapter->findWhere('GITHUB_ASSIGNMENT_STUDENT_MAP', [
            'id_assignment' => $assignmentId
        ]);
        foreach ($existing as $ex) {
            if (!empty($ex['id_map'])) {
                try {
                    $dbAdapter->deleteRow('GITHUB_ASSIGNMENT_STUDENT_MAP', $ex['id_map'], 'id_map');
                } catch (Exception $e) {
                    // continua
                }
            }
        }

        foreach ($usernames as $idx => $uname) {
            $uname = trim($uname);
            if (!$uname) continue;
            $studentIdCv = $studentsCv[$idx] ?? '';
            $mc = strtoupper(trim($confidence[$idx] ?? ''));
            if (!in_array($mc, ['AUTO', 'MANUAL', 'UNMATCHED'], true)) {
                $mc = $studentIdCv ? 'MANUAL' : 'UNMATCHED';
            }
            $row = [
                'id_map' => 'GHMAP_' . uniqid(),
                'id_assignment' => $assignmentId,
                'github_username' => $uname,
                'roster_identifier' => $rosters[$idx] ?? '',
                'student_repository_url' => $repos[$idx] ?? '',
                'id_studente_cv' => $studentIdCv,
                'match_confidence' => $mc,
                'note' => '',
                'data_creazione' => date('Y-m-d H:i:s'),
                // id_utente viene impostato automaticamente dal DatabaseAdapter user-scoped
            ];
            $dbAdapter->insertRow('GITHUB_ASSIGNMENT_STUDENT_MAP', $row);
        }
        // Aggiorna il test (se presente) con gli ID dell'assignment/classroom selezionati
        try {
            if ($testIdFromQuery) {
                $mapRow = $dbAdapter->findOne('GITHUB_CLASSROOMS', 'id_mapping', $mappingId);
                $update = [
                    'github_assignment_id' => (string)$assignmentId
                ];
                if (!empty($mapRow['github_classroom_id'])) {
                    $update['github_classroom_id'] = (string)$mapRow['github_classroom_id'];
                }
                $dbAdapter->updateRow('TEST', 'id_test', $testIdFromQuery, $update);
            }
        } catch (Exception $e) {
            // non bloccare
        }

        // PRG: mantieni filtri/torna in modalitÇÿ mappatura studenti
        $redirectParams = [
            'action' => 'map_students',
            'mapping_id' => $mappingId,
            'assignment_id' => $assignmentId,
            'saved' => '1'
        ];
        try {
            $mapRow = $dbAdapter->findOne('GITHUB_CLASSROOMS', 'id_mapping', $mappingId);
            if (!empty($mapRow['github_classroom_id'])) {
                $redirectParams['github_classroom_id'] = (string)$mapRow['github_classroom_id'];
            }
        } catch (Exception $e) {
            // continua
        }
        if (!empty($idUdaFromQuery)) {
            $redirectParams['id_uda'] = (string)$idUdaFromQuery;
        }
        if (!empty($testIdFromQuery)) {
            $redirectParams['test_id'] = (string)$testIdFromQuery;
        }

        header('Location: github_classroom_mapping.php?' . http_build_query($redirectParams) . '#student-map');
        exit;
    } catch (Exception $e) {
        $errorMessage = $errorMessage ?: "Errore salvataggio mapping studenti: " . $e->getMessage();
    }
}


// Carica classi e materie da ClasseViva
$classiMaterie = [];
try {
    $classesData = $cv->getClassesWithTeacherSubjects();
    // Espandi l'array: per ogni classe, crea una coppia classe-materia
    foreach ($classesData as $class) {
        $classId = $class['id'];
        $className = $class['name'];
        $subjects = $class['subjects'] ?? [];

        foreach ($subjects as $subject) {
            $classiMaterie[] = [
                'id_classe' => $classId,
                'nome_classe' => $className,
                'id_materia' => $subject['id'],
                'nome_materia' => $subject['name']
            ];
        }
    }
} catch (Exception $e) {
    $errorMessage = "Errore nel caricamento classi/materie: " . $e->getMessage();
}

// Carica GitHub Classrooms se autenticato
$githubClassrooms = [];
if ($isAuthenticated) {
    try {
        $classroomsData = $github->listClassrooms();
        $githubClassrooms = $classroomsData['classrooms'] ?? $classroomsData ?? [];
    } catch (Exception $e) {
        $errorMessage = "Errore nel caricamento GitHub Classrooms: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        tr.mapping-preselected td { background-color: #d1e7dd !important; }
        tr.mapping-manual td { background-color: #fff3cd !important; }
    </style>
</head>
<body>
    <?php
    $pageTitle = $pageTitle ?? 'Mappatura GitHub Classroom';
    ob_start();
    ?>
    <?php if (!empty($testReviewUrl)): ?>
        <a href="<?= htmlspecialchars($testReviewUrl) ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-return-left"></i> Torna all'assegnazione del test
        </a>
    <?php endif; ?>
    <?php if (!empty($idUdaFromQuery)): ?>
        <a href="uda_tests.php?id=<?= urlencode($idUdaFromQuery) ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-clipboard-check"></i> Torna ai Test UDA
        </a>
        <a href="uda_view.php?id=<?= urlencode($idUdaFromQuery) ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-eye"></i> Torna alla UDA
        </a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left"></i> Torna alla Dashboard
    </a>
    <?php
    $headerActions = ob_get_clean();
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
<?php if (isset($_GET['auth']) && $_GET['auth'] === 'success'): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle"></i> Autenticazione GitHub completata con successo!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                    <?php if (!empty($testReviewUrl)): ?>
                        <a href="<?= htmlspecialchars($testReviewUrl) ?>" class="btn btn-sm btn-success ms-3">
                            <i class="bi bi-clipboard-check"></i> Torna all'assegnazione del test
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($warningMessage): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($warningMessage) ?>
                    <?php if ($isAuthenticated): ?>
                        <a href="user_integrations.php#github" class="btn btn-sm btn-outline-dark ms-3">Apri integrazioni</a>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stato Autenticazione GitHub -->
            <div class="card mb-4">
                <div class="card-header <?= $isAuthenticated ? 'bg-success text-white' : 'bg-warning' ?>">
                    <h5 class="mb-0">
                        <i class="bi bi-person-circle"></i> Stato Autenticazione GitHub
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($isAuthenticated && $githubUser): ?>
                        <div class="d-flex align-items-center">
                            <?php if (isset($githubUser['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($githubUser['avatar_url']) ?>"
                                     alt="Avatar" class="rounded-circle me-3" style="width: 50px; height: 50px;">
                            <?php endif; ?>
                            <div class="flex-grow-1">
                                <h6 class="mb-1">✓ Autenticato come: <strong><?= htmlspecialchars($githubUser['login']) ?></strong></h6>
                                <p class="mb-0 text-muted small">
                                    <?= htmlspecialchars($githubUser['name'] ?? '') ?>
                                    <?php if (isset($githubUser['email'])): ?>
                                        · <?= htmlspecialchars($githubUser['email']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <a href="?action=logout_github" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-box-arrow-right"></i> Disconnetti
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="mb-3">
                            <i class="bi bi-info-circle"></i>
                            Per usare questa funzionalità devi prima autenticarti con il tuo account GitHub.
                        </p>
                        <a href="<?= $github->getAuthorizationUrl(null, $_SERVER['REQUEST_URI'] ?? null) ?>" class="btn btn-dark">
                            <i class="bi bi-github"></i> Autentica con GitHub
                        </a>
                        <p class="text-muted small mt-2">
                            Sarai reindirizzato a GitHub per autorizzare l'applicazione.
                            Scopes richiesti: read:user, read:org, repo
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isAuthenticated): ?>
                <?php if (!$mapStudentsMode): ?>
                <!-- Mappature Esistenti -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-link-45deg"></i> Mappature Classe-Materia → GitHub Classroom
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($isFiltered): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-funnel"></i>
                                Filtro attivo: mostra solo la classroom GitHub selezionata.
                                <?php $clearFilterLink = 'github_classroom_mapping.php' . (!empty($idUdaFromQuery) ? ('?id_uda=' . urlencode($idUdaFromQuery)) : ''); ?>
                                <a href="<?= htmlspecialchars($clearFilterLink) ?>" class="ms-2">Rimuovi filtro</a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($duplicateMappingTuples)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Attenzione: sembrano presenti mappature duplicate (stessa Classe-Materia e stessa GitHub Classroom) (<?= count($duplicateMappingTuples) ?>).
                                Usa il pulsante cestino per eliminarne una.
                            </div>
                        <?php endif; ?>

                        <?php if (empty($mappingsForUi)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Nessuna mappatura configurata.
                                Usa il form qui sotto per crearne una.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Classe-Materia</th>
                                            <th>GitHub Classroom</th>
                                            <th>Organization</th>
                                            <th>Stato</th>
                                            <th>Data Creazione</th>
                                            <th>Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mappingsForUi as $mapping): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($mapping['id_classe_cv']) ?></strong><br>
                                                    <small class="text-muted"><?= htmlspecialchars($mapping['id_materia_cv']) ?></small>
                                                </td>
                                                <td><?= htmlspecialchars($mapping['classroom_name']) ?></td>
                                                <td>
                                                    <a href="https://github.com/<?= urlencode($mapping['github_org_name']) ?>"
                                                       target="_blank" class="text-decoration-none">
                                                        <i class="bi bi-github"></i> <?= htmlspecialchars($mapping['github_org_name']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $mapping['stato'] === 'attivo' ? 'success' : 'secondary' ?>">
                                                        <?= htmlspecialchars($mapping['stato']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($mapping['data_creazione']) ?></td>
                                                <td>
                                                    <?php
                                                    $deleteParams = [
                                                        'action' => 'delete',
                                                        'id' => $mapping['id_mapping'] ?? ''
                                                    ];
                                                    if (!empty($idUdaFromQuery)) {
                                                        $deleteParams['id_uda'] = $idUdaFromQuery;
                                                    }
                                                    if (!empty($filterGithubClassroomId)) {
                                                        $deleteParams['github_classroom_id'] = $filterGithubClassroomId;
                                                    }
                                                    if (!empty($selectedMappingId)) {
                                                        $deleteParams['mapping_id'] = $selectedMappingId;
                                                    }
                                                    if (!empty($selectedAssignmentId)) {
                                                        $deleteParams['assignment_id'] = $selectedAssignmentId;
                                                    }
                                                    $deleteUrl = '?' . http_build_query($deleteParams);
                                                    ?>
                                                    <a href="<?= htmlspecialchars($deleteUrl) ?>"
                                                       class="btn btn-sm btn-outline-danger"
                                                       onclick="return confirm('Sei sicuro di voler eliminare questa mappatura?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Form Nuova Mappatura -->
                <?php if (!$isFiltered): ?>
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle"></i> Nuova Mappatura
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_mapping">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Classe-Materia (ClasseViva) *</label>
                                    <select name="classe_materia" id="classeMateria" class="form-select" required>
                                        <option value="">-- Seleziona --</option>
                                        <?php foreach ($classiMaterie as $cm): ?>
                                            <option value="<?= htmlspecialchars($cm['id_classe']) ?>,<?= htmlspecialchars($cm['id_materia']) ?>">
                                                <?= htmlspecialchars($cm['nome_classe']) ?> - <?= htmlspecialchars($cm['nome_materia']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="id_classe_cv" id="idClasseCv">
                                    <input type="hidden" name="id_materia_cv" id="idMateriaCv">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">GitHub Classroom *</label>
                                    <select name="classroom_data" id="classroomData" class="form-select" required>
                                        <option value="">-- Seleziona --</option>
                                        <?php foreach ($githubClassrooms as $classroom): ?>
                                            <?php
                                            $classroomId = $classroom['id'] ?? '';
                                            $classroomName = $classroom['name'] ?? '';
                                            $orgName = $classroom['organization']['login'] ?? '';
                                            $dataValue = "{$classroomId}|{$orgName}|{$classroomName}";
                                            ?>
                                            <option value="<?= htmlspecialchars($dataValue) ?>">
                                                <?= htmlspecialchars($classroomName) ?> (<?= htmlspecialchars($orgName) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="github_classroom_id" id="githubClassroomId">
                                    <input type="hidden" name="github_org_name" id="githubOrgName">
                                    <input type="hidden" name="classroom_name" id="classroomName">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Note (opzionale)</label>
                                <textarea name="note" class="form-control" rows="2"
                                          placeholder="Es: Classe per progetto finale..."></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save"></i> Salva Mappatura
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Annulla
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Associazione studenti GitHub ↔ ClasseViva -->
                <?php endif; ?>

                <?php if ($isAuthenticated): ?>
                <div class="card mt-4" id="student-map">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-people"></i> Associa studenti GitHub alla ClasseViva
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($filterRemapped): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-info-circle"></i>
                                La pagina è stata aperta con un identificativo classroom non coerente con la mappatura salvata; ho applicato la mappatura corretta (UDA/ClasseViva) per permetterti di proseguire.
                            </div>
                        <?php endif; ?>

                        <?php if (empty($mappingsForUi)): ?>
                            <div class="alert alert-warning mb-0">
                                Nessuna mappatura ClasseViva → GitHub Classroom trovata per i filtri correnti.
                                <?php if (!empty($idUdaFromQuery)): ?>
                                    Verifica la mappatura in questa pagina (senza filtri) oppure crea prima la mappatura classe/materia.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                        <form method="GET" class="row g-3 mb-3">
                            <input type="hidden" name="action" value="map_students">
                            <?php if (!empty($idUdaFromQuery)): ?>
                                <input type="hidden" name="id_uda" value="<?= htmlspecialchars((string)$idUdaFromQuery) ?>">
                            <?php endif; ?>
                            <?php if (!empty($testIdFromQuery)): ?>
                                <input type="hidden" name="test_id" value="<?= htmlspecialchars((string)$testIdFromQuery) ?>">
                            <?php endif; ?>
                            <?php if (!empty($filterGithubClassroomId)): ?>
                                <input type="hidden" name="github_classroom_id" value="<?= htmlspecialchars((string)$filterGithubClassroomId) ?>">
                            <?php endif; ?>
                            <?php if (!empty($selectedAssignmentSlug) && empty($selectedAssignmentId)): ?>
                                <input type="hidden" name="assignment_slug" value="<?= htmlspecialchars((string)$selectedAssignmentSlug) ?>">
                            <?php endif; ?>
                            <div class="col-md-5">
                                <label class="form-label">Mappatura Classe-Materia</label>
                                <?php if ($lockMappingSelect): ?>
                                    <input type="hidden" name="mapping_id" value="<?= htmlspecialchars((string)$selectedMappingId) ?>">
                                <?php endif; ?>
                                <select name="mapping_id" class="form-select" onchange="this.form.submit()" <?= $lockMappingSelect ? 'disabled' : '' ?>>
                                    <option value="">Seleziona...</option>
                                    <?php foreach ($mappingsForUi as $m): ?>
                                        <option value="<?= htmlspecialchars($m['id_mapping']) ?>" <?= ($selectedMappingId === ($m['id_mapping'] ?? '')) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(($m['classroom_name'] ?? 'Classroom') . ' - ' . ($m['id_classe_cv'] ?? '') . '/' . ($m['id_materia_cv'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Assignment GitHub</label>
                                <?php if ($lockAssignmentSelect): ?>
                                    <input type="hidden" name="assignment_id" value="<?= htmlspecialchars((string)$selectedAssignmentId) ?>">
                                <?php endif; ?>
                                <select name="assignment_id" class="form-select" <?= $selectedMapping ? '' : 'disabled' ?> onchange="this.form.submit()" <?= $lockAssignmentSelect ? 'disabled' : '' ?>>
                                    <option value="">Seleziona...</option>
                                    <?php foreach ($githubAssignments as $a): ?>
                                        <?php
                                        $aid = $a['id'] ?? '';
                                        $aname = $a['title'] ?? ($a['name'] ?? '');
                                        ?>
                                        <option value="<?= htmlspecialchars($aid) ?>" <?= ((string)$selectedAssignmentId === (string)$aid) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($aname ?: $aid) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100" <?= $selectedMapping ? '' : 'disabled' ?>>
                                    <i class="bi bi-download"></i> Carica roster
                                </button>
                            </div>
                        </form>

                        <?php if ($selectedMapping && $selectedAssignmentId): ?>
                            <?php
                            if (!function_exists('ghNormalizeEmail')) {
                                function ghNormalizeEmail($value)
                                {
                                    return strtolower(trim((string)$value));
                                }
                            }
                            if (!function_exists('ghNormalizeName')) {
                                function ghNormalizeName($value)
                                {
                                    $value = strtolower(trim((string)$value));
                                    if ($value === '') {
                                        return '';
                                    }

                                    if (function_exists('iconv')) {
                                        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
                                        if (is_string($converted) && $converted !== '') {
                                            $value = $converted;
                                        }
                                    }

                                    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
                                    $value = preg_replace('/\s+/', ' ', $value);
                                    return trim($value);
                                }
                            }

                            // Indici per pre-assegnazione (email o Nome/Cognome su roster_identifier)
                            $cvByEmail = [];
                            $cvByNameExact = [];
                            foreach ($cvStudents as $s) {
                                $email = ghNormalizeEmail($s['email'] ?? '');
                                if ($email) {
                                    $cvByEmail[$email] = $s;
                                }

                                $nome = trim((string)($s['nome'] ?? ''));
                                $cognome = trim((string)($s['cognome'] ?? ''));
                                if ($nome !== '' && $cognome !== '') {
                                    $k1 = ghNormalizeName($nome . ' ' . $cognome);
                                    $k2 = ghNormalizeName($cognome . ' ' . $nome);
                                    if ($k1) $cvByNameExact[$k1][] = $s;
                                    if ($k2) $cvByNameExact[$k2][] = $s;
                                }
                            }
                            ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_student_map">
                                <input type="hidden" name="mapping_id" value="<?= htmlspecialchars($selectedMappingId) ?>">
                                <input type="hidden" name="github_assignment_id" value="<?= htmlspecialchars($selectedAssignmentId) ?>">
                                <?php if (!empty($idUdaFromQuery)): ?>
                                    <input type="hidden" name="id_uda" value="<?= htmlspecialchars((string)$idUdaFromQuery) ?>">
                                <?php endif; ?>
                                <?php if (!empty($testIdFromQuery)): ?>
                                    <input type="hidden" name="test_id" value="<?= htmlspecialchars((string)$testIdFromQuery) ?>">
                                <?php endif; ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped align-middle">
                                        <thead>
                                            <tr>
                                                <th>GitHub Username</th>
                                                <th>Roster Identifier</th>
                                                <th>Repo Studente</th>
                                                <th>Studente ClasseViva</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assignmentRoster as $idx => $row): ?>
                                                <?php
                                                $autoMatch = null;
                                                $autoConfidence = 'UNMATCHED';

                                                $ridRaw = (string)($row['roster_identifier'] ?? '');
                                                $ridEmail = ghNormalizeEmail($ridRaw);

                                                // 1) Match email (alta confidenza)
                                                if ($ridEmail && strpos($ridEmail, '@') !== false && isset($cvByEmail[$ridEmail])) {
                                                    $autoMatch = $cvByEmail[$ridEmail];
                                                    $autoConfidence = 'AUTO';
                                                } else {
                                                    // 2) Match Nome/Cognome su roster_identifier
                                                    $ridName = ghNormalizeName($ridRaw);
                                                    if ($ridName) {
                                                        // 2A) Match esatto "nome cognome" o "cognome nome" (solo se univoco)
                                                        if (isset($cvByNameExact[$ridName]) && count($cvByNameExact[$ridName]) === 1) {
                                                            $autoMatch = $cvByNameExact[$ridName][0];
                                                            $autoConfidence = 'AUTO';
                                                        } else {
                                                            // 2B) Match "contiene nome + cognome" (pre-assegnazione prudente)
                                                            $bestStudent = null;
                                                            $bestScore = 0;
                                                            $bestTies = 0;

                                                            foreach ($cvStudents as $s) {
                                                                $nomeNorm = ghNormalizeName($s['nome'] ?? '');
                                                                $cognomeNorm = ghNormalizeName($s['cognome'] ?? '');
                                                                if (!$nomeNorm || !$cognomeNorm) {
                                                                    continue;
                                                                }

                                                                $full1 = trim($nomeNorm . ' ' . $cognomeNorm);
                                                                $full2 = trim($cognomeNorm . ' ' . $nomeNorm);

                                                                $score = 0;
                                                                if ($ridName === $full1 || $ridName === $full2) {
                                                                    $score = 100;
                                                                } else {
                                                                    $hasNome = (strpos($ridName, $nomeNorm) !== false);
                                                                    $hasCognome = (strpos($ridName, $cognomeNorm) !== false);
                                                                    if ($hasNome) $score += 40;
                                                                    if ($hasCognome) $score += 60;
                                                                }

                                                                if ($score > $bestScore) {
                                                                    $bestStudent = $s;
                                                                    $bestScore = $score;
                                                                    $bestTies = $score > 0 ? 1 : 0;
                                                                } elseif ($score > 0 && $score === $bestScore) {
                                                                    $bestTies++;
                                                                }
                                                            }

                                                            if ($bestStudent && $bestScore >= 100 && $bestTies === 1) {
                                                                $autoMatch = $bestStudent;
                                                                $autoConfidence = 'AUTO';
                                                            } elseif ($bestStudent && $bestScore >= 90 && $bestTies === 1) {
                                                                $autoMatch = $bestStudent;
                                                                $autoConfidence = 'MANUAL';
                                                            }
                                                        }
                                                    }
                                                }
                                                $autoStudentId = $autoMatch['id'] ?? ($autoMatch['id_studente'] ?? '');
                                                ?>
                                                <tr data-initial-preselected="<?= $autoMatch ? '1' : '0' ?>"
                                                    data-initial-student="<?= htmlspecialchars($autoStudentId) ?>"
                                                    class="<?= $autoMatch ? 'mapping-preselected' : '' ?>">
                                                    <td>
                                                        <input type="hidden" name="github_username[<?= $idx ?>]" value="<?= htmlspecialchars($row['github_username'] ?? '') ?>">
                                                        <input type="hidden" name="match_confidence[<?= $idx ?>]" class="match-confidence" value="<?= htmlspecialchars($autoConfidence) ?>">
                                                        <?= htmlspecialchars($row['github_username'] ?? '') ?>
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="roster_identifier[<?= $idx ?>]" value="<?= htmlspecialchars($row['roster_identifier'] ?? '') ?>">
                                                        <span class="text-muted roster-identifier"><?= htmlspecialchars($row['roster_identifier'] ?? '') ?></span>
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="student_repository_url[<?= $idx ?>]" value="<?= htmlspecialchars($row['student_repository_url'] ?? '') ?>">
                                                        <?php if (!empty($row['student_repository_url'])): ?>
                                                            <a href="<?= htmlspecialchars($row['student_repository_url']) ?>" target="_blank">
                                                                <?= htmlspecialchars($row['student_repository_url']) ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <em class="text-muted">N/D</em>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <select name="id_studente_cv[<?= $idx ?>]" class="form-select form-select-sm student-select">
                                                            <option value="">-- Seleziona studente --</option>
                                                            <?php foreach ($cvStudents as $s): ?>
                                                                <?php
                                                                $sid = $s['id'] ?? ($s['id_studente'] ?? '');
                                                                $label = trim(($s['cognome'] ?? '') . ' ' . ($s['nome'] ?? ''));
                                                                $selected = ($autoMatch && (($autoMatch['id'] ?? '') === $sid || ($autoMatch['id_studente'] ?? '') === $sid)) ? 'selected' : '';
                                                                $email = $s['email'] ?? '';
                                                                $nome = $s['nome'] ?? '';
                                                                $cognome = $s['cognome'] ?? '';
                                                                $fullNorm = ghNormalizeName($nome . ' ' . $cognome);
                                                                $fullRevNorm = ghNormalizeName($cognome . ' ' . $nome);
                                                                ?>
                                                                <option value="<?= htmlspecialchars($sid) ?>"
                                                                        data-email="<?= htmlspecialchars($email) ?>"
                                                                        data-fullname="<?= htmlspecialchars($fullNorm) ?>"
                                                                        data-fullname-rev="<?= htmlspecialchars($fullRevNorm) ?>"
                                                                        <?= $selected ?>>
                                                                    <?= htmlspecialchars($label) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save"></i> Salva associazioni
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($idUdaFromQuery)): ?>
                <div class="mt-4 d-flex gap-2">
                    <?php if (!empty($testReviewUrl)): ?>
                        <a href="<?= htmlspecialchars($testReviewUrl) ?>" class="btn btn-outline-success">
                            <i class="bi bi-arrow-return-left"></i> Torna all'assegnazione del test
                        </a>
                    <?php endif; ?>
                    <a href="uda_tests.php?id=<?= urlencode($idUdaFromQuery) ?>" class="btn btn-outline-primary">
                        <i class="bi bi-clipboard-check"></i> Torna ai Test UDA
                    </a>
                    <a href="uda_view.php?id=<?= urlencode($idUdaFromQuery) ?>" class="btn btn-outline-primary">
                        <i class="bi bi-eye"></i> Torna alla UDA
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Separa classe e materia
document.getElementById('classeMateria')?.addEventListener('change', function() {
    const value = this.value;
    if (value) {
        const [classe, materia] = value.split(',');
        document.getElementById('idClasseCv').value = classe;
        document.getElementById('idMateriaCv').value = materia;
    }
});

// Separa dati classroom
document.getElementById('classroomData')?.addEventListener('change', function() {
    const value = this.value;
    if (value) {
        const [id, org, name] = value.split('|');
        document.getElementById('githubClassroomId').value = id;
        document.getElementById('githubOrgName').value = org;
        document.getElementById('classroomName').value = name;
    }
});

function normalizeEmail(value) {
    return (value || '').trim().toLowerCase();
}

function normalizeName(value) {
    const raw = (value || '').trim().toLowerCase();
    if (!raw) return '';
    const noDiacritics = raw.normalize('NFD').replace(/\p{Diacritic}+/gu, '');
    const cleaned = noDiacritics.replace(/[^a-z0-9]+/gi, ' ').replace(/\s+/g, ' ').trim();
    return cleaned;
}

function updateMatchConfidenceForRow(selectEl) {
    if (!selectEl) return;
    const row = selectEl.closest('tr');
    if (!row) return;

    const confidenceInput = row.querySelector('input.match-confidence');
    if (!confidenceInput) return;

    const rosterInput = row.querySelector('input[name^="roster_identifier"]');
    const rosterRaw = (rosterInput?.value || row.querySelector('.roster-identifier')?.textContent || '').trim();
    const rosterIsEmail = rosterRaw.includes('@');
    const rosterIdentifierEmail = normalizeEmail(rosterRaw);
    const rosterIdentifierName = normalizeName(rosterRaw);

    const selectedOption = selectEl.options[selectEl.selectedIndex] || null;
    const selectedEmail = normalizeEmail(selectedOption?.dataset?.email || '');
    const hasSelection = !!(selectEl.value || '').trim();

    if (!hasSelection) {
        confidenceInput.value = 'UNMATCHED';
        return;
    }

    if (rosterIsEmail) {
        if (rosterIdentifierEmail && selectedEmail && rosterIdentifierEmail === selectedEmail) {
            confidenceInput.value = 'AUTO';
            return;
        }
    } else {
        const optFull = normalizeName(selectedOption?.dataset?.fullname || '');
        const optFullRev = normalizeName(selectedOption?.dataset?.fullnameRev || '');
        if (rosterIdentifierName && (rosterIdentifierName === optFull || rosterIdentifierName === optFullRev)) {
            confidenceInput.value = 'AUTO';
            return;
        }
    }

    confidenceInput.value = 'MANUAL';
}

function updateRowHighlight(selectEl) {
    if (!selectEl) return;
    const row = selectEl.closest('tr');
    if (!row) return;

    const current = ((selectEl.value || '') + '').trim();
    const initialStudent = (row.dataset.initialStudent || '').trim();
    const initialPreselected = row.dataset.initialPreselected === '1';

    row.classList.remove('mapping-preselected', 'mapping-manual');

    if (!current) return;

    if (initialPreselected && initialStudent && current === initialStudent) {
        row.classList.add('mapping-preselected');
        return;
    }

    row.classList.add('mapping-manual');
}

document.querySelectorAll('select.student-select').forEach(function(selectEl) {
    selectEl.addEventListener('change', function() {
        updateMatchConfidenceForRow(selectEl);
        updateRowHighlight(selectEl);
    });
    updateMatchConfidenceForRow(selectEl);
    updateRowHighlight(selectEl);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
