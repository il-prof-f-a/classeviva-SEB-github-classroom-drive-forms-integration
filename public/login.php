<?php
/**
 * Redirect alla pagina di login principale
 * La pagina di login ufficiale si trova in /index.php (root)
 */

require_once __DIR__ . '/../bootstrap.php';

// Se l'utente è già loggato, rimanda alla dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Altrimenti rimanda alla pagina di login principale
header('Location: ../index.php');
exit;
