<?php

declare(strict_types=1);

// Verifica funzionale che l'esportazione UDA produca un pacchetto ZIP protetto da
// password contenente un documento Word (con i link) e un file Excel con i dati
// sensibili (voti, valutazioni, risposte ai test).
$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
require_once $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\DatabaseFactory;
use App\Core\ExportManager;

$relative = 'storage/temp/export-package-' . bin2hex(random_bytes(6)) . '.db';
$absolute = $root . '/' . $relative;
$config = [
    'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relative]],
    'paths' => ['storage' => 'storage'],
    'academic_year' => ['current' => '2026/27'],
];
$failures = [];
$user = 'exp-user';

try {
    $db = DatabaseFactory::createWithInitialization($config);
    $manager = new ExportManager($db, $config);

    $udaId = 'UDA_EXP_1';
    $db->insertRow('UDA_ANAGRAFICA', [
        'id_uda' => $udaId,
        'titolo' => 'UDA Export Test',
        'stato' => 'bozza',
        'data_creazione' => date('Y-m-d H:i:s'),
        'id_utente' => $user,
    ]);

    $db->insertRow('TEST', [
        'id_test' => 'TEST_EXP_1',
        'id_uda' => $udaId,
        'nome' => 'Quiz Kahoot',
        'piattaforma' => 'kahoot',
        'url_studenti' => 'https://kahoot.it/challenge/123',
        'url_docente' => 'https://create.kahoot.it/details/123',
        'id_utente' => $user,
    ]);

    $db->insertRow('VOTI', [
        'id_voto' => 'VOTO_EXP_1',
        'id_uda' => $udaId,
        'id_studente' => 'STD_1',
        'tipo_voto' => 'orale',
        'voto' => '8',
        'giudizio' => 'Buono',
        'data_valutazione' => '2026-08-20',
        'pubblicato' => 1,
        'provider_pubblicazione' => 'classeviva',
        'link_origine' => 'http://localhost/rubrica_orale_v2.php?id_uda=UDA_EXP_1',
        'id_utente' => $user,
    ]);

    $db->insertRow('VALUTAZIONI_RUBRICA', [
        'id_valutazione' => 'VR_EXP_1',
        'id_uda' => $udaId,
        'id_studente' => 'STD_1',
        'voto_finale' => '8',
        'pubblicato_cv' => 1,
        'id_utente' => $user,
    ]);

    $db->insertRow('TEST_CBM_RISPOSTE', [
        'id_risposta' => 'RISP_EXP_1',
        'id_test' => 'TEST_EXP_1',
        'id_studente' => 'STD_1',
        'domanda_label' => 'Q1',
        'corretta' => 1,
        'score_cba' => '1.5',
        'id_utente' => $user,
    ]);

    // 1) Excel dati con 4 fogli.
    $xlsx = $manager->esportaUDADatiExcel($udaId);
    if (!is_file($xlsx)) {
        $failures[] = 'excel dati non generato';
    } else {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsx);
        if (count($spreadsheet->getAllSheets()) !== 4) {
            $failures[] = 'attesi 4 fogli, trovati ' . count($spreadsheet->getAllSheets());
        }
    }

    // 2) ZIP protetto da password.
    $zipPath = $root . '/storage/exports/export_test_' . bin2hex(random_bytes(4)) . '.zip';
    $manager->creaZipProtetto([$xlsx], 'test-pass-123', $zipPath);
    if (!is_file($zipPath)) {
        $failures[] = 'zip protetto non generato';
    } else {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $failures[] = 'zip protetto non apribile';
        } else {
            $zip->setPassword('test-pass-123');
            $content = $zip->getFromName(basename($xlsx));
            if ($content === false) {
                $failures[] = 'zip protetto non leggibile con la password corretta';
            }
            $zip->close();
        }
        @unlink($zipPath);
    }

    // 3) Pacchetto completo Word + Excel in ZIP.
    $pkg = $manager->esportaUDAPacchetto($udaId, ['test', 'info', 'materiali'], 'pack-pass');
    if (!is_file($pkg)) {
        $failures[] = 'pacchetto non generato';
    } else {
        $zip = new \ZipArchive();
        if ($zip->open($pkg) !== true) {
            $failures[] = 'pacchetto non apribile';
        } else {
            $zip->setPassword('pack-pass');
            if ($zip->numFiles !== 2) {
                $failures[] = 'attesi 2 file nel pacchetto, trovati ' . $zip->numFiles;
            }
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $hasDocx = false;
            $hasXlsx = false;
            foreach ($names as $name) {
                if (str_ends_with($name, '.docx')) {
                    $hasDocx = true;
                }
                if (str_ends_with($name, '.xlsx')) {
                    $hasXlsx = true;
                }
            }
            if (!$hasDocx || !$hasXlsx) {
                $failures[] = 'pacchetto senza docx o xlsx: ' . implode(', ', $names);
            }
            $zip->close();
        }
        @unlink($pkg);
    }
} catch (Throwable $e) {
    $failures[] = 'errore inatteso: ' . $e->getMessage();
} finally {
    @unlink($absolute);
    @unlink($absolute . '-wal');
    @unlink($absolute . '-shm');
    foreach (glob($root . '/storage/exports/UDA_Export_Test_*') ?: [] as $f) {
        @unlink($f);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: esportazione UDA in pacchetto ZIP protetto (Word + Excel).
");
