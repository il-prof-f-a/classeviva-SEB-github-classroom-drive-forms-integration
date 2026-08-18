<?php

declare(strict_types=1);

// Verifica il precaricamento automatico delle risorse Classroom nello step 3
// del wizard: se il gruppo ha un corso mappato il selettore mostra quel corso
// come non modificabile, altrimenti carica l'elenco completo dei corsi. Il
// caricamento avviene all'apertura dello step, non tramite un pulsante dedicato.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_create.php') ?: '';
$failures = [];

foreach ([
    'loadWizardClassroomCourses(true)',                // trigger Ricarica / force
    'ajax_get_classroom_courses.php',                  // elenco completo dei corsi
    'wizardClassroomSelectAll',                        // checkbox master (fix render risorse)
    'wizardClassroomCourseHelp',                       // help dinamico del selettore corso
    'setWizardClassroomCourseHelp',                    // helper help dinamico
    'courseSelect.disabled = true',                    // corso mappato non modificabile
    'await loadWizardClassroomResources(mappedCourses[0].id)', // precarico risorse corso mappato
    'if (stepNumber === 3)',                           // hook apertura step 3
    'wizardClassroomLoadedSignature',                  // guardia anti doppio caricamento
] as $needle) {
    if (!str_contains($source, $needle)) {
        $failures[] = $needle;
    }
}

// Il vecchio pulsante "Carica risorse" non deve più essere il trigger.
if (str_contains($source, 'Carica risorse')) {
    $failures[] = 'pulsante "Carica risorse" ancora presente';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

echo "PASS: wizard step 3 precarica automaticamente le risorse Classroom
";
