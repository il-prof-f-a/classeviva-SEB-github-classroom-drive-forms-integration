<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Database/DatabaseAdapterInterface.php';
require_once dirname(__DIR__, 2) . '/src/Core/Security/MaterialAccessService.php';

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\Security\MaterialAccessService;

$db = new class implements DatabaseAdapterInterface {
    public function findAll(string $s): array { return []; }
    public function findWhere(string $s, array $w): array { return []; }
    public function findOne(string $s, string $k, $v): ?array { return $v === 'MAT_1' ? ['id_materiale'=>'MAT_1','id_uda'=>'UDA_1','id_utente'=>'USR_1','file_id_drive'=>'DRIVE_1234567890','titolo'=>'dispensa'] : null; }
    public function insertRow(string $s, array $d): bool { return true; }
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
    public function count(string $s): int { return 0; }
    public function truncate(string $s): bool { return true; }
    public function getAllSheetNames(): array { return []; }
    public function clearSheet(string $s): bool { return true; }
    public function createSheet(string $s, array $c = []): bool { return true; }
};
$service = new MaterialAccessService($db);
$rows = $service->resolveForUda('USR_1', 'UDA_1', ['MAT_1']);
if ($rows[0]['drive_file_id'] !== 'DRIVE_1234567890') exit(1);
try { $service->resolveForUda('USR_2', 'UDA_1', ['MAT_1']); exit(1); } catch (RuntimeException) { }
fwrite(STDOUT, "PASS: accesso materiali AI limitato a owner/UDA.\n");
