<?php
require_once __DIR__ . '/../bootstrap.php';

$currentEmail = strtolower(trim($_SESSION['user_email'] ?? ''));
if (!is_admin_user($currentEmail)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo "Accesso non autorizzato.\n";
    exit(1);
}

$checks = [];
$checks['Versione PHP >= 8.0'] = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks['Estensione ZIP'] = extension_loaded('zip');
$checks['Estensione XML'] = extension_loaded('xml');
$checks['Estensione cURL'] = extension_loaded('curl');
$checks['Estensione GD'] = extension_loaded('gd');
$checks['Autoload Composer (PhpSpreadsheet)'] = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
$checks['File database/uda_master.xlsx esiste'] = file_exists(__DIR__ . '/../database/uda_master.xlsx');
$checks['Cartella storage/logs scrivibile'] = is_writable(__DIR__ . '/../storage/logs');
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Test di Sistema UDA</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container mt-5">
    <h1>Sistema UDA - Test di Sistema</h1>
    <p>Verifica la configurazione del server e dell'applicazione.</p>
    <ul class="list-group">
      <?php foreach ($checks as $label => $ok): ?>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <?php echo $label; ?>
        <?php if ($ok): ?>
          <span class="badge bg-success">OK</span>
        <?php else: ?>
          <span class="badge bg-danger">ERRORE</span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</body>
</html>
