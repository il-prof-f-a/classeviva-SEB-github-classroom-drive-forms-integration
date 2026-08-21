<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rendererPath = $root . '/src/Core/LegalDocumentRenderer.php';

if (!is_file($rendererPath)) {
    fwrite(STDERR, "FAIL: renderer dei documenti legali non disponibile.\n");
    exit(1);
}

require_once $rendererPath;

use App\Core\LegalDocumentRenderer;

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda_legal_' . bin2hex(random_bytes(6));
if (!mkdir($fixtureRoot, 0700, true) && !is_dir($fixtureRoot)) {
    fwrite(STDERR, "FAIL: impossibile creare le fixture temporanee.\n");
    exit(1);
}

$template = <<<'HTML'
<!doctype html>
<html lang="it">
<head><title>Documento</title></head>
<body><main>Contatto: <span data-legal-admin-contact>Contattare l'amministratore dell'istanza.</span></main></body>
</html>
HTML;

file_put_contents($fixtureRoot . '/privacy-policy.html', $template);
file_put_contents($fixtureRoot . '/termini-servizio.html', $template);

try {
    $renderer = new LegalDocumentRenderer($fixtureRoot);

    $require(
        LegalDocumentRenderer::firstValidAdminEmail(
            ' non-valida , email@email.it, email@email.it'
        ) === 'email@email.it',
        'deve scegliere il primo indirizzo valido'
    );
    $require(
        LegalDocumentRenderer::firstValidAdminEmail('non-valida,') === null,
        'una configurazione priva di email valide deve produrre null'
    );

    $rendered = $renderer->render(
        'privacy-policy',
        'non-valida, email@email.it, email@email.it'
    );
    $require(
        str_contains($rendered, 'href="mailto:email@email.it"'),
        'il link deve usare il primo indirizzo valido'
    );
    $require(
        substr_count($rendered, 'email@email.it') === 2,
        'l’indirizzo deve comparire soltanto nel link e nel testo'
    );
    $require(
        !str_contains($rendered, 'email@email.it'),
        'gli indirizzi successivi della allowlist non devono essere pubblicati'
    );

    $fallback = $renderer->render('termini-servizio', 'non-valida');
    $require(
        str_contains($fallback, "Contattare l'amministratore dell'istanza."),
        'in assenza di email valida deve rimanere il fallback'
    );
    $require(
        !str_contains($fallback, 'mailto:'),
        'il fallback non deve produrre un link vuoto'
    );

    $fragment = $renderer->render('privacy-policy', null, true);
    $require(str_contains($fragment, '<main>'), 'il frammento deve conservare il contenuto del body');
    $require(!str_contains($fragment, '<html'), 'il frammento non deve contenere il tag html');
    $require(!str_contains($fragment, '<head'), 'il frammento non deve contenere il tag head');
    $require(!str_contains($fragment, '<body'), 'il frammento non deve contenere il tag body');

    $unknownRejected = false;
    try {
        $renderer->render('../config/.env', null);
    } catch (InvalidArgumentException) {
        $unknownRejected = true;
    }
    $require($unknownRejected, 'una chiave documento fuori allowlist deve essere rifiutata');

    file_put_contents(
        $fixtureRoot . '/termini-servizio.html',
        '<!doctype html><html><body><p>Nessun marcatore</p></body></html>'
    );
    $missingMarkerRejected = false;
    try {
        $renderer->render('termini-servizio', null);
    } catch (RuntimeException) {
        $missingMarkerRejected = true;
    }
    $require($missingMarkerRejected, 'un template privo del marcatore deve essere rifiutato');
} finally {
    foreach (['privacy-policy.html', 'termini-servizio.html'] as $file) {
        $path = $fixtureRoot . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($fixtureRoot);
}

$termsPath = $root . '/termini-servizio.html';
$privacyPath = $root . '/privacy-policy.html';
$disclaimerPath = $root . '/DISCLAIMER.md';
$readmePath = $root . '/README.md';
$terms = is_file($termsPath) ? (string) file_get_contents($termsPath) : '';
$privacy = is_file($privacyPath) ? (string) file_get_contents($privacyPath) : '';
$readme = is_file($readmePath) ? (string) file_get_contents($readmePath) : '';

$require($terms !== '', 'il template dei termini deve esistere ed essere leggibile');
$require($privacy !== '', 'il template privacy deve esistere ed essere leggibile');
$require(is_file($disclaimerPath), 'il disclaimer autonomo deve essere distribuito');
$require(str_contains($readme, 'DISCLAIMER.md'), 'il README deve collegare il disclaimer');

$emailPattern = '~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i';
foreach (['termini' => $terms, 'privacy' => $privacy] as $label => $document) {
    $require(
        substr_count($document, LegalDocumentRenderer::CONTACT_PLACEHOLDER) === 1,
        "{$label}: deve esserci un solo marcatore del contatto"
    );
    $require(
        preg_match($emailPattern, $document) !== 1,
        "{$label}: il template non deve contenere email hardcoded"
    );
}

$sectionContracts = [
    'termini' => [
        $terms,
        [
            'test-phase',
            'software-distribution',
            'stage',
            'independent-installations',
            'authorized-use',
            'administrator-responsibility',
            'human-review',
            'external-services',
            'security-continuity',
            'license',
            'warranties',
            'contact',
            'official-sources',
        ],
    ],
    'privacy' => [
        $privacy,
        [
            'test-phase',
            'scope',
            'operator',
            'data-sources',
            'runtime-data',
            'session-data',
            'persistent-data',
            'legacy-data',
            'purposes',
            'roles-legal-basis',
            'third-parties',
            'retention',
            'cookies',
            'security',
            'breach-consequences',
            'data-breach',
            'rights',
            'changes',
            'official-sources',
        ],
    ],
];

foreach ($sectionContracts as $label => [$document, $requiredIds]) {
    if ($document === '') {
        continue;
    }
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML($document, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $require($loaded, "{$label}: il documento deve essere HTML analizzabile");
    if (!$loaded) {
        continue;
    }

    $xpath = new DOMXPath($dom);
    foreach ($requiredIds as $id) {
        $nodes = $xpath->query('//*[@id="' . $id . '"]');
        $require($nodes !== false && $nodes->length === 1, "{$label}: sezione {$id} assente o duplicata");
    }

    $links = [];
    foreach ($xpath->query('//a[@href]') ?: [] as $node) {
        $links[] = (string) $node->getAttribute('href');
    }
    $require(
        count(array_filter($links, static fn(string $href): bool => str_contains($href, 'eur-lex.europa.eu'))) === 1,
        "{$label}: deve esserci un riferimento ufficiale al GDPR"
    );
    $require(
        count(array_filter($links, static fn(string $href): bool => str_contains($href, 'garanteprivacy.it'))) >= 1,
        "{$label}: deve esserci un riferimento ufficiale al Garante"
    );
}

$runEndpoint = static function (
    string $applicationRoot,
    string $document,
    ?string $adminEmails,
    bool $fragment = false
): array {
    $runnerPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda_legal_endpoint_' . bin2hex(random_bytes(6)) . '.php';
    $endpointPath = $applicationRoot . '/public/legal_document.php';
    $environmentSetup = $adminEmails === null
        ? "putenv('ADMIN_EMAILS'); unset(\$_ENV['ADMIN_EMAILS'], \$_SERVER['ADMIN_EMAILS']);"
        : 'putenv(' . var_export('ADMIN_EMAILS=' . $adminEmails, true) . '); '
            . '$_ENV[\'ADMIN_EMAILS\'] = ' . var_export($adminEmails, true) . ';';
    $runner = '<?php declare(strict_types=1); '
        . $environmentSetup
        . ' $_GET = ' . var_export([
            'document' => $document,
            'fragment' => $fragment ? '1' : '0',
        ], true) . ';'
        . ' $_SERVER[\'SCRIPT_NAME\'] = \'/public/legal_document.php\';'
        . ' $_SERVER[\'REQUEST_URI\'] = \'/privacy-policy.html\';'
        . ' ob_start(); require ' . var_export($endpointPath, true) . ';'
        . ' $body = ob_get_clean();'
        . ' echo json_encode(['
        . '   \'status\' => http_response_code(),'
        . '   \'session\' => session_status(),'
        . '   \'body\' => $body,'
        . ' ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);';
    file_put_contents($runnerPath, $runner);

    try {
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
        $decoded = json_decode(implode("\n", $output), true);
        return [
            'exit' => $exitCode,
            'result' => is_array($decoded) ? $decoded : null,
            'raw' => implode("\n", $output),
        ];
    } finally {
        if (is_file($runnerPath)) {
            unlink($runnerPath);
        }
    }
};

$endpointPath = $root . '/public/legal_document.php';
$require(is_file($endpointPath), 'l’endpoint pubblico dei documenti legali deve esistere');
if (is_file($endpointPath)) {
    $endpointResult = $runEndpoint(
        $root,
        'privacy-policy',
        'non-valida,email@email.it,email@email.it'
    );
    $require($endpointResult['exit'] === 0, 'l’endpoint valido deve terminare senza errori');
    $require(is_array($endpointResult['result']), 'l’endpoint valido deve restituire HTML acquisibile');
    if (is_array($endpointResult['result'])) {
        $require($endpointResult['result']['status'] === 200, 'l’endpoint valido deve rispondere 200');
        $require(
            $endpointResult['result']['session'] === PHP_SESSION_NONE,
            'l’endpoint pubblico non deve avviare una sessione'
        );
        $require(
            str_contains($endpointResult['result']['body'], 'mailto:email@email.it'),
            'l’endpoint deve usare il primo ADMIN_EMAILS valido'
        );
        $require(
            !str_contains($endpointResult['result']['body'], 'email@email.it'),
            'l’endpoint non deve pubblicare gli altri amministratori'
        );
    }

    $fallbackResult = $runEndpoint($root, 'termini-servizio', 'non-valida');
    $require($fallbackResult['exit'] === 0, 'l’endpoint senza email deve terminare senza errori');
    $require(
        is_array($fallbackResult['result'])
            && str_contains(
                $fallbackResult['result']['body'],
                "Contattare l'amministratore dell'istanza."
            ),
        'l’endpoint senza email deve mostrare il fallback'
    );

    $fragmentResult = $runEndpoint($root, 'privacy-policy', null, true);
    $fragmentBody = is_array($fragmentResult['result']) ? (string) $fragmentResult['result']['body'] : '';
    $require(
        preg_match('/<html(?:\s|>)/i', $fragmentBody) !== 1,
        'il viewer non deve ricevere il documento HTML completo'
    );
    $require(
        preg_match('/<head(?:\s|>)/i', $fragmentBody) !== 1,
        'il viewer non deve ricevere la sezione head'
    );
    $require(str_contains($fragmentBody, 'container-fluid'), 'il frammento deve avere il contenitore iframe');

    $unknownResult = $runEndpoint($root, '../config/.env', null);
    $unknownBody = is_array($unknownResult['result']) ? (string) $unknownResult['result']['body'] : '';
    $require(
        is_array($unknownResult['result']) && $unknownResult['result']['status'] === 404,
        'una chiave documento sconosciuta deve rispondere 404'
    );
    $require(
        $unknownBody === 'Documento non disponibile.',
        'il 404 non deve esporre dettagli o percorsi locali'
    );
}

$runView = static function (string $applicationRoot, string $viewFile): array {
    $runnerPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda_legal_view_' . bin2hex(random_bytes(6)) . '.php';
    $viewPath = $applicationRoot . '/public/' . $viewFile;
    $runner = '<?php declare(strict_types=1); '
        . ' putenv(\'ADMIN_EMAILS=email@email.it\');'
        . ' $_ENV[\'ADMIN_EMAILS\'] = \'email@email.it\';'
        . ' $_SERVER[\'SCRIPT_NAME\'] = ' . var_export('/public/' . $viewFile, true) . ';'
        . ' ob_start(); require ' . var_export($viewPath, true) . ';'
        . ' $body = ob_get_clean();'
        . ' echo json_encode(['
        . '   \'status\' => http_response_code(),'
        . '   \'session\' => session_status(),'
        . '   \'body\' => $body,'
        . ' ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);';
    file_put_contents($runnerPath, $runner);

    try {
        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
        $decoded = json_decode(implode("\n", $output), true);
        return [
            'exit' => $exitCode,
            'result' => is_array($decoded) ? $decoded : null,
            'raw' => implode("\n", $output),
        ];
    } finally {
        if (is_file($runnerPath)) {
            unlink($runnerPath);
        }
    }
};

foreach (
    ['privacy-policy-view.php', 'termini-servizio-view.php']
    as $viewFile
) {
    $viewResult = $runView($root, $viewFile);
    $require($viewResult['exit'] === 0, "{$viewFile}: il viewer deve terminare senza errori");
    $require(is_array($viewResult['result']), "{$viewFile}: il viewer deve produrre output acquisibile");
    if (!is_array($viewResult['result'])) {
        continue;
    }
    $require(
        $viewResult['result']['session'] === PHP_SESSION_NONE,
        "{$viewFile}: il viewer non deve avviare sessioni"
    );
    $require(
        str_contains((string) $viewResult['result']['body'], 'mailto:email@email.it'),
        "{$viewFile}: il viewer deve risolvere il contatto dinamico"
    );
    $require(
        preg_match('/<html(?:\s|>)/i', (string) $viewResult['result']['body']) !== 1,
        "{$viewFile}: il viewer deve restituire soltanto il frammento"
    );
}

$require(is_file($root . '/.htaccess'), 'la root web deve contenere il rewrite per gli URL legali storici');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: rendering sicuro dei documenti legali.\n");
