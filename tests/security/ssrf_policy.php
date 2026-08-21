<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/OutboundUrlPolicy.php';

use App\Core\Security\OutboundUrlPolicy;

$resolver = static fn(string $host): array => match ($host) {
    'allowed.example' => ['93.184.216.34'],
    'private.example' => ['127.0.0.1'],
    default => [],
};
$policy = new OutboundUrlPolicy(['allowed.example'], [443], $resolver);
$policy->assertAllowed('https://allowed.example/document');
foreach (['file:///etc/passwd', 'php://filter/resource=x', 'https://private.example/x', 'https://allowed.example:8443/x', 'https://u:email@email.it/x'] as $url) {
    try { $policy->assertAllowed($url); fwrite(STDERR, "FAIL: URL accettato {$url}\n"); exit(1); } catch (RuntimeException) { }
}
fwrite(STDOUT, "PASS: policy SSRF.\n");
