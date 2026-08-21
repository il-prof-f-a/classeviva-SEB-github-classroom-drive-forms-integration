<?php

declare(strict_types=1);

// Restituisce le domande associate a un'UDA temporanea del wizard (UDA_TMP_...),
// usate dallo step 5 per mostrare le domande importate via import_questions.php.
error_reporting(E_ALL);

session_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;

header('Content-Type: application/json; charset=utf-8');

$id = trim((string)($_GET['id'] ?? ''));

if ($id === '') {
    echo json_encode(['success' => false, 'error' => 'id mancante']);
    exit;
}

// Solo le UDA temporanee del wizard sono esposte qui: nessuna UDA reale.
if (!str_starts_with($id, 'UDA_TMP')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Solo le UDA temporanee sono supportate']);
    exit;
}

$sessionTemp = $_SESSION['uda_create_temp_id'] ?? null;
if (is_string($sessionTemp) && $sessionTemp !== '' && $sessionTemp !== $id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'UDA temporanea non di questa sessione']);
    exit;
}

try {
    $db = DatabaseFactory::createWithInitialization($config, true);
    $rows = $db->findWhere('DOMANDE_INTERROGAZIONE', ['id_uda' => $id]);
    usort($rows, static function (array $a, array $b): int {
        $oa = (int)($a['ordine_consigliato'] ?? 0);
        $ob = (int)($b['ordine_consigliato'] ?? 0);
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp((string)($a['data_creazione'] ?? ''), (string)($b['data_creazione'] ?? ''));
    });

    $questions = array_map(static function (array $r): array {
        return [
            'id_domanda' => (string)($r['id_domanda'] ?? ''),
            'argomento' => (string)($r['argomento'] ?? ''),
            'domanda' => (string)($r['domanda'] ?? ''),
            'tipo_domanda' => (string)($r['tipo_domanda'] ?? 'aperta'),
            'difficolta' => (int)($r['difficolta'] ?? 3),
            'parole_chiave' => (string)($r['parole_chiave'] ?? ''),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'count' => count($questions), 'questions' => $questions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => \App\Core\Security\PublicError::message($e, 'ajax_get_temp_questions')]);
}
