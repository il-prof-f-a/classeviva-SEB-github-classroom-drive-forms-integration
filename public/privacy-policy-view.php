<?php

declare(strict_types=1);

// Viewer pubblico per l'iframe del consenso: nessun bootstrap, sessione o database.
$_GET['document'] = 'privacy-policy';
$_GET['fragment'] = '1';
require __DIR__ . '/legal_document.php';
