<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 2) . '/public/uda_create.php') ?: '';

if (preg_match('/<label[^>]*>N\. Domande<\/label>|name=["\']test_domande\[\]["\']/', $source)) {
    fwrite(STDERR, "FAIL: N. Domande ancora esposto nel wizard\n");
    exit(1);
}
if (preg_match('/<label[^>]*>Durata \(min\)<\/label>|name=["\']test_durata\[\]["\']/', $source)) {
    fwrite(STDERR, "FAIL: Durata ancora esposta nel wizard\n");
    exit(1);
}
if (preg_match('/<label[^>]*>Punti Max<\/label>|name=["\']test_punti\[\]["\']/', $source)) {
    fwrite(STDERR, "FAIL: Punti Max ancora esposto nel wizard\n");
    exit(1);
}
if (preg_match('/<label[^>]*>URL Test<\/label>|name=["\']test_url\[\]["\']/', $source)) {
    fwrite(STDERR, "FAIL: URL Test ancora esposto nel wizard\n");
    exit(1);
}
if (!str_contains($source, 'name="test_url_studenti[]"') || !str_contains($source, 'name="test_url_docente[]"')) {
    fwrite(STDERR, "FAIL: link studenti/docente mancanti nel wizard\n");
    exit(1);
}

echo "PASS: wizard test fields\n";
