<?php
/**
 * Redirect a laboratorio_griglia.php (nuova vista ottimizzata)
 *
 * Questo file è un redirect per compatibilità.
 * Il sistema di valutazione laboratorio con +/- è ora in laboratorio_griglia.php
 * (vista griglia completa classe, più veloce e intuitiva)
 */

// Recupera parametri dalla query string
$udaId = $_GET['uda_id'] ?? null;
$idClasse = $_GET['id_classe'] ?? null;
$idMateria = $_GET['id_materia'] ?? null;

// Costruisci URL di redirect per griglia laboratorio
$params = [];
if ($udaId) {
    $params['id_uda'] = $udaId;
}
if ($idClasse) {
    $params['id_classe_cv'] = $idClasse;  // laboratorio usa id_classe_cv
}
if ($idMateria) {
    $params['id_materia_cv'] = $idMateria;  // laboratorio usa id_materia_cv
}

// Redirect a nuova griglia ottimizzata
if (!empty($params)) {
    $queryString = http_build_query($params);
    header("Location: laboratorio_griglia.php?$queryString");
} else {
    header("Location: laboratorio_griglia.php");
}
exit;
