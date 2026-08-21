<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/SchemaDefinitions.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/DatabaseAdapterInterface.php';
require_once dirname(__DIR__, 2) . '/src/Core/Database/UserScopedDatabaseAdapter.php';
require_once dirname(__DIR__, 2) . '/src/Core/StudentReferenceGateway.php';

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\Database\UserScopedDatabaseAdapter;

$inner = new class implements DatabaseAdapterInterface {
    public array $rows = [
        ['id_uda'=>'UDA_A','titolo'=>'A','id_utente_owner'=>'USR_A'],
        ['id_uda'=>'UDA_B','titolo'=>'B','id_utente_owner'=>'USR_B'],
        ['id_uda'=>'UDA_ORPHAN','titolo'=>'orphan','id_utente_owner'=>''],
    ];
    public array $lastWhere = [];
    public function findAll(string $s): array { return $this->rows; }
    public function findWhere(string $s, array $w): array { $this->lastWhere = $w; return array_values(array_filter($this->rows, fn($r) => (string)($r['id_utente_owner'] ?? '') === (string)($w['id_utente_owner'] ?? ''))); }
    public function findOne(string $s, string $k, $v): ?array { foreach ($this->rows as $r) if (($r[$k] ?? null) == $v) return $r; return null; }
    public function insertRow(string $s, array $d): bool { $this->rows[] = $d; return true; }
    public function updateRow(string $s, string $k, $v, array $d): bool { return true; }
    public function deleteRow(string $s, $v, string $k = 'id'): bool { return true; }
    public function ensureSheetExists(string $s): bool { return true; }
    public function getConnection() { return null; }
    public function createBackup(): string { return ''; }
    public function initialize(): array { return []; }
    public function validate(): array { return []; }
    public function repair(): array { return []; }
    public function getColumns(string $s): array { return []; }
    public function sheetExists(string $s): bool { return true; }
    public function count(string $s): int { return count($this->rows); }
    public function truncate(string $s): bool { return true; }
    public function getAllSheetNames(): array { return []; }
    public function clearSheet(string $s): bool { return true; }
    public function createSheet(string $s, array $c = []): bool { return true; }
};
$db = new UserScopedDatabaseAdapter($inner, 'USR_A');
if ($db->findOne('UDA_ANAGRAFICA', 'id_uda', 'UDA_B') !== null) { fwrite(STDERR,"findOne\n"); exit(1); }
$db->findWhere('UDA_ANAGRAFICA', ['id_utente_owner' => 'USR_B']);
if (($inner->lastWhere['id_utente_owner'] ?? '') !== 'USR_A') { fwrite(STDERR,"where=".json_encode($inner->lastWhere)."\n"); exit(1); }
$db->insertRow('UDA_ANAGRAFICA', ['id_uda'=>'UDA_NEW','id_utente_owner'=>'USR_B']);
if (($inner->rows[array_key_last($inner->rows)]['id_utente_owner'] ?? '') !== 'USR_A') { fwrite(STDERR,"insert=".json_encode($inner->rows[array_key_last($inner->rows)])."\n"); exit(1); }
if ($db->updateRow('UDA_ANAGRAFICA', 'id_uda', 'UDA_B', ['titolo'=>'x'])) { fwrite(STDERR,"update\n"); exit(1); }
if ($db->deleteRow('UDA_ANAGRAFICA', 'UDA_ORPHAN', 'id_uda')) { fwrite(STDERR,"delete\n"); exit(1); }
fwrite(STDOUT, "PASS: ownership fail-closed.\n");
