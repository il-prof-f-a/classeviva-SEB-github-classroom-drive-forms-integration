<?php
/**
 * Guida completa del portale UDA
 * Utilizza il contenuto riutilizzabile da partials/guida_content.php
 */
$config = require_once __DIR__ . '/../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guida Portale UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f7f9fc; }
        .section-card {
            border: 1px solid #e9ecef;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            background: #fff;
            padding: 2rem;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-book"></i> Guida Portale UDA';
    $pageSubtitle = 'Tutte le funzionalità in un colpo d\'occhio';
    ob_start();
    ?>
    <a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Torna alla Dashboard</a>
    <a href="uda_create.php" class="btn btn-outline-light btn-sm"><i class="bi bi-plus-circle"></i> Crea una nuova UDA</a>
    <?php
    $headerActions = ob_get_clean();
    include __DIR__ . '/partials/app_header.php';
    ?>

<div class="container my-4">
    <div class="section-card mb-4">
        <h1 class="fw-bold mb-3"><i class="bi bi-stars"></i> Guida Completa del Portale UDA</h1>
        <p class="lead">Sistema integrato per la gestione automatizzata delle Unità di Apprendimento, dalla progettazione alla valutazione.</p>
    </div>

    <div class="section-card">
        <?php
        // Include il contenuto completo della guida
        $section = 'full';
        $containerClass = '';
        include __DIR__ . '/partials/guida_content.php';
        ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
