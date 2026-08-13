<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/AcademicPeriodHelper.php';

use App\Utils\AcademicPeriodHelper;

function assertPeriodValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$two = AcademicPeriodHelper::options('2025/2026', ['period_count' => 2, 'period_date_1' => '01-31']);
assertPeriodValue(['2025-09-01', '2026-01-31'], [$two[0]['start'], $two[0]['end']], 'primo periodo a due periodi');
assertPeriodValue(['2026-02-01', '2026-06-30'], [$two[1]['start'], $two[1]['end']], 'secondo periodo a due periodi');
assertPeriodValue(['2025-09-01', '2026-06-30'], [$two[2]['start'], $two[2]['end']], 'anno completo');

$shortYear = AcademicPeriodHelper::options('2026-27', ['period_count' => 2, 'period_date_1' => '01-31']);
assertPeriodValue(3, count($shortYear), 'formato anno scolastico usato dal wizard');
assertPeriodValue('Intero anno scolastico', $shortYear[2]['label'], 'etichetta anno completo');
assertPeriodValue(['2026-09-01', '2027-06-30'], [$shortYear[2]['start'], $shortYear[2]['end']], 'anno completo attraversa gennaio');

$three = AcademicPeriodHelper::options('2025/2026', [
    'period_count' => 3,
    'period_date_1' => '12-15',
    'period_date_2' => '03-31',
]);
assertPeriodValue(['2025-09-01', '2025-12-15'], [$three[0]['start'], $three[0]['end']], 'primo trimestre');
assertPeriodValue(['2025-12-16', '2026-03-31'], [$three[1]['start'], $three[1]['end']], 'secondo trimestre');
assertPeriodValue(['2026-04-01', '2026-06-30'], [$three[2]['start'], $three[2]['end']], 'terzo trimestre');
assertPeriodValue(['2025-09-01', '2026-03-31'], [$three[3]['start'], $three[3]['end']], 'primo e secondo trimestre');

try {
    AcademicPeriodHelper::options('2025/2026', ['period_count' => 3, 'period_date_1' => 'bad']);
    fwrite(STDERR, "FAIL: configurazione periodo non valida accettata\n");
    exit(1);
} catch (InvalidArgumentException) {
}

echo "PASS: academic period helper\n";
