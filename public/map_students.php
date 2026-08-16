<?php

define('REQUIRES_CLASSEVIVA', true);
define('SKIP_CV_TOKEN_POPUP', true);
/**
 * Mappatura Studenti - GDPR Compliant
 *
 * IMPORTANTE: Salva solo ID, mai dati personali!
 * I nomi vengono letti in real-time dalle API esterne.
 */

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Core\StudentProviderMappingService;
use App\Core\TeachingGroupCatalogService;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;
use App\Utils\LocalReturnUrl;

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

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
        $cvTokenNotice = 'Integrazione ClasseViva disabilitata. Abilitala dalla sezione Integrazioni per poter importare studenti.';
    } elseif (!$cvHasToken) {
        $cvTokenNotice = 'Token ClasseViva mancante. Autorizza nuovamente l\'accesso nella pagina Integrazioni.';
    } else {
        $cvTokenNotice = $cvTokenError
            ? "Token ClasseViva non valido: {$cvTokenError}"
            : 'Token ClasseViva non valido o scaduto. Rigeneralo dalla pagina Integrazioni.';
    }
}

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$teachingGroupCatalog = new TeachingGroupCatalogService($dbAdapter, $userId);
$csrfSessionKey = 'map_students_csrf';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!is_string($_SESSION[$csrfSessionKey] ?? null) || $_SESSION[$csrfSessionKey] === '') {
    $_SESSION[$csrfSessionKey] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION[$csrfSessionKey];
$requestScalar = static function (array $source, string $key, string $default = ''): string {
    $value = $source[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};
$assertCsrf = static function () use ($csrfToken): void {
    $posted = $_POST['csrf_token'] ?? null;
    if (!is_string($posted) || $posted === '' || !hash_equals($csrfToken, $posted)) {
        throw new RuntimeException('Token CSRF non valido. Ricarica la pagina e riprova.');
    }
};

$groupRaw = $_GET['group_id'] ?? ($_GET['id_gruppo'] ?? ($_POST['group_id'] ?? ($_POST['id_gruppo'] ?? '')));
$requestedGroupId = is_scalar($groupRaw) ? trim((string)$groupRaw) : '';
if ($requestedGroupId !== '') {
    $group = $teachingGroupCatalog->findForWizard($requestedGroupId);
    if ($group === null) {
        http_response_code(404);
        echo 'Gruppo didattico non trovato.';
        exit;
    }
    // I gruppi moderni sono gestiti dall'editor provider-neutral. Non
    // inizializzare la vecchia tabella MAPPATURA_STUDENTI in questo flusso.
    $groupReturn = LocalReturnUrl::sanitize(
        is_scalar($_GET['return_to'] ?? null)
            ? $_GET['return_to']
            : (is_scalar($_POST['return_to'] ?? null) ? $_POST['return_to'] : null),
        'teaching_groups.php?tab=students&id=' . rawurlencode($requestedGroupId)
    );
    $groupEditorUrl = 'teaching_groups.php?tab=students&id=' . rawurlencode($requestedGroupId)
        . '&return_to=' . rawurlencode($groupReturn);
    ?>
    <!DOCTYPE html>
    <html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mappatura studenti</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>
    <body class="bg-light"><main class="container py-5"><div class="card shadow-sm"><div class="card-body">
        <h1 class="h4">Mappatura studenti del gruppo didattico</h1>
        <p class="text-muted">Questo gruppo usa la gestione provider-neutral: le identita vengono collegate nella tab Studenti del nuovo editor.</p>
        <a class="btn btn-primary" href="<?= htmlspecialchars($groupEditorUrl, ENT_QUOTES, 'UTF-8') ?>">Apri tab Studenti</a>
    </div></div></main></body></html>
    <?php
    exit;
}

$mappingService = new ProviderNeutralMappingService($dbAdapter, $userId);
$studentMappingService = new StudentProviderMappingService($dbAdapter, $userId);
$classeVivaAPI = $cvReady ? new ClasseVivaAPI($config) : null;
$googleClassroomAPI = new GoogleClassroomAPI($config);

$mappingId = $requestScalar($_GET, 'mapping_id') ?: null;
$action = $requestScalar($_POST, 'action') ?: null;

if (!$mappingId) {
    die("ID Mappatura mancante. Torna alla <a href='map_classes.php'>pagina principale</a> e seleziona una materia.");
}

// Carica il mapping (classe + materia) → classroom
$allMappings = $mappingService->listGoogleClassroomMappings();
$mapping = null;
foreach ($allMappings as $m) {
    $stato = strtolower(trim((string)($m['stato'] ?? 'attivo')));
    if ($m['id_mapping'] === $mappingId &&
        ($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1')) {
        $mapping = $m;
        break;
    }
}

if (!$mapping) {
    die("Mappatura non trovata. Torna alla <a href='map_classes.php'>pagina principale</a>.");
}

// Estrai dati dalla mappatura
$idClasseCV = $mapping['id_classe_cv'] ?? '';
$groupId = (string)($mapping['id_gruppo'] ?? '');
$courseId = $mapping['id_corso_gc'] ?? '';
$className = $mapping['nome_classe_cv'] ?? '';
$subjectName = $mapping['nome_materia_cv'] ?? '';
$courseName = $mapping['nome_corso_gc'] ?? '';

$classeMappataCompleta = !empty($idClasseCV) && !empty($courseId);

$successMessage = null;
$errorMessage = null;

// Verifica che il foglio MAPPATURA_STUDENTI esista e sia configurato correttamente
$sheetCheckError = null;
try {
    $testRead = $dbAdapter->findAll('MAPPATURA_STUDENTI');
    // Verifica che abbia le colonne corrette leggendo la prima riga
    if (empty($testRead)) {
        // Foglio vuoto ma esistente - OK
    } else {
        // Verifica che la prima riga abbia le colonne richieste
        $firstRow = reset($testRead);
        $requiredColumns = ['id_mappatura', 'id_mapping_materia', 'id_studente_cv', 'id_studente_gc'];
        foreach ($requiredColumns as $col) {
            if (!array_key_exists($col, $firstRow)) {
                $sheetCheckError = "Il foglio MAPPATURA_STUDENTI esiste ma non ha la struttura corretta. Manca la colonna: $col";
                break;
            }
        }
    }
} catch (Exception $e) {
    $sheetCheckError = "Il foglio MAPPATURA_STUDENTI non esiste o non è accessibile: " . $e->getMessage();
}

// Gestione salvataggio mappature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_mappings') {
    try {
        $assertCsrf();
        $rawMappings = $_POST['mappings'] ?? '[]';
        if (!is_string($rawMappings)) {
            throw new RuntimeException('Formato mappature non valido.');
        }
        $mappings = json_decode($rawMappings, true, 512, JSON_THROW_ON_ERROR);

        if (empty($mappings)) {
            throw new Exception("Nessuna mappatura da salvare. Seleziona almeno uno studente.");
        }

        $savedCount = 0;
        $updatedCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($mappings as $mappingData) {
            if (!is_array($mappingData)) {
                continue;
            }
            $cvRaw = $mappingData['cv_id'] ?? '';
            $gcRaw = $mappingData['gc_id'] ?? '';
            $methodRaw = $mappingData['method'] ?? 'manuale';
            $idStudenteCV = is_scalar($cvRaw) ? trim((string)$cvRaw) : '';
            $idStudenteGC = is_scalar($gcRaw) ? trim((string)$gcRaw) : '';
            $method = is_scalar($methodRaw) ? trim((string)$methodRaw) : 'manuale';

            if (empty($idStudenteCV) || empty($idStudenteGC)) {
                continue;
            }

            try {
                // IMPORTANTE: Salviamo SOLO gli ID, MAI i nomi!
                $mappatura = [
                    'id_mappatura' => 'MAPSTUD_' . uniqid(),
                    'id_mapping_materia' => $mappingId,  // Riferimento al mapping (classe+materia)→classroom
                    'id_studente_cv' => $idStudenteCV,  // SOLO ID
                    'id_studente_gc' => $idStudenteGC,  // SOLO ID
                    'data_associazione' => date('d/m/Y'),
                    'stato' => 'attivo',
                    'confermato_da' => $method,
                    'note' => ''
                ];

                // Verifica se mappatura già esiste
                $allMappature = $dbAdapter->findAll('MAPPATURA_STUDENTI');
                $exists = false;
                $existingId = null;

                foreach ($allMappature as $m) {
                    if ($m['id_mapping_materia'] === $mappingId && $m['id_studente_cv'] === $idStudenteCV) {
                        $exists = true;
                        $existingId = $m['id_mappatura'];
                        break;
                    }
                }

                if ($exists) {
                    // Aggiorna esistente
                    $dataToUpdate = [
                        'id_studente_gc' => $idStudenteGC,
                        'data_associazione' => date('d/m/Y'),
                        'confermato_da' => $method
                    ];
                    $result = $dbAdapter->updateRow('MAPPATURA_STUDENTI', 'id_mappatura', $existingId, $dataToUpdate);
                    if ($result) {
                        $updatedCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "Impossibile aggiornare mappatura per studente CV ID: $idStudenteCV";
                    }
                } else {
                    // Inserisci nuova
                    $result = $dbAdapter->insertRow('MAPPATURA_STUDENTI', $mappatura);
                    if ($result) {
                        $savedCount++;
                    } else {
                        $errorCount++;
                        $errors[] = "Impossibile salvare mappatura per studente CV ID: $idStudenteCV";
                    }
                }
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = "Errore studente CV ID $idStudenteCV: " . $e->getMessage();
            }
        }

        // Costruisci messaggio di successo/errore
        if ($savedCount > 0 || $updatedCount > 0) {
            $parts = [];
            if ($savedCount > 0) $parts[] = "$savedCount nuove";
            if ($updatedCount > 0) $parts[] = "$updatedCount aggiornate";

            $successMessage = "✅ Salvate " . implode(', ', $parts) . " mappature!";

            if ($errorCount > 0) {
                $successMessage .= " ⚠️ $errorCount non salvate.";
            }
        } else {
            throw new Exception("❌ Nessuna mappatura salvata. " . ($errorCount > 0 ? "Errori: " . implode("; ", $errors) : ""));
        }

        // Se ci sono errori, mostrali
        if (!empty($errors)) {
            $errorMessage = "Alcuni errori durante il salvataggio:\n" . implode("\n", array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $errorMessage .= "\n... e altri " . (count($errors) - 5) . " errori.";
            }
        }

    } catch (Exception $e) {
        $errorMessage = "Errore durante il salvataggio: " . $e->getMessage();
    }
}

// Carica studenti real-time (NO database locale!)
$studentiCV = [];
$studentiGC = [];
$error_loading = null;

try {
    // ClasseViva studenti - usa $idClasseCV già estratto dal mapping sopra
    if (!empty($idClasseCV)) {
        if ($classeVivaAPI) {
            $studentiCV = $classeVivaAPI->getStudentiClasse($idClasseCV);
        } else {
            $error_loading = $cvTokenNotice ?? 'Token ClasseViva non disponibile per caricare gli studenti.';
        }
    }

    // Google Classroom studenti - usa $courseId già estratto dal mapping sopra
    if (!empty($courseId)) {
        $studentiGC = $googleClassroomAPI->getCourseStudents($courseId);
    }

} catch (Exception $e) {
    $error_loading = "Errore caricamento studenti: " . $e->getMessage();
}

// Carica mappature esistenti per questo mapping (SOLO ID)
$allMappature = $dbAdapter->findAll('MAPPATURA_STUDENTI');
$mappatureMateria = array_filter($allMappature, fn($m) => ($m['id_mapping_materia'] ?? '') === $mappingId);

// Crea mappa per lookup veloce
$mappatureMap = [];
foreach ($mappatureMateria as $m) {
    if ($m['stato'] === 'attivo') {
        $mappatureMap[$m['id_studente_cv']] = $m['id_studente_gc'];
    }
}

// Suggerimenti automatici
function calculateSimilarity($str1, $str2) {
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));

    if ($str1 === $str2) return 1.0;

    $lev = levenshtein($str1, $str2);
    $maxLen = max(strlen($str1), strlen($str2));

    if ($maxLen === 0) return 1.0;

    return 1 - ($lev / $maxLen);
}

$suggerimenti = [];
foreach ($studentiCV as $cv) {
    $nomeCognomeCV = strtolower(($cv['nome'] ?? '') . ' ' . ($cv['cognome'] ?? ''));

    $bestMatch = null;
    $bestScore = 0;

    foreach ($studentiGC as $gc) {
        $nomeGC = strtolower($gc['name'] ?? '');
        $score = calculateSimilarity($nomeCognomeCV, $nomeGC);

        if ($score > $bestScore && $score > 0.75) {
            $bestScore = $score;
            $bestMatch = $gc;
        }
    }

    if ($bestMatch) {
        $suggerimenti[$cv['id']] = [
            'gc_id' => $bestMatch['id'],
            'gc_name' => $bestMatch['name'],
            'confidence' => $bestScore
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Associa Studenti - <?= htmlspecialchars($classe['nome'] ?? '') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .student-row {
            border-bottom: 1px solid #dee2e6;
            padding: 10px 0;
        }
        .student-row:hover {
            background-color: #f8f9fa;
        }
        .mapped {
            background-color: #d1e7dd;
        }
        .confidence-high {
            color: #198754;
        }
        .confidence-medium {
            color: #ffc107;
        }
        .confidence-low {
            color: #dc3545;
        }
        .privacy-note {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-people"></i> Associa Studenti';
    $headerActions = '<a class="nav-link" href="map_classes.php"><i class="bi bi-arrow-left"></i> Torna alle Associazioni</a>'
        . '<a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <div class="alert alert-info">
            <h5 class="mb-2"><i class="bi bi-info-circle"></i> Associazione Selezionata</h5>
            <div class="row">
                <div class="col-md-4">
                    <strong><i class="bi bi-building"></i> Classe:</strong><br>
                    <?= htmlspecialchars($className) ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="bi bi-book"></i> Materia:</strong><br>
                    <?= htmlspecialchars($subjectName) ?>
                </div>
                <div class="col-md-4">
                    <strong><i class="bi bi-google"></i> Corso Google:</strong><br>
                    <?= htmlspecialchars($courseName) ?>
                </div>
            </div>
        </div>

        <!-- Alert se foglio database non configurato -->
        <?php if ($sheetCheckError): ?>
            <div class="alert alert-danger">
                <h5><i class="bi bi-exclamation-triangle-fill"></i> Configurazione Database Mancante</h5>
                <p class="mb-2">
                    <strong>Errore:</strong> <?= htmlspecialchars($sheetCheckError) ?>
                </p>
                <p class="mb-3">
                    Il foglio <code>MAPPATURA_STUDENTI</code> nel database Excel non è configurato correttamente.
                </p>
                <div class="alert alert-info mb-3">
                    <h6><i class="bi bi-info-circle"></i> Come risolvere:</h6>
                    <ol class="mb-0">
                        <li>Apri il terminale nella cartella del progetto</li>
                        <li>Esegui: <code>php public/setup_mappatura_studenti_sheet.php</code></li>
                        <li>Segui le istruzioni a schermo</li>
                        <li>Ricarica questa pagina</li>
                    </ol>
                </div>
                <a href="map_classes.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Torna alle Associazioni
                </a>
            </div>
        <?php elseif (!$classeMappataCompleta): ?>
            <div class="alert alert-danger">
                <h5><i class="bi bi-exclamation-triangle"></i> Mappatura Incompleta</h5>
                <p class="mb-2">
                    Questa associazione non è configurata correttamente. Dati mancanti:
                </p>
                <ul class="mb-2">
                    <?php if (empty($idClasseCV)): ?>
                        <li><strong>ID Classe ClasseViva</strong> mancante</li>
                    <?php endif; ?>
                    <?php if (empty($courseId)): ?>
                        <li><strong>ID Corso Google Classroom</strong> mancante</li>
                    <?php endif; ?>
                </ul>
                <a href="map_classes.php" class="btn btn-danger">
                    <i class="bi bi-arrow-left"></i> Torna alle Associazioni
                </a>
            </div>
        <?php endif; ?>

        <?php if (!$sheetCheckError && $classeMappataCompleta): ?>
        <!-- IMPORTANTE: Avviso Privacy -->
        <div class="privacy-note">
            <h5><i class="bi bi-shield-lock"></i> Privacy e GDPR</h5>
            <p class="mb-0">
                <strong>Importante:</strong> I nomi degli studenti visualizzati sono letti in tempo reale da ClasseViva e Google Classroom.
                Nel database vengono salvati <strong>solo gli ID</strong>, mai nomi, email o altri dati personali.
            </p>
        </div>

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

        <?php if ($error_loading): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_loading) ?>
            </div>
        <?php endif; ?>

        <?php if ($cvTokenNotice): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <?php if (strpos($cvTokenNotice, 'ClasseViva') !== false): ?>
                    <br>
                    <small class="text-muted">
                        Per aggiornare il token apri <a href="user_integrations.php#classeviva-section">Integrazioni</a>.
                    </small>
                <?php endif; ?>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>

        <!-- Statistiche -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary"><?= count($studentiCV) ?></h3>
                        <small class="text-muted">Studenti ClasseViva</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info"><?= count($studentiGC) ?></h3>
                        <small class="text-muted">Studenti Google Classroom</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success"><?= count($mappatureMap) ?></h3>
                        <small class="text-muted">Già Mappati</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning"><?= count($studentiCV) - count($mappatureMap) ?></h3>
                        <small class="text-muted">Da Mappare</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card Anteprima Suggerimenti Automatici -->
        <?php if (!empty($suggerimenti) && count($studentiCV) > count($mappatureMap)): ?>
            <div class="card border-success mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-robot"></i> 🤖 Suggerimenti Automatici
                    </h5>
                    <span class="badge bg-light text-success">
                        <?= count($suggerimenti) ?> corrispondenze trovate
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        <strong>Trovate <?= count($suggerimenti) ?> corrispondenze automatiche</strong> su <?= count($studentiCV) ?> studenti.
                        Puoi accettarle tutte o rivederle una per una.
                    </div>

                    <div class="row mb-3">
                        <?php
                        $displayLimit = 5;
                        $displayedCount = 0;
                        foreach ($suggerimenti as $cvId => $suggestion):
                            if ($displayedCount >= $displayLimit) break;
                            $displayedCount++;

                            // Trova il nome dello studente CV
                            $cvName = '';
                            foreach ($studentiCV as $cv) {
                                if ($cv['id'] == $cvId) {
                                    $cvName = ($cv['nome'] ?? '') . ' ' . ($cv['cognome'] ?? '');
                                    break;
                                }
                            }

                            $confidence = $suggestion['confidence'];
                            $confidencePercent = round($confidence * 100);
                            $confidenceClass = $confidence >= 0.90 ? 'text-success' : ($confidence >= 0.80 ? 'text-warning' : 'text-danger');
                        ?>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-check-circle <?= $confidenceClass ?> me-2"></i>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($cvName) ?></strong>
                                        <i class="bi bi-arrow-right mx-2"></i>
                                        <?= htmlspecialchars($suggestion['gc_name']) ?>
                                        <span class="badge bg-secondary ms-2"><?= $confidencePercent ?>%</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (count($suggerimenti) > $displayLimit): ?>
                        <small class="text-muted d-block mb-3">
                            ... e altre <?= count($suggerimenti) - $displayLimit ?> corrispondenze
                        </small>
                    <?php endif; ?>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                        <button type="button" class="btn btn-success btn-lg" onclick="applyAllSuggestions()">
                            <i class="bi bi-check-all"></i> Accetta Tutti i <?= count($suggerimenti) ?> Suggerimenti
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#suggestionsList">
                            <i class="bi bi-eye"></i> Vedi Tutti i Suggerimenti
                        </button>
                    </div>

                    <!-- Lista completa suggerimenti (collapsabile) -->
                    <div class="collapse mt-3" id="suggestionsList">
                        <hr>
                        <div class="row">
                            <?php foreach ($suggerimenti as $cvId => $suggestion):
                                // Trova il nome dello studente CV
                                $cvName = '';
                                foreach ($studentiCV as $cv) {
                                    if ($cv['id'] == $cvId) {
                                        $cvName = ($cv['nome'] ?? '') . ' ' . ($cv['cognome'] ?? '');
                                        break;
                                    }
                                }

                                $confidence = $suggestion['confidence'];
                                $confidencePercent = round($confidence * 100);
                                $confidenceClass = $confidence >= 0.90 ? 'text-success' : ($confidence >= 0.80 ? 'text-warning' : 'text-danger');
                            ?>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-check-circle <?= $confidenceClass ?> me-2"></i>
                                        <div class="flex-grow-1 small">
                                            <strong><?= htmlspecialchars($cvName) ?></strong>
                                            <i class="bi bi-arrow-right mx-1"></i>
                                            <?= htmlspecialchars($suggestion['gc_name']) ?>
                                            <span class="badge bg-secondary ms-1"><?= $confidencePercent ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Mappatura -->
        <form method="POST" id="mappingForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_mappings">
            <input type="hidden" name="mappings" id="mappingsInput">

            <div class="card mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-link-45deg"></i> Associazioni Studenti
                    </h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-light" onclick="applyAllSuggestions()">
                            <i class="bi bi-robot"></i> Accetta Tutti i Suggerimenti
                        </button>
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-save"></i> Salva Mappature
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($studentiCV)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Nessuno studente trovato su ClasseViva.
                        </div>
                    <?php else: ?>
                        <?php foreach ($studentiCV as $cv): ?>
                            <?php
                            $cvId = $cv['id'];
                            $cvNome = ($cv['nome'] ?? '') . ' ' . ($cv['cognome'] ?? '');
                            $currentMapping = $mappatureMap[$cvId] ?? null;
                            $suggestion = $suggerimenti[$cvId] ?? null;
                            $isMapped = !empty($currentMapping);
                            ?>
                            <div class="student-row <?= $isMapped ? 'mapped' : '' ?>" data-cv-id="<?= htmlspecialchars($cvId) ?>">
                                <div class="row align-items-center">
                                    <!-- ClasseViva Student -->
                                    <div class="col-md-5">
                                        <div class="d-flex align-items-center">
                                            <?php if ($isMapped): ?>
                                                <i class="bi bi-check-circle-fill text-success me-2"></i>
                                            <?php else: ?>
                                                <i class="bi bi-circle text-muted me-2"></i>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?= htmlspecialchars($cvNome) ?></strong>
                                                <br>
                                                <small class="text-muted">ID: <?= htmlspecialchars($cvId) ?></small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Freccia -->
                                    <div class="col-md-1 text-center">
                                        <i class="bi bi-arrow-right-circle"></i>
                                    </div>

                                    <!-- Google Classroom Student -->
                                    <div class="col-md-6">
                                        <select class="form-select mapping-select" data-cv-id="<?= htmlspecialchars($cvId) ?>">
                                            <option value="">-- Seleziona studente GC --</option>
                                            <?php foreach ($studentiGC as $gc): ?>
                                                <?php
                                                $gcId = $gc['id'];
                                                $gcName = $gc['name'] ?? '';
                                                $gcEmail = $gc['email'] ?? '';
                                                $selected = ($currentMapping === $gcId) ? 'selected' : '';
                                                ?>
                                                <option value="<?= htmlspecialchars($gcId) ?>" <?= $selected ?>>
                                                    <?= htmlspecialchars($gcName) ?> (<?= htmlspecialchars($gcEmail) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <!-- Suggerimento -->
                                        <?php if ($suggestion && !$isMapped): ?>
                                            <?php
                                            $confidence = $suggestion['confidence'];
                                            $confidenceClass = $confidence >= 0.90 ? 'confidence-high' : ($confidence >= 0.80 ? 'confidence-medium' : 'confidence-low');
                                            $confidencePercent = round($confidence * 100);
                                            ?>
                                            <small class="<?= $confidenceClass ?> d-block mt-1">
                                                <i class="bi bi-lightbulb"></i>
                                                Suggerito: <?= htmlspecialchars($suggestion['gc_name']) ?>
                                                (<?= $confidencePercent ?>% match)
                                                <button type="button" class="btn btn-sm btn-link p-0"
                                                        onclick="acceptSuggestion('<?= htmlspecialchars($cvId) ?>', '<?= htmlspecialchars($suggestion['gc_id']) ?>')">
                                                    Accetta
                                                </button>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-5">
                <a href="map_classes.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Annulla
                </a>
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-save"></i> Salva Tutte le Mappature
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Accetta singolo suggerimento
        function acceptSuggestion(cvId, gcId) {
            const select = document.querySelector(`select[data-cv-id="${cvId}"]`);
            if (select) {
                select.value = gcId;
                const row = select.closest('.student-row');
                row.classList.add('mapped');
            }
        }

        // Accetta tutti i suggerimenti
        function applyAllSuggestions() {
            const suggestions = <?= json_encode($suggerimenti) ?>;

            Object.keys(suggestions).forEach(cvId => {
                const gcId = suggestions[cvId].gc_id;
                acceptSuggestion(cvId, gcId);
            });

            alert('Tutti i suggerimenti sono stati applicati. Clicca su "Salva" per confermare.');
        }

        // Salvataggio mappature
        document.getElementById('mappingForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const mappings = [];
            const selects = document.querySelectorAll('.mapping-select');

            selects.forEach(select => {
                const cvId = select.getAttribute('data-cv-id');
                const gcId = select.value;

                if (gcId) {
                    mappings.push({
                        cv_id: cvId,
                        gc_id: gcId,
                        method: 'manuale'
                    });
                }
            });

            if (mappings.length === 0) {
                alert('Seleziona almeno una mappatura prima di salvare.');
                return;
            }

            document.getElementById('mappingsInput').value = JSON.stringify(mappings);
            this.submit();
        });
    </script>
</body>
</html>
