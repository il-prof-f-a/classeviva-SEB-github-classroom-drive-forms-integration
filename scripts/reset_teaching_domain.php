<?php

declare(strict_types=1);

use App\Core\Database\DatabaseFactory;
use App\Core\Database\TeachingDomainReset;

$options = getopt('', ['dry-run', 'apply', 'confirm:', 'output::']);
$isDryRun = array_key_exists('dry-run', $options);
$isApply = array_key_exists('apply', $options);
$confirmation = (string)($options['confirm'] ?? '');

if (!$isDryRun && !$isApply) {
    fwrite(STDERR, "Usare --dry-run oppure --apply --confirm=RESET-TEACHING-DOMAIN.\n");
    exit(2);
}
if ($isDryRun && $isApply) {
    fwrite(STDERR, "--dry-run e --apply sono mutuamente esclusivi.\n");
    exit(2);
}
if ($isApply && $confirmation !== 'RESET-TEACHING-DOMAIN') {
    fwrite(STDERR, "Conferma mancante: usare --confirm=RESET-TEACHING-DOMAIN.\n");
    exit(2);
}

$root = dirname(__DIR__);
try {
    /** @var array<string,mixed> $config */
    $config = require $root . '/bootstrap.php';
    $environment = (string)($config['system']['environment'] ?? 'production');
    if (strtolower($environment) === 'production') {
        throw new RuntimeException('Reset didattico vietato in ambiente production.');
    }

    $adapter = DatabaseFactory::create($config);
    $reset = new TeachingDomainReset($adapter, $environment);
    $report = $isDryRun ? $reset->dryRun() : $reset->apply();
    $report['mode'] = $isDryRun ? 'dry-run' : 'apply';
    $report['generated_at'] = date(DATE_ATOM);

    $reportDirectory = $root . '/storage/reports';
    if (!is_dir($reportDirectory)) {
        mkdir($reportDirectory, 0750, true);
    }
    $output = (string)($options['output'] ?? '');
    if ($output === '') {
        $output = $reportDirectory . '/reset-teaching-domain-' . date('Ymd-His') . '.json';
    } else {
        $normalizedOutput = str_replace('\\', '/', $output);
        $isAbsolute = preg_match('/^[A-Za-z]:\//', $normalizedOutput) === 1 || str_starts_with($normalizedOutput, '/');
        if (!$isAbsolute) {
            $output = $root . '/' . ltrim($output, '/');
            $normalizedOutput = str_replace('\\', '/', $output);
        }
        if (!str_starts_with($normalizedOutput, str_replace('\\', '/', $root . '/'))) {
            throw new RuntimeException('Il report deve essere salvato dentro la repo privata.');
        }
    }
    file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    fwrite(STDOUT, "Report salvato: {$output}\n");
    exit(($report['success'] ?? true) ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Reset non eseguito: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
