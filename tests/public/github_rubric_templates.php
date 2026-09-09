<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rubricPagePath = $root . '/public/github_rubriche.php';
$servicePath = $root . '/src/Core/GitHubRubricTemplateService.php';
$templatePath = $root . '/Materiale/Rubrica valutazione github VUOTA.xlsx';

$rubricPage = is_file($rubricPagePath) ? (string)file_get_contents($rubricPagePath) : '';
$service = is_file($servicePath) ? (string)file_get_contents($servicePath) : '';
$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(is_file($templatePath), 'template Excel GitHub non presente in Materiale');
$require($service !== '', 'servizio GitHubRubricTemplateService non presente');
$require(str_contains($service, 'MASTER'), 'servizio senza validazione del foglio MASTER');
$require(str_contains($service, 'SpreadsheetPolicy::assertWithinLimits'), 'limiti spreadsheet non applicati');
$require(str_contains($service, 'UploadPolicy::assertValid'), 'policy upload non applicata');
$require(str_contains($service, 'livello_1_desc') && str_contains($service, 'livello_4_desc'), 'mapping dei livelli GitHub incompleto');
$require(str_contains($rubricPage, 'GitHubRubricTemplateService'), 'pagina GitHub senza servizio template centralizzato');
$require(str_contains($rubricPage, 'Carica rubrica da template'), 'etichetta caricamento da template assente');
$require(str_contains($rubricPage, 'Carica Rubrica Personalizzata'), 'sezione upload personalizzato assente');
$require(str_contains($rubricPage, 'github_rubric_template_modal'), 'modal upload template assente');
$require(str_contains($rubricPage, 'temporary_template'), 'sorgente temporanea non gestita');
$require(str_contains($rubricPage, 'GoogleDriveAPI'), 'import Google Sheet non collegato a GoogleDriveAPI');
$require(str_contains($rubricPage, 'Csrf::assertValid'), 'azioni template senza verifica CSRF');
$require(str_contains($rubricPage, 'finally'), 'cleanup del file temporaneo non garantito');

if (class_exists(ZipArchive::class) && is_file($templatePath)) {
    $zip = new ZipArchive();
    $opened = $zip->open($templatePath);
    $require($opened === true, 'template GitHub non è un archivio XLSX valido');
    if ($opened === true) {
        $workbookXml = (string)$zip->getFromName('xl/workbook.xml');
        $sharedStrings = (string)$zip->getFromName('xl/sharedStrings.xml');
        $sheetXml = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
        $allXml = $workbookXml . $sharedStrings . $sheetXml;
        $require(str_contains($allXml, 'MASTER'), 'template senza foglio MASTER');
        $require(str_contains($allXml, 'Indicatore'), 'template senza intestazione Indicatore');
        $require(str_contains($allXml, 'Peso'), 'template senza intestazione Peso');
        $zip->close();
    }

    $autoload = $root . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
        try {
            $rows = (new App\Core\GitHubRubricTemplateService())->parseFile($templatePath);
            $require(count($rows) === 6, 'il parser non restituisce i sei indicatori GitHub');
            $collaboration = array_values(array_filter($rows, static fn(array $row): bool => ($row['nome_indicatore'] ?? '') === 'Integrazione e collaborazione'));
            $require(count($collaboration) === 1 && ($collaboration[0]['peso'] ?? '') === '15', 'peso collaborazione nel parser diverso da 15');
        } catch (Throwable $exception) {
            $require(false, 'il parser del template GitHub non è eseguibile: ' . $exception->getMessage());
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: template e flusso rubriche GitHub verificati.\n");
