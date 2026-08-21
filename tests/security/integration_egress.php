<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/OutboundUrlPolicy.php';
use App\Core\Security\OutboundUrlPolicy;
$public = new OutboundUrlPolicy(['smtp.example'], [465, 587], static fn(string $host): array => ['93.184.216.34']);
$public->assertAllowed('https://smtp.example:587');
if ($public->resolveAllowed('https://smtp.example:587') !== ['93.184.216.34']) exit(1);
try { $public->assertAllowed('https://smtp.example:25'); exit(1); } catch (RuntimeException) { }
$private = new OutboundUrlPolicy(['smtp.example'], [587], static fn(string $host): array => ['192.168.1.5']);
try { $private->assertAllowed('https://smtp.example:587'); exit(1); } catch (RuntimeException) { }
$github = file_get_contents(dirname(__DIR__, 2) . '/public/github_assignment_review.php') ?: '';
if (str_contains($github, 'CURLOPT_UNRESTRICTED_AUTH')) exit(1);
$notification = file_get_contents(dirname(__DIR__, 2) . '/src/Core/NotificationManager.php') ?: '';
if (!str_contains($notification, 'resolveAllowed') || !str_contains($notification, "'peer_name'")) exit(1);
fwrite(STDOUT, "PASS: destinazioni integrazioni vincolate.\n");
