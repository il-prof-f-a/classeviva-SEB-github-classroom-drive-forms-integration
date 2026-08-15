<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use App\Core\ClasseVivaCapability;
use App\Core\ClasseVivaTokenGuard;

$failures = [];
if (ClasseVivaCapability::requested()) {
    $failures[] = 'capability richiesta per default';
}
$state = ClasseVivaTokenGuard::getTokenState([
    'classeviva' => ['enabled' => true, 'token' => ['token' => ''], 'token_valid' => false],
]);
if (($state['ready'] ?? true) !== false) {
    $failures[] = 'token mancante marcato pronto';
}
if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "PASS: ClasseViva è una capability opt-in e il token mancante resta non pronto.\n");
