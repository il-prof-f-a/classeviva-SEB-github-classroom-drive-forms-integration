<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Core/ProviderCapabilityResolver.php';

use App\Core\ProviderCapabilityResolver;

$failures = [];

$cvLinks = [['provider' => 'classeviva', 'stato' => 'attivo']];
$googleLinks = [['provider' => 'google_classroom', 'stato' => 'attivo']];
$githubLinks = [['provider' => 'github_classroom', 'stato' => 'attivo']];
$disabledCv = [['provider' => 'classeviva', 'stato' => 'disattivo']];

$assert = static function (bool $expected, bool $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (atteso ' . var_export($expected, true) . ', ottenuto ' . var_export($actual, true) . ')';
    }
};

// Operazioni ClasseViva solo con un link CV attivo.
$assert(true, ProviderCapabilityResolver::supports($cvLinks, 'classeviva', 'publish_grade'), 'CV publish_grade supportato');
$assert(true, ProviderCapabilityResolver::supports($cvLinks, 'classeviva', 'sync_roster'), 'CV sync_roster supportato');
// Le operazioni Google/GitHub non sono operazioni ClasseViva.
$assert(false, ProviderCapabilityResolver::supports($cvLinks, 'classeviva', 'list_courses'), 'op Google non e operazione CV');
$assert(false, ProviderCapabilityResolver::supports($cvLinks, 'google_classroom', 'publish_grade'), 'publish_grade non e operazione Google');

// Gruppo Google-only.
$assert(false, ProviderCapabilityResolver::supports($googleLinks, 'classeviva', 'publish_grade'), 'gruppo Google-only rifiuta op CV');
$assert(true, ProviderCapabilityResolver::supports($googleLinks, 'google_classroom', 'list_courses'), 'gruppo Google-only accetta op Google');

// Gruppo GitHub-only.
$assert(false, ProviderCapabilityResolver::supports($githubLinks, 'classeviva', 'sync_roster'), 'gruppo GitHub-only rifiuta op CV');
$assert(true, ProviderCapabilityResolver::supports($githubLinks, 'github_classroom', 'list_assignments'), 'gruppo GitHub-only accetta op GitHub');

// Provider e operazioni sconosciute.
$assert(false, ProviderCapabilityResolver::supports($cvLinks, 'unknown_provider', 'publish_grade'), 'provider sconosciuto');
$assert(false, ProviderCapabilityResolver::supports($cvLinks, 'classeviva', 'unknown_operation'), 'operazione sconosciuta');

// Link disattivato.
$assert(false, ProviderCapabilityResolver::supports($disabledCv, 'classeviva', 'publish_grade'), 'link disattivo non supportato');

// Nessun collegamento.
$assert(false, ProviderCapabilityResolver::supports([], 'classeviva', 'publish_grade'), 'nessun collegamento');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: capability resolver provider-neutral.\n");
