<?php

declare(strict_types=1);

// Regressione: nel contesto gruppo-didattico la materia non fa parte della UI
// e non deve bloccare il salvataggio locale del voto.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/rubrica_orale_v2.php') ?: '';
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(!str_contains($source, '<select name="id_materia_cv" class="form-select"'),
    'il selettore materia è ancora esposto nella grafica della rubrica orale');
$require(str_contains($source, "'id_gruppo' => \$idGruppo !== '' ? \$idGruppo : null")
        && str_contains($source, "'id_materia_cv' => (\$subjectId !== '' && \$subjectId !== null) ? \$subjectId : null"),
    'il salvataggio del voto non conserva il contesto gruppo senza materia');
$require(!str_contains($source, "ID materia (subject_id) mancante per il salvataggio."),
    'il salvataggio singolo blocca ancora i gruppi senza materia');
$require(!str_contains($source, 'ID materia mancante'),
    'il salvataggio massivo blocca ancora i gruppi senza materia');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: rubrica orale in modalità gruppo senza materia.\n");
