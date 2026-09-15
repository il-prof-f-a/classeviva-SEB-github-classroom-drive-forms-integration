<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$failures = [];

foreach ([
    'assignment_mode' => 'la pagina deve inviare la modalità singola o di gruppo',
    'team_groups_json' => 'la pagina deve inviare il payload dei gruppi team',
    'Assegnazione singola/di gruppo' => 'deve essere presente il selettore della modalità',
    'team-groups' => 'deve essere presente il pannello dinamico dei gruppi',
    'student_ids' => 'ogni gruppo deve conservare gli id degli studenti',
    'GitHubAssignmentTeamService' => 'la validazione server-side deve usare il servizio dei gruppi team',
    'team_groups' => 'la composizione deve essere persistita nel JSON del test',
    'createRepositoryFromTemplate' => 'la creazione deve usare il template GitHub',
    'assignment_mode.*group' => 'il ramo di creazione di gruppo deve essere riconoscibile',
] as $needle => $message) {
    if (str_contains($needle, '.*')) {
        $pattern = '/' . $needle . '/s';
        if (!preg_match($pattern, $source)) {
            $failures[] = $message;
        }
    } elseif (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: markup assignment GitHub singolo/gruppo verificato.\n");
