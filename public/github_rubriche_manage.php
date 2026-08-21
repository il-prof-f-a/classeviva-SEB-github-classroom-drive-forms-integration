<?php
/**
 * DEPRECATO: pagina unica rubriche -> github_rubriche.php
 */

$params = [];
foreach (['test_id', 'id_rubrica', 'id_uda'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $params[$k] = $_GET[$k];
}
$url = 'github_rubriche.php' . (!empty($params) ? ('?' . http_build_query($params)) : '');
header('Location: ' . $url, true, 302);
exit;
