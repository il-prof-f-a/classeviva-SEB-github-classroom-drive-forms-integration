<?php

declare(strict_types=1);

namespace App\Utils;

final class AcademicPeriodHelper
{
    /** @return list<array{value:string,label:string,start:string,end:string}> */
    public static function options(string $academicYear, array $config): array
    {
        [$startYear, $endYear] = self::parseAcademicYear($academicYear);
        $periodCount = (int)($config['period_count'] ?? 2);
        if (!in_array($periodCount, [2, 3], true)) {
            $periodCount = 2;
        }

        $firstEnd = self::periodDateForAcademicYear($startYear, $endYear, $config['period_date_1'] ?? '01-31');
        $secondEnd = $periodCount === 3
            ? self::periodDateForAcademicYear($startYear, $endYear, $config['period_date_2'] ?? '')
            : new \DateTimeImmutable($endYear . '-06-30');
        if ($periodCount === 3 && $secondEnd === null) {
            throw new \InvalidArgumentException('Configurazione ClasseViva: data del secondo periodo non valida.');
        }
        if ($firstEnd === null || $firstEnd >= $secondEnd) {
            throw new \InvalidArgumentException('Configurazione ClasseViva: date dei periodi non valide.');
        }

        $yearStart = new \DateTimeImmutable($startYear . '-09-01');
        $yearEnd = new \DateTimeImmutable($endYear . '-06-30');
        $firstStart = $yearStart;
        $secondStart = $firstEnd->modify('+1 day');

        if ($periodCount === 2) {
            return [
                self::item('primo_periodo', 'Primo periodo', $firstStart, $firstEnd),
                self::item('secondo_periodo', 'Secondo periodo', $secondStart, $yearEnd),
                self::item('anno_intero', 'Intero anno scolastico', $yearStart, $yearEnd),
            ];
        }

        $thirdStart = $secondEnd->modify('+1 day');
        return [
            self::item('primo_trimestre', 'Primo trimestre', $firstStart, $firstEnd),
            self::item('secondo_trimestre', 'Secondo trimestre', $secondStart, $secondEnd),
            self::item('terzo_trimestre', 'Terzo trimestre', $thirdStart, $yearEnd),
            self::item('primo_secondo_trimestre', 'Primo e secondo trimestre', $firstStart, $secondEnd),
            self::item('secondo_terzo_trimestre', 'Secondo e terzo trimestre', $secondStart, $yearEnd),
            self::item('anno_intero', 'Intero anno scolastico', $yearStart, $yearEnd),
        ];
    }

    /** @return array{0:int,1:int} */
    private static function parseAcademicYear(string $academicYear): array
    {
        if (preg_match('/^(\d{4})\/(\d{4})$/', trim($academicYear), $match)) {
            $first = (int)$match[1];
            $second = (int)$match[2];
        } elseif (preg_match('/^(\d{4})-(\d{2})$/', trim($academicYear), $match)) {
            $first = (int)$match[1];
            // Il wizard usa il formato breve YYYY-YY (es. 2026-27).
            $second = intdiv($first, 100) * 100 + (int)$match[2];
        } else {
            throw new \InvalidArgumentException('Anno scolastico non valido.');
        }
        if ($second !== $first + 1) {
            throw new \InvalidArgumentException('Anno scolastico non valido.');
        }
        return [$first, $second];
    }

    private static function periodDateForAcademicYear(int $startYear, int $endYear, string $monthDay): ?\DateTimeImmutable
    {
        if (!preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', trim($monthDay))) {
            return null;
        }
        try {
            $month = (int)substr($monthDay, 0, 2);
            $year = $month >= 9 ? $startYear : $endYear;
            $date = new \DateTimeImmutable($year . '-' . $monthDay);
            return $date->format('m-d') === $monthDay ? $date : null;
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{value:string,label:string,start:string,end:string} */
    private static function item(string $value, string $label, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return ['value' => $value, 'label' => $label, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }
}
