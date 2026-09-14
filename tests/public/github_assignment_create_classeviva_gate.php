<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/Core/Database/DatabaseAdapterInterface.php';
require_once $root . '/src/Core/ClasseVivaCapability.php';

use App\Core\ClasseVivaCapability;
use App\Core\Database\DatabaseAdapterInterface;

$db = new class implements DatabaseAdapterInterface {
    public function findAll(string $sheet): array { return []; }
    public function findWhere(string $sheet, array $where): array
    {
        if ($sheet === 'UDA_GRUPPI' && ($where['id_uda'] ?? '') === 'UDA_CV') {
            return [
                ['id_gruppo' => 'GRP_CV', 'id_utente' => 'USR_1'],
                ['id_gruppo' => 'GRP_NO_CV', 'id_utente' => 'USR_1'],
            ];
        }
        if ($sheet === 'GRUPPI_INTEGRAZIONI' && ($where['id_gruppo'] ?? '') === 'GRP_CV') {
            return [
                ['provider' => 'classeviva', 'stato' => 'disattivo', 'external_context_id' => 'OLD'],
                ['provider' => 'classeviva', 'stato' => 'attivo', 'external_context_id' => 'CV_CLASS_1'],
            ];
        }
        return [];
    }
    public function findOne(string $sheet, string $key, $value): ?array { return null; }
    public function insertRow(string $sheet, array $data): bool { return true; }
    public function updateRow(string $sheet, string $key, $value, array $data): bool { return true; }
    public function deleteRow(string $sheet, $value, string $key = 'id'): bool { return true; }
    public function updateWhere(string $sheet, array $where, array $data): bool { return true; }
    public function deleteWhere(string $sheet, array $where): bool { return true; }
    public function ensureSheetExists(string $sheet): bool { return true; }
    public function getConnection() { return null; }
    public function createBackup(): string { return ''; }
    public function initialize(): array { return []; }
    public function validate(): array { return []; }
    public function repair(): array { return []; }
    public function getColumns(string $sheet): array { return []; }
    public function sheetExists(string $sheet): bool { return true; }
    public function count(string $sheet): int { return 0; }
    public function truncate(string $sheet): bool { return true; }
    public function getAllSheetNames(): array { return []; }
    public function clearSheet(string $sheet): bool { return true; }
    public function createSheet(string $sheet, array $columns = []): bool { return true; }
};

$failures = [];
$pageSource = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$bootstrapSource = file_get_contents($root . '/bootstrap.php') ?: '';
if (!str_contains($pageSource, "define('REQUIRES_CLASSEVIVA_FOR_UDA', \$idUda)")) {
    $failures[] = 'la pagina deve passare l’id UDA al bootstrap prima del caricamento';
}
if (!str_contains($bootstrapSource, 'ClasseVivaCapability::hasMappedUda')) {
    $failures[] = 'il bootstrap deve valutare il mapping ClasseViva dell’UDA';
}
if (!str_contains($pageSource, 'ClasseVivaTokenGuard::requireToken')) {
    $failures[] = 'il submit deve rifiutare l’operazione se il token richiesto non è pronto';
}
if (!ClasseVivaCapability::hasMappedUda($db, 'USR_1', 'UDA_CV')) {
    $failures[] = 'un gruppo UDA con mapping ClasseViva attivo deve richiedere il token';
}
if (ClasseVivaCapability::hasMappedUda($db, 'USR_1', 'UDA_EMPTY')) {
    $failures[] = 'un UDA senza mapping ClasseViva non deve richiedere il token';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: gate ClasseViva condizionale ai gruppi dell'UDA.\n");
