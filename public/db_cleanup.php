<?php
/**
 * Utility di manutenzione: cancella righe da una tabella del database.
 * - Seleziona la tabella
 * - Mostra le righe correnti con checkbox
 * - Permette la cancellazione di quelle selezionate
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\SchemaDefinitions;
use App\Core\Database\DatabaseFactory;

$db = DatabaseFactory::createWithInitialization($config, true);

// Elenco tabelle gestite dall'utente (esclude tabelle di sistema come UTENTI)
$allSheets = SchemaDefinitions::getAllSheets();
$systemTables = [
    'UTENTI',          // gestione account di sistema
];
$tables = array_values(array_diff(array_keys($allSheets), $systemTables));

$selectedTable = $_GET['table'] ?? ($_POST['table'] ?? null);
$rows = [];
$primaryKey = null;
$message = null;
$error = null;

// Determina la PK (usa la prima colonna definita nello schema)
function getPrimaryKeyFor(string $table): ?string {
    $defs = SchemaDefinitions::getAllSheets();
    if (!isset($defs[$table]['columns']) || empty($defs[$table]['columns'])) {
        return null;
    }
    return $defs[$table]['columns'][0];
}

// Impedisci la selezione di tabelle non permesse anche via query string
if ($selectedTable && !in_array($selectedTable, $tables, true)) {
    $error = "Tabella non disponibile per questa operazione.";
    $selectedTable = null;
    $rows = [];
}

if ($selectedTable) {
    $primaryKey = getPrimaryKeyFor($selectedTable) ?? 'id';
    try {
        $rows = $db->findAll($selectedTable);
    } catch (Exception $e) {
        $error = "Errore nel caricamento della tabella: " . $e->getMessage();
        $rows = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $selectedTable = $_POST['table'] ?? null;
    $primaryKey = getPrimaryKeyFor($selectedTable) ?? 'id';
    $ids = $_POST['rows'] ?? [];

    if (!$selectedTable) {
        $error = "Seleziona una tabella.";
    } elseif (empty($ids)) {
        $error = "Seleziona almeno una riga da cancellare.";
    } else {
        $deleted = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $db->deleteRow($selectedTable, $id, $primaryKey);
                $deleted++;
            } catch (Exception $e) {
                $failed++;
            }
        }
        $message = "Cancellate $deleted righe";
        if ($failed > 0) {
            $message .= " (non cancellate: $failed)";
        }

        // Ricarica le righe aggiornate
        try {
            $rows = $db->findAll($selectedTable);
        } catch (Exception $e) {
            $rows = [];
            $error = "Errore nel ricaricare la tabella: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Cleanup Tabelle</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container my-4">
    <div class="d-flex align-items-center mb-3">
        <h1 class="me-3">Pulizia Tabelle</h1>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">Torna alla dashboard</a>
    </div>
    <p class="text-muted">Seleziona una tabella, spunta le righe da cancellare e conferma. La chiave usata per la cancellazione è la prima colonna definita nello schema.</p>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="mb-4">
        <div class="row g-2">
            <div class="col-md-6">
                <select name="table" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Seleziona tabella --</option>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= htmlspecialchars($table) ?>" <?= $selectedTable === $table ? 'selected' : '' ?>>
                            <?= htmlspecialchars($table) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Carica</button>
            </div>
        </div>
    </form>

    <?php if ($selectedTable && $rows): ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong>Tabella:</strong> <?= htmlspecialchars($selectedTable) ?>
                    <span class="text-muted ms-2">PK: <?= htmlspecialchars($primaryKey) ?></span>
                </div>
                <form method="POST" class="mb-0" onsubmit="return confirm('Confermi la cancellazione delle righe selezionate?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($selectedTable) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash"></i> Cancella selezionate
                    </button>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                    <tr>
                        <th style="width:40px;">
                            <input type="checkbox" id="checkAll" onclick="toggleAll(this)">
                        </th>
                        <?php foreach (array_keys($rows[0]) as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="rows[]" value="<?= htmlspecialchars($row[$primaryKey] ?? '') ?>">
                            </td>
                            <?php foreach ($row as $value): ?>
                                <td><?= htmlspecialchars(is_scalar($value) ? $value : json_encode($value)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
                </form>
        </div>
    <?php elseif ($selectedTable): ?>
        <div class="alert alert-warning">Nessuna riga presente in questa tabella.</div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleAll(master) {
        document.querySelectorAll('input[name="rows[]"]').forEach(cb => cb.checked = master.checked);
    }
</script>
</body>
</html>
