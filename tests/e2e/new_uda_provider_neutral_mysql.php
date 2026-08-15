<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Core\StudentProviderMappingService;
use App\Core\TeachingGroupRepository;
use App\Core\UdaGroupRepository;

$failures = [];
try {
    $db = DatabaseFactory::createWithInitialization($config, true);
    $pdo = $db->getConnection();
    if (!$pdo instanceof PDO) throw new RuntimeException('PDO MySQL non disponibile');
    $pdo->beginTransaction();
    $user = 'mysql-e2e-' . bin2hex(random_bytes(4));
    $group = (new TeachingGroupRepository($db, $user))->create(['nome_gruppo' => 'MySQL E2E']);
    $groupId = (string)$group['id_gruppo'];
    $mapping = new ProviderNeutralMappingService($db, $user);
    $mapping->upsertGoogleClassroomMapping(['id_gruppo' => $groupId, 'google_course_id' => 'mysql-course-' . bin2hex(random_bytes(4))]);
    $service = new StudentProviderMappingService($db, $user);
    $service->link($groupId, 'mysql-cv-student', 'mysql-gc-student');
    $db->insertRow('VOTI', [
        'id_voto' => 'MYSQL_E2E_' . bin2hex(random_bytes(4)),
        'id_uda' => '', 'id_gruppo' => $groupId, 'id_studente_gc' => 'mysql-gc-student',
        'tipo_voto' => 'google_classroom', 'voto' => '7', 'id_utente' => $user,
    ]);
    $rows = $db->findWhere('VOTI', ['id_utente' => $user]);
    if (count($rows) !== 1 || empty($rows[0]['id_studente'])) $failures[] = 'voto MySQL non normalizzato';
    $pdo->rollBack();
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
}
if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "PASS: E2E provider-neutral MySQL con transazione rollback.\n");
