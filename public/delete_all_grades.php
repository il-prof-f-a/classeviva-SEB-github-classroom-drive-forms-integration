<?php

/**
 * Script per cancellare tutti i voti di uno studente
 *
 * Usa l'endpoint di cancellazione voti di ClasseViva
 */

define('REQUIRES_CLASSEVIVA', true);
require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

error_reporting(E_ALL);

echo "\n🗑️  CANCELLAZIONE VOTI STUDENTE\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile');
    }
    $cvAPI = new ClasseVivaAPI($config);

    // Carica dati studente
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
    echo "👤 Studente: $nomeStudente (ID: $idStudente)\n";
    echo "📖 Materia: $nomeMateria (ID: $idMateria)\n\n";

    echo str_repeat("-", 80) . "\n";
    echo "FASE 1: Recupero voti esistenti\n";
    echo str_repeat("-", 80) . "\n\n";

    $grades = $cvAPI->getStudentGrades($idStudente, $idClasse, $idMateria);
    $totalGrades = count($grades);

    echo "✅ Trovati $totalGrades voti\n\n";

    if ($totalGrades == 0) {
        echo "⚠️ Nessun voto da cancellare.\n";
        exit(0);
    }

    // Mostra voti
    foreach ($grades as $grade) {
        echo "  🔹 Voto #{$grade['index']}: {$grade['value']} ({$grade['date']})\n";
    }

    echo "\n" . str_repeat("-", 80) . "\n";
    echo "FASE 2: Recupero evento_id dalla pagina regvoti.php\n";
    echo str_repeat("-", 80) . "\n\n";

    // Login web
    $reflection = new ReflectionClass($cvAPI);
    $method = $reflection->getMethod('authenticateWeb');
    $method->setAccessible(true);
    $method->invoke($cvAPI);

    $property = $reflection->getProperty('phpSessionId');
    $property->setAccessible(true);
    $sessionId = $property->getValue($cvAPI);

    // Recupera HTML con evento_id
    $url = 'https://web.spaggiari.eu/cvv/app/default/regvoti.php';
    $client = new \GuzzleHttp\Client();
    $response = $client->get($url, [
        'query' => [
            'classe_id' => $idClasse,
            'gruppo_id' => '',
            'materia_id' => $idMateria
        ],
        'headers' => [
            'Cookie' => 'PHPSESSID=' . $sessionId,
            'User-Agent' => 'Mozilla/5.0'
        ],
        'timeout' => 30
    ]);

    $html = (string) $response->getBody();

    // Estrai tutti gli evento_id dello studente
    preg_match_all(
        '/studente_id=["\']' . $idStudente . '["\'][^>]*evento_id=["\'](\d+)["\'][^>]*voto_valore=["\']([^"\']+)["\'][^>]*mydata=["\']([^"\']+)["\']/i',
        $html,
        $matches,
        PREG_SET_ORDER
    );

    $eventoIds = [];
    foreach ($matches as $match) {
        $eventoId = $match[1];
        $voto = $match[2];
        $data = $match[3];

        // Salta evento_id = 0 (voti vuoti)
        if ($eventoId != '0' && !empty($voto) && $voto != '0.000') {
            $eventoIds[] = [
                'evento_id' => $eventoId,
                'voto' => $voto,
                'data' => $data
            ];
        }
    }

    echo "✅ Trovati " . count($eventoIds) . " voti con evento_id valido:\n\n";

    if (empty($eventoIds)) {
        echo "⚠️ Nessun evento_id trovato. I voti potrebbero essere:\n";
        echo "   - Voti competenza (non standard)\n";
        echo "   - Voti senza evento_id nella tabella\n";
        echo "   - Voti visibili solo in regvoti_dettaglio.php\n\n";
        echo "❌ Impossibile procedere con la cancellazione automatica.\n";
        exit(1);
    }

    foreach ($eventoIds as $i => $ev) {
        $num = $i + 1;
        echo "  🔹 Evento #$num:\n";
        echo "     ID: {$ev['evento_id']}\n";
        echo "     Voto: {$ev['voto']}\n";
        echo "     Data: {$ev['data']}\n";
        echo "\n";
    }

    echo str_repeat("-", 80) . "\n";
    echo "FASE 3: Cancellazione voti\n";
    echo str_repeat("-", 80) . "\n\n";

    echo "⚠️  ATTENZIONE: Sto per cancellare " . count($eventoIds) . " voti!\n";
    echo "Procedo tra 3 secondi...\n\n";
    sleep(3);

    $deleted = 0;
    $failed = 0;

    foreach ($eventoIds as $i => $ev) {
        echo "[" . ($i+1) . "/" . count($eventoIds) . "] Cancellazione evento_id {$ev['evento_id']} (voto: {$ev['voto']})... ";

        try {
            $result = $cvAPI->deleteGrade([
                'evento_id' => $ev['evento_id'],
                'student_id' => $idStudente,
                'class_id' => $idClasse,
                'subject_id' => $idMateria,
                'description_code' => 'S1_2_1'
            ]);

            if ($result['success']) {
                echo "✅ Cancellato\n";
                $deleted++;
            } else {
                echo "❌ Fallito\n";
                $failed++;
            }
        } catch (Exception $e) {
            echo "❌ Errore: " . $e->getMessage() . "\n";
            $failed++;
        }

        usleep(300000); // 300ms pausa
    }

    echo "\n" . str_repeat("=", 80) . "\n";
    echo "📋 RIEPILOGO FINALE:\n";
    echo str_repeat("=", 80) . "\n";
    echo "   - Voti totali trovati: $totalGrades\n";
    echo "   - Voti con evento_id: " . count($eventoIds) . "\n";
    echo "   - Voti cancellati: $deleted\n";
    echo "   - Voti falliti: $failed\n\n";

} catch (Exception $e) {
    echo "\n❌ ERRORE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo str_repeat("=", 80) . "\n";
echo "✅ Script completato!\n\n";
