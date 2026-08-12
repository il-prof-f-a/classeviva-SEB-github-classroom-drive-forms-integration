<?php
/**
 * Pagina di login alla root del sito.
 * Se l'utente è già autenticato viene reindirizzato alla dashboard in /public/.
 */

require_once __DIR__ . '/bootstrap.php';

// Se l'utente è già loggato, manda direttamente alla dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: public/index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <!-- Card Login -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white text-center">
                        <h4 class="mb-0"><i class="bi bi-collection"></i> Sistema UDA</h4>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3 text-center">Accesso Docenti</h5>
                        <p class="text-muted small text-center mb-4">
                            In questa fase l'accesso avviene esclusivamente tramite il tuo account Google.
                        </p>

                        <?php if (!empty($_GET['error'])): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i>
                                <?= htmlspecialchars($_GET['error']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2">
                            <a href="public/login_google.php" class="btn btn-danger btn-lg">
                                <i class="bi bi-google"></i> Accedi con Google
                            </a>
                        </div>

                        <p class="mt-4 mb-0 text-muted small text-center">
                            L'accesso è riservato ai docenti che hanno ricevuto le credenziali o l'invito.
                        </p>
                    </div>
                </div>

                <!-- Sezione introduttiva della guida -->
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <?php
                        // Include solo la sezione introduttiva della guida
                        $section = 'intro';
                        $containerClass = '';
                        $assetsPath = 'public/assets'; // Percorso corretto dalla root
                        include __DIR__ . '/public/partials/guida_content.php';
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer con link legali -->
    <footer class="text-center text-muted py-4 mt-5" style="background-color: #f8f9fa; border-top: 1px solid #dee2e6;">
        <div class="container">
            <small>
                &copy; <?= date('Y') ?> Sistema UDA - Tutti i diritti riservati
                <span class="mx-2">|</span>
                <a href="privacy-policy.html" target="_blank" class="text-decoration-none">Privacy Policy</a>
                <span class="mx-2">|</span>
                <a href="termini-servizio.html" target="_blank" class="text-decoration-none">Termini di Servizio</a>
            </small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

