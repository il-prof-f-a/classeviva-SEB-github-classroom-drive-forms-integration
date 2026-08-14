<?php

declare(strict_types=1);

$markup = file_get_contents(dirname(__DIR__, 2) . '/public/uda_create.php') ?: '';
$mapMarkup = file_get_contents(dirname(__DIR__, 2) . '/public/map_classes.php') ?: '';
$githubMarkup = file_get_contents(dirname(__DIR__, 2) . '/public/github_classroom_mapping.php') ?: '';

function failWizardMarkup(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

foreach (['Info Generali', 'Classi e integrazioni', 'Materiali Didattici', 'Obiettivi Didattici e Disciplinari', 'Domande per Interrogazioni', 'Test e Valutazioni', 'Riepilogo'] as $label) {
    if (strpos($markup, $label) === false) {
        failWizardMarkup("etichetta mancante: {$label}");
    }
}
if (strpos($markup, 'Importa da Argomento Classroom') !== false) {
    failWizardMarkup('import Classroom ancora presente nelle informazioni generali');
}
if (strpos($markup, 'name="classroom_imported"') !== false) {
    failWizardMarkup('campo classroom_imported ancora esposto');
}
foreach (['map_classes.php?return_to=uda_create.php', 'github_classroom_mapping.php?return_to=uda_create.php', 'classroomMappingIndex', 'githubMappingIndex'] as $needle) {
    if (strpos($markup, $needle) === false) {
        failWizardMarkup("elemento mappatura mancante: {$needle}");
    }
}
foreach (['updateWizardHash', 'location.hash'] as $needle) {
    if (strpos($markup, $needle) === false) {
        failWizardMarkup("ritorno/hash wizard mancante: {$needle}");
    }
}
foreach (['uda_create.php?integration_updated=1#2'] as $needle) {
    if (strpos($mapMarkup, $needle) === false || strpos($githubMarkup, $needle) === false) {
        failWizardMarkup("ritorno mappatura wizard mancante: {$needle}");
    }
}

echo "PASS: wizard mapping markup\n";
