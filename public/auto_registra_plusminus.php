<?php
/**
 * Auto-Registrazione Evidenze PiùOMeno su ClasseViva
 *
 * Chiamare questa pagina ogni 10-30 minuti via cron HTTP:
 * wget -q -O- http://localhost/uda-system/public/auto_registra_plusminus.php
 * curl http://localhost/uda-system/public/auto_registra_plusminus.php
 *
 * Logica:
 * 1. Seleziona evidenze > 2 ore e non ancora registrate
 * 2. Raggruppa per studente
 * 3. Pubblica come annotazione su ClasseViva
 * 4. Marca come registrato=1
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\StudentiManager;
use App\Integration\ClasseVivaAPI;

// Log file
$logFile = ROOT_PATH . '/logs/auto_registra_plusminus.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

function logMessage($msg) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMessage("=== AVVIO AUTO-REGISTRAZIONE PLUSMINUS ===");

try {
    ClasseVivaTokenGuard::requireToken($config);
    logMessage("Token ClasseViva valido: uso la sessione salvata");
} catch (RuntimeException $e) {
    logMessage("Token ClasseViva non valido: " . $e->getMessage());
    die("ERRORE: Token ClasseViva non disponibile. " . $e->getMessage());
}

try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    $cvAPI = new ClasseVivaAPI($config);
    $studentiManager = new StudentiManager($dbAdapter, $cvAPI, $config);


    // Recupera evidenze da registrare (tutti i voti in coda non ancora pubblicati)
    $allQueue = $dbAdapter->findAll('PLUSMINUS_QUEUE');

    $daRegistrare = array_filter($allQueue, function($ev) {
        // Pubblica TUTTE le evidenze in coda (registrato = 0), senza filtro temporale
        $registrato = ($ev['registrato'] ?? 0) == 0;
        return $registrato;
    });

    $count = count($daRegistrare);
    logMessage("Evidenze da registrare (in coda): $count");

    if ($count === 0) {
        logMessage("Nessuna evidenza da registrare. Fine.");
        exit(0);
    }

    // Raggruppa per studente+materia+classe
    $gruppi = [];
    foreach ($daRegistrare as $ev) {
        $key = ($ev['id_studente_cv'] ?? '') . '|' .
               ($ev['id_materia_cv'] ?? '') . '|' .
               ($ev['id_classe_cv'] ?? '') . '|' .
               ($ev['id_uda'] ?? '');

        if (!isset($gruppi[$key])) {
            $gruppi[$key] = [
                'id_studente_cv' => $ev['id_studente_cv'] ?? '',
                'id_materia_cv' => $ev['id_materia_cv'] ?? '',
                'id_classe_cv' => $ev['id_classe_cv'] ?? '',
                'id_uda' => $ev['id_uda'] ?? '',
                'evidenze' => []
            ];
        }

        $gruppi[$key]['evidenze'][] = $ev;
    }

    logMessage("Gruppi (studente+materia+classe): " . count($gruppi));

    $registrate = 0;
    $errori = 0;

    foreach ($gruppi as $idx => $gruppo) {
        try {
            logMessage("--- Processo gruppo " . ($idx + 1) . "/" . count($gruppi) . " ---");

            $idStudenteCV = $gruppo['id_studente_cv'];
            $idMateriaCV = $gruppo['id_materia_cv'];
            $idClasseCV = $gruppo['id_classe_cv'];
            $idUda = $gruppo['id_uda'];
            $evidenze = $gruppo['evidenze'];

            logMessage("Studente: $idStudenteCV, Classe: $idClasseCV, Materia: $idMateriaCV, UDA: $idUda");
            logMessage("Evidenze nel gruppo: " . count($evidenze));

            // Conta +/-
            $positivi = 0;
            $negativi = 0;
            foreach ($evidenze as $ev) {
                if (($ev['valore'] ?? '') === '+') {
                    $positivi++;
                } else {
                    $negativi++;
                }
            }

            $totali = count($evidenze);
            logMessage("Positivi: $positivi, Negativi: $negativi, Totali: $totali");

            // Formula PiùOMeno: ((positivi/totali - 0.5) * 4) + 6
            $percentualePositivi = $totali > 0 ? ($positivi / $totali) : 0.5;
            $votoDecimi = (($percentualePositivi - 0.5) * 4) + 6;
            $votoDecimi = max(0, min(10, $votoDecimi)); // Clamp 0-10
            $votoDecimi = round($votoDecimi, 1);

            // Determina tipo annotazione
            $tipoAnnotazione = 'neutral';
            if ($percentualePositivi >= 0.7) {
                $tipoAnnotazione = 'positive';
            } elseif ($percentualePositivi < 0.5) {
                $tipoAnnotazione = 'negative';
            }

            // Testo annotazione
            $testoAnnotazione = "Valutazione laboratorio UDA: {$positivi}+ / {$negativi}- (tot: {$totali}) → Voto: {$votoDecimi}/10";

            // Aggiungi commenti se presenti
            $commenti = [];
            foreach ($evidenze as $ev) {
                if (!empty($ev['commento'])) {
                    $commenti[] = trim($ev['commento']);
                }
            }
            if (!empty($commenti)) {
                $testoAnnotazione .= "\nNote: " . implode('; ', array_slice($commenti, 0, 3));
            }

            logMessage("Testo annotazione: $testoAnnotazione");
            logMessage("Tipo: $tipoAnnotazione");

            // Pubblica annotazione su ClasseViva
            $idAnnotazioneCV = null;
            try {
                logMessage("📤 Pubblicazione annotazione su ClasseViva...");

                // Recupera nome materia
                $allSubjects = $cvAPI->getSubjects();
                $subjectName = '';
                foreach ($allSubjects as $subject) {
                    if (($subject['id'] ?? '') == $idMateriaCV) {
                        $subjectName = $subject['nome'] ?? $subject['description'] ?? 'Materia';
                        break;
                    }
                }
                if (empty($subjectName)) {
                    $subjectName = 'Materia';
                }

                $annotationData = [
                    'student_id' => $idStudenteCV,
                    'class_id' => $idClasseCV,
                    'subject_id' => $idMateriaCV,
                    'subject_name' => $subjectName,
                    'text' => $testoAnnotazione,
                    'date' => date('Y-m-d'),
                    'type' => $tipoAnnotazione,
                    'visible_to_student' => true
                ];

                logMessage("Dati annotazione: " . json_encode($annotationData));

                $result = $cvAPI->publishAnnotation($annotationData);

                if (isset($result['id'])) {
                    $idAnnotazioneCV = $result['id'];
                    logMessage("✓ Annotazione pubblicata con successo! ID: $idAnnotazioneCV");
                } else {
                    logMessage("⚠️ Annotazione pubblicata ma senza ID in risposta");
                    logMessage("   Risposta: " . json_encode($result));
                }

            } catch (Exception $e) {
                logMessage("❌ Errore pubblicazione annotazione: " . $e->getMessage());
                // Continua comunque a marcare come registrato nel database locale
            }

            // Aggiorna tutte le evidenze del gruppo
            logMessage("Aggiorno " . count($evidenze) . " evidenze in PLUSMINUS_QUEUE...");
            $dataRegistrazione = date('Y-m-d H:i:s');
            foreach ($evidenze as $ev) {
                $idEvidenza = $ev['id_evidenza'] ?? null;
                if ($idEvidenza) {
                    logMessage("  - Aggiorno evidenza ID: $idEvidenza");
                    $dbAdapter->updateRow('PLUSMINUS_QUEUE',
                        ['id_evidenza' => $idEvidenza],
                        [
                            'registrato' => 1,
                            'data_registrazione' => $dataRegistrazione,
                            'id_annotazione_cv' => $idAnnotazioneCV
                        ]
                    );
                    logMessage("  ✓ Evidenza $idEvidenza aggiornata");
                }
            }

            $registrate += count($evidenze);
            logMessage("✓ Gruppo completato. Totale evidenze registrate finora: $registrate");

        } catch (Exception $e) {
            logMessage("❌ Errore registrazione gruppo: " . $e->getMessage());
            logMessage("   Stack trace: " . $e->getTraceAsString());
            $errori++;
        }
    }

    logMessage("=== COMPLETATO ===");
    logMessage("Evidenze registrate: $registrate");
    logMessage("Errori: $errori");

} catch (Exception $e) {
    logMessage("❌ ERRORE FATALE: " . $e->getMessage());
    logMessage($e->getTraceAsString());
    exit(1);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Auto-Registrazione PiùOMeno</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
    </style>
</head>
<body>
    <h2>🤖 Auto-Registrazione PiùOMeno</h2>
    <p class="info">Ultima esecuzione: <?= date('Y-m-d H:i:s') ?></p>
    <p class="success">Evidenze registrate: <?= $registrate ?? 0 ?></p>
    <?php if (($errori ?? 0) > 0): ?>
        <p class="error">Errori: <?= $errori ?></p>
    <?php endif; ?>

    <hr>
    <h3>📋 Log Completo</h3>
    <pre style="background: white; padding: 10px; border: 1px solid #ccc; max-height: 400px; overflow-y: auto;">
<?= file_exists($logFile) ? htmlspecialchars(file_get_contents($logFile)) : 'Nessun log disponibile' ?>
    </pre>

    <hr>
    <p><strong>Setup Cron HTTP:</strong></p>
    <code>
    */10 * * * * wget -q -O- http://localhost/uda-system/public/auto_registra_plusminus.php<br>
    # Oppure ogni 30 minuti:<br>
    */30 * * * * curl -s http://localhost/uda-system/public/auto_registra_plusminus.php
    </code>
</body>
</html>
