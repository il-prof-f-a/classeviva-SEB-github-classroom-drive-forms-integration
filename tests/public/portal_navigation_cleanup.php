<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(!is_file($root . '/public/studenti_sync.php'), 'la pagina standalone studenti_sync.php è ancora presente');

$publicFiles = glob($root . '/public/*.php') ?: [];
foreach ($publicFiles as $path) {
    $source = file_get_contents($path) ?: '';
    $require(
        !str_contains($source, 'studenti_sync.php'),
        'il portale contiene ancora un riferimento a studenti_sync.php: ' . basename($path)
    );
}

$index = file_get_contents($root . '/public/index.php') ?: '';
$manageGrades = file_get_contents($root . '/public/manage_grades.php') ?: '';
$verifyGrades = file_get_contents($root . '/public/verify_grades.php') ?: '';
$systemStatus = file_get_contents($root . '/public/system_status.php') ?: '';

$require(str_contains($index, 'Gestione voti Classe Viva'), 'la dashboard non espone Gestione voti Classe Viva');
$require(str_contains($manageGrades, 'Gestione voti Classe Viva'), 'manage_grades.php non espone Gestione voti Classe Viva');
$require(str_contains($verifyGrades, 'Gestione voti Classe Viva'), 'verify_grades.php non espone Gestione voti Classe Viva');
$require(!str_contains($systemStatus, 'Azioni Disponibili'), 'system_status.php contiene ancora la sezione Azioni Disponibili');
$require(!str_contains($systemStatus, 'tests/test_database.php'), 'system_status.php contiene ancora il link Test Database');
$require(!str_contains($systemStatus, 'tests/test_integrations.php'), 'system_status.php contiene ancora il link Test Integrazioni API');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: pulizia navigazione studenti, voti e stato sistema.\n");
