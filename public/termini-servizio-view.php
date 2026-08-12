<?php
/**
 * Visualizzatore Termini di Servizio
 *
 * Questo file mostra i Termini di Servizio SENZA controlli di autenticazione
 * o consenso privacy, per evitare loop circolari quando viene caricato
 * dall'iframe nella pagina di consenso.
 */

// NON includere bootstrap.php per evitare controlli di sessione
// Leggi e mostra direttamente il file HTML

$termsPath = __DIR__ . '/../termini-servizio.html';

if (!file_exists($termsPath)) {
    http_response_code(404);
    die('Termini di Servizio non trovati');
}

// Leggi il file HTML
$htmlContent = file_get_contents($termsPath);

// Estrai solo il contenuto del body (rimuovi html, head, body tags per iframe)
// Cerca il contenuto tra <body> e </body>
if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $htmlContent, $matches)) {
    $bodyContent = $matches[1];
} else {
    // Se non trova body, usa tutto
    $bodyContent = $htmlContent;
}

// Aggiungi gli stili Bootstrap se non sono già presenti
if (strpos($bodyContent, 'bootstrap') === false) {
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">';
}

// Aggiungi padding per iframe
echo '<div style="padding: 1rem;">';
echo $bodyContent;
echo '</div>';
