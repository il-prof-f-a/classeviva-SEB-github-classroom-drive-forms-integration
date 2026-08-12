<?php

declare(strict_types=1);

use App\Core\ClasseVivaSessionStore;
use App\Integration\ClasseVivaAPI;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$failures = [];
$passes = 0;

function cvCheck(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
        echo "PASS: {$message}\n";
        return;
    }

    $failures[] = $message;
    echo "FAIL: {$message}\n";
}

if (!class_exists(ClasseVivaSessionStore::class)) {
    cvCheck(false, 'archivio ClasseViva limitato alla sessione PHP');
} else {
    $session = [];
    $store = new ClasseVivaSessionStore($session);
    $store->storeTokenPayload([
        'token' => 'rest-token',
        'token_ap' => 'unused-secondary-token',
        'user_id' => '1234567',
        'ident' => 'S1234567',
        'expire' => '2099-01-01T00:00:00Z',
        'php_session_id' => 'php-session',
        'username' => 'must-not-survive',
        'password' => 'must-not-survive',
    ], 1_000);

    $saved = $store->getTokenPayload(3_600, 1_001);
    cvCheck(($saved['token'] ?? null) === 'rest-token', 'il token REST resta nella sola sessione applicativa');
    cvCheck(($saved['php_session_id'] ?? null) === 'php-session', 'PHPSESSID resta nella sola sessione applicativa');
    cvCheck(
        !isset($saved['username']) && !isset($saved['password']) && !isset($saved['token_ap']),
        'credenziali e token secondari non necessari non vengono conservati'
    );

    $config = $store->mergeIntoConfig(['classeviva' => ['enabled' => true]], 3_600, 1_002);
    cvCheck(($config['classeviva']['token']['token'] ?? null) === 'rest-token', 'il token di sessione viene esposto al codice esistente senza database');

    $store->clearPhpSessionId();
    $withoutWebSession = $store->getTokenPayload(3_600, 1_003);
    cvCheck(
        ($withoutWebSession['token'] ?? null) === 'rest-token' && !isset($withoutWebSession['php_session_id']),
        'una PHPSESSID scaduta viene rimossa senza perdere il token REST'
    );

    $store->storeTokenPayload(['token' => 'expiring-token', 'user_id' => '1234567'], 2_000);
    cvCheck($store->getTokenPayload(60, 2_061) === null, 'il token viene eliminato dopo il timeout di inattivita');
    cvCheck($store->requiresReauthentication(), 'la scadenza della sessione richiede una nuova autenticazione');

    $store->storeTokenPayload(['token' => 'new-token', 'user_id' => '1234567'], 3_000);
    cvCheck(!$store->requiresReauthentication(), 'un nuovo login rimuove la richiesta di riautenticazione');
    $store->clearAuth();
    cvCheck($store->getTokenPayload(3_600, 3_001) === null, 'logout elimina token REST e PHPSESSID dalla sessione');

    try {
        $store->storeTokenPayload([], 4_000);
        cvCheck(false, 'un payload senza token viene rifiutato');
    } catch (InvalidArgumentException) {
        cvCheck(true, 'un payload senza token viene rifiutato');
    }
}

if (class_exists(ClasseVivaAPI::class) && method_exists(ClasseVivaAPI::class, 'getPhpSessionToken')) {
    $history = [];
    $mock = new MockHandler([
        new Response(302, [
            'Set-Cookie' => 'PHPSESSID=generated-session-id; Path=/; Secure; HttpOnly',
            'Location' => '/cvv/app/default/gioprof_selezione.php',
        ]),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $api = new ClasseVivaAPI([
        'classeviva' => [
            'token' => ['token' => 'rest-token', 'user_id' => '1234567', 'ident' => 'S1234567'],
        ],
    ], $client);

    try {
        $phpSessionId = $api->getPhpSessionToken();
        cvCheck($phpSessionId === 'generated-session-id', 'PHPSESSID viene generato dal token REST senza credenziali');
        cvCheck(
            isset($history[0]) && $history[0]['request']->getHeaderLine('Z-Auth-Token') === 'rest-token',
            'lo scambio invia soltanto il token REST a ClasseViva'
        );
        cvCheck(
            !isset(($api->getTokenPayload() ?? [])['username']) && !isset(($api->getTokenPayload() ?? [])['password']),
            'il payload API non contiene credenziali'
        );
    } catch (Throwable $e) {
        cvCheck(false, 'PHPSESSID viene generato dal token REST senza credenziali: ' . $e->getMessage());
    }

    $ticketHistory = [];
    $ticketMock = new MockHandler([new Response(200, [], '{"ticket":"temporary"}')]);
    $ticketStack = HandlerStack::create($ticketMock);
    $ticketStack->push(Middleware::history($ticketHistory));
    $ticketApi = new ClasseVivaAPI([
        'classeviva' => [
            'token' => ['token' => 'rest-token', 'user_id' => '1234567', 'ident' => 'S1234567'],
        ],
    ], new Client(['handler' => $ticketStack]));
    cvCheck($ticketApi->validateToken(), 'validazione token tramite endpoint di autenticazione');
    cvCheck(
        isset($ticketHistory[0]) && str_ends_with($ticketHistory[0]['request']->getUri()->getPath(), '/auth/ticket'),
        'la validazione non dipende dall endpoint classi del docente'
    );

    $invalidApi = new ClasseVivaAPI([
        'classeviva' => [
            'token' => ['token' => 'expired-token', 'user_id' => '1234567'],
        ],
    ], new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(409, [], '{"error":"expired"}'),
    ]))]));
    cvCheck(!$invalidApi->validateToken(), 'un token REST rifiutato viene considerato non valido');

    $missingCookieApi = new ClasseVivaAPI([
        'classeviva' => [
            'token' => ['token' => 'rest-token', 'user_id' => '1234567'],
        ],
    ], new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(302, ['Location' => '/cvv/app/default/gioprof_selezione.php']),
    ]))]));
    cvCheck($missingCookieApi->getPhpSessionToken() === null, 'lo scambio senza cookie non inventa una PHPSESSID');

    $expiredWebSessionApi = new ClasseVivaAPI([
        'classeviva' => [
            'token' => [
                'token' => 'rest-token',
                'user_id' => '1234567',
                'php_session_id' => 'expired-session',
            ],
        ],
    ], new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(302, ['Location' => '/auth-p7/app/default/login.php']),
    ]))]));
    cvCheck(!$expiredWebSessionApi->pingWebSession(true), 'una PHPSESSID che reindirizza al login viene considerata scaduta');
} else {
    cvCheck(false, 'API ClasseViva espone lo scambio token REST verso PHPSESSID');
}

$refreshSource = file_get_contents($root . '/public/refresh_classeviva_token.php') ?: '';
$integrationsSource = file_get_contents($root . '/public/user_integrations.php') ?: '';
$logoutSource = file_get_contents($root . '/public/logout.php') ?: '';
$oauthCallbackSource = file_get_contents($root . '/public/oauth_callback.php') ?: '';
$bootstrapSource = file_get_contents($root . '/bootstrap.php') ?: '';
$quickLoginPartialSource = file_get_contents($root . '/public/partials/classeviva_quick_login.php') ?: '';
$readme = file_get_contents($root . '/README.md') ?: '';
$envExample = file_get_contents($root . '/.env.example') ?: '';

cvCheck(!str_contains($refreshSource, "saveConfig('classeviva'"), 'il popup rapido non persiste token ClasseViva nel database');
cvCheck(
    str_contains($refreshSource, "define('SKIP_CV_TOKEN_VALIDATION', true)")
        && str_contains($logoutSource, "define('SKIP_CV_TOKEN_VALIDATION', true)"),
    'rinnovo e logout non validano il vecchio token prima di sostituirlo o cancellarlo'
);
cvCheck(!preg_match("/'token'\\s*=>\\s*\\\$classevivaConfig\\['token'\\]/", $integrationsSource), 'il salvataggio configurazione non ricopia token legacy nel database');
cvCheck(str_contains($logoutSource, 'session_get_cookie_params'), 'logout elimina anche il cookie della sessione PHP');
cvCheck(str_contains($oauthCallbackSource, 'session_regenerate_id(true)'), 'il login rigenera l identificatore della sessione PHP');
cvCheck(str_contains($envExample, 'SESSION_IDLE_TIMEOUT='), 'il timeout sessione e configurabile da ambiente');
cvCheck(
    !str_contains($envExample, 'CLASSEVIVA_USERNAME=')
        && !str_contains($integrationsSource, 'CLASSEVIVA_USERNAME'),
    'le credenziali ClasseViva non fanno parte della configurazione persistente'
);
cvCheck(
    str_contains($bootstrapSource, "if (\$classeVivaEnabled && \$classeVivaToken === '')")
        && str_contains($bootstrapSource, "\$config['classeviva']['token_error'] = 'Token ClasseViva mancante';")
        && str_contains($bootstrapSource, 'markReauthenticationRequired()'),
    'il bootstrap richiede la riautenticazione anche quando il token ClasseViva è assente'
);
cvCheck(
    !str_contains($quickLoginPartialSource, 'cvQuickLoginModal')
        && str_contains($quickLoginPartialSource, 'non renderizza un secondo modal'),
    'il bootstrap resta l unico renderer del popup ClasseViva'
);
cvCheck(
    str_contains($readme, 'PHPSESSID') && str_contains($readme, 'database'),
    'README documenta il ciclo di vita session-only di ClasseViva'
);

echo "\nRisultato ClasseViva session auth: {$passes} superati, " . count($failures) . " falliti.\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}
