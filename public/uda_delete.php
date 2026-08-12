<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;

$udaManager = new UDAManager($config);
$error = null;
$success = null;

$udaId = $_GET['id'] ?? null;

if (!$udaId) {
    header('Location: index.php');
    exit;
}

// Recupera info UDA prima di eliminarla
try {
    $udaData = $udaManager->getUDAComplete($udaId);
    if (!$udaData) {
        throw new Exception("UDA non trovata");
    }
    $uda = $udaData['uda'];
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Gestione conferma eliminazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        $deleted = $udaManager->deleteUDA($udaId);
        if ($deleted) {
            header('Location: index.php?deleted=1');
            exit;
        } else {
            throw new Exception("Impossibile eliminare l'UDA");
        }
    } catch (Exception $e) {
        $error = "Errore durante l'eliminazione: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Elimina UDA - Sistema Gestione UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-trash"></i> Elimina UDA';
    $pageSubtitle = $uda->titolo ?? '';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <br><a href="index.php" class="btn btn-sm btn-outline-danger mt-2">Torna alla Dashboard</a>
                    </div>
                <?php else: ?>
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h4 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Conferma Eliminazione</h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <strong>Attenzione!</strong> Stai per eliminare definitivamente questa UDA e tutti i dati correlati.
                                Questa azione non può essere annullata.
                            </div>

                            <h5>UDA da eliminare:</h5>
                            <ul>
                                <li><strong>Titolo:</strong> <?= htmlspecialchars($uda->titolo) ?></li>
                                <li><strong>Argomento:</strong> <?= htmlspecialchars($uda->argomento) ?></li>
                                <li><strong>ID:</strong> <?= htmlspecialchars($uda->id_uda) ?></li>
                            </ul>

                            <p class="text-muted">Verranno eliminati anche:</p>
                            <ul class="text-muted">
                                <li>Materiali didattici associati</li>
                                <li>Obiettivi didattici</li>
                                <li>Test e verifiche</li>
                                <li>Voti registrati</li>
                                <li>Assegnazioni classi</li>
                            </ul>

                            <hr>

                            <form method="POST" action="">
                                <input type="hidden" name="confirm_delete" value="1">
                                <div class="d-flex justify-content-between">
                                    <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle"></i> Annulla
                                    </a>
                                    <button type="submit" class="btn btn-danger">
                                        <i class="bi bi-trash"></i> Conferma Eliminazione
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
