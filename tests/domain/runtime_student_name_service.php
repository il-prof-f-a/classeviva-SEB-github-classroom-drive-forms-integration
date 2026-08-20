<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\RuntimeStudentNameService;
use App\Core\RuntimeStudentNameResolver;

$relativeDb = 'storage/temp/runtime-student-name-service-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_NAME_SERVICE';
    $group = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    ))->createGroup(['nome_gruppo' => 'Gruppo nomi runtime']);
    $groupId = (string)$group['id_gruppo'];

    $integrations = new TeachingGroupIntegrationRepository($adapter, $userId);
    foreach ([
        ['provider' => 'classeviva', 'context' => 'CV_CLASS', 'name' => 'ClasseViva'],
        ['provider' => 'google_classroom', 'context' => 'GC_COURSE', 'name' => 'Google'],
        ['provider' => 'github_classroom', 'context' => 'GH_CLASS', 'name' => 'GitHub'],
    ] as $integration) {
        $integrations->link([
            'id_gruppo' => $groupId,
            'provider' => $integration['provider'],
            'external_context_id' => $integration['context'],
            'external_name' => $integration['name'],
        ]);
    }

    $students = new StudentRepository($adapter, $userId);
    $identities = new StudentIdentityRepository($adapter, $userId);
    $memberships = new GroupStudentRepository($adapter, $userId);
    $fixture = [
        ['origin' => 'classeviva', 'ids' => ['classeviva' => 'CV_1', 'google_classroom' => 'GC_1', 'github_classroom' => 'GH_1'], 'names' => ['CV' => 'Rossi Mario', 'GC' => 'Mario Rossi GC', 'GH' => 'mrossi']],
        ['origin' => 'google_classroom', 'ids' => ['google_classroom' => 'GC_2', 'github_classroom' => 'GH_2'], 'names' => ['GC' => 'Bianchi Anna', 'GH' => 'anna-b']],
        ['origin' => 'github_classroom', 'ids' => ['github_classroom' => 'GH_3'], 'names' => ['GH' => 'Verdi Luca']],
    ];

    foreach ($fixture as $row) {
        $student = $students->create();
        $studentId = (string)$student['id_studente'];
        $memberships->add($groupId, $studentId, [
            'provider_origine' => $row['origin'],
            'external_context_id' => match ($row['origin']) {
                'classeviva' => 'CV_CLASS',
                'google_classroom' => 'GC_COURSE',
                default => 'GH_CLASS',
            },
        ]);
        foreach ($row['ids'] as $provider => $externalId) {
            $identities->attach($studentId, [
                'provider' => $provider,
                'external_user_id' => $externalId,
            ]);
        }
    }

    $rosters = [
        'classeviva' => [['external_user_id' => 'CV_1', 'display_name' => 'Rossi Mario']],
        'google_classroom' => [
            ['external_user_id' => 'GC_1', 'display_name' => 'Mario Rossi GC'],
            ['external_user_id' => 'GC_2', 'display_name' => 'Bianchi Anna'],
        ],
        'github_classroom' => [
            ['external_user_id' => 'GH_1', 'display_name' => 'mrossi'],
            ['external_user_id' => 'GH_2', 'display_name' => 'anna-b'],
            ['external_user_id' => 'GH_3', 'display_name' => 'Verdi Luca'],
        ],
    ];

    $service = new RuntimeStudentNameService(
        $adapter,
        $userId,
        static fn(string $provider, string $contextId): array => $rosters[$provider] ?? []
    );
    $resolved = $service->resolveGroupStudents($groupId);
    $byStudent = [];
    foreach ($resolved as $row) {
        $byStudent[(string)$row['id_studente']] = $row;
    }

    if (count($byStudent) !== 3) {
        $failures[] = 'il servizio non ha restituito tutti gli studenti del gruppo';
    }
    $expectedNames = ['Rossi Mario', 'Bianchi Anna', 'Verdi Luca'];
    foreach ($expectedNames as $expectedName) {
        $found = false;
        foreach ($byStudent as $row) {
            if (($row['nome_completo'] ?? '') === $expectedName) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $failures[] = "nome atteso non risolto: {$expectedName}";
        }
    }
    $hasClasseVivaWinner = false;
    foreach ($byStudent as $row) {
        if (($row['nome_completo'] ?? '') === 'Rossi Mario' && ($row['provider'] ?? '') === 'classeviva') {
            $hasClasseVivaWinner = true;
            break;
        }
    }
    if (!$hasClasseVivaWinner) {
        $failures[] = 'la priorità ClasseViva non è stata rispettata';
    }

    // Golden regression: la nuova chiamata centralizzata deve restituire gli
    // stessi nomi del resolver precedente sullo stesso roster.
    $membershipRows = $memberships->listForGroup($groupId);
    $identitiesByStudent = [];
    foreach ($membershipRows as $membership) {
        $studentId = (string)($membership['id_studente'] ?? '');
        $identitiesByStudent[$studentId] = $identities->listForStudent($studentId);
    }
    $legacyNames = RuntimeStudentNameResolver::resolveNames($membershipRows, $identitiesByStudent, $rosters);
    foreach ($legacyNames as $studentId => $legacyName) {
        if (($byStudent[$studentId]['nome_completo'] ?? '') !== $legacyName) {
            $failures[] = "nome cambiato dalla centralizzazione per {$studentId}";
        }
    }

    $futureDetails = RuntimeStudentNameResolver::resolveDetails(
        [['id_studente' => 'STD_FUTURE']],
        ['STD_FUTURE' => [['provider' => 'future_provider', 'external_user_id' => 'FUT_1']]],
        ['future_provider' => [['external_user_id' => 'FUT_1', 'display_name' => 'Nome futuro']]],
        ['classeviva', 'google_classroom', 'github_classroom', 'future_provider']
    );
    if (($futureDetails['STD_FUTURE']['name'] ?? '') !== 'Nome futuro') {
        $failures[] = 'provider futuro non considerato dopo i provider noti';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    if (is_file($absoluteDb)) {
        @unlink($absoluteDb);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: servizio centralizzato nomi studenti e priorità provider.\n");
