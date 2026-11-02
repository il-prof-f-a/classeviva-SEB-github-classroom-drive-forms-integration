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

use App\Core\GradeEmailRecipientResolver;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\StudentIdentityRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupStudentService;
use App\Core\Database\DatabaseFactory;

$relativeDb = 'storage/temp/grade-email-recipient-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_A';
    $groups = new TeachingGroupRepository($adapter, $userId);
    $integrations = new TeachingGroupIntegrationRepository($adapter, $userId);
    $group = (new TeachingGroupService($groups, $integrations))->createGroup(['nome_gruppo' => '4 Informatica']);
    $groupId = (string)$group['id_gruppo'];
    $integrations->link([
        'id_gruppo' => $groupId,
        'provider' => 'google_classroom',
        'external_context_id' => 'GC_COURSE_1',
        'external_name' => 'Corso Classroom',
    ]);

    $students = new TeachingGroupStudentService($adapter, $userId);
    $students->syncRoster($groupId, 'google_classroom', 'GC_COURSE_1', [[
        'external_user_id' => 'GC_STUDENT_1',
        'display_name' => 'Mario Rossi',
        'email' => 'mario.rossi@istituto.it',
    ]]);
    $studentId = (string)(new StudentIdentityRepository($adapter, $userId))
        ->findByExternal('google_classroom', 'GC_STUDENT_1')['id_studente'];

    $rosterLoader = static function (string $provider, string $contextId): array {
        if ($provider === 'google_classroom' && $contextId === 'GC_COURSE_1') {
            return [[
                'external_user_id' => 'GC_STUDENT_1',
                'name' => 'Mario Rossi',
                'email' => 'mario.rossi@istituto.it',
            ]];
        }
        return [];
    };
    $config = [
        'user_profile' => [
            'school_email_domain' => 'istituto.it',
            'school_student_email_template' => '{cognome}.{nome}@{domain}',
        ],
        'notifications' => ['email' => ['test_recipient' => 'test@example.invalid']],
    ];

    $resolver = new GradeEmailRecipientResolver($adapter, $userId, $config, $rosterLoader);
    $resolved = $resolver->resolve($groupId, $studentId);
    if (($resolved['email'] ?? '') !== 'mario.rossi@istituto.it') {
        $failures[] = 'email reale del roster Google Classroom non risolta';
    }
    if (($resolved['source'] ?? '') !== 'google_classroom') {
        $failures[] = 'sorgente email Google Classroom non tracciata';
    }

    $groupWithoutEmail = (new TeachingGroupService($groups, $integrations))->createGroup(['nome_gruppo' => '4 Informatica senza email']);
    $groupWithoutEmailId = (string)$groupWithoutEmail['id_gruppo'];
    $integrations->link([
        'id_gruppo' => $groupWithoutEmailId,
        'provider' => 'google_classroom',
        'external_context_id' => 'GC_COURSE_NO_EMAIL',
        'external_name' => 'Corso senza email',
    ]);
    $students->syncRoster($groupWithoutEmailId, 'google_classroom', 'GC_COURSE_NO_EMAIL', [[
        'external_user_id' => 'GC_STUDENT_1',
        'display_name' => 'Mario Rossi',
    ]]);

    $noEmailResolver = new GradeEmailRecipientResolver(
        $adapter,
        $userId,
        $config,
        static fn(string $provider, string $contextId): array => [[
            'external_user_id' => 'GC_STUDENT_1',
            'name' => 'Mario Rossi',
            'email' => '',
        ]]
    );
    if ($noEmailResolver->resolve($groupWithoutEmailId, $studentId) !== null) {
        $failures[] = 'destinatario di test usato come fallback';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($resolver, $noEmailResolver, $students, $adapter);
    gc_collect_cycles();
    @unlink($absoluteDb);
    @unlink($absoluteDb . '-wal');
    @unlink($absoluteDb . '-shm');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: resolver destinatari email valutazioni.\n");
