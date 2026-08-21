<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/Csrf.php';
require_once dirname(__DIR__, 2) . '/src/Core/Security/Authorization.php';
require_once dirname(__DIR__, 2) . '/src/Core/Security/RequestGuard.php';

use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Core\Security\RequestGuard;

$throws = static function (callable $callable, string $class): void {
    try { $callable(); } catch (Throwable $error) {
        if ($error instanceof $class) return;
        throw $error;
    }
    throw new RuntimeException('Eccezione attesa non generata');
};

$session = ['user_id' => 'USR_1', 'user_email' => 'email@email.it'];
$token = Csrf::token($session);
Csrf::assertValid($session, $token);
$throws(fn() => Csrf::assertValid($session, 'wrong'), RuntimeException::class);
$throws(fn() => RequestGuard::assertPost(['REQUEST_METHOD' => 'GET'], $session, $token), RuntimeException::class);
$postToken = Csrf::providedToken(['_csrf_token' => $token], []);
if ($postToken !== $token) {
    throw new RuntimeException('Token CSRF POST non riconosciuto');
}
$headerToken = Csrf::providedToken([], ['HTTP_X_CSRF_TOKEN' => $token]);
if ($headerToken !== $token) {
    throw new RuntimeException('Token CSRF header non riconosciuto');
}
if (Csrf::providedToken([], []) !== null) {
    throw new RuntimeException('Token CSRF assente non restituisce null');
}
$field = Csrf::hiddenField($session);
if (!str_contains($field, 'name="_csrf_token"') || !str_contains($field, $token)) {
    throw new RuntimeException('Campo CSRF nascosto non valido');
}
$protectedHtml = Csrf::injectIntoHtml('<html><head><title>Test</title></head><body><form method="post"></form></body></html>', $token);
foreach (['app-csrf-bridge', 'X-CSRF-Token', '_csrf_token', 'HTMLFormElement.prototype.submit', $token] as $expectedFragment) {
    if (!str_contains($protectedHtml, $expectedFragment)) {
        throw new RuntimeException('Bridge CSRF HTML incompleto: ' . $expectedFragment);
    }
}
$throws(
    fn() => RequestGuard::assertMutation(['REQUEST_METHOD' => 'GET'], $session, ['_csrf_token' => $token]),
    RuntimeException::class
);
$throws(
    fn() => RequestGuard::assertMutation(['REQUEST_METHOD' => 'POST'], $session, []),
    RuntimeException::class
);
RequestGuard::assertMutation(['REQUEST_METHOD' => 'POST'], $session, ['_csrf_token' => $token]);
if (Csrf::shouldInstallHtmlBridge(['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/public/uda_view.php', 'HTTP_ACCEPT' => 'text/html'], [] ) !== true) {
    throw new RuntimeException('Bridge HTML non attivato su pagina ordinaria');
}
foreach ([
    [['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/public/uda_export.php', 'HTTP_ACCEPT' => 'text/html'], ['action' => 'download']],
    [['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/public/download_template_domande.php', 'HTTP_ACCEPT' => '*/*'], []],
    [['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/public/ajax_get.php', 'HTTP_ACCEPT' => 'application/json'], []],
] as [$serverCase, $getCase]) {
    if (Csrf::shouldInstallHtmlBridge($serverCase, $getCase)) {
        throw new RuntimeException('Bridge HTML attivato su risposta non HTML/download');
    }
}
$throws(fn() => Authorization::assertAdmin($session, ['email@email.it']), RuntimeException::class);
$throws(fn() => Authorization::assertAuthenticated([]), RuntimeException::class);
Authorization::assertAdmin(['user_id' => 'USR_2', 'user_email' => 'email@email.it'], ['email@email.it']);
fwrite(STDOUT, "PASS: guardie CSRF, metodo HTTP e ruolo.\n");
