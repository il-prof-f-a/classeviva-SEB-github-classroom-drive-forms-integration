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
$throws(fn() => Authorization::assertAdmin($session, ['email@email.it']), RuntimeException::class);
$throws(fn() => Authorization::assertAuthenticated([]), RuntimeException::class);
Authorization::assertAdmin(['user_id' => 'USR_2', 'user_email' => 'email@email.it'], ['email@email.it']);
fwrite(STDOUT, "PASS: guardie CSRF, metodo HTTP e ruolo.\n");
