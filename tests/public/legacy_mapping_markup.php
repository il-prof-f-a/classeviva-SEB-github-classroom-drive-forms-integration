<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pages = [
    'map_classes' => file_get_contents($root . '/public/map_classes.php') ?: '',
    'github_mapping' => file_get_contents($root . '/public/github_classroom_mapping.php') ?: '',
    'map_students' => file_get_contents($root . '/public/map_students.php') ?: '',
];
$failures = [];
$require = static function (string $page, string $needle, string $message) use (&$failures, $pages): void {
    if (!str_contains($pages[$page] ?? '', $needle)) {
        $failures[] = $message;
    }
};

$require('map_classes', "['id_gruppo']", 'map_classes non accetta id_gruppo');
$require('map_classes', 'teaching_groups.php', 'map_classes non espone il ritorno all’editor gruppi');
$require('github_mapping', "['id_gruppo']", 'github mapping non accetta id_gruppo');
$require('github_mapping', 'integration_updated=1', 'github mapping non uniforma il ritorno con integration_updated');
$require('map_students', 'group_id', 'map_students non accetta group_id');
$require('map_students', 'tab=students', 'map_students non inoltra alla tab studenti dell’editor');
$require('map_students', 'teaching_groups.php', 'map_students non espone il collegamento all’editor gruppi');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: compatibilità markup pagine legacy provider-neutral.\n");
