<?php

declare(strict_types=1);

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\Database\DatabaseFactory;
use App\Core\Database\LegacyJsonRestoreMapper;
use App\Core\GroupStudentRepository;
use App\Core\LegacyGithubStudentMapGateway;
use App\Core\LegacyStudentMappingGateway;
use App\Core\LegacyUdaDataGateway;
use App\Core\ProviderNeutralMappingService;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;

$root = dirname(__DIR__);
$config = require_once $root . '/bootstrap.php';

$options = getopt('', [
    'source:',
    'source-email:',
    'target-email:',
    'dry-run',
    'apply',
    'replace',
    'confirm:',
]);

$mode = isset($options['apply']) ? 'apply' : (isset($options['dry-run']) ? 'dry-run' : '');
$sourcePath = (string)($options['source'] ?? 'database/backup/uda_mysql_2026-02-08_11-35-31.json');
$sourceEmail = strtolower(trim((string)($options['source-email'] ?? '')));
$targetEmail = strtolower(trim((string)($options['target-email'] ?? '')));
$replace = isset($options['replace']);
$confirmation = (string)($options['confirm'] ?? '');

if ($mode === '') {
    fwrite(STDERR, "Uso: php scripts/restore_legacy_json.php --dry-run|--apply --source=... --source-email=... --target-email=...\n");
    exit(2);
}
if ($mode === 'apply' && (!$replace || $confirmation !== 'RESTORE-LOCAL-DATABASE')) {
    fwrite(STDERR, "L'applicazione richiede --replace --confirm=RESTORE-LOCAL-DATABASE.\n");
    exit(2);
}
if ($sourceEmail === '' || $targetEmail === '') {
    fwrite(STDERR, "Specificare --source-email e --target-email; le email non sono memorizzate nello script.\n");
    exit(2);
}

$environment = strtolower((string)env('APP_ENV', 'local'));
$dbHost = strtolower(trim((string)env('DB_HOST', '')));
$dbName = (string)env('DB_DATABASE', '');
if ($environment === 'production' || !in_array($dbHost, ['db', '127.0.0.1', 'localhost'], true) || $dbName !== 'uda_system') {
    fwrite(STDERR, "Ripristino rifiutato: sono consentiti solo APP_ENV local e MySQL uda_system locale.\n");
    exit(3);
}

$sourceAbsolute = $sourcePath;
if (!str_starts_with($sourceAbsolute, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $sourceAbsolute)) {
    $sourceAbsolute = $root . '/' . ltrim($sourceAbsolute, '/\\');
}
$sourceAbsolute = realpath($sourceAbsolute) ?: '';
if ($sourceAbsolute === '' || !is_file($sourceAbsolute)) {
    fwrite(STDERR, "Dump sorgente non trovato.\n");
    exit(4);
}

$dump = json_decode((string)file_get_contents($sourceAbsolute), true);
if (!is_array($dump) || !is_array($dump['tables'] ?? null)) {
    fwrite(STDERR, "Dump JSON non valido o privo di tabelle.\n");
    exit(4);
}

/** @return list<array<string,mixed>> */
function dumpRows(array $dump, string $table): array
{
    $rows = $dump['tables'][$table]['rows'] ?? [];
    if (!is_array($rows)) return [];
    return array_values(array_filter($rows, 'is_array'));
}

/** @param array<string,mixed> $row @param list<string> $fields */
function firstValue(array $row, array $fields): string
{
    foreach ($fields as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

/** @param mixed $value */
function scalarize($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $value;
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function scalarRow(array $row): array
{
    foreach ($row as $key => $value) $row[$key] = scalarize($value);
    return $row;
}

/** @return string */
function sourceOwnerId(array $dump, string $email): string
{
    foreach (dumpRows($dump, 'UTENTI') as $row) {
        if (strtolower(trim((string)($row['email'] ?? ''))) === $email) {
            return trim((string)($row['id_utente'] ?? ''));
        }
    }
    return '';
}

/** @return array<string,mixed> */
function reportBase(string $mode, string $source, string $sourceEmail, string $targetEmail, string $sourceOwner, string $targetOwner): array
{
    return [
        'success' => true,
        'mode' => $mode,
        'source' => $source,
        'source_email' => $sourceEmail,
        'target_email' => $targetEmail,
        'source_owner_id' => $sourceOwner,
        'target_owner_id' => $targetOwner,
        'counts' => [],
        'skipped' => [],
        'warnings' => [],
        'errors' => [],
        'generated_at' => date(DATE_ATOM),
    ];
}

try {
    $adapter = DatabaseFactory::createWithInitialization($config, true);
    $targetUsers = $adapter->findWhere('UTENTI', ['email' => $targetEmail]);
    $targetUser = $targetUsers[0] ?? null;
    if (!is_array($targetUser) || trim((string)($targetUser['id_utente'] ?? '')) === '') {
        throw new RuntimeException("Utente target non presente nel database locale: {$targetEmail}");
    }
    $sourceOwner = sourceOwnerId($dump, $sourceEmail);
    if ($sourceOwner === '') {
        throw new RuntimeException("Utente sorgente non presente nel dump: {$sourceEmail}");
    }
    $targetOwner = (string)$targetUser['id_utente'];
    $mapper = new LegacyJsonRestoreMapper($sourceOwner, $targetOwner);
    $report = reportBase($mode, $sourceAbsolute, $sourceEmail, $targetEmail, $sourceOwner, $targetOwner);

    $ownedRows = static function (string $table) use ($dump, $mapper): array {
        return array_map(
            static fn(array $row): array => $mapper->rewriteOwner(scalarRow($row)),
            $mapper->ownedRows(dumpRows($dump, $table))
        );
    };

    $summaryTables = [
        'UDA_ANAGRAFICA', 'MATERIALI', 'OBIETTIVI', 'OBIETTIVI_MASTER',
        'CATEGORIE_COMPETENZE', 'INDICATORI_LABORATORIO', 'RUBRICA',
        'DOMANDE_INTERROGAZIONE', 'TEST', 'STUDENTI', 'CLASSI',
        'CLASSI_ASSEGNATE', 'CLASSROOM_MAPPINGS', 'GITHUB_CLASSROOMS',
        'MAPPATURA_STUDENTI', 'GITHUB_ASSIGNMENTS', 'GITHUB_ASSIGNMENT_STUDENT_MAP',
        'INTEGRAZIONI_UTENTE', 'VOTI', 'VALUTAZIONI_RUBRICA', 'VALUTAZIONI_LABORATORIO',
    ];
    foreach ($summaryTables as $table) {
        $report['counts'][$table] = [
            'source_rows' => count(dumpRows($dump, $table)),
            'owned_rows' => count($ownedRows($table)),
            'imported_rows' => 0,
        ];
    }

    if ($mode === 'dry-run') {
        $report['finished_at'] = date(DATE_ATOM);
        $report['report_path'] = writeRestoreReport($root, $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $pdo = $adapter->getConnection();
    if (!$pdo instanceof PDO) throw new RuntimeException('Connessione PDO locale non disponibile.');
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($adapter->getAllSheetNames() as $table) {
            if (in_array($table, ['UTENTI', 'SCHEMA_MIGRATIONS'], true)) continue;
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $pdo->exec("DELETE FROM {$quoted}");
        }
        $stmt = $pdo->prepare('DELETE FROM `UTENTI` WHERE `id_utente` <> ?');
        $stmt->execute([$targetOwner]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $importDirect = static function (string $table, array $rows) use (&$report, $adapter, $mapper): void {
            if ($rows === []) return;
            $allowed = $adapter->getColumns($table);
            foreach ($rows as $row) {
                $payload = $mapper->onlyAllowedColumns($row, $allowed);
                if ($payload === [] || !$adapter->insertRow($table, $payload)) {
                    throw new RuntimeException("Importazione fallita nella tabella {$table}");
                }
                $report['counts'][$table]['imported_rows'] = ($report['counts'][$table]['imported_rows'] ?? 0) + 1;
            }
        };

        $directEarly = [
            'INTEGRAZIONI_UTENTE', 'UDA_ANAGRAFICA', 'MATERIALI', 'OBIETTIVI',
            'OBIETTIVI_MASTER', 'CATEGORIE_COMPETENZE', 'INDICATORI_LABORATORIO',
            'RUBRICA', 'RUBRICA_DETTAGLI', 'DOMANDE_INTERROGAZIONE', 'TEST',
            'TEST_CBM_MAPPING', 'GITHUB_REPO_TEMPLATES', 'UDA_CONDIVISIONI', 'INVITI_UDA',
        ];
        foreach ($directEarly as $table) $importDirect($table, $ownedRows($table));

        $groups = new TeachingGroupRepository($adapter, $targetOwner);
        $integrations = new TeachingGroupIntegrationRepository($adapter, $targetOwner);
        $groupMap = [];
        $groupByClass = [];
        $ensureGroup = static function (string $classId, string $subjectId, string $className, string $subjectName, string $year) use (&$groupMap, &$groupByClass, $groups, $integrations, $adapter, $targetOwner): string {
            $key = $classId . '|' . $subjectId;
            if ($classId === '' && $subjectId === '') $key = $className . '|' . $subjectName;
            if (isset($groupMap[$key])) return $groupMap[$key];
            $id = 'GRP_LEGACY_' . substr(hash('sha256', $targetOwner . '|' . $key), 0, 24);
            $existing = $groups->findById($id);
            if ($existing === null) {
                $group = $groups->create([
                    'id_gruppo' => $id,
                    'nome_gruppo' => trim($className . ($subjectName !== '' ? ' - ' . $subjectName : '')) ?: $key,
                    'nome_classe' => $className,
                    'nome_materia' => $subjectName,
                    'anno_scolastico' => $year,
                ]);
                $id = (string)$group['id_gruppo'];
            }
            if ($classId !== '' && $integrations->findByExternal('classeviva', $classId, $subjectId !== '' ? $subjectId : null) === null) {
                $integrations->link([
                    'id_gruppo' => $id,
                    'provider' => 'classeviva',
                    'tipo_risorsa' => 'classe_materia',
                    'external_context_id' => $classId,
                    'external_subject_id' => $subjectId,
                    'external_name' => $className,
                    'metadata_json' => json_encode(['subject_name' => $subjectName], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
            $groupMap[$key] = $id;
            if ($classId !== '') $groupByClass[$classId][] = $id;
            return $id;
        };

        foreach ($ownedRows('CLASSI') as $row) {
            $ensureGroup(
                firstValue($row, ['id_classe', 'id_classe_cv']),
                '',
                firstValue($row, ['nome_classe', 'nome', 'classeviva_class_name']),
                '',
                firstValue($row, ['anno_scolastico'])
            );
        }
        $mappingIdMap = [];
        $providerService = new ProviderNeutralMappingService($adapter, $targetOwner);
        foreach ($ownedRows('CLASSROOM_MAPPINGS') as $row) {
            $classId = firstValue($row, ['id_classe_cv', 'classeviva_class_id']);
            $subjectId = firstValue($row, ['id_materia_cv', 'classeviva_subject_id']);
            $groupId = $ensureGroup($classId, $subjectId, firstValue($row, ['nome_classe_cv', 'classeviva_class_name']), firstValue($row, ['nome_materia_cv', 'classeviva_subject_name']), '');
            $externalId = firstValue($row, ['id_corso_gc', 'google_course_id']);
            if ($externalId === '') { $report['skipped'][] = ['table' => 'CLASSROOM_MAPPINGS', 'reason' => 'corso Google senza ID']; continue; }
            // Il vecchio schema consentiva lo stesso corso in più righe/classe;
            // il nuovo modello impone un solo gruppo per risorsa esterna.
            // Manteniamo il collegamento canonico e riallineiamo gli ID legacy.
            $existing = $integrations->findByContext('google_classroom', $externalId);
            if ($existing !== null && (string)($existing['id_gruppo'] ?? '') !== $groupId) {
                $oldMappingId = (string)($row['id_mapping'] ?? '');
                if ($oldMappingId !== '') {
                    $mappingIdMap[$oldMappingId] = (string)($existing['id_collegamento'] ?? '');
                }
                $report['skipped'][] = [
                    'table' => 'CLASSROOM_MAPPINGS',
                    'id_mapping' => $oldMappingId,
                    'reason' => 'corso Google già collegato a un altro gruppo; riusato il collegamento canonico',
                ];
                continue;
            }
            $linked = $providerService->upsertGoogleClassroomMapping([
                'id_gruppo' => $groupId,
                'classeviva_class_id' => $classId,
                'classeviva_class_name' => firstValue($row, ['nome_classe_cv', 'classeviva_class_name']),
                'classeviva_subject_id' => $subjectId,
                'classeviva_subject_name' => firstValue($row, ['nome_materia_cv', 'classeviva_subject_name']),
                'google_course_id' => $externalId,
                'google_course_name' => firstValue($row, ['nome_corso_gc', 'google_course_name']),
            ]);
            $mappingIdMap[(string)($row['id_mapping'] ?? '')] = (string)($linked['id_collegamento'] ?? '');
            $report['counts']['CLASSROOM_MAPPINGS']['imported_rows']++;
        }
        foreach ($ownedRows('GITHUB_CLASSROOMS') as $row) {
            $classId = firstValue($row, ['id_classe_cv', 'classeviva_class_id']);
            $subjectId = firstValue($row, ['id_materia_cv', 'classeviva_subject_id']);
            $groupId = $ensureGroup($classId, $subjectId, firstValue($row, ['nome_classe_cv', 'classeviva_class_name']), firstValue($row, ['nome_materia_cv', 'classeviva_subject_name']), '');
            $externalId = firstValue($row, ['github_classroom_id']);
            if ($externalId === '') { $report['skipped'][] = ['table' => 'GITHUB_CLASSROOMS', 'reason' => 'roster GitHub senza ID']; continue; }
            $existing = $integrations->findByContext('github_classroom', $externalId);
            if ($existing !== null && (string)($existing['id_gruppo'] ?? '') !== $groupId) {
                $oldMappingId = (string)($row['id_mapping'] ?? '');
                if ($oldMappingId !== '') {
                    $mappingIdMap[$oldMappingId] = (string)($existing['id_collegamento'] ?? '');
                }
                $report['skipped'][] = [
                    'table' => 'GITHUB_CLASSROOMS',
                    'id_mapping' => $oldMappingId,
                    'reason' => 'roster GitHub già collegato a un altro gruppo; riusato il collegamento canonico',
                ];
                continue;
            }
            $linked = $providerService->upsertGithubClassroomMapping([
                'id_gruppo' => $groupId,
                'classeviva_class_id' => $classId,
                'classeviva_class_name' => firstValue($row, ['nome_classe_cv', 'classeviva_class_name']),
                'classeviva_subject_id' => $subjectId,
                'classeviva_subject_name' => firstValue($row, ['nome_materia_cv', 'classeviva_subject_name']),
                'github_classroom_id' => $externalId,
                'github_org_name' => firstValue($row, ['github_org_name']),
                'classroom_name' => firstValue($row, ['classroom_name']),
                'note' => firstValue($row, ['note']),
            ]);
            $mappingIdMap[(string)($row['id_mapping'] ?? '')] = (string)($linked['id_collegamento'] ?? '');
            $report['counts']['GITHUB_CLASSROOMS']['imported_rows']++;
        }

        $studentImportedIds = [];
        $studentIdentities = new StudentIdentityRepository($adapter, $targetOwner);
        $studentResolver = new StudentIdentityResolver(
            new StudentRepository($adapter, $targetOwner),
            $studentIdentities,
            new GroupStudentRepository($adapter, $targetOwner),
            new StudentResourceRepository($adapter, $targetOwner)
        );
        foreach ($ownedRows('STUDENTI') as $row) {
            $cvId = firstValue($row, ['id_studente_cv']);
            // Il dump storico contiene anche righe-header/documentazione GDPR.
            if ($cvId === '' || str_starts_with($cvId, '**')) {
                $report['skipped'][] = ['table' => 'STUDENTI', 'reason' => 'riga documentale senza ID ClasseViva'];
                continue;
            }
            $identity = $studentIdentities->findByExternal('classeviva', $cvId);
            if (!is_array($identity)) {
                $student = $studentResolver->resolveOrCreate('classeviva', $cvId);
                $internalStudentId = (string)($student['id_studente'] ?? '');
                if ($internalStudentId === '') {
                    throw new RuntimeException("Identità ClasseViva non creata per lo studente {$cvId}");
                }
                $identity = $studentIdentities->findByExternal('classeviva', $cvId);
                $state = ((string)($row['attivo'] ?? '') === '0') ? 'inattivo' : 'attivo';
                $adapter->updateRow('STUDENTI', 'id_studente', $internalStudentId, [
                    'stato' => $state,
                    'data_creazione' => (string)($row['data_sincronizzazione'] ?? date('Y-m-d H:i:s')),
                    'ultima_modifica' => (string)($row['data_sincronizzazione'] ?? date('Y-m-d H:i:s')),
                    'id_utente' => $targetOwner,
                ]);
            }
            if (!is_array($identity)) {
                throw new RuntimeException("Identità ClasseViva non creata per lo studente {$cvId}");
            }
            $internalStudentId = (string)($identity['id_studente'] ?? '');
            if ($internalStudentId !== '' && !isset($studentImportedIds[$internalStudentId])) {
                $studentImportedIds[$internalStudentId] = true;
                $report['counts']['STUDENTI']['imported_rows']++;
            }
            $classId = firstValue($row, ['id_classe_cv']);
            if ($cvId === '' || $classId === '') continue;
            foreach (array_values(array_unique($groupByClass[$classId] ?? [])) as $groupId) {
                (new GroupStudentRepository($adapter, $targetOwner))->add($groupId, $internalStudentId, [
                    'provider_origine' => 'classeviva',
                    'external_context_id' => $classId,
                    'stato' => (string)($row['attivo'] ?? 'attivo'),
                    'ultima_sincronizzazione' => (string)($row['data_sincronizzazione'] ?? date('Y-m-d H:i:s')),
                ]);
            }
        }

        foreach ($ownedRows('CLASSI_ASSEGNATE') as $row) {
            $classId = firstValue($row, ['id_classe', 'id_classe_cv']);
            $subjectId = firstValue($row, ['id_materia_cv', 'classeviva_subject_id']);
            $groupId = $ensureGroup($classId, $subjectId, firstValue($row, ['nome_classe', 'classeviva_class_name']), firstValue($row, ['nome_materia', 'classeviva_subject_name']), firstValue($row, ['anno_scolastico', 'anno_corso']));
            $row['id_gruppo'] = $groupId;
            if (!LegacyUdaDataGateway::insertClasseAssegnata($adapter, $row)) throw new RuntimeException('Importazione assegnazione UDA fallita');
            $report['counts']['CLASSI_ASSEGNATE']['imported_rows']++;
        }

        foreach ($ownedRows('MAPPATURA_STUDENTI') as $row) {
            $oldMappingId = (string)($row['id_mapping_materia'] ?? '');
            if ($oldMappingId !== '' && isset($mappingIdMap[$oldMappingId])) $row['id_mapping_materia'] = $mappingIdMap[$oldMappingId];
            try {
                $linked = (string)($row['id_mapping_materia'] ?? '') !== ''
                    && LegacyStudentMappingGateway::insert($adapter, $row);
            } catch (Throwable $mappingError) {
                $linked = false;
                $report['skipped'][] = [
                    'table' => 'MAPPATURA_STUDENTI',
                    'id_mappatura' => $row['id_mappatura'] ?? '',
                    'reason' => 'conflitto tra identità esterne: ' . $mappingError->getMessage(),
                ];
            }
            if (!$linked) {
                $report['skipped'][] = ['table' => 'MAPPATURA_STUDENTI', 'reason' => 'mappatura Classroom non risolta', 'id' => $row['id_mappatura'] ?? ''];
                continue;
            }
            $report['counts']['MAPPATURA_STUDENTI']['imported_rows']++;
        }

        $lateRows = [
            'GITHUB_ASSIGNMENTS', 'GITHUB_SUBMISSIONS', 'GITHUB_REPO_LOC_SNAPSHOTS',
            'PLUSMINUS_QUEUE', 'VALUTAZIONI_LABORATORIO', 'VALUTAZIONI_RUBRICA',
            'VOTI', 'TEST_CBM_RISPOSTE',
        ];
        foreach ($lateRows as $table) {
            foreach ($ownedRows($table) as $row) {
                if ($table === 'GITHUB_ASSIGNMENTS') {
                    $oldMap = (string)($row['id_classroom_map'] ?? '');
                    if ($oldMap !== '' && isset($mappingIdMap[$oldMap])) $row['id_classroom_map'] = $mappingIdMap[$oldMap];
                }
                $allowed = $adapter->getColumns($table);
                $payload = $mapper->onlyAllowedColumns($row, $allowed);
                if ($payload !== [] && $adapter->insertRow($table, $payload)) {
                    $report['counts'][$table]['imported_rows'] = ($report['counts'][$table]['imported_rows'] ?? 0) + 1;
                } else {
                    throw new RuntimeException("Importazione fallita nella tabella {$table}");
                }
            }
        }

        foreach ($ownedRows('GITHUB_ASSIGNMENT_STUDENT_MAP') as $row) {
            try {
                if (!LegacyGithubStudentMapGateway::insert($adapter, $row)) {
                    throw new RuntimeException('insert mappatura GitHub ha restituito false');
                }
            } catch (Throwable $mappingError) {
                $report['skipped'][] = [
                    'table' => 'GITHUB_ASSIGNMENT_STUDENT_MAP',
                    'id_map' => $row['id_map'] ?? '',
                    'reason' => 'conflitto tra identità esterne: ' . $mappingError->getMessage(),
                ];
                continue;
            }
            $report['counts']['GITHUB_ASSIGNMENT_STUDENT_MAP']['imported_rows']++;
        }

        // Le routine legacy possono creare una riga STUDENTI prima di rilevare
        // un conflitto tra identità esterne. Rimuoviamo solo gli studenti
        // completamente orfani, cioè privi di identità e di qualunque
        // riferimento didattico o di valutazione.
        $cleanup = $pdo->prepare(
            'DELETE s FROM `STUDENTI` s '
            . 'WHERE (s.`id_utente` = ? OR s.`id_utente` = \'system\') '
            . 'AND NOT EXISTS (SELECT 1 FROM `STUDENTI_IDENTITA_ESTERNE` i WHERE i.`id_utente` = s.`id_utente` AND i.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `GRUPPI_STUDENTI` gs WHERE gs.`id_utente` = s.`id_utente` AND gs.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `STUDENTI_RISORSE_ESTERNE` sr WHERE sr.`id_utente` = s.`id_utente` AND sr.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `VOTI` v WHERE v.`id_utente` = s.`id_utente` AND v.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `VALUTAZIONI_RUBRICA` vr WHERE vr.`id_utente` = s.`id_utente` AND vr.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `VALUTAZIONI_LABORATORIO` vl WHERE vl.`id_utente` = s.`id_utente` AND vl.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `PLUSMINUS_QUEUE` pq WHERE pq.`id_utente` = s.`id_utente` AND pq.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `TEST_CBM_RISPOSTE` tr WHERE tr.`id_utente` = s.`id_utente` AND tr.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `GITHUB_SUBMISSIONS` sub WHERE sub.`id_utente` = s.`id_utente` AND sub.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `GITHUB_REPO_LOC_SNAPSHOTS` snap WHERE snap.`id_utente` = s.`id_utente` AND snap.`id_studente` = s.`id_studente`) '
            . 'AND NOT EXISTS (SELECT 1 FROM `GITHUB_ASSIGNMENT_STUDENT_LINKS` gal WHERE gal.`id_utente` = s.`id_utente` AND gal.`id_studente` = s.`id_studente`)'
        );
        $cleanup->execute([$targetOwner]);
        $report['orphan_students_removed'] = $cleanup->rowCount();

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    $report['group_map'] = $groupMap;
    $report['mapping_id_map'] = $mappingIdMap;
    $report['finished_at'] = date(DATE_ATOM);
    $report['report_path'] = writeRestoreReport($root, $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    $report = $report ?? [
        'success' => false,
        'mode' => $mode,
        'source' => $sourceAbsolute,
        'errors' => [],
        'generated_at' => date(DATE_ATOM),
    ];
    $report['success'] = false;
    $report['errors'][] = $error->getMessage();
    $report['finished_at'] = date(DATE_ATOM);
    $report['report_path'] = writeRestoreReport($root, $report);
    fwrite(STDERR, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

function writeRestoreReport(string $root, array $report): string
{
    $dir = $root . '/storage/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir . '/legacy-json-restore-' . date('Ymd-His') . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $path;
}
