<?php

declare(strict_types=1);

return [
    'admin' => [
        'api/test_api_handler.php',
        'database_manager.php',
        'db_cleanup.php',
        'system_status.php',
        'test_api_integrations.php',
    ],
    'local_only' => [
        'apply-privacy-migration.php',
        'setup_mappatura_studenti_sheet.php',
        'setup_mappings_sheet.php',
        'delete_all_grades.php',
        'get_evento_ids.php',
        'insert_test_grade.php',
        'auto_registra_plusminus.php',
    ],
];
