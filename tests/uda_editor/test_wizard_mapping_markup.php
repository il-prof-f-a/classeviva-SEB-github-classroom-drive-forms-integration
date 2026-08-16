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
foreach (['teaching_groups.php?return_to=uda_create.php#2', 'teachingGroupCatalog', 'teachingGroupCatalogIndex', 'teaching-group-select', 'id_gruppo[]'] as $needle) {
    if (strpos($markup, $needle) === false) {
        failWizardMarkup("elemento mappatura mancante: {$needle}");
    }
}
foreach (['ClasseVivaTokenGuard', "\$classevivaState['ready']", "listForWizard(true)", "['google_classroom', 'github_classroom']"] as $needle) {
    if (strpos($markup, $needle) === false) {
        failWizardMarkup("guard o validazione gruppi mancante: {$needle}");
    }
}
if (strpos($markup, 'Assegna questa UDA a una o più classi ClasseViva') !== false) {
    failWizardMarkup('help step 2 ancora vincolato a ClasseViva');
}
if (strpos($markup, '$teachingGroupCatalog->findForWizard($groupId)') !== false) {
    failWizardMarkup('POST può accettare un gruppo inattivo tramite findForWizard');
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
