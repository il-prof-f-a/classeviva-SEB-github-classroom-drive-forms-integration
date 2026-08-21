<?php
/**
 * Pagina di login alla root del sito.
 * Se l'utente è già autenticato viene reindirizzato alla dashboard in /public/.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\NotificationManager;
use App\Core\Security\RateLimiter;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$testAccessError = null;
$testAccessSuccess = null;
if (empty($_SESSION['test_access_csrf'])) {
    $_SESSION['test_access_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_test_update') {
    $rateLimiter = new RateLimiter(ROOT_PATH . '/storage/rate_limits');
    if (!$rateLimiter->allow('newsletter:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 3600)) {
        http_response_code(429);
        header('Retry-After: 3600');
        $testAccessError = 'Troppe richieste. Riprova più tardi.';
    }
    $csrf = (string)($_POST['test_access_csrf'] ?? '');
    $subscriberEmail = strtolower(trim((string)($_POST['subscriber_email'] ?? '')));
    $privacyAccepted = ($_POST['updates_privacy_consent'] ?? '') === '1';

    if ($testAccessError !== null) {
        // rate limited
    } elseif (!hash_equals((string)$_SESSION['test_access_csrf'], $csrf)) {
        $testAccessError = 'La richiesta è scaduta. Ricarica la pagina e riprova.';
    } elseif (!filter_var($subscriberEmail, FILTER_VALIDATE_EMAIL)) {
        $testAccessError = 'Inserisci un indirizzo email valido.';
    } elseif (!$privacyAccepted) {
        $testAccessError = 'Devi leggere e accettare l’informativa privacy per restare aggiornato.';
    } else {
        $notificationEmail = trim((string)($config['security']['test_access']['notification_email'] ?? ''));
        if ($notificationEmail === '') {
            $notificationEmail = trim((string)($config['notifications']['email']['test_recipient'] ?? ''));
        }
        if ($notificationEmail === '') {
            $notificationEmail = trim((string)($config['notifications']['email']['from_address'] ?? ''));
        }

        if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            $testAccessError = 'Il servizio email non è ancora configurato.';
        } else {
            $safeEmail = htmlspecialchars($subscriberEmail, ENT_QUOTES, 'UTF-8');
            $htmlBody = '<h2>Nuova iscrizione agli aggiornamenti UDA System</h2>'
                . '<p>Email: <strong>' . $safeEmail . '</strong></p>'
                . '<p>Consenso informativa privacy: sì</p>'
                . '<p>Data: ' . date('d/m/Y H:i:s') . '</p>';
            $mailer = new NotificationManager($config);
            if ($mailer->sendHtmlEmail($notificationEmail, 'Iscrizione aggiornamenti UDA System', $htmlBody)) {
                $testAccessSuccess = 'Grazie! Abbiamo registrato la tua richiesta di aggiornamento.';
                $_SESSION['test_access_csrf'] = bin2hex(random_bytes(32));
            } else {
                $testAccessError = 'Non è stato possibile inviare la richiesta. Riprova più tardi.';
            }
        }
    }
}

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

                        <?php if (($_GET['access'] ?? '') === 'testing' || $testAccessError !== null || $testAccessSuccess !== null): ?>
                            <div class="alert alert-warning">
                                <h6><i class="bi bi-hourglass-split"></i> Software in fase di test</h6>
                                <p class="mb-2">
                                    Questo software è in fase di test e verrà presto pubblicato su <strong>uda-smart.it</strong>.
                                </p>
                                <p class="mb-3">Inserisci qui la tua email per rimanere aggiornato:</p>

                                <?php if ($testAccessError !== null): ?>
                                    <div class="alert alert-danger py-2"><?= htmlspecialchars($testAccessError) ?></div>
                                <?php endif; ?>
                                <?php if ($testAccessSuccess !== null): ?>
                                    <div class="alert alert-success py-2"><?= htmlspecialchars($testAccessSuccess) ?></div>
                                <?php endif; ?>

                                <form method="post" action="index.php" class="mt-3">
                                    <input type="hidden" name="action" value="request_test_update">
                                    <input type="hidden" name="test_access_csrf" value="<?= htmlspecialchars((string)$_SESSION['test_access_csrf']) ?>">
                                    <div class="mb-2">
                                        <label for="subscriber_email" class="form-label">Email</label>
                                        <input type="email" id="subscriber_email" name="subscriber_email" class="form-control" required autocomplete="email">
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="updates_privacy_consent" name="updates_privacy_consent" value="1" required>
                                        <label class="form-check-label small" for="updates_privacy_consent">
                                            Ho letto l'<a href="privacy-policy.html" target="_blank" rel="noopener">Informativa Privacy</a> e acconsento all'invio della richiesta di aggiornamento.
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-outline-primary">
                                        <i class="bi bi-envelope"></i> Rimani aggiornato
                                    </button>
                                </form>
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
                <span class="mx-2">|</span>
                <a href="DISCLAIMER.md" target="_blank" rel="noopener" class="text-decoration-none">Disclaimer</a>
            </small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
