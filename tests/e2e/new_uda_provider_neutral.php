<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\GroupStudentRepository;
use App\Core\ProviderNeutralMappingService;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\TeachingGroupRepository;
use App\Core\UdaGroupRepository;
use App\Core\UdaPublicationRepository;

$relative = 'storage/temp/e2e-provider-neutral-' . bin2hex(random_bytes(6)) . '.db';
$absolute = $root . '/' . $relative;
$failures = [];

try {
    $db = DatabaseFactory::createWithInitialization([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relative]],
    ]);
    $user = 'e2e-user';

    $groups = new TeachingGroupRepository($db, $user);
    $group = $groups->create([
        'nome_gruppo' => '4 Informatica',
        'anno_scolastico' => '2026/27',
    ]);
    $groupId = (string)$group['id_gruppo'];

    $mapping = new ProviderNeutralMappingService($db, $user);
    $google = $mapping->upsertGoogleClassroomMapping([
        'id_gruppo' => $groupId,
        'google_course_id' => 'google-course-1',
        'google_course_name' => '4 Informatica Classroom',
    ]);
    $github = $mapping->upsertGithubClassroomMapping([
        'id_gruppo' => $groupId,
        'github_classroom_id' => 'github-roster-1',
        'classroom_name' => '4 Informatica GitHub',
    ]);

    if (($google['id_gruppo'] ?? '') !== $groupId || ($github['id_gruppo'] ?? '') !== $groupId) {
        $failures[] = 'provider Google/GitHub non collegati al gruppo interno';
    }
    if ($db->findWhere('GRUPPI_INTEGRAZIONI', ['id_gruppo' => $groupId]) === []) {
        $failures[] = 'integrazioni provider non persistite';
    }

    $udaId = 'UDA_E2E_1';
    $db->insertRow('UDA_ANAGRAFICA', [
        'id_uda' => $udaId,
        'titolo' => 'UDA senza ClasseViva',
        'stato' => 'bozza',
        'data_creazione' => date('Y-m-d H:i:s'),
        'ultima_modifica' => date('Y-m-d H:i:s'),
        'id_utente_owner' => $user,
        'id_utente' => $user,
    ]);
    $assignment = (new UdaGroupRepository($db, $user))->assign($udaId, $groupId);
    if (($assignment['id_gruppo'] ?? '') !== $groupId) {
        $failures[] = 'assegnazione UDA al gruppo interno fallita';
    }
    (new UdaPublicationRepository($db, $user))->publish($udaId, $groupId, [
        'provider' => 'google_classroom',
        'external_resource_id' => 'assignment-1',
        'external_url' => 'https://classroom.google.com/a/assignment-1',
    ]);

    $students = new StudentRepository($db, $user);
    $identities = new StudentIdentityRepository($db, $user);
    $memberships = new GroupStudentRepository($db, $user);
    $resources = new StudentResourceRepository($db, $user);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);
    $student = $resolver->resolveOrCreate('google_classroom', 'google-user-1');
    $classIdentity = $resolver->resolveOrCreate('classeviva', 'cv-user-1');
    $resolver->merge((string)$classIdentity['id_studente'], (string)$student['id_studente']);
    $studentId = (string)$student['id_studente'];
    $memberships->add($groupId, $studentId, [
        'provider_origine' => 'google_classroom',
        'external_context_id' => 'google-course-1',
    ]);
    $resources->attach($studentId, [
        'provider' => 'google_classroom',
        'external_context_id' => 'google-course-1',
        'external_resource_id' => 'submission-1',
        'tipo_risorsa' => 'submission',
    ]);

    $db->insertRow('VOTI', [
        'id_voto' => 'VOTO_E2E_1',
        'id_uda' => $udaId,
        'id_gruppo' => $groupId,
        'id_studente_gc' => 'google-user-1',
        'tipo_voto' => 'google_classroom',
        'voto' => '8',
        'data_valutazione' => date('Y-m-d'),
        'data_creazione' => date('Y-m-d H:i:s'),
        'id_utente' => $user,
    ]);
    $votes = $db->findWhere('VOTI', ['id_utente' => $user]);
    if (count($votes) !== 1 || ($votes[0]['id_studente'] ?? '') !== $studentId) {
        $failures[] = 'voto provider non normalizzato su id_studente interno';
    }

    $serialized = json_encode(array_merge(
        $db->findAll('STUDENTI'),
        $db->findAll('STUDENTI_IDENTITA_ESTERNE'),
        $db->findAll('GRUPPI_STUDENTI'),
        $db->findAll('STUDENTI_RISORSE_ESTERNE')
    ), JSON_UNESCAPED_UNICODE);
    foreach (['nome', 'cognome', 'email', 'Nome', 'Cognome'] as $forbidden) {
        if (stripos((string)$serialized, $forbidden) !== false) {
            $failures[] = "PII persistito: {$forbidden}";
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    @unlink($absolute);
    @unlink($absolute . '-wal');
    @unlink($absolute . '-shm');
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "PASS: E2E provider-neutral UDA, gruppi, identità, voto e pubblicazione.\n");
