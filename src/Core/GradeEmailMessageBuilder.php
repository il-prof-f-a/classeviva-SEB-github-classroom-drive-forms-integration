<?php

declare(strict_types=1);

namespace App\Core;

/** Builds the public-facing email for one student's selected grades. */
final class GradeEmailMessageBuilder
{
    /**
     * @param array<string,mixed> $student
     * @param list<array<string,mixed>> $grades
     * @return array{subject:string,html:string,text:string}
     */
    public static function build(string $activityTitle, array $student, array $grades): array
    {
        $studentName = trim((string)($student['nome'] ?? '') . ' ' . (string)($student['cognome'] ?? ''));
        $studentName = $studentName !== '' ? $studentName : 'Studente';
        $activityTitle = trim(str_replace(["\r", "\n"], ' ', $activityTitle));
        $activityTitle = $activityTitle !== '' ? $activityTitle : 'Valutazione';

        $htmlRows = [];
        $textRows = [];
        foreach ($grades as $grade) {
            $value = self::displayValue($grade['voto'] ?? '');
            $type = self::gradeTypeLabel((string)($grade['tipo_valutazione'] ?? $grade['tipo_voto'] ?? ''));
            $date = self::displayDate((string)($grade['data_valutazione'] ?? $grade['data_creazione'] ?? ''));
            // giudizio è il campo specifico del voto destinato alla famiglia.
            // descrizione contiene note interne e non deve mai finire nell'email.
            $notes = trim((string)($grade['giudizio'] ?? ''));
            if ($notes === '') {
                $notes = 'Nessuna nota aggiuntiva.';
            }

            $htmlRows[] = '<div class="grade-card">'
                . '<p><strong>Esame:</strong> ' . self::escape($type) . '</p>'
                . '<p><strong>Data:</strong> ' . self::escape($date) . '</p>'
                . '<p class="grade-value"><strong>Voto: ' . self::escape($value) . '</strong></p>'
                . '<p><strong>Note per la famiglia:</strong><br>' . nl2br(self::escape($notes)) . '</p>'
                . '</div>';

            $textRows[] = implode("\n", [
                "Esame: {$type}",
                "Data: {$date}",
                "Voto: {$value}",
                "Note per la famiglia: {$notes}",
            ]);
        }

        if ($htmlRows === []) {
            $htmlRows[] = '<p>Nessuna valutazione selezionata.</p>';
            $textRows[] = 'Nessuna valutazione selezionata.';
        }

        $safeStudent = self::escape($studentName);
        $safeActivity = self::escape($activityTitle);
        $html = '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">'
            . '<title>Comunicazione della valutazione</title>'
            . '<style>body{font-family:Arial,sans-serif;color:#243447;line-height:1.55;max-width:680px;margin:0 auto;padding:24px}.header{background:#0d6efd;color:#fff;padding:20px;border-radius:10px 10px 0 0}.content{background:#f8fafc;border:1px solid #dbe4ef;border-top:0;padding:24px}.grade-card{background:#fff;border-left:4px solid #0d6efd;padding:16px;margin:16px 0;border-radius:6px}.grade-value{font-size:1.25rem}.footer{color:#667085;font-size:.85rem;margin-top:24px}</style>'
            . '</head><body><div class="header"><h1>Comunicazione della valutazione</h1></div>'
            . '<div class="content"><p>Gentile ' . $safeStudent . ' e famiglia,</p>'
            . '<p>condividiamo le valutazioni relative all’attività <strong>' . $safeActivity . '</strong>.</p>'
            . implode('', $htmlRows)
            . '<p>Cordiali saluti,<br><strong>Il docente</strong></p></div>'
            . '<div class="footer"><p>Questa è una comunicazione automatica. Per eventuali chiarimenti contattare il docente.</p></div>'
            . '</body></html>';

        return [
            'subject' => 'Valutazione - ' . $activityTitle,
            'html' => $html,
            'text' => "Comunicazione della valutazione\n\n"
                . "Gentile {$studentName} e famiglia,\n\n"
                . "Attività: {$activityTitle}\n\n"
                . implode("\n\n", $textRows)
                . "\n\nCordiali saluti,\nIl docente\n\n"
                . 'Questa è una comunicazione automatica. Per eventuali chiarimenti contattare il docente.',
        ];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_numeric($value)) {
            return number_format((float)$value, 2, ',', '');
        }

        return trim((string)$value);
    }

    private static function gradeTypeLabel(string $type): string
    {
        return match (strtolower(trim($type))) {
            'orale' => 'Interrogazione orale',
            'scritto' => 'Verifica scritta',
            'pratico' => 'Prova pratica',
            'test' => 'Test',
            default => trim($type) !== '' ? ucfirst(trim($type)) : 'Valutazione',
        };
    }

    private static function displayDate(string $date): string
    {
        if (trim($date) === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($date))->format('d/m/Y');
        } catch (\Throwable) {
            return trim($date);
        }
    }
}
