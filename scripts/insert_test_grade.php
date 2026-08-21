<?php

/**
 * Inserisce un voto di test e poi lo recupera per analisi
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

error_reporting(E_ALL);

echo "\n🧪 INSERIMENTO E ANALISI VOTO DI TEST\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile');
    }
    $cvAPI = new ClasseVivaAPI($config);
    echo "✅ Token valido - uso la sessione salvata\n\n";

    // Carica dati
    $db = DatabaseFactory::createWithInitialization($config, true);
    $classi = $db->findAll('CLASSI');

    $primaClasse = null;
    foreach ($classi as $classe) {
        if (!empty($classe['id_classeviva'])) {
            $primaClasse = $classe;
            break;
        }
    }

    $idClasse = $primaClasse['id_classeviva'];
    $nomeClasse = $primaClasse['nome'];

    $studenti = $cvAPI->getStudentiClasse($idClasse);
    $primoStudente = $studenti[0];
    $idStudente = $primoStudente['id'];
    $nomeStudente = trim($primoStudente['nome'] . ' ' . $primoStudente['cognome']);

    $classiConMaterie = $cvAPI->getClassesWithTeacherSubjects();
    $materieClasse = [];
    foreach ($classiConMaterie as $classe) {
        if ($classe['id'] == $idClasse) {
            $materieClasse = $classe['subjects'] ?? [];
            break;
        }
    }

    $primaMateria = $materieClasse[0];
    $idMateria = $primaMateria['id'];
    $nomeMateria = $primaMateria['name'];

    echo "📚 Classe: $nomeClasse\n";
    echo "👤 Studente: $nomeStudente\n";
    echo "📖 Materia: $nomeMateria\n\n";

    echo str_repeat("-", 80) . "\n";
    echo "FASE 1: Inserimento voto di test\n";
    echo str_repeat("-", 80) . "\n\n";

    // Prepara dati voto di test
    $gradeData = [
        'student_id' => $idStudente,
        'class_id' => $idClasse,
        'subject_id' => $idMateria,
        'subject_name' => $nomeMateria,
        'grade_type' => 'orale',
        'grade_value' => '8',
        'date' => date('Y-m-d'),
        'description' => 'Test analisi voti - interrogazione',
        'notes' => 'Voto inserito automaticamente per test sistema',
        'weight' => '100'
    ];

    echo "📝 Dati voto:\n";
    echo "   - Tipo: Orale\n";
    echo "   - Valore: 8\n";
    echo "   - Data: " . date('Y-m-d') . "\n";
    echo "   - Descrizione: Test analisi voti\n\n";

    echo "🚀 Pubblicazione in corso...\n";
    $result = $cvAPI->publishGrade($gradeData);

    if ($result['success']) {
        echo "✅ VOTO PUBBLICATO CON SUCCESSO!\n";
        echo "   - HTTP Code: {$result['http_code']}\n";
        echo "   - Evento ID: " . ($result['response']['evento_id'] ?? 'N/A') . "\n\n";
    } else {
        throw new Exception("Pubblicazione fallita: " . ($result['message'] ?? 'Errore sconosciuto'));
    }

    echo str_repeat("-", 80) . "\n";
    echo "FASE 2: Recupero e analisi voto inserito\n";
    echo str_repeat("-", 80) . "\n\n";

    echo "🔍 Recupero voti in corso...\n";

    // Aspetta 2 secondi per dare tempo a ClasseViva di processare
    sleep(2);

    $grades = $cvAPI->getStudentGrades($idStudente, $idClasse, $idMateria);

    $countOrale = count($grades['orale']);
    $countScritto = count($grades['scritto']);
    $countPratico = count($grades['pratico']);
    $total = $countOrale + $countScritto + $countPratico;

    echo "✅ Recuperati $total voti (Orali: $countOrale, Scritti: $countScritto, Pratici: $countPratico)\n\n";

    if ($total > 0) {
        echo str_repeat("=", 80) . "\n";
        echo "📊 ANALISI DETTAGLIATA VOTI\n";
        echo str_repeat("=", 80) . "\n\n";

        // Calcola medie
        $averages = $cvAPI->calculateGradeAverage($grades);

        // Mostra voti orali
        if (!empty($grades['orale'])) {
            echo "📢 VOTI ORALI (Media: {$averages['orale']}):\n";
            foreach ($grades['orale'] as $g) {
                echo "\n   🔹 Slot {$g['slot']}:\n";
                echo "      Valore: {$g['value']}\n";
                echo "      Data: {$g['date']}\n";
                echo "      Codice: {$g['description_code']}\n";
                if (!empty($g['notes'])) {
                    echo "      Note: {$g['notes']}\n";
                }
                if (!empty($g['evento_id'])) {
                    echo "      Evento ID: {$g['evento_id']}\n";
                }
            }
            echo "\n";
        }

        // Mostra voti scritti
        if (!empty($grades['scritto'])) {
            echo "📝 VOTI SCRITTI (Media: {$averages['scritto']}):\n";
            foreach ($grades['scritto'] as $g) {
                echo "\n   🔹 Slot {$g['slot']}:\n";
                echo "      Valore: {$g['value']}\n";
                echo "      Data: {$g['date']}\n";
                echo "      Codice: {$g['description_code']}\n";
                if (!empty($g['notes'])) echo "      Note: {$g['notes']}\n";
                if (!empty($g['evento_id'])) echo "      Evento ID: {$g['evento_id']}\n";
            }
            echo "\n";
        }

        // Mostra voti pratici
        if (!empty($grades['pratico'])) {
            echo "🔧 VOTI PRATICI (Media: {$averages['pratico']}):\n";
            foreach ($grades['pratico'] as $g) {
                echo "\n   🔹 Slot {$g['slot']}:\n";
                echo "      Valore: {$g['value']}\n";
                echo "      Data: {$g['date']}\n";
                echo "      Codice: {$g['description_code']}\n";
                if (!empty($g['notes'])) echo "      Note: {$g['notes']}\n";
                if (!empty($g['evento_id'])) echo "      Evento ID: {$g['evento_id']}\n";
            }
            echo "\n";
        }

        echo "✨ MEDIA GENERALE: {$averages['generale']}\n\n";

        echo str_repeat("-", 80) . "\n";
        echo "📦 DATI GREZZI (JSON):\n";
        echo str_repeat("-", 80) . "\n";
        echo json_encode($grades, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    } else {
        echo "⚠️ Nessun voto trovato dopo l'inserimento.\n";
        echo "   Potrebbe essere necessario attendere qualche secondo.\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRORE: " . $e->getMessage() . "\n";
    echo "\n" . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "✅ Test completato!\n\n";
