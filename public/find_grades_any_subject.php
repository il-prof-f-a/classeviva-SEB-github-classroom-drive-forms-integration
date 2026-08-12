<?php
/**
 * Cerca voti in qualsiasi materia
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

error_reporting(E_ALL);

echo "\n🔍 RICERCA VOTI IN TUTTE LE MATERIE\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile');
    }
    $cvAPI = new ClasseVivaAPI($config);
    echo "✅ Token valido - uso la sessione salvata\n\n";

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

    echo "📚 Classe: $nomeClasse\n";

    // Carica studenti
    $studenti = $cvAPI->getStudentiClasse($idClasse);
    $primoStudente = $studenti[0];
    $idStudente = $primoStudente['id'];
    $nomeStudente = trim($primoStudente['nome'] . ' ' . $primoStudente['cognome']);

    echo "👤 Studente: $nomeStudente (ID: $idStudente)\n\n";

    // Carica TUTTE le materie
    $classiConMaterie = $cvAPI->getClassesWithTeacherSubjects();
    $materieClasse = [];
    foreach ($classiConMaterie as $classe) {
        if ($classe['id'] == $idClasse) {
            $materieClasse = $classe['subjects'] ?? [];
            break;
        }
    }

    echo "📖 Materie trovate: " . count($materieClasse) . "\n";
    echo str_repeat("-", 80) . "\n\n";

    // Prova ogni materia
    $gradeFound = false;

    foreach ($materieClasse as $index => $materia) {
        $idMateria = $materia['id'];
        $nomeMateria = $materia['name'];

        echo "[" . ($index + 1) . "/" . count($materieClasse) . "] 📖 $nomeMateria... ";

        try {
            $grades = $cvAPI->getStudentGrades($idStudente, $idClasse, $idMateria);

            $countOrale = count($grades['orale']);
            $countScritto = count($grades['scritto']);
            $countPratico = count($grades['pratico']);
            $total = $countOrale + $countScritto + $countPratico;

            if ($total > 0) {
                echo "✅ $total voti!\n";

                if (!$gradeFound) {
                    $gradeFound = true;

                    echo "\n" . str_repeat("=", 80) . "\n";
                    echo "🎯 PRIMA MATERIA CON VOTI TROVATA!\n";
                    echo str_repeat("=", 80) . "\n\n";
                    echo "👤 Studente: $nomeStudente\n";
                    echo "📖 Materia: $nomeMateria\n";
                    echo "📊 Voti: $total totali (Orali: $countOrale, Scritti: $countScritto, Pratici: $countPratico)\n\n";

                    // Calcola medie
                    $averages = $cvAPI->calculateGradeAverage($grades);

                    // Mostra voti orali
                    if (!empty($grades['orale'])) {
                        echo "📢 VOTI ORALI (Media: {$averages['orale']}):\n";
                        foreach ($grades['orale'] as $g) {
                            echo "   • Slot {$g['slot']}: {$g['value']} ({$g['date']})";
                            if (!empty($g['notes'])) echo " - {$g['notes']}";
                            echo "\n";
                        }
                        echo "\n";
                    }

                    // Mostra voti scritti
                    if (!empty($grades['scritto'])) {
                        echo "📝 VOTI SCRITTI (Media: {$averages['scritto']}):\n";
                        foreach ($grades['scritto'] as $g) {
                            echo "   • Slot {$g['slot']}: {$g['value']} ({$g['date']})";
                            if (!empty($g['notes'])) echo " - {$g['notes']}";
                            echo "\n";
                        }
                        echo "\n";
                    }

                    // Mostra voti pratici
                    if (!empty($grades['pratico'])) {
                        echo "🔧 VOTI PRATICI (Media: {$averages['pratico']}):\n";
                        foreach ($grades['pratico'] as $g) {
                            echo "   • Slot {$g['slot']}: {$g['value']} ({$g['date']})";
                            if (!empty($g['notes'])) echo " - {$g['notes']}";
                            echo "\n";
                        }
                        echo "\n";
                    }

                    echo "✨ MEDIA GENERALE: {$averages['generale']}\n\n";

                    // JSON
                    echo str_repeat("-", 80) . "\n";
                    echo "📦 DATI JSON:\n";
                    echo json_encode($grades, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

                    echo str_repeat("=", 80) . "\n\n";
                    echo "Continuo a cercare altre materie con voti...\n\n";
                }
            } else {
                echo "⚪ Nessun voto\n";
            }

        } catch (Exception $e) {
            echo "❌ Errore: " . $e->getMessage() . "\n";
        }

        usleep(150000); // 150ms pausa
    }

    echo "\n" . str_repeat("=", 80) . "\n";

    if (!$gradeFound) {
        echo "⚠️ Nessun voto trovato in nessuna materia per questo studente.\n";
        echo "💡 Prova a inserire un voto di test con test_voti_insertion.php\n";
    } else {
        echo "✅ Ricerca completata!\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRORE: " . $e->getMessage() . "\n";
}

echo "\n";
