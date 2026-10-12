<?php

declare(strict_types=1);

use App\Integration\ClasseVivaAPI;
use App\Core\Security\PublicError;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$failures = [];
$passes = 0;
$expectedMessage = 'funzione non più supportata a causa di incompatibilità con il sistema';

function cvRestCheck(bool $condition, string $message): void
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

$api = new ClasseVivaAPI([
    'classeviva' => [
        'token' => [
            'token' => 'rest-token',
            'user_id' => '1234567',
        ],
    ],
], new GuzzleHttp\Client([
    'handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler()),
]));

$unsupportedCalls = [
    'authenticateWebSession' => static fn() => $api->authenticateWebSession('user', 'password'),
    'getPhpSessionToken' => static fn() => $api->getPhpSessionToken(),
    'pingWebSession' => static fn() => $api->pingWebSession(true),
    'publishGrade' => static fn() => $api->publishGrade([]),
    'getStudentGrades' => static fn() => $api->getStudentGrades('student', 'class', 'subject'),
    'deleteGrade' => static fn() => $api->deleteGrade([]),
    'publishAnnotationWeb' => static fn() => $api->publishAnnotationWeb([]),
    'deleteAnnotation' => static fn() => $api->deleteAnnotation([]),
    'getStudentAnnotationsWeb' => static fn() => $api->getStudentAnnotationsWeb('student', 'class'),
];

foreach ($unsupportedCalls as $name => $call) {
    try {
        $call();
        cvRestCheck(false, "{$name} espone lo stub REST-only");
    } catch (Throwable $e) {
        cvRestCheck(
            $e->getMessage() === $expectedMessage,
            "{$name} restituisce l'errore di incompatibilità previsto"
        );
    }
}

cvRestCheck(
    PublicError::message(new Exception($expectedMessage), 'classeviva stub') === $expectedMessage,
    'i chiamanti web mostrano all utente il messaggio di incompatibilità senza riferimento generico'
);

$restSource = file_get_contents($root . '/src/Integration/ClasseVivaAPI.php') ?: '';
$integrationsSource = file_get_contents($root . '/public/user_integrations.php') ?: '';
$refreshSource = file_get_contents($root . '/public/refresh_classeviva_token.php') ?: '';
$laboratorioSource = file_get_contents($root . '/public/laboratorio_griglia.php') ?: '';
$plusminusSource = file_get_contents($root . '/public/pubblica_plusminus.php') ?: '';
$apiHandlerSource = file_get_contents($root . '/public/api/test_api_handler.php') ?: '';

cvRestCheck(
    str_contains($restSource, "UNSUPPORTED_WEB_SESSION_MESSAGE")
        && str_contains($restSource, $expectedMessage),
    'ClasseVivaAPI centralizza il messaggio degli endpoint web non più supportati'
);
$legacyPayloadApi = new ClasseVivaAPI(['classeviva' => []]);
$legacyPayloadApi->setTokenPayload([
    'token' => 'rest-token',
    'php_session_id' => 'legacy-session',
    'username' => 'legacy-user',
    'password' => 'legacy-password',
    'unexpected' => 'must-not-survive',
]);
cvRestCheck(
    !isset(($legacyPayloadApi->getTokenPayload() ?? [])['php_session_id'])
        && !isset(($legacyPayloadApi->getTokenPayload() ?? [])['password'])
        && !isset(($legacyPayloadApi->getTokenPayload() ?? [])['unexpected']),
    'un payload legacy viene ripulito da PHPSESSID, credenziali e campi estranei'
);
cvRestCheck(
    !str_contains($integrationsSource, 'authenticateWebSession(')
        && !str_contains($integrationsSource, 'getPhpSessionToken()'),
    'la configurazione ClasseViva usa soltanto il login REST'
);
cvRestCheck(
    !str_contains($refreshSource, 'authenticateWebSession(')
        && !str_contains($refreshSource, 'getPhpSessionToken()'),
    'il rinnovo rapido ClasseViva usa soltanto il login REST'
);
cvRestCheck(
    !str_contains($laboratorioSource, 'publishAnnotationWeb(')
        && !str_contains($plusminusSource, 'publishAnnotationWeb(')
        && !str_contains($apiHandlerSource, 'getStudentAnnotationsWeb('),
    'i flussi di annotazione non invocano più il ponte web dismesso'
);

$subjectsApi = new ClasseVivaAPI([
    'classeviva' => [
        'token' => ['token' => 'rest-token', 'user_id' => '1234567'],
    ],
], new GuzzleHttp\Client([
    'handler' => GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([
        new GuzzleHttp\Psr7\Response(200, [], '{"ticket":"valid"}'),
        new GuzzleHttp\Psr7\Response(200, [], '{"classes":[{"classId":"class-1","name":"4L","subjects":[{"subjectId":"sub-1","subjectDesc":"TPSIT"}]}]}'),
    ])),
]));
$subjects = $subjectsApi->getSubjects();
cvRestCheck(
    count($subjects) === 1
        && ($subjects[0]['id'] ?? '') === 'sub-1'
        && ($subjects[0]['name'] ?? '') === 'TPSIT',
    'getSubjects usa le materie incluse nell endpoint REST delle classi'
);

if ($failures) {
    fwrite(STDERR, "\nFAILURES:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\n{$passes} test ClasseViva REST-only superati.\n";
