<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\GitHubAssignmentTeamService;

$failures = [];
$students = [
    ['id_studente' => 'STD_1', 'nome' => 'Mario Rossi'],
    ['id_studente' => 'STD_2', 'nome' => 'Maria Bianchi'],
    ['id_studente' => 'STD_3', 'nome' => 'Luca Verdi'],
];

try {
    $service = new GitHubAssignmentTeamService();
    if ($service->normalizeMode('group') !== 'group' || $service->normalizeMode('single') !== 'single') {
        $failures[] = 'normalizeMode deve conservare le due modalità supportate';
    }

    $groups = $service->normalizeGroups([
        ['id' => 'team-1', 'name' => 'Gruppo A', 'color' => '#123456', 'student_ids' => ['STD_1', 'STD_2']],
        ['id' => 'team-2', 'name' => 'Gruppo vuoto', 'color' => '#abcdef', 'student_ids' => []],
        ['id' => 'team-3', 'name' => 'Gruppo B', 'color' => 'not-a-color', 'student_ids' => ['STD_3']],
    ], $students);
    if (count($groups) !== 2 || ($groups[0]['student_ids'] ?? []) !== ['STD_1', 'STD_2']) {
        $failures[] = 'i gruppi vuoti devono essere esclusi e i membri conservati';
    }
    if (!preg_match('/^#[0-9a-f]{6}$/i', (string)($groups[1]['color'] ?? ''))) {
        $failures[] = 'il colore deve essere normalizzato in formato esadecimale';
    }

    $expectedFailures = [
        [[['id' => 'team-1', 'student_ids' => ['STD_1', 'STD_2']]], ['STD_1', 'STD_2', 'STD_3']],
        [[['id' => 'team-1', 'student_ids' => ['STD_1', 'STD_1']]], ['STD_1', 'STD_2', 'STD_3']],
        [[['id' => 'team-1', 'student_ids' => ['STD_UNKNOWN']]], ['STD_1', 'STD_2', 'STD_3']],
    ];
    foreach ($expectedFailures as $case) {
        try {
            $service->normalizeGroups($case[0], array_map(
                static fn(string $id): array => ['id_studente' => $id],
                $case[1]
            ));
            $failures[] = 'una composizione non valida è stata accettata';
        } catch (InvalidArgumentException) {
            // comportamento atteso
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: validazione gruppi team assignment GitHub.\n");
