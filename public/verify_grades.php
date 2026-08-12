<?php
/**
 * Verifica e Sincronizzazione Voti
 *
 * Mostra tutti i voti del registro VOTI interno e verifica la loro presenza su ClasseViva.
 * Permette di eliminare i voti non più presenti su ClasseViva per sincronizzare lo stato.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

error_reporting(E_ALL);
// Evita timeout su verifiche lunghe
set_time_limit(0);

$classevivaTokenState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classevivaTokenState['ready'];
$cvTokenNotice = $classevivaTokenState['notice'] ?? null;
$cv = $cvReady ? new ClasseVivaAPI($config) : null;
// Usa l'adapter con scoping utente e metodi helper (UserScopedDatabaseAdapter)
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

$successMessage = null;
$errorMessage = null;
$deletedCount = 0;

if (!$cvReady) {
    $errorMessage = $cvTokenNotice ?? 'Token ClasseViva non valido o mancante.';
}

// Gestione eliminazione voti non sincronizzati
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_orphans') {
        try {
            $votiToDelete = json_decode($_POST['voti_to_delete'], true);

            foreach ($votiToDelete as $votoId) {
                if ($dbAdapter->deleteVoto($votoId)) {
                    $deletedCount++;
                }
            }

            $successMessage = "Eliminati {$deletedCount} voti non sincronizzati dal database interno";

        } catch (Exception $e) {
            $errorMessage = "Errore durante l'eliminazione: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete_single') {
        try {
            $votoId = $_POST['voto_id'];
            if ($dbAdapter->deleteVoto($votoId)) {
                $successMessage = "Voto eliminato con successo dal database interno";
            } else {
                $errorMessage = "Impossibile eliminare il voto";
            }
        } catch (Exception $e) {
            $errorMessage = "Errore durante l'eliminazione: " . $e->getMessage();
        }
    }
}

// Recupera tutti i voti dal registro VOTI interno
$allVoti = [];
$verifiedVoti = [];
$orphanedVoti = [];

if ($cvReady && $cv) {
    try {
    $allVoti = $dbAdapter->findAll('VOTI');

    // Filtra voti ClasseViva (includi anche i nuovi voti tracciati con id_voto)
    $cvVoti = array_filter($allVoti, function($voto) {
        return !empty($voto['id_voto_classeviva']) ||
               !empty($voto['id_voto']) ||
               (isset($voto['tipo_voto']) && strpos($voto['tipo_voto'], 'classeviva_') === 0) ||
               (isset($voto['tipo_valutazione']) && strpos($voto['tipo_valutazione'], 'classeviva_') === 0);
    });

    // Ordina per data decrescente
    usort($cvVoti, function($a, $b) {
        $dateA = $a['data_pubblicazione'] ?? $a['data_valutazione'] ?? '';
        $dateB = $b['data_pubblicazione'] ?? $b['data_valutazione'] ?? '';
        return strcmp($dateB, $dateA);
    });

    // Recupera tutte le classi e materie del docente per il matching
    $classiMaterie = [];
    $classNameMap = [];
    try {
        $classiMaterie = $cv->getClassesWithTeacherSubjects();
        foreach ($classiMaterie as $classe) {
            $classNameMap[$classe['id']] = $classe['name'] ?? ($classe['description'] ?? ($classe['nome'] ?? $classe['id']));
        }
    } catch (Exception $e) {
        // Ignora errori
    }

    // Cache per evitare chiamate ripetute (studente|classe|materia)
    $gradesCache = [];

    // Per ogni voto, verifica se esiste ancora su ClasseViva
    foreach ($cvVoti as &$voto) {
        $voto['verified'] = false;
        $voto['exists_on_classeviva'] = false;

        // Estrai informazioni dal voto
        $studentId = $voto['id_studente_cv'] ?? $voto['id_studente'] ?? '';
        $classId = $voto['id_classe_cv'] ?? $voto['id_classe'] ?? '';

        // Determina subject_id dal dettaglio_rubrica se disponibile
        $subjectId = null;
        if (!empty($voto['dettaglio_rubrica'])) {
            $dettaglio = json_decode($voto['dettaglio_rubrica'], true);
            $subjectId = $dettaglio['subject_id'] ?? null;
        }

        // Se non abbiamo subject_id, proviamo a recuperare tutte le materie della classe
        // e verificare su tutte
        $subjectsToCheck = [];
        if ($subjectId) {
            $subjectsToCheck = [$subjectId];
        } else {
            // Trova tutte le materie per questa classe
            foreach ($classiMaterie as $classe) {
                if ($classe['id'] == $classId) {
                    foreach ($classe['subjects'] as $subject) {
                        $subjectsToCheck[] = $subject['id'];
                    }
                    break;
                }
            }
        }

        // Se abbiamo tutte le info necessarie, verifica su ClasseViva
        if ($studentId && $classId && !empty($subjectsToCheck)) {
            try {
                // Determina il tipo di voto
                $tipoVoto = $voto['tipo_voto'] ?? ($voto['tipo_valutazione'] ?? '');
                $gradeType = 'orale'; // default

                if (strpos($tipoVoto, 'scritto') !== false) {
                    $gradeType = 'scritto';
                } elseif (strpos($tipoVoto, 'pratico') !== false) {
                    $gradeType = 'pratico';
                }

                // Cerca il voto su tutte le materie se necessario
                $found = false;
                foreach ($subjectsToCheck as $checkSubjectId) {
                    $cacheKey = $studentId . '|' . $classId . '|' . $checkSubjectId;
                    if (isset($gradesCache[$cacheKey])) {
                        $grades = $gradesCache[$cacheKey];
                    } else {
                        $grades = $cv->getStudentGrades($studentId, $classId, $checkSubjectId);
                        $gradesCache[$cacheKey] = $grades;
                    }

                    if (isset($grades[$gradeType])) {
                        foreach ($grades[$gradeType] as $cvGrade) {
                            // Verifica tramite evento_id se disponibile
                            if (!empty($voto['id_voto_classeviva']) &&
                                isset($cvGrade['evento_id']) &&
                                $cvGrade['evento_id'] == $voto['id_voto_classeviva']) {
                                $found = true;
                                break 2;
                            }

                            // Altrimenti verifica tramite placeholder nelle note
                            // Cerca sia con < > normali che con caratteri Unicode ï¹¤ ï¹¥
                            $note = $cvGrade['notes'] ?? '';
							$idToken = $voto['id_voto'] ?? '';
							$placeholders = [
								'<uda_s=' . $idToken . '>',
								'‹﹤uda_s=' . $idToken . '﹥',
								'<' . $idToken . '>',
								$idToken
							];
							foreach ($placeholders as $ph) {
								if ($ph && strpos($note, $ph) !== false) {
									$found = true;
									$voto['id_voto_classeviva'] = $cvGrade['evento_id'] ?? '';
									if (!$subjectId) {
										$dettaglio = json_decode($voto['dettaglio_rubrica'] ?? '{}', true);
										$dettaglio['subject_id'] = $checkSubjectId;
										$voto['dettaglio_rubrica'] = json_encode($dettaglio);
									}
									break 3;
								}
							}
                        }
                    }
                }

                $voto['verified'] = true;
                $voto['exists_on_classeviva'] = $found;

                if ($found) {
                    $verifiedVoti[] = $voto;
                } else {
                    $orphanedVoti[] = $voto;
                }

            } catch (Exception $e) {
                $voto['verification_error'] = $e->getMessage();
            }
        }
    }
    unset($voto); // Importante: libera il riferimento dopo foreach con &$voto

} catch (Exception $e) {
    $errorMessage = "Errore recupero voti: " . $e->getMessage();
}
} else {
    $errorMessage = $errorMessage ?? $cvTokenNotice;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica e Sincronizzazione Voti</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stats-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }

        .stat-box {
            text-align: center;
            padding: 15px;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .grade-row-verified {
            background-color: #d4edda;
        }

        .grade-row-orphaned {
            background-color: #fff3cd;
        }

        .grade-row-error {
            background-color: #f8d7da;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-check2-circle"></i> Verifica e Sincronizzazione Voti';
    $cvUser = $config['classeviva']['token']['ident'] ?? 'Docente';
    ob_start();
    ?>
    <a href="index.php" class="btn btn-outline-light btn-sm">
        <i class="fas fa-home me-1"></i>Home
    </a>
    <a href="manage_grades.php" class="btn btn-outline-light btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Gestione Voti
    </a>
    <span class="text-white small">
        <i class="fas fa-user-circle me-2"></i>
        <?php echo htmlspecialchars($cvUser); ?>
    </span>
    <?php
    $headerActions = ob_get_clean();
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container-fluid mt-4">
        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistiche -->
        <div class="stats-card">
            <div class="row">
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number text-primary"><?= count($cvVoti) ?></div>
                        <div class="stat-label">Totale Voti ClasseViva</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number text-success"><?= count($verifiedVoti) ?></div>
                        <div class="stat-label">Sincronizzati</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <div class="stat-number text-warning"><?= count($orphanedVoti) ?></div>
                        <div class="stat-label">Non Trovati</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-box">
                        <?php if (count($orphanedVoti) > 0): ?>
                            <button type="button" class="btn btn-danger" onclick="deleteOrphanedGrades()">
                                <i class="fas fa-trash me-2"></i>Elimina Non Sincronizzati
                            </button>
                        <?php else: ?>
                            <span class="text-success"><i class="fas fa-check-circle fs-1"></i></span>
                            <div class="stat-label mt-2">Tutto Sincronizzato!</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabella Tutti i Voti -->
        <div class="card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Tutti i Voti ClasseViva nel Registro Interno
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Stato</th>
                                <th>ID Voto</th>
                                <th>Studente</th>
                                <th>Classe</th>
                                <th>Tipo</th>
                                <th>Voto</th>
                                <th>Data</th>
                                <th>Note</th>
                                <th>ID ClasseViva</th>
                                <th>Link ClasseViva</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cvVoti)): ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">
                                        Nessun voto ClasseViva trovato nel registro interno
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cvVoti as $voto): ?>
                                    <?php
                                    $rowClass = '';
                                    $statusIcon = '';
                                    $statusText = '';

                                    if (!$voto['verified']) {
                                        $rowClass = 'grade-row-error';
                                        $statusIcon = '<i class="fas fa-exclamation-circle text-danger"></i>';
                                        $statusText = 'Non verificato';
                                    } elseif ($voto['exists_on_classeviva']) {
                                        $rowClass = 'grade-row-verified';
                                        $statusIcon = '<i class="fas fa-check-circle text-success"></i>';
                                        $statusText = 'Sincronizzato';
                                    } else {
                                        $rowClass = 'grade-row-orphaned';
                                        $statusIcon = '<i class="fas fa-exclamation-triangle text-warning"></i>';
                                        $statusText = 'Non trovato su ClasseViva';
                                    }
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="text-center">
                                            <?= $statusIcon ?>
                                            <small class="d-block"><?= $statusText ?></small>
                                        </td>
                                        <td>
                                            <small><code><?= htmlspecialchars($voto['id_voto'] ?? '') ?></code></small>
                                        </td>
                                        <?php
                                        $studentName = trim(($voto['cognome_studente'] ?? '') . ' ' . ($voto['nome_studente'] ?? ''));
                                        if ($studentName === '') {
                                            $studentName = $voto['nome_studente'] ?? ($voto['student_name'] ?? ($voto['nome'] ?? 'N/D'));
                                        }

                                        $classIdRow = $voto['id_classe_cv'] ?? $voto['id_classe'] ?? '';
                                        $classeLabel = $voto['nome_classe'] ?? ($classNameMap[$classIdRow] ?? ($classIdRow ?: 'N/D'));
                                        $tipoValutazione = $voto['tipo_valutazione'] ?? $voto['tipo_voto'] ?? 'N/D';
                                        $gradeValue = $voto['voto'] ?? $voto['voto_numerico'] ?? 'N/D';
                                        $dataVal = $voto['data_pubblicazione'] ?? $voto['data_valutazione'] ?? 'N/D';
                                        $noteFull = $voto['giudizio'] ?? $voto['descrizione'] ?? $voto['note'] ?? '';
                                        ?>
                                        <td><?= htmlspecialchars($studentName) ?></td>
                                        <td><?= htmlspecialchars($classeLabel) ?></td>
                                        <td><?= htmlspecialchars($tipoValutazione) ?></td>
                                        <td><strong><?= htmlspecialchars($gradeValue) ?></strong></td>
                                        <td><?= htmlspecialchars($dataVal) ?></td>
                                        <td>
                                            <small><?= htmlspecialchars(substr($noteFull, 0, 80)) ?></small>
                                            <?php if (strlen($noteFull) > 80): ?>
                                                <small>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($voto['id_voto_classeviva'])): ?>
                                                <code><?= htmlspecialchars($voto['id_voto_classeviva']) ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            // Estrai subject_id dal dettaglio_rubrica
                                            $subjectId = null;
                                            if (!empty($voto['dettaglio_rubrica'])) {
                                                $dettaglio = json_decode($voto['dettaglio_rubrica'], true);
                                            $subjectId = $dettaglio['subject_id'] ?? null;
                                        }

                                            $classId = $voto['id_classe_cv'] ?? $voto['id_classe'] ?? null;

                                            // Se non abbiamo subject_id, usa la prima materia disponibile per questa classe
                                            if ($classId && !$subjectId) {
                                                foreach ($classiMaterie as $classe) {
                                                    if ($classe['id'] == $classId && !empty($classe['subjects'])) {
                                                        $subjectId = $classe['subjects'][0]['id'];
                                                        break;
                                                    }
                                                }
                                            }

                                            // Fallback a id_materia_cv presente sulla riga voto
                                            if (!$subjectId && !empty($voto['id_materia_cv'])) {
                                                $subjectId = $voto['id_materia_cv'];
                                            }

                                            if ($classId && $subjectId): ?>
                                                <a href="https://web.spaggiari.eu/cvv/app/default/regvoti.php?classe_id=<?= htmlspecialchars($classId) ?>&materia_id=<?= htmlspecialchars($subjectId) ?>"
                                                   target="_blank"
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-external-link-alt me-1"></i>Apri
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted" title="Classe non trovata">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$voto['exists_on_classeviva']): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="deleteSingleGrade('<?= htmlspecialchars($voto['id_voto']) ?>', '<?= htmlspecialchars($studentName) ?>', '<?= htmlspecialchars($gradeValue) ?>')">
                                                    <i class="fas fa-trash me-1"></i>Elimina
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3 mb-4">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                <span class="badge bg-success">Verde</span> = Voto trovato su ClasseViva |
                <span class="badge bg-warning">Giallo</span> = Voto non trovato su ClasseViva |
                <span class="badge bg-danger">Rosso</span> = Errore verifica
            </small>
        </div>
    </div>

    <!-- Form nascosto per eliminazione multipla -->
    <form id="deleteOrphansForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_orphans">
        <input type="hidden" name="voti_to_delete" id="voti_to_delete">
    </form>

    <!-- Form nascosto per eliminazione singola -->
    <form id="deleteSingleForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_single">
        <input type="hidden" name="voto_id" id="single_voto_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteOrphanedGrades() {
            const orphanedVoti = <?= json_encode(array_column($orphanedVoti, 'id_voto')) ?>;

            if (orphanedVoti.length === 0) {
                alert('Nessun voto da eliminare');
                return;
            }

            const message = `Sei sicuro di voler eliminare ${orphanedVoti.length} voti non sincronizzati dal database interno?\n\n` +
                          `Questa azione è irreversibile.\n\n` +
                          `I voti eliminati sono quelli non più presenti su ClasseViva.`;

            if (confirm(message)) {
                document.getElementById('voti_to_delete').value = JSON.stringify(orphanedVoti);
                document.getElementById('deleteOrphansForm').submit();
            }
        }

        function deleteSingleGrade(votoId, studentName, gradeValue) {
            const message = `Sei sicuro di voler eliminare il voto?\n\n` +
                          `Studente: ${studentName}\n` +
                          `Voto: ${gradeValue}\n\n` +
                          `Questa azione è irreversibile.`;

            if (confirm(message)) {
                document.getElementById('single_voto_id').value = votoId;
                document.getElementById('deleteSingleForm').submit();
            }
        }
    </script>
</body>
</html>
