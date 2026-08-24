<?php
/**
 * Mappatura GitHub Classroom (Percorso legacy).
 *
 * La mappatura delle GitHub Classroom è deprecata: gli assignment GitHub usano la
 * metodologia REST (riga TEST con piattaforma='github' + org in github_config_json).
 * Usa teaching_groups.php per i gruppi e github_assignment_create.php per gli assignment.
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Core\Security\Authorization;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Authorization::assertAuthenticated($_SESSION);

$pageTitle = 'Mappatura GitHub Classroom (Percorso legacy)';

$db = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$mappingService = new ProviderNeutralMappingService($db, $userId);

$requestScalar = static function (array $source, string $key, string $default = ''): string {
    $value = $source[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

$csrfToken = (string)($_SESSION['github_classroom_mapping_csrf'] ?? '');
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['github_classroom_mapping_csrf'] = $csrfToken;
}

$successMessage = null;
$errorMessage = null;

// Eliminazione mappatura: mai tramite GET. Verifica ownership via listGithubClassroomMappings().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestScalar($_POST, 'action') === 'delete') {
    try {
        $posted = $_POST['csrf_token'] ?? null;
        if (!is_string($posted) || $posted === '' || !hash_equals($csrfToken, $posted)) {
            throw new RuntimeException('Token CSRF non valido. Ricarica la pagina e riprova.');
        }
        $mappingIdToDelete = trim((string)($_POST['id'] ?? ''));
        if ($mappingIdToDelete === '') {
            throw new RuntimeException('Mappatura non valida.');
        }
        $ownedMapping = false;
        foreach ($mappingService->listGithubClassroomMappings() as $candidate) {
            if ((string)($candidate['id_mapping'] ?? '') === $mappingIdToDelete) {
                $ownedMapping = true;
                break;
            }
        }
        if (!$ownedMapping) {
            throw new RuntimeException('Mappatura non disponibile per questo utente.');
        }
        $mappingService->deactivateMapping($mappingIdToDelete);
        $successMessage = 'Mappatura eliminata con successo!';
        // Ritorno uniformato con integration_updated: dal wizard si torna a uda_create.php.
        $returnPath = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
        header('Location: ' . ($returnPath === 'uda_create.php'
            ? 'uda_create.php?integration_updated=1#2'
            : 'github_classroom_mapping.php?integration_updated=1'));
        exit;
    } catch (Throwable $e) {
        $errorMessage = "Errore nell'eliminazione: " . $e->getMessage();
    }
}

// Mappature esistenti (solo lettura).
$mappings = $mappingService->listGithubClassroomMappings();
$requestedGroupId = trim((string)($_GET['id_gruppo'] ?? $_POST['id_gruppo'] ?? ''));

function ghmap_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ghmap_h($pageTitle) ?> - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
<?php
$pageSubtitle = 'Deprecata — usa teaching_groups.php';
$headerActions = '<a class="nav-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>';
$pageActions = '';
include __DIR__ . '/partials/app_header.php';
?>
<div class="container mt-4">
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        <strong>Percorso legacy.</strong> La mappatura delle GitHub Classroom è stata sostituita dalla
        metodologia REST (assignment come riga TEST). Gestisci i gruppi in
        <a href="teaching_groups.php" class="alert-link">teaching_groups.php</a> e crea gli assignment da una UDA
        (Test &amp; attività &rarr; Crea assegnazione GitHub).
    </div>

    <?php if ($successMessage !== null): ?><div class="alert alert-success"><?= ghmap_h($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="alert alert-danger"><?= ghmap_h($errorMessage) ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-link-45deg"></i> Mappature GitHub Classroom esistenti (solo lettura)</h6>
        </div>
        <div class="card-body">
            <?php if ($mappings === []): ?>
                <div class="text-muted">Nessuna mappatura GitHub Classroom presente.</div>
            <?php else: ?>
                <table class="table table-sm table-striped">
                    <thead><tr><th>Gruppo</th><th>Classroom</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($mappings as $m): ?>
                        <tr>
                            <td><code><?= ghmap_h((string)($m['id_gruppo'] ?? '')) ?></code></td>
                            <td><?= ghmap_h((string)($m['classroom_name'] ?? ($m['github_classroom_id'] ?? ''))) ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa mappatura legacy?');">
                                    <input type="hidden" name="csrf_token" value="<?= ghmap_h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= ghmap_h((string)($m['id_mapping'] ?? '')) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
