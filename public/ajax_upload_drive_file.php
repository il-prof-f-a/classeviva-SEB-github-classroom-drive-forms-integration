<?php
/**
 * AJAX - Upload file su Google Drive (wizard UDA)
 */

error_reporting(E_ALL);
ob_start();

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Integration\GoogleDriveAPI;

$responsePayload = null;

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File mancante o non valido');
    }

    $folderId = trim($config['google']['drive']['root_folder_id'] ?? '');
    if ($folderId === '') {
        $folderId = null;
    }

    $driveApi = new GoogleDriveAPI($config);
    $uploadResult = $driveApi->uploadFile(
        $_FILES['file']['tmp_name'],
        $_FILES['file']['name'],
        $folderId,
        $_FILES['file']['type'] ?? null
    );

    $responsePayload = [
        'success' => true,
        'fileId' => $uploadResult['id'] ?? '',
        'viewLink' => $uploadResult['viewLink'] ?? '',
        'name' => $uploadResult['name'] ?? ($_FILES['file']['name'] ?? ''),
        'mimeType' => $uploadResult['mimeType'] ?? ($_FILES['file']['type'] ?? '')
    ];
} catch (Exception $e) {
    $responsePayload = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

$bufferedOutput = trim(ob_get_clean() ?? '');
if ($bufferedOutput !== '') {
    $responsePayload = [
        'success' => false,
        'error' => 'Output inatteso dal server',
        'detail' => substr($bufferedOutput, 0, 500)
    ];
}

if ($responsePayload === null) {
    $responsePayload = [
        'success' => false,
        'error' => 'Errore sconosciuto'
    ];
}

header('Content-Type: application/json');
echo json_encode($responsePayload);
