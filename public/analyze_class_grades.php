<?php

/**
 * Analisi Voti Classe - Cerca studenti con voti esistenti
 */

define('REQUIRES_CLASSEVIVA', true);
require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

error_reporting(E_ALL);

echo "\n📊 ANALISI VOTI CLASSE\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile');
    }
    $cvAPI = new ClasseVivaAPI($config);
    echo "✅ Token valido - uso sessione salvata\n\n";

    // Carica classe
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

    echo "📚 Classe: $nomeClasse (ID: $idClasse)\n";

    // Carica studenti
    $studenti = $cvAPI->getStudentiClasse($idClasse);
    echo "👥 Studenti trovati: " . count($studenti) . "\n\n";

    // Carica materie
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

    echo "📖 Materia: $nomeMateria (ID: $idMateria)\n";
    echo str_repeat("-", 80) . "\n\n";

    // Analizza ogni studente
    $studentWithGrades = null;
    $totalGrades = 0;

    foreach ($studenti as $index => $studente) {
        $studentId = $studente['id'];
        $nomeCompleto = trim($studente['nome'] . ' ' . $studente['cognome']);

        echo "[" . ($index + 1) . "/" . count($studenti) . "] 🔍 $nomeCompleto... ";

        try {
            $grades = $cvAPI->getStudentGrades($studentId, $idClasse, $idMateria);

            $countOrale = count($grades['orale']);
            $countScritto = count($grades['scritto']);
            $countPratico = count($grades['pratico']);
            $totalStudentGrades = $countOrale + $countScritto + $countPratico;

            if ($totalStudentGrades > 0) {
                echo "✅ $totalStudentGrades voti (O:$countOrale S:$countScritto P:$countPratico)\n";

                if ($studentWithGrades === null) {
                    $studentWithGrades = [
                        'studente' => $studente,
                        'grades' => $grades
                    ];
                }

                $totalGrades += $totalStudentGrades;
            } else {
                echo "⚪ Nessun voto\n";
            }

        } catch (Exception $e) {
            echo "❌ Errore: " . $e->getMessage() . "\n";
        }

        // Rate limiting
        usleep(200000); // 200ms di pausa tra richieste
    }

    echo "\n" . str_repeat("=", 80) . "\n";
    echo "📊 RIEPILOGO:\n";
    echo "   - Totale voti trovati: $totalGrades\n\n";

    // Se abbiamo trovato uno studente con voti, mostra analisi dettagliata
    if ($studentWithGrades !== null) {
        $studente = $studentWithGrades['studente'];
        $grades = $studentWithGrades['grades'];

        $nomeCompleto = trim($studente['nome'] . ' ' . $studente['cognome']);

        echo str_repeat("=", 80) . "\n";
        echo "🎯 ANALISI DETTAGLIATA - $nomeCompleto\n";
        echo str_repeat("=", 80) . "\n\n";

        // Calcola medie
        $averages = $cvAPI->calculateGradeAverage($grades);

        // Voti Orali
        if (!empty($grades['orale'])) {
            echo "📢 VOTI ORALI (Media: {$averages['orale']}):\n";
            foreach ($grades['orale'] as $grade) {
                echo "   Slot {$grade['slot']}: {$grade['value']} ";
                echo "({$grade['date']})";
                if (!empty($grade['notes'])) {
                    echo " - {$grade['notes']}";
                }
                echo "\n";
            }
            echo "\n";
        }

        // Voti Scritti
        if (!empty($grades['scritto'])) {
            echo "📝 VOTI SCRITTI (Media: {$averages['scritto']}):\n";
            foreach ($grades['scritto'] as $grade) {
                echo "   Slot {$grade['slot']}: {$grade['value']} ";
                echo "({$grade['date']})";
                if (!empty($grade['notes'])) {
                    echo " - {$grade['notes']}";
                }
                echo "\n";
            }
            echo "\n";
        }

        // Voti Pratici
        if (!empty($grades['pratico'])) {
            echo "🔧 VOTI PRATICI (Media: {$averages['pratico']}):\n";
            foreach ($grades['pratico'] as $grade) {
                echo "   Slot {$grade['slot']}: {$grade['value']} ";
                echo "({$grade['date']})";
                if (!empty($grade['notes'])) {
                    echo " - {$grade['notes']}";
                }
                echo "\n";
            }
            echo "\n";
        }

        echo "✨ MEDIA GENERALE: {$averages['generale']}\n\n";

        // Dati grezzi JSON
        echo str_repeat("-", 80) . "\n";
        echo "🔍 DATI GREZZI (JSON):\n";
        echo json_encode($grades, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    } else {
        echo "⚠️ Nessuno studente ha voti in questa materia.\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRORE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n";
