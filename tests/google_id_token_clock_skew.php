<?php

declare(strict_types=1);

use App\Core\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Google\Client;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$failures = [];
$passes = 0;

function clockSkewCheck(bool $condition, string $message): void
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

if (!class_exists(GoogleIdTokenVerifier::class)) {
    clockSkewCheck(false, 'verificatore ID token Google con tolleranza temporale limitata');
} else {
    JWT::$leeway = 7;
    $client = new class extends Client {
        public int $observedLeeway = -1;

        public function verifyIdToken($idToken = null)
        {
            $this->observedLeeway = JWT::$leeway;
            return ['sub' => 'test-user'];
        }
    };

    $payload = GoogleIdTokenVerifier::verify($client, 'synthetic-id-token');
    clockSkewCheck($client->observedLeeway === 30, 'la verifica Google accetta fino a 30 secondi di clock skew');
    clockSkewCheck(($payload['sub'] ?? null) === 'test-user', 'il payload verificato viene restituito senza alterazioni');
    clockSkewCheck(JWT::$leeway === 7, 'la tolleranza JWT precedente viene ripristinata');

    $failingClient = new class extends Client {
        public function verifyIdToken($idToken = null)
        {
            throw new RuntimeException('synthetic verification failure');
        }
    };
    try {
        GoogleIdTokenVerifier::verify($failingClient, 'synthetic-invalid-token');
    } catch (RuntimeException) {
        // Atteso: interessa verificare il ripristino nel blocco finally.
    }
    clockSkewCheck(JWT::$leeway === 7, 'la tolleranza JWT viene ripristinata anche dopo un errore');
}

echo "\nRisultato clock skew Google: {$passes} superati, " . count($failures) . " falliti.\n";
if ($failures !== []) {
    exit(1);
}
