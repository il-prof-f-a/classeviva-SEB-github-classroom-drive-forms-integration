<?php

declare(strict_types=1);

// Verifica che lo step 6 del wizard colleghi i test ai provider mappati senza
// selezione manuale: corso/classroom preselezionati dal gruppo, altrimenti
// messaggio "nessun mapping" con link alla pagina dei mapping.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_create.php') ?: '';
$failures = [];

foreach ([
    'wizardMappedProviderContext',          // risoluzione provider dal gruppo selezionato
    'test-classroom-nomap',                 // fallback "nessun corso mappato"
    'test-github-nomap',                    // fallback "nessuna classroom mappata"
    'const loadClassroom = async',          // carica compiti del corso mappato
    'const loadGithub = async',             // carica assignment della classroom mappata
    'id_gruppo: [...selectedGroupIds]',     // query per id_gruppo (non più class_ids CV)
    'GoogleFormsCatalogHelper',            // evidenziazione form già pubblicati (helper condiviso)
    'teaching_groups.php?return_to=uda_create.php#6', // link ai mapping
] as $needle) {
    if (!str_contains($source, $needle)) {
        $failures[] = $needle;
    }
}

// L'evidenziazione dei form pubblicati vive nell'helper condiviso.
$helper = file_get_contents($root . '/public/assets/js/google-forms-catalog.js') ?: '';
foreach (['published_in_classroom', 'highlight'] as $needle) {
    if (!str_contains($helper, $needle)) {
        $failures[] = "google-forms-catalog.js: {$needle} assente";
    }
}

// I vecchi selettori manuali non devono più essere il flusso primario.
foreach (['loadClassroomCourses', 'loadClassroomAssignments'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        $failures[] = "funzione legacy ancora presente: {$forbidden}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

echo "PASS: wizard step 6 collega test ai provider mappati
";
