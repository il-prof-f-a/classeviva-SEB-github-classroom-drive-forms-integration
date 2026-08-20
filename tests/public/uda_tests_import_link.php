<?php

declare(strict_types=1);

// Il link Socrative/Kahoot deve usare l'UDA del test, senza interpolare una
// variabile che può non esistere nel contesto della vista.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_tests.php') ?: '';
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($source, "\$test['id_uda']") && str_contains($source, 'import_quiz_results_excel.php?uda_id='),
    'il link di importazione non usa l’id UDA del test');
$require(!str_contains($source, "urlencode(\$udaId ?: (\$test['id_uda'] ?? ''))"),
    'il link di importazione interpola ancora $udaId in modo fragile');
$require(!str_contains($source, '<?= urlencode($udaId) ?>'),
    'il link di importazione contiene ancora una variabile UDA non garantita');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: link importazione quiz con UDA corretta.\n");
