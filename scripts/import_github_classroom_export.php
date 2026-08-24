<?php

declare(strict_types=1);

/**
 * Importa gli assignment GitHub Classroom (export + vecchio DB) nel modello REST.
 *
 * Catena di mapping:
 *  - classroom.github_classroom_id -> GITHUB_CLASSROOMS -> id_classe_cv/id_materia_cv
 *  - id_classe_cv/id_materia_cv    -> GRUPPI_INTEGRAZIONI(classeviva) -> id_gruppo
 *  - github_username               -> GITHUB_ASSIGNMENT_STUDENT_MAP -> id_studente_cv
 *  - id_studente_cv                -> StudentIdentityResolver -> id_studente interno
 *
 * Config fissata (approvata dall'utente):
 *  - org = ITFranchettiSalviani (l'org è stata rinominata da IIS-Franchetti-Salviani)
 *  - utente destinazione = USER_6a801fe6949bb
 *  - saltate le classroom 302101 (quinte-inf, test docente) e 327652 (4L-TPSIT, 0 accettati)
 *
 * Uso (locale o stage, stesso script):
 *  php scripts/import_github_classroom_export.php --dry-run [--export=... --source=...]
 *  php scripts/import_github_classroom_export.php --apply --confirm=IMPORT-GITHUB-ASSIGNMENTS
 */

use App\Core\Database\DatabaseFactory;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;

$root = dirname(__DIR__);
$config = require_once $root . '/bootstrap.php';

$options = getopt('', ['export:', 'source:', 'target-user:', 'dry-run', 'apply', 'confirm:']);
$mode = isset($options['apply']) ? 'apply' : 'dry-run';

// ---- Config fissata ----
$GITHUB_ORG = 'ITFranchettiSalviani';         // org attuale
$GITHUB_OLD_ORG = 'IIS-Franchetti-Salviani';  // vecchio nome org nelle URL repo dell'export
$SKIP_CLASSROOM_IDS = ['302101', '327652'];   // classroom da saltare

$mainRepo = dirname($root);
$exportDir = (string)($options['export'] ?? $mainRepo . '/Materiale/export-francescoadriani');
$sourcePath = (string)($options['source'] ?? $root . '/database/backup/uda_mysql_2026-02-08_11-35-31.json');
$targetUser = trim((string)($options['target-user'] ?? 'USER_6a801fe6949bb'));

if ($mode === 'apply' && (($options['confirm'] ?? '') !== 'IMPORT-GITHUB-ASSIGNMENTS')) {
    fwrite(STDERR, "Per applicare servono: --apply --confirm=IMPORT-GITHUB-ASSIGNMENTS\n");
    exit(2);
}

$environment = strtolower((string)env('APP_ENV', 'local'));
if ($environment === 'production') {
    fwrite(STDERR, "Import bloccato: non consentito in production.\n");
    exit(3);
}

if (!is_dir($exportDir) || !is_file($sourcePath)) {
    fwrite(STDERR, "Percorsi non validi: export={$exportDir} source={$sourcePath}\n");
    exit(2);
}

$db = DatabaseFactory::createWithInitialization($config, true);

/** Data rows da un dump {tables:{TABLE:{columns:[...], rows:[...]}}} */
function importRows(array $dump, string $table): array
{
    $obj = $dump['tables'][$table] ?? [];
    return (isset($obj['rows']) && is_array($obj['rows'])) ? $obj['rows'] : [];
}

/** Riscrive l'org nelle URL repo (vecchio -> nuovo). */
function rewriteRepoUrl(string $url, string $old, string $new): string
{
    return str_replace($old, $new, $url);
}

// ---- 1) Vecchio DB ----
$dump = json_decode((string)file_get_contents($sourcePath), true);
if (!is_array($dump)) {
    fwrite(STDERR, "Impossibile leggere il vecchio DB JSON.\n");
    exit(2);
}
$oldClassrooms = importRows($dump, 'GITHUB_CLASSROOMS');
$oldStudentMap = importRows($dump, 'GITHUB_ASSIGNMENT_STUDENT_MAP');

$classroomToCv = [];   // github_classroom_id -> [id_classe_cv, id_materia_cv]
foreach ($oldClassrooms as $r) {
    $gh = trim((string)($r['github_classroom_id'] ?? ''));
    if ($gh !== '') {
        $classroomToCv[$gh] = [trim((string)($r['id_classe_cv'] ?? '')), trim((string)($r['id_materia_cv'] ?? ''))];
    }
}
$userToCv = [];       // github_username(lower) -> [id_studente_cv, data_creazione]
foreach ($oldStudentMap as $r) {
    $u = strtolower(trim((string)($r['github_username'] ?? '')));
    if ($u !== '') {
        $userToCv[$u] = [
            trim((string)($r['id_studente_cv'] ?? '')),
            trim((string)($r['data_creazione'] ?? '')),
        ];
    }
}

// ---- 2) Live: mappa classe/materia -> gruppo ----
$cvToGroup = [];      // "classe|materia" -> id_gruppo
foreach ($db->findWhere('GRUPPI_INTEGRAZIONI', ['provider' => 'classeviva']) as $it) {
    $cl = trim((string)($it['external_context_id'] ?? ''));
    $ma = trim((string)($it['external_subject_id'] ?? ''));
    $gid = trim((string)($it['id_gruppo'] ?? ''));
    if ($cl !== '' && $gid !== '') {
        $cvToGroup[$cl . '|' . $ma] = $gid;
    }
}

// ---- 3) Live: TEST github esistenti (per nome) ----
$existingTests = [];  // nome(lower) -> id_test
foreach ($db->findWhere('TEST', ['piattaforma' => 'github']) as $t) {
    $n = mb_strtolower(trim((string)($t['nome'] ?? '')), 'UTF-8');
    if ($n !== '' && !isset($existingTests[$n])) {
        $existingTests[$n] = (string)($t['id_test'] ?? '');
    }
}

// ---- 4) Export ----
$classrooms = json_decode((string)file_get_contents($exportDir . '/classrooms.json'), true);
if (!is_array($classrooms)) {
    fwrite(STDERR, "Impossibile leggere classrooms.json\n");
    exit(2);
}

$planTests = [];
$planLinks = [];
$skippedClassrooms = [];
$unresolvedStudents = [];

foreach ($classrooms as $cl) {
    $clId = (string)($cl['id'] ?? '');
    $clName = (string)($cl['name'] ?? $clId);
    if (in_array($clId, $SKIP_CLASSROOM_IDS, true)) {
        $skippedClassrooms[] = "$clName (id $clId)";
        continue;
    }
    $clDir = $exportDir . '/classroom-' . $clId;

    [$cvClass, $cvSubject] = $classroomToCv[$clId] ?? ['', ''];
    $groupId = ($cvClass !== '' && isset($cvToGroup[$cvClass . '|' . $cvSubject])) ? $cvToGroup[$cvClass . '|' . $cvSubject] : '';

    $assignments = json_decode((string)@file_get_contents($clDir . '/assignments.json'), true);
    if (!is_array($assignments)) {
        continue;
    }
    foreach ($assignments as $a) {
        $aId = (string)($a['id'] ?? '');
        $title = trim((string)($a['title'] ?? 'Assignment ' . $aId));
        $slug = trim((string)($a['slug'] ?? ''));
        $accFile = $clDir . '/assignment-' . $aId . '/accepted-assignments.json';
        if (!is_file($accFile)) {
            continue;
        }
        $accepted = json_decode((string)file_get_contents($accFile), true);
        if (!is_array($accepted) || $accepted === []) {
            continue;
        }
        $prefix = $slug !== '' ? $slug : 'assignment-' . $aId;

        $key = mb_strtolower($title, 'UTF-8');
        $testId = $existingTests[$key] ?? null;
        $isNew = ($testId === null);
        if ($testId === null) {
            $testId = 'TEST_' . uniqid();
        }
        $cfg = [
            'org' => $GITHUB_ORG,
            'slug' => $slug,
            'repo_prefix' => $prefix,
            'template_id' => '',
            'modalita_invito' => [],
            'imported_from_classroom' => true,
            'classroom_id' => $clId,
        ];
        $planTests[] = [
            'test_id' => $testId, 'new' => $isNew, 'nome' => $title,
            'id_gruppo' => $groupId, 'slug' => $slug, 'classroom' => $clName,
            'accepted' => count($accepted), 'config' => $cfg,
        ];

        foreach ($accepted as $entry) {
            $login = trim((string)($entry['students'][0]['login'] ?? ''));
            $repoUrl = rewriteRepoUrl((string)($entry['repository']['html_url'] ?? ''), $GITHUB_OLD_ORG, $GITHUB_ORG);
            if ($login === '' || $repoUrl === '') {
                continue;
            }
            $mapped = $userToCv[strtolower($login)] ?? null;
            if ($mapped === null || $mapped[0] === '') {
                $unresolvedStudents[] = "$login ($title)";
                continue;
            }
            $planLinks[] = [
                'test_id' => $testId, 'nome' => $title,
                'github_username' => $login, 'id_studente_cv' => $mapped[0],
                'repo_url' => $repoUrl, 'accepted_at' => $mapped[1],
            ];
        }
    }
}

// ---- Report ----
echo "=== Modalità: {$mode} ===\n";
echo "Org: {$GITHUB_ORG}\n";
echo "Target utente: {$targetUser}\n\n";

echo "TEST da creare/aggiornare: " . count($planTests) . "\n";
foreach ($planTests as $t) {
    echo "  [" . ($t['new'] ? 'NUOVO' : 'esistente') . "] {$t['test_id']} — {$t['nome']} (gruppo=" . ($t['id_gruppo'] ?: 'NON MAPPATO') . ", slug={$t['slug']})\n";
}
echo "\nLink studente da creare: " . count($planLinks) . "\n";

if ($skippedClassrooms !== []) {
    echo "\nClassroom saltate:\n";
    foreach ($skippedClassrooms as $s) echo "  - $s\n";
}
if ($unresolvedStudents !== []) {
    echo "\nStudenti senza id_studente_cv (non importati):\n";
    foreach ($unresolvedStudents as $s) echo "  - $s\n";
}

if ($mode !== 'apply') {
    echo "\n[DRY-RUN] Nessuna scrittura. Per applicare: --apply --confirm=IMPORT-GITHUB-ASSIGNMENTS\n";
    exit(0);
}

// ---- Apply ----
$resolver = new StudentIdentityResolver(
    new StudentRepository($db, $targetUser),
    new StudentIdentityRepository($db, $targetUser),
    new GroupStudentRepository($db, $targetUser),
    new StudentResourceRepository($db, $targetUser)
);

// 1) TEST
$validTestIds = [];
foreach ($planTests as $t) {
    $validTestIds[$t['test_id']] = true;
    $configJson = json_encode($t['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($t['new']) {
        $db->insertRow('TEST', [
            'id_test' => $t['test_id'],
            'id_uda' => '',
            'id_gruppo' => $t['id_gruppo'],
            'tipo_test' => 'altro',
            'nome' => $t['nome'],
            'descrizione' => 'Importato da GitHub Classroom',
            'piattaforma' => 'github',
            'url' => '',
            'id_esterno' => '',
            'pubblicato' => 'NO',
            'data_creazione' => date('Y-m-d H:i:s'),
            'note' => 'importato da GitHub Classroom',
            'id_utente' => $targetUser,
            'url_studenti' => '',
            'url_docente' => '',
            'url_assignment_student' => '',
            'url_assignment_teacher' => '',
            'github_config_json' => $configJson,
        ]);
        echo "TEST creato: {$t['test_id']} ({$t['nome']})\n";
    } else {
        $db->updateRow('TEST', 'id_test', $t['test_id'], [
            'id_gruppo' => $t['id_gruppo'],
            'github_config_json' => $configJson,
        ]);
        echo "TEST aggiornato: {$t['test_id']} ({$t['nome']})\n";
    }
}

// 2) Link studente
$inserted = 0;
foreach ($planLinks as $l) {
    $cvStudent = $resolver->resolveOrCreate('classeviva', $l['id_studente_cv']);
    $ghStudent = $resolver->resolveOrCreate('github_classroom', $l['github_username']);
    if ((string)$cvStudent['id_studente'] !== (string)$ghStudent['id_studente']) {
        $resolver->merge((string)$ghStudent['id_studente'], (string)$cvStudent['id_studente']);
    }
    $idStudente = (string)$cvStudent['id_studente'];

    $existing = $db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', [
        'id_assignment' => $l['test_id'],
        'id_studente' => $idStudente,
    ]);
    if ($existing !== []) {
        continue;
    }
    $db->insertRow('GITHUB_ASSIGNMENT_STUDENT_LINKS', [
        'id_map' => 'GHMAP_' . bin2hex(random_bytes(10)),
        'id_assignment' => $l['test_id'],
        'student_repository_url' => $l['repo_url'],
        'id_studente' => $idStudente,
        'acceptance_code' => bin2hex(random_bytes(16)),
        'github_username' => $l['github_username'],
        'accepted_at' => $l['accepted_at'] !== '' ? $l['accepted_at'] : date('Y-m-d H:i:s'),
        'match_confidence' => 'AUTO',
        'note' => 'importato da GitHub Classroom',
        'data_creazione' => date('Y-m-d H:i:s'),
        'id_utente' => $targetUser,
    ]);
    $inserted++;
}
echo "Link studente inseriti: {$inserted}\n";

// 3) Pulizia link orfani (id_assignment numerico o non più valido). DELETE diretto
//    perché alcuni vecchi link hanno id_map NULL (deleteRow per chiave fallirebbe).
$removed = 0;
$pdo = $db->getConnection();
if ($pdo instanceof PDO) {
    $stmt = $pdo->prepare(
        "DELETE FROM GITHUB_ASSIGNMENT_STUDENT_LINKS
         WHERE id_assignment REGEXP '^[0-9]+$'
            OR id_assignment NOT IN (SELECT id_test FROM TEST WHERE piattaforma = 'github')"
    );
    $stmt->execute();
    $removed = (int)$stmt->rowCount();
}
echo "Link orfani rimossi: {$removed}\n";
echo "Import completato.\n";
