<?php

declare(strict_types=1);

putenv('APP_URL=http://process-env.invalid');
$_ENV['APP_URL'] = 'http://array-env.invalid';
$_SERVER['APP_URL'] = 'http://server-env.invalid';

require dirname(__DIR__) . '/bootstrap.php';

if (env('APP_URL') !== 'http://process-env.invalid') {
    fwrite(STDERR, "La variabile d'ambiente del processo non ha precedenza su config/.env.\n");
    exit(1);
}

fwrite(STDOUT, "OK: le variabili Docker/processo hanno precedenza su config/.env.\n");
