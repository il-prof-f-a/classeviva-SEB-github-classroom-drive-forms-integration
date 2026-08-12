<?php
/**
 * Pagina di Consenso Privacy
 * Mostrata al primo login e fino a quando l'utente non accetta i termini obbligatori
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;

// Verifica che l'utente sia loggato
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$db = DatabaseFactory::createWithInitialization($config, true);
$userId = $_SESSION['user_id'];

// Recupera l'utente
$userRows = $db->findWhere('UTENTI', ['id_utente' => $userId]);
$user = !empty($userRows) ? $userRows[0] : null;

if (!$user) {
    header('Location: ../index.php?error=Utente non trovato');
    exit;
}

// Se l'utente ha già dato il consenso, redirect alla dashboard
if ($user['privacy_consent_given'] == 1) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = null;

// Gestione del form di consenso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_consent'])) {
    $privacyConsent = isset($_POST['privacy_consent']) ? 1 : 0;
    $termsConsent = isset($_POST['terms_consent']) ? 1 : 0;
    $marketingConsent = isset($_POST['marketing_consent']) ? 1 : 0;

    // Verifica che entrambi i consensi obbligatori siano stati dati
    if ($privacyConsent && $termsConsent) {
        // Recupera dati per il tracciamento
        $consentDate = date('Y-m-d H:i:s');
        $consentIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $consentToken = session_id();
        $privacyVersion = '1.0'; // Versione della privacy policy

        // Aggiorna l'utente con i consensi
        $marketingDate = $marketingConsent ? $consentDate : null;

        $updateData = [
            'privacy_consent_given' => 1,
            'privacy_consent_date' => $consentDate,
            'privacy_consent_ip' => $consentIp,
            'privacy_consent_token' => $consentToken,
            'privacy_consent_version' => $privacyVersion,
            'marketing_consent' => $marketingConsent,
            'marketing_consent_date' => $marketingDate
        ];

        try {
            $db->updateRow('UTENTI', 'id_utente', $userId, $updateData);

            // Registra l'evento nel log (opzionale)
            error_log("Privacy consent given by user {$userId} from IP {$consentIp}");

            // Redirect alla dashboard
            header('Location: index.php?consent=success');
            exit;
        } catch (Exception $e) {
            $error = "Errore durante il salvataggio dei consensi. Riprova.";
            error_log("Privacy consent error for user {$userId}: " . $e->getMessage());
        }
    } else {
        $error = "Devi accettare l'Informativa sulla Privacy e i Termini di Servizio per utilizzare la piattaforma.";
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consenso Privacy - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .consent-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .consent-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .consent-body {
            padding: 2rem;
        }
        .document-viewer {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            background: #f8f9fa;
            max-height: 400px;
            overflow-y: auto;
            padding: 1.5rem;
            margin: 1rem 0;
        }
        .consent-checkbox {
            padding: 1rem;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }
        .consent-checkbox:hover {
            border-color: #0d6efd;
            background: #f8f9fa;
        }
        .consent-checkbox.required {
            border-left: 4px solid #dc3545;
        }
        .consent-checkbox.optional {
            border-left: 4px solid #198754;
        }
        .consent-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .consent-checkbox label {
            cursor: pointer;
            margin-left: 0.5rem;
            font-weight: 500;
        }
        .required-badge {
            background: #dc3545;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .optional-badge {
            background: #198754;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        #submitBtn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .user-info {
            background: #e7f3ff;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="consent-card">
                    <div class="consent-header">
                        <i class="bi bi-shield-lock" style="font-size: 3rem;"></i>
                        <h2 class="mt-3 mb-2">Benvenuto nel Sistema UDA</h2>
                        <p class="mb-0">Prima di iniziare, è necessario leggere e accettare i seguenti documenti</p>
                    </div>

                    <div class="consent-body">
                        <!-- Info utente -->
                        <div class="user-info">
                            <strong><i class="bi bi-person-circle"></i> Utente:</strong> <?= htmlspecialchars($user['nome'] . ' ' . $user['cognome']) ?><br>
                            <strong><i class="bi bi-envelope"></i> Email:</strong> <?= htmlspecialchars($user['email']) ?>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="consentForm">
                            <!-- Sezione Informativa Privacy -->
                            <div class="mb-4">
                                <h4 class="mb-3">
                                    <i class="bi bi-shield-check text-primary"></i> Informativa sulla Privacy
                                    <span class="required-badge">OBBLIGATORIO</span>
                                </h4>
                                <p class="text-muted">
                                    Ti preghiamo di leggere attentamente la nostra Informativa sulla Privacy che descrive come trattiamo i tuoi dati personali in conformità al GDPR.
                                </p>

                                <div class="document-viewer" id="privacyDocument">
                                    <iframe src="privacy-policy-view.php" style="width: 100%; height: 350px; border: none;"></iframe>
                                </div>

                                <div class="text-center mb-3">
                                    <a href="privacy-policy-view.php" target="_blank" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-box-arrow-up-right"></i> Apri in una nuova finestra
                                    </a>
                                </div>

                                <div class="consent-checkbox required">
                                    <input type="checkbox" id="privacy_consent" name="privacy_consent" class="form-check-input required-checkbox">
                                    <label for="privacy_consent" class="form-check-label">
                                        <strong>Ho letto e accetto l'Informativa sulla Privacy</strong> *
                                        <div class="small text-muted mt-1">
                                            Acconsento al trattamento dei miei dati personali secondo quanto descritto nell'informativa.
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Sezione Termini di Servizio -->
                            <div class="mb-4">
                                <h4 class="mb-3">
                                    <i class="bi bi-file-text text-primary"></i> Termini di Servizio
                                    <span class="required-badge">OBBLIGATORIO</span>
                                </h4>
                                <p class="text-muted">
                                    I Termini di Servizio regolano l'utilizzo della piattaforma Sistema UDA.
                                </p>

                                <div class="document-viewer" id="termsDocument">
                                    <iframe src="termini-servizio-view.php" style="width: 100%; height: 350px; border: none;"></iframe>
                                </div>

                                <div class="text-center mb-3">
                                    <a href="termini-servizio-view.php" target="_blank" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-box-arrow-up-right"></i> Apri in una nuova finestra
                                    </a>
                                </div>

                                <div class="consent-checkbox required">
                                    <input type="checkbox" id="terms_consent" name="terms_consent" class="form-check-input required-checkbox">
                                    <label for="terms_consent" class="form-check-label">
                                        <strong>Ho letto e accetto i Termini di Servizio</strong> *
                                        <div class="small text-muted mt-1">
                                            Mi impegno a rispettare i termini e le condizioni d'uso della piattaforma.
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Sezione Marketing (Opzionale) -->
                            <div class="mb-4">
                                <h4 class="mb-3">
                                    <i class="bi bi-envelope-heart text-success"></i> Comunicazioni Marketing
                                    <span class="optional-badge">FACOLTATIVO</span>
                                </h4>
                                <p class="text-muted">
                                    Puoi scegliere se ricevere comunicazioni promozionali e novità sul Sistema UDA.
                                </p>

                                <div class="consent-checkbox optional">
                                    <input type="checkbox" id="marketing_consent" name="marketing_consent" class="form-check-input">
                                    <label for="marketing_consent" class="form-check-label">
                                        <strong>Acconsento a ricevere comunicazioni marketing</strong>
                                        <div class="small text-muted mt-1">
                                            Newsletter, aggiornamenti sulle nuove funzionalità e offerte speciali. Puoi revocare il consenso in qualsiasi momento dalle impostazioni del tuo account.
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Informazioni sul tracciamento -->
                            <div class="alert alert-info">
                                <h6><i class="bi bi-info-circle"></i> Tracciamento del Consenso</h6>
                                <p class="small mb-0">
                                    In conformità al GDPR, i tuoi consensi verranno registrati insieme ai seguenti dati:
                                    data e ora, indirizzo IP e identificativo di sessione. Questi dati sono utilizzati esclusivamente per dimostrare che il consenso è stato fornito in modo libero e informato.
                                </p>
                            </div>

                            <!-- Pulsanti -->
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" name="save_consent" id="submitBtn" class="btn btn-primary btn-lg" disabled>
                                    <i class="bi bi-check-circle"></i> Accetta e Prosegui
                                </button>
                                <a href="logout.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Rifiuta ed Esci
                                </a>
                            </div>

                            <p class="text-center text-muted small mt-3">
                                * Campi obbligatori per utilizzare la piattaforma
                            </p>
                        </form>
                    </div>
                </div>

                <div class="text-center text-white mt-3">
                    <small>Sistema UDA &copy; 2026 - Tutti i diritti riservati</small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gestione abilitazione pulsante "Accetta e Prosegui"
        const privacyCheckbox = document.getElementById('privacy_consent');
        const termsCheckbox = document.getElementById('terms_consent');
        const submitBtn = document.getElementById('submitBtn');

        function updateSubmitButton() {
            // Abilita il pulsante solo se entrambi i consensi obbligatori sono stati dati
            if (privacyCheckbox.checked && termsCheckbox.checked) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('btn-secondary');
                submitBtn.classList.add('btn-primary');
            } else {
                submitBtn.disabled = true;
                submitBtn.classList.remove('btn-primary');
                submitBtn.classList.add('btn-secondary');
            }
        }

        // Aggiungi event listeners
        privacyCheckbox.addEventListener('change', updateSubmitButton);
        termsCheckbox.addEventListener('change', updateSubmitButton);

        // Inizializza lo stato del pulsante
        updateSubmitButton();

        // Evidenzia le checkbox obbligatorie quando si prova a inviare senza averle spuntate
        document.getElementById('consentForm').addEventListener('submit', function(e) {
            if (!privacyCheckbox.checked || !termsCheckbox.checked) {
                e.preventDefault();
                alert('Devi accettare l\'Informativa sulla Privacy e i Termini di Servizio per continuare.');

                // Evidenzia le checkbox non spuntate
                if (!privacyCheckbox.checked) {
                    privacyCheckbox.parentElement.style.borderColor = '#dc3545';
                    setTimeout(() => {
                        privacyCheckbox.parentElement.style.borderColor = '';
                    }, 2000);
                }
                if (!termsCheckbox.checked) {
                    termsCheckbox.parentElement.style.borderColor = '#dc3545';
                    setTimeout(() => {
                        termsCheckbox.parentElement.style.borderColor = '';
                    }, 2000);
                }
            }
        });
    </script>

    <!-- Footer con link legali -->
    <footer class="text-center py-3 mt-4" style="background-color: rgba(255,255,255,0.1);">
        <div class="container">
            <small class="text-white">
                &copy; <?= date('Y') ?> Sistema UDA - Tutti i diritti riservati
            </small>
        </div>
    </footer>
</body>
</html>
