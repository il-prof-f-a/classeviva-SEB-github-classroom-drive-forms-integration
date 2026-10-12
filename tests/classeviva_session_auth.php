<?php

declare(strict_types=1);

use App\Core\ClasseVivaSessionStore;
use App\Integration\ClasseVivaAPI;

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

$session = [];
$store = new ClasseVivaSessionStore($session);
$store->storeTokenPayload([
    'token' => 'rest-token',
    'user_id' => '1234567',
    'ident' => 'S1234567',
    'expire' => '2099-01-01T00:00:00Z',
    'username' => 'must-not-survive',
    'password' => 'must-not-survive',
], 1_000);

$saved = $store->getTokenPayload(3_600, 1_001);
cvCheck(($saved['token'] ?? null) === 'rest-token', 'il token REST resta nella sola sessione applicativa');
cvCheck(
    !isset($saved['username']) && !isset($saved['password']) && !isset($saved['php_session_id']),
    'credenziali e PHPSESSID non vengono conservati nel payload REST'
);

$config = $store->mergeIntoConfig(['classeviva' => ['enabled' => true]], 3_600, 1_002);
cvCheck(($config['classeviva']['token']['token'] ?? null) === 'rest-token', 'il token REST viene esposto al codice esistente senza database');

$store->storeTokenPayload(['token' => 'expiring-token', 'user_id' => '1234567'], 2_000);
cvCheck($store->getTokenPayload(60, 2_061) === null, 'il token viene eliminato dopo il timeout di inattivita');
cvCheck($store->requiresReauthentication(), 'la scadenza della sessione richiede una nuova autenticazione');

$store->storeTokenPayload(['token' => 'new-token', 'user_id' => '1234567'], 3_000);
cvCheck(!$store->requiresReauthentication(), 'un nuovo login rimuove la richiesta di riautenticazione');
$store->clearAuth();
cvCheck($store->getTokenPayload(3_600, 3_001) === null, 'logout elimina il token REST dalla sessione');

try {
    $store->storeTokenPayload([], 4_000);
    cvCheck(false, 'un payload senza token viene rifiutato');
} catch (InvalidArgumentException) {
    cvCheck(true, 'un payload senza token viene rifiutato');
}

$restOnlyMessage = ClasseVivaAPI::UNSUPPORTED_WEB_SESSION_MESSAGE;
$api = new ClasseVivaAPI([
    'classeviva' => [
        'token' => ['token' => 'rest-token', 'user_id' => '1234567'],
    ],
], new GuzzleHttp\Client([
    'handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler()),
]));

try {
    $api->authenticateWebSession('user', 'password');
    cvCheck(false, 'il login ClasseViva non apre più una sessione web');
} catch (Throwable $e) {
    cvCheck($e->getMessage() === $restOnlyMessage, 'il login web obsoleto restituisce lo stub esplicito');
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
cvCheck(!str_contains($integrationsSource, "'token' => \$classevivaConfig['token']"), 'il salvataggio configurazione non ricopia token legacy nel database');
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
    str_contains($readme, 'token REST') && str_contains($readme, 'ClasseViva non permette più'),
    'README documenta il nuovo ciclo REST-only di ClasseViva'
);

echo "\nRisultato ClasseViva REST-only: {$passes} superati, " . count($failures) . " falliti.\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}
