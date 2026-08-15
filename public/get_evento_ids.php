<?php

define('REQUIRES_CLASSEVIVA', true);
/**
 * Recupera gli evento_id dei voti per poterli cancellare
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

error_reporting(E_ALL);

echo "\n🔍 RECUPERO EVENTO_ID DEI VOTI\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $tokenState = ClasseVivaTokenGuard::getTokenState($config);
    if (!$tokenState['ready']) {
        echo "Token ClasseViva non disponibile: " . ($tokenState['notice'] ?? 'abilitalo nelle integrazioni.') . "\n";
        exit(1);
    }

    $cvAPI = new ClasseVivaAPI($config);

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

    echo "👤 Studente: $nomeStudente (ID: $idStudente)\n";
    echo "📖 Materia: $nomeMateria\n\n";

    // Login web
    $reflection = new ReflectionClass($cvAPI);
    $method = $reflection->getMethod('authenticateWeb');
    $method->setAccessible(true);
    $method->invoke($cvAPI);

    $property = $reflection->getProperty('phpSessionId');
    $property->setAccessible(true);
    $sessionId = $property->getValue($cvAPI);

    // Usa regvoti.php che ha gli evento_id negli attributi
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

    echo "✅ HTML recuperato (" . strlen($html) . " bytes)\n\n";

    // Cerca tutti i voti dello studente con evento_id
    // Pattern: studente_id="11821995" ... evento_id="XXXXX" ... voto_valore="8.000"

    echo str_repeat("=", 80) . "\n";
    echo "📋 VOTI CON EVENTO_ID\n";
    echo str_repeat("=", 80) . "\n\n";

    // Cerca righe TD con studente_id e evento_id
    preg_match_all(
        '/<td[^>]*studente_id=["\']?' . $idStudente . '["\']?[^>]*evento_id=["\']?(\d+)["\']?[^>]*voto_valore=["\']?([^"\'>\s]+)["\']?[^>]*mydata=["\']?([^"\']+)["\']?[^>]*>/i',
        $html,
        $matches,
        PREG_SET_ORDER
    );

    $votiTrovati = [];

    foreach ($matches as $match) {
        $eventoId = $match[1];
        $votoValore = $match[2];
        $data = $match[3];

        // Salta voti vuoti
        if (empty($votoValore) || $votoValore == '0' || $votoValore == '0.000') {
            continue;
        }

        $votiTrovati[] = [
            'evento_id' => $eventoId,
            'voto' => $votoValore,
            'data' => $data
        ];
    }

    if (empty($votiTrovati)) {
        echo "⚠️ Nessun voto trovato con questo metodo.\n";
        echo "Provo un parsing alternativo...\n\n";

        // Metodo alternativo: cerca pattern più ampio
        preg_match_all(
            '/studente_id=["\']?' . $idStudente . '["\']?.*?evento_id=["\']?(\d+)["\']?.*?voto_valore=["\']?([0-9.]+)["\']?/is',
            $html,
            $matches2,
            PREG_SET_ORDER
        );

        foreach ($matches2 as $match) {
            $eventoId = $match[1];
            $votoValore = $match[2];

            if ($eventoId != '0' && $votoValore != '0.000') {
                $votiTrovati[] = [
                    'evento_id' => $eventoId,
                    'voto' => $votoValore,
                    'data' => 'N/A'
                ];
            }
        }
    }

    if (!empty($votiTrovati)) {
        echo "Trovati " . count($votiTrovati) . " voti con evento_id valido:\n\n";

        foreach ($votiTrovati as $i => $voto) {
            echo "🔹 Voto #" . ($i + 1) . ":\n";
            echo "   EVENTO_ID: {$voto['evento_id']}\n";
            echo "   Valore: {$voto['voto']}\n";
            echo "   Data: {$voto['data']}\n";
            echo "\n";
        }

        echo str_repeat("-", 80) . "\n";
        echo "📝 LISTA EVENTO_ID DA CANCELLARE:\n";
        echo str_repeat("-", 80) . "\n\n";

        $ids = array_map(function($v) { return $v['evento_id']; }, $votiTrovati);
        echo implode(", ", $ids) . "\n\n";

        echo "🔧 Per cancellare questi voti, usa:\n";
        echo "   - evento_id come parametro nell'interfaccia web di ClasseViva\n";
        echo "   - oppure chiama l'endpoint di cancellazione se disponibile\n\n";

    } else {
        echo "❌ Nessun voto con evento_id valido trovato.\n\n";

        // Salva HTML per debug
        $debugFile = __DIR__ . '/debug_regvoti_for_evento_id.html';
        file_put_contents($debugFile, $html);
        echo "💾 HTML salvato in: $debugFile\n";
        echo "   Cerca manualmente 'studente_id=\"$idStudente\"' nel file\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRORE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "✅ Completato!\n\n";
