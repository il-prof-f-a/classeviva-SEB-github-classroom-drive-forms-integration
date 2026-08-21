<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/SessionGuard.php';
require_once dirname(__DIR__, 2) . '/src/Core/Security/RateLimiter.php';
use App\Core\Security\SessionGuard;
use App\Core\Security\RateLimiter;
$s = [];
if (SessionGuard::touch($s, 100, 60, 300)['expired']) exit(1);
$s['_last_activity'] = 0;
if (!SessionGuard::touch($s, 100, 60, 300)['expired']) exit(1);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda-rate-' . bin2hex(random_bytes(4));
$r = new RateLimiter($dir);
if (!$r->allow('x', 1, 60, 100) || $r->allow('x', 1, 60, 100)) exit(1);
$source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Security/RateLimiter.php') ?: '';
if (!str_contains($source, 'flock(') || !str_contains($source, 'ftruncate(')) exit(1);
$root = dirname(__DIR__, 2);
foreach (['public/api_generate_questions.php', 'public/github_assignment_review.php'] as $relative) {
    $endpoint = file_get_contents($root . '/' . $relative) ?: '';
    if (!str_contains($endpoint, 'RateLimiter')) exit(1);
}
foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir);
fwrite(STDOUT, "PASS: sessioni e rate limit.\n");
