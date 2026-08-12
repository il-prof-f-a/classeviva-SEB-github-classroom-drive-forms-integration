<?php
/**
 * Pagina di debug: lista tutte le domande in DOMANDE_INTERROGAZIONE (con filtro UDA).
 */
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';
use App\Core\Database\DatabaseFactory;

$db = DatabaseFactory::createWithInitialization($config, true);
$udaId = $_GET['id'] ?? null;

$all = $db->findAll('DOMANDE_INTERROGAZIONE');
if ($udaId) {
    $domande = array_values(array_filter($all, fn($d) => ($d['id_uda'] ?? '') === $udaId));
} else {
    $domande = $all;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Debug Domande</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container my-4">
    <h1 class="mb-3">Debug Domande</h1>
    <form class="mb-3" method="GET">
        <div class="input-group">
            <input type="text" class="form-control" name="id" placeholder="Filtro UDA ID" value="<?= htmlspecialchars($udaId ?? '') ?>">
            <button class="btn btn-primary" type="submit">Filtra</button>
            <a href="list_domande.php" class="btn btn-outline-secondary">Tutte</a>
        </div>
    </form>
    <div class="alert alert-info">Totale domande: <?= count($domande) ?> (tabella completa: <?= count($all) ?>)</div>
    <table class="table table-bordered table-sm">
        <thead>
        <tr>
            <th>#</th>
            <th>ID Domanda</th>
            <th>UDA</th>
            <th>Argomento</th>
            <th>Tipo</th>
            <th>Domanda</th>
            <th>Risposta attesa</th>
            <th>Parole chiave</th>
            <th>Difficolta</th>
            <th>Ordine</th>
            <th>Note</th>
            <th>Azioni</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($domande as $i => $d): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($d['id_domanda'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['id_uda'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['argomento'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['tipo'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['domanda'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['risposta_attesa'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['parole_chiave'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['difficolta'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['ordine_consigliato'] ?? '') ?></td>
                <td><?= htmlspecialchars($d['note'] ?? '') ?></td>
                <td>
                    <?php if (!empty($d['id_uda'])): ?>
                        <a class="btn btn-sm btn-primary" href="uda_questions.php?id=<?= urlencode($d['id_uda']) ?>" target="_blank">Apri UDA</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
