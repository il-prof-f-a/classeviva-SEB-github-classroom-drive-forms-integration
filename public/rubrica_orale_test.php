<?php
/**
 * Rubrica Orale - Versione Test (senza API)
 */

session_start();
error_reporting(E_ALL);

echo "DEBUG: Inizio caricamento<br>";

use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;

try {
    $config = require_once __DIR__ . '/../bootstrap.php';
    echo "DEBUG: Bootstrap caricato<br>";

    echo "DEBUG: Use statements OK<br>";

    $db = new DatabaseManager($config);
    echo "DEBUG: DatabaseManager OK<br>";

    $udaManager = new UDAManager($config);
    echo "DEBUG: UDAManager OK<br>";

    // Carica UDA
    $udas = $udaManager->getAllUDAs();
    echo "DEBUG: UDAs caricate: " . count($udas) . "<br>";

    // Mock classi (senza API)
    $classi = [
        ['id' => 'MOCK_CLASSE_1', 'name' => '3A Informatica'],
        ['id' => 'MOCK_CLASSE_2', 'name' => '4B Informatica'],
        ['id' => 'MOCK_CLASSE_3', 'name' => '5C Informatica']
    ];
    echo "DEBUG: Classi mock: " . count($classi) . "<br>";

    // Mock studenti
    $studenti = [
        ['id' => 'MOCK_001', 'nome_completo' => 'Rossi Mario'],
        ['id' => 'MOCK_002', 'nome_completo' => 'Verdi Anna'],
        ['id' => 'MOCK_003', 'nome_completo' => 'Bianchi Luca']
    ];
    echo "DEBUG: Studenti mock: " . count($studenti) . "<br>";

    echo "<hr>";
    echo "<h3>✓ Tutti i componenti caricati correttamente!</h3>";
    echo "<a href='?test=form'>Vedi form test</a>";

    if (isset($_GET['test']) && $_GET['test'] === 'form') {
        ?>
        <hr>
        <h4>Form Step 1</h4>
        <form method="POST" action="">
            <label>Seleziona UDA:</label><br>
            <select name="id_uda" required>
                <?php foreach ($udas as $uda): ?>
                    <option value="<?= htmlspecialchars($uda->id_uda) ?>">
                        <?= htmlspecialchars($uda->titolo) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>

            <label>Seleziona Classe:</label><br>
            <select name="id_classe_cv" required>
                <?php foreach ($classi as $classe): ?>
                    <option value="<?= htmlspecialchars($classe['id']) ?>">
                        <?= htmlspecialchars($classe['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>

            <label>Domanda 1:</label><br>
            <input type="text" name="domanda_1" value="Gestione processi" required>
            <br><br>

            <label>Domanda 2:</label><br>
            <input type="text" name="domanda_2" value="Scheduling" required>
            <br><br>

            <label>Domanda 3:</label><br>
            <input type="text" name="domanda_3" value="Sistema operativo" required>
            <br><br>

            <button type="submit">Inizia</button>
        </form>
        <?php
    }

} catch (Exception $e) {
    echo "<h2 style='color: red;'>ERRORE:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
