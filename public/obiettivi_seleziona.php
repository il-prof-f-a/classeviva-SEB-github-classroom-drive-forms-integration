<?php
/**
 * Selezione Obiettivi dal Repository Master
 *
 * Permette di:
 * - Visualizzare tutti gli obiettivi master
 * - Filtrare per tipo, area, livello Bloom, keyword
 * - Selezionare multipli obiettivi
 * - Associare obiettivi selezionati a una UDA
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Core\ObiettiviManager;
use App\Models\Obiettivo;

$db = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$obiettiviManager = new ObiettiviManager($db, $config);

$message = $_SESSION['flash_obiettivi'] ?? null;
unset($_SESSION['flash_obiettivi']);
$error = null;

// Gestione azioni
$action = $_GET['action'] ?? $_POST['action'] ?? null;
// Supporta id_uda da uda_view.php per preselezionare l'UDA
$preselectedUdaId = $_GET['id_uda'] ?? null;

try {
    // Recupera UDA
    $udas = $udaManager->getAllUDAs();

    if ($action === 'associa' && isset($_POST['obiettivi']) && isset($_POST['uda_id'])) {
        // Associa obiettivi selezionati a UDA
        $obiettiviIds = $_POST['obiettivi'];
        $udaId = $_POST['uda_id'];

        $count = $obiettiviManager->associaObiettiviAUDA($obiettiviIds, $udaId);

        // PRG: torna alla pagina con l'UDA in querystring (refresh-safe)
        $_SESSION['flash_obiettivi'] = "$count obiettivi associati alla UDA con successo!";
        header('Location: obiettivi_seleziona.php?id_uda=' . urlencode($udaId));
        exit;
    }

    // Applica filtri
    $filtri = [
        'tipo_obiettivo' => $_GET['tipo'] ?? null,
        'area_disciplinare' => $_GET['area'] ?? null,
        'livello_tassonomia' => isset($_GET['livello']) ? (int)$_GET['livello'] : null,
        'keyword' => $_GET['q'] ?? null
    ];

    // Rimuovi filtri vuoti
    $filtri = array_filter($filtri, function($v) { return $v !== null && $v !== ''; });

    $activeFilters = [];
    $qValue = trim((string)($_GET['q'] ?? ''));
    if ($qValue !== '') {
        $activeFilters[] = ['param' => 'q', 'label' => 'Ricerca', 'value' => $qValue];
    }
    $tipoValue = (string)($_GET['tipo'] ?? '');
    if ($tipoValue !== '') {
        $tipoLabel = Obiettivo::TIPI_OBIETTIVO[$tipoValue] ?? $tipoValue;
        $activeFilters[] = ['param' => 'tipo', 'label' => 'Tipo', 'value' => $tipoLabel];
    }
    $areaValue = (string)($_GET['area'] ?? '');
    if ($areaValue !== '') {
        $activeFilters[] = ['param' => 'area', 'label' => 'Area', 'value' => $areaValue];
    }
    $livelloValue = (string)($_GET['livello'] ?? '');
    if ($livelloValue !== '') {
        $livelloDesc = Obiettivo::LIVELLI_BLOOM[(int)$livelloValue] ?? '';
        $livelloLabel = $livelloDesc !== '' ? ($livelloValue . ' - ' . $livelloDesc) : $livelloValue;
        $activeFilters[] = ['param' => 'livello', 'label' => 'Bloom', 'value' => $livelloLabel];
    }

    // Recupera obiettivi con filtri
    $obiettivi = $obiettiviManager->getObiettiviMaster($filtri);

    // Determina quali master sono già associati all'UDA preselezionata.
    $fingerprint = static function (Obiettivo $o): string {
        return mb_strtolower(trim((string)$o->tipo_obiettivo))
            . '|' . mb_strtolower(trim((string)$o->codice))
            . '|' . mb_strtolower(trim((string)$o->descrizione))
            . '|' . mb_strtolower(trim((string)$o->competenza));
    };
    $associatiMap = [];
    if (!empty($preselectedUdaId)) {
        $fpAssociati = [];
        foreach ($obiettiviManager->getObiettiviPerUDA($preselectedUdaId) as $objUda) {
            $fpAssociati[$fingerprint($objUda)] = true;
        }
        foreach ($obiettivi as $obiettivo) {
            $associatiMap[(string)$obiettivo->id_obiettivo] = isset($fpAssociati[$fingerprint($obiettivo)]);
        }
    }

    // Gli obiettivi già associati vanno in cima (ordinamento stabile).
    usort($obiettivi, static function (Obiettivo $a, Obiettivo $b) use ($associatiMap): int {
        $aAssoc = !empty($associatiMap[(string)$a->id_obiettivo]) ? 1 : 0;
        $bAssoc = !empty($associatiMap[(string)$b->id_obiettivo]) ? 1 : 0;
        return $bAssoc <=> $aAssoc;
    });

    // Recupera valori unici per i filtri
    $aree = $obiettiviManager->getAreeDisciplinari();

} catch (Exception $e) {
    $error = $e->getMessage();
    $obiettivi = [];
    $aree = [];
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selezione Obiettivi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .obiettivo-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        .obiettivo-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .obiettivo-card.selected {
            border-left-color: #28a745;
            background-color: #d1e7dd;
        }
        .badge-tipo {
            min-width: 120px;
        }
        .sticky-top-bar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filter-badge {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-check2-square"></i> Seleziona Obiettivi dal Repository';
    $importUrl = 'obiettivi_import.php' . ($preselectedUdaId ? '?id_uda=' . urlencode($preselectedUdaId) : '');
    ob_start();
    if ($preselectedUdaId):
    ?>
    <a href="uda_view.php?id=<?php echo urlencode($preselectedUdaId); ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left"></i> Torna all'UDA
    </a>
    <?php endif; ?>
    <a href="<?php echo $importUrl; ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-file-earmark-arrow-up"></i> Import Obiettivi
    </a>
    <a href="index.php" class="btn btn-outline-light btn-sm">
        <i class="bi bi-house"></i> Dashboard
    </a>
    <?php
    $headerActions = ob_get_clean();
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container-fluid mt-4">
<!-- Messaggi -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Sidebar Filtri -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-funnel"></i> Filtri</h5>
                    </div>
                    <div class="card-body">
                        <form method="get" action="obiettivi_seleziona.php" id="filtriForm">
                            <?php if ($preselectedUdaId): ?>
                                <input type="hidden" name="id_uda" value="<?php echo htmlspecialchars($preselectedUdaId); ?>">
                            <?php endif; ?>
                            <!-- Ricerca Testuale -->
                            <div class="mb-3">
                                <label class="form-label">Ricerca</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" name="q" class="form-control"
                                           placeholder="Parole chiave..."
                                           value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Tipo Obiettivo</label>
                                <select name="tipo" class="form-select">
                                    <option value="">Tutti</option>
                                    <?php foreach (Obiettivo::TIPI_OBIETTIVO as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo ($_GET['tipo'] ?? '') === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Area Disciplinare</label>
                                <select name="area" class="form-select">
                                    <option value="">Tutte</option>
                                    <?php foreach ($aree as $area): ?>
                                        <option value="<?php echo htmlspecialchars($area); ?>" <?php echo ($_GET['area'] ?? '') === $area ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($area); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Livello Bloom</label>
                                <select name="livello" class="form-select">
                                    <option value="">Tutti</option>
                                    <?php foreach (Obiettivo::LIVELLI_BLOOM as $num => $desc): ?>
                                        <option value="<?php echo $num; ?>" <?php echo ($_GET['livello'] ?? '') == $num ? 'selected' : ''; ?>>
                                            <?php echo "$num - $desc"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-search"></i> Applica
                                </button>
                                <a href="obiettivi_seleziona.php<?php echo $preselectedUdaId ? '?id_uda=' . urlencode($preselectedUdaId) : ''; ?>" class="btn btn-outline-secondary flex-fill">
                                    <i class="bi bi-x-circle"></i> Reset
                                </a>
                            </div>
                        </form>

                        <!-- Filtri Attivi -->
                        <?php if (!empty($activeFilters)): ?>
                            <hr>
                            <h6>Filtri Attivi:</h6>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($activeFilters as $filter): ?>
                                    <button type="button" class="badge bg-info filter-badge border-0"
                                            onclick="removeFiltro('<?php echo $filter['param']; ?>')">
                                        <?php echo htmlspecialchars($filter['label'] . ': ' . $filter['value']); ?> x
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistiche -->
                <div class="card mt-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-graph-up"></i> Statistiche</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-1"><strong>Totale obiettivi:</strong> <?php echo count($obiettivi); ?></p>
                        <p class="mb-0"><strong>Selezionati:</strong> <span id="countSelezionati">0</span></p>
                    </div>
                </div>
            </div>

            <!-- Lista Obiettivi -->
            <div class="col-md-9">
                <!-- Barra Azioni Sticky -->
                <div class="sticky-top-bar">
                    <form method="post" action="obiettivi_seleziona.php" id="associaForm">
                        <input type="hidden" name="action" value="associa">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <select name="uda_id" class="form-select" required onchange="changeUda()">
                                    <option value="">-- Seleziona UDA --</option>
                                    <?php foreach ($udas as $uda): ?>
                                        <option value="<?php echo htmlspecialchars($uda->id_uda); ?>"
                                                <?php echo ($preselectedUdaId && $uda->id_uda === $preselectedUdaId) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($uda->titolo); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <button type="submit" class="btn btn-success w-100" id="btnAssocia" disabled>
                                    <i class="bi bi-link-45deg"></i> Associa Selezionati a UDA (<span id="countBtn">0</span>)
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Lista -->
                <div class="mt-3">
                    <?php if (empty($obiettivi)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nessun obiettivo trovato con i filtri attuali.
                            <?php if (!empty(array_filter($filtri))): ?>
                                <a href="obiettivi_seleziona.php<?php echo $preselectedUdaId ? '?id_uda=' . urlencode($preselectedUdaId) : ''; ?>">Rimuovi i filtri</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($obiettivi as $obiettivo): ?>
                            <div class="card obiettivo-card mb-3" data-obiettivo-id="<?php echo htmlspecialchars($obiettivo->id_obiettivo); ?>">
                                <div class="card-body">
                                    <div class="row align-items-start">
                                        <div class="col-auto">
                                            <input type="checkbox" class="form-check-input obiettivo-checkbox"
                                                   name="obiettivi[]"
                                                   value="<?php echo htmlspecialchars($obiettivo->id_obiettivo); ?>"
                                                   form="associaForm"
                                                   style="width: 24px; height: 24px;"
                                                   <?php echo !empty($associatiMap[(string)$obiettivo->id_obiettivo]) ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="col">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <span class="badge bg-primary badge-tipo">
                                                        <?php echo htmlspecialchars($obiettivo->getTipoObiettivoDescrizione()); ?>
                                                    </span>
                                                    <?php if (!empty($associatiMap[(string)$obiettivo->id_obiettivo])): ?>
                                                        <span class="badge bg-success">Associato</span>
                                                    <?php endif; ?>
                                                    <?php if ($obiettivo->codice): ?>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($obiettivo->codice); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($obiettivo->livello_tassonomia): ?>
                                                        <span class="badge bg-info">Bloom: <?php echo $obiettivo->getLivelloBloomDescrizione(); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($obiettivo->peso): ?>
                                                        <span class="badge bg-warning text-dark">Peso: <?php echo $obiettivo->peso; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <h6 class="card-title"><?php echo htmlspecialchars($obiettivo->descrizione); ?></h6>

                                            <?php if ($obiettivo->competenza): ?>
                                                <p class="mb-1"><strong>Competenza:</strong> <?php echo htmlspecialchars($obiettivo->competenza); ?></p>
                                            <?php endif; ?>

                                            <?php if ($obiettivo->area_disciplinare): ?>
                                                <p class="mb-1"><strong>Area:</strong> <span class="badge bg-light text-dark"><?php echo htmlspecialchars($obiettivo->area_disciplinare); ?></span></p>
                                            <?php endif; ?>

                                            <?php if ($obiettivo->parole_chiave): ?>
                                                <p class="mb-0"><small class="text-muted"><i class="bi bi-tags"></i> <?php echo htmlspecialchars($obiettivo->parole_chiave); ?></small></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gestione selezione obiettivi
        const checkboxes = document.querySelectorAll('.obiettivo-checkbox');
        const countSelezionati = document.getElementById('countSelezionati');
        const countBtn = document.getElementById('countBtn');
        const btnAssocia = document.getElementById('btnAssocia');

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelection);
        });

        function changeUda() {
            const select = document.querySelector('select[name="uda_id"]');
            if (!select) return;
            const url = new URL(window.location.href);
            if (select.value) {
                url.searchParams.set('id_uda', select.value);
            } else {
                url.searchParams.delete('id_uda');
            }
            window.location.href = url.toString();
        }

        // Inizializza conteggio ed evidenziazione in base ai checkbox preselezionati
        updateSelection();

        function updateSelection() {
            const selected = document.querySelectorAll('.obiettivo-checkbox:checked');
            const count = selected.length;

            countSelezionati.textContent = count;
            countBtn.textContent = count;
            btnAssocia.disabled = count === 0;

            // Evidenzia card selezionate
            document.querySelectorAll('.obiettivo-card').forEach(card => {
                const checkbox = card.querySelector('.obiettivo-checkbox');
                if (checkbox && checkbox.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            });
        }

        // Rimuovi singolo filtro
        function removeFiltro(key) {
            const url = new URL(window.location.href);
            url.searchParams.delete(key);
            window.location.href = url.toString();
        }

        // Submit su selezione checkbox (auto-submit filtri)
        document.querySelectorAll('#filtriForm select').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filtriForm').submit();
            });
        });
    </script>
</body>
</html>
