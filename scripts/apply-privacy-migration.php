<?php
/**
 * Script Helper per Applicare la Migrazione Privacy Consent
 *
 * ATTENZIONE: Questo script deve essere eseguito UNA SOLA VOLTA
 * Dopo l'esecuzione, è consigliato eliminare o rinominare questo file per sicurezza
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;

// Controllo di sicurezza: permetti esecuzione solo in sviluppo
if (!defined('DEVELOPMENT_MODE')) {
    define('DEVELOPMENT_MODE', true); // Cambia in false in produzione
}

if (!DEVELOPMENT_MODE) {
    die('Script disabilitato in produzione. Modifica DEVELOPMENT_MODE per abilitarlo.');
}

$executed = false;
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_migration'])) {
    try {
        $db = DatabaseFactory::createWithInitialization($config, true);

        // Leggi il file SQL
        $sqlFile = __DIR__ . '/../add_privacy_consent_fields.sql';

        if (!file_exists($sqlFile)) {
            throw new Exception("File SQL non trovato: {$sqlFile}");
        }

        $sql = file_get_contents($sqlFile);

        // Rimuovi i commenti SQL
        $sql = preg_replace('/^--.*$/m', '', $sql);

        // Separa le query (usando il punto e virgola come delimitatore)
        $queries = array_filter(
            array_map('trim', explode(';', $sql)),
            function($q) { return !empty($q); }
        );

        // Esegui ogni query
        foreach ($queries as $query) {
            if (!empty(trim($query))) {
                $db->query($query);
            }
        }

        $success = "Migrazione eseguita con successo! Sono stati aggiunti i campi di tracciamento consenso privacy alla tabella UTENTI.";
        $executed = true;

    } catch (Exception $e) {
        $error = "Errore durante l'esecuzione della migrazione: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrazione Privacy Consent - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .migration-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 800px;
            margin: 0 auto;
        }
        .migration-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        pre {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.875rem;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="migration-card">
            <div class="migration-header">
                <i class="bi bi-database-gear" style="font-size: 3rem;"></i>
                <h2 class="mt-3 mb-2">Migrazione Database Privacy Consent</h2>
                <p class="mb-0">Aggiungi i campi di tracciamento consenso GDPR alla tabella UTENTI</p>
            </div>

            <div class="p-4">
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <h5><i class="bi bi-check-circle"></i> Successo!</h5>
                        <p><?= htmlspecialchars($success) ?></p>

                        <h6 class="mt-3">Prossimi Passi:</h6>
                        <ol>
                            <li>Elimina o rinomina questo file (<code>apply-privacy-migration.php</code>) per sicurezza</li>
                            <li>Testa il sistema di consenso con un nuovo utente</li>
                            <li>Verifica che i documenti Privacy Policy e Termini di Servizio siano accessibili</li>
                            <li>Personalizza i placeholder nei file HTML con i tuoi dati</li>
                        </ol>

                        <a href="../PRIVACY_CONSENT_README.md" target="_blank" class="btn btn-info mt-3">
                            <i class="bi bi-book"></i> Leggi la Documentazione Completa
                        </a>
                    </div>
                <?php elseif ($error): ?>
                    <div class="alert alert-danger">
                        <h5><i class="bi bi-exclamation-triangle"></i> Errore</h5>
                        <p><?= htmlspecialchars($error) ?></p>

                        <h6 class="mt-3">Possibili Soluzioni:</h6>
                        <ul>
                            <li>Verifica che il database sia accessibile</li>
                            <li>Controlla che la tabella UTENTI esista</li>
                            <li>Verifica che il file <code>add_privacy_consent_fields.sql</code> esista</li>
                            <li>Controlla i permessi del database</li>
                            <li>Esegui manualmente lo script SQL da phpMyAdmin</li>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!$executed): ?>
                    <div class="warning-box">
                        <h5><i class="bi bi-exclamation-triangle"></i> Attenzione</h5>
                        <p>Questo script modificherà la struttura del database aggiungendo i seguenti campi alla tabella <code>UTENTI</code>:</p>
                    </div>

                    <h5 class="mt-4">Campi che Verranno Aggiunti:</h5>
                    <ul>
                        <li><code>privacy_consent_given</code> - Consenso privacy dato (0/1)</li>
                        <li><code>privacy_consent_date</code> - Data e ora del consenso</li>
                        <li><code>privacy_consent_ip</code> - IP al momento del consenso</li>
                        <li><code>privacy_consent_token</code> - Token di sessione</li>
                        <li><code>privacy_consent_version</code> - Versione privacy policy</li>
                        <li><code>marketing_consent</code> - Consenso marketing (0/1)</li>
                        <li><code>marketing_consent_date</code> - Data consenso marketing</li>
                    </ul>

                    <h5 class="mt-4">SQL da Eseguire:</h5>
                    <pre><?php
                    $sqlFile = __DIR__ . '/../add_privacy_consent_fields.sql';
                    if (file_exists($sqlFile)) {
                        echo htmlspecialchars(file_get_contents($sqlFile));
                    } else {
                        echo "File SQL non trovato!";
                    }
                    ?></pre>

                    <div class="alert alert-info mt-4">
                        <h6><i class="bi bi-info-circle"></i> Backup Raccomandato</h6>
                        <p class="mb-0">Prima di procedere, è consigliato effettuare un backup del database. La migrazione è <strong>non distruttiva</strong> (aggiunge solo campi, non rimuove dati).</p>
                    </div>

                    <form method="POST" class="mt-4">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirm" required>
                            <label class="form-check-label" for="confirm">
                                <strong>Confermo di aver letto e compreso le modifiche che verranno apportate al database</strong>
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="execute_migration" class="btn btn-primary btn-lg">
                                <i class="bi bi-database-add"></i> Esegui Migrazione
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Annulla
                            </a>
                        </div>
                    </form>

                    <div class="alert alert-warning mt-4">
                        <small>
                            <strong>Nota di Sicurezza:</strong> Questo script è abilitato solo in modalità sviluppo.
                            In produzione, esegui la migrazione manualmente da phpMyAdmin o MySQL command line.
                        </small>
                    </div>
                <?php else: ?>
                    <div class="d-grid gap-2 mt-4">
                        <a href="index.php" class="btn btn-primary">
                            <i class="bi bi-house"></i> Vai alla Dashboard
                        </a>
                    </div>
                <?php endif; ?>

                <hr class="my-4">

                <h5>Risorse Utili:</h5>
                <ul>
                    <li><a href="../privacy-policy.html" target="_blank">Privacy Policy</a></li>
                    <li><a href="../termini-servizio.html" target="_blank">Termini di Servizio</a></li>
                    <li><a href="../PRIVACY_CONSENT_README.md" target="_blank">Documentazione Completa</a></li>
                    <li><a href="privacy-consent.php">Pagina Consenso Privacy (test)</a></li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
