<?php

declare(strict_types=1);

use App\Core\Database\LegacyJsonRestoreMapper;

require_once dirname(__DIR__, 2) . '/src/Core/Database/LegacyJsonRestoreMapper.php';

$failures = [];
$mapper = new LegacyJsonRestoreMapper('USER_SOURCE', 'USER_CURRENT');

$owned = [
    'id_utente' => 'USER_SOURCE',
    'id_utente_owner' => 'USER_SOURCE',
    'id_utente_invitato' => 'USER_SOURCE',
    'titolo' => 'UDA di test',
];
$other = ['id_utente' => 'USER_OTHER', 'titolo' => 'Da escludere'];

if (!$mapper->belongsToSource($owned)) {
    $failures[] = 'la riga proprietaria non viene riconosciuta';
}
if ($mapper->belongsToSource($other)) {
    $failures[] = 'la riga di un altro utente viene riconosciuta come proprietaria';
}

$rewritten = $mapper->rewriteOwner($owned);
foreach (['id_utente', 'id_utente_owner', 'id_utente_invitato'] as $field) {
    if (($rewritten[$field] ?? null) !== 'USER_CURRENT') {
        $failures[] = "il campo {$field} non viene rimappato";
    }
}

$rows = $mapper->ownedRows([$owned, $other]);
if (count($rows) !== 1 || ($rows[0]['titolo'] ?? '') !== 'UDA di test') {
    $failures[] = 'il filtro per proprietario non è isolato';
}

$filtered = $mapper->onlyAllowedColumns(
    ['id_utente' => 'USER_CURRENT', 'id_uda' => 'UDA_1', 'id_legacy' => 'drop-me'],
    ['id_utente', 'id_uda']
);
if ($filtered !== ['id_utente' => 'USER_CURRENT', 'id_uda' => 'UDA_1']) {
    $failures[] = 'le colonne non previste non vengono rimosse';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: filtro proprietario e rimappatura dump legacy.\n");

