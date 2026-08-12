<?php

namespace App\Integration;

use GuzzleHttp\Client;
use Exception;

/**
 * KahootAPI - Integrazione con Kahoot (API non ufficiale)
 *
 * Nota: Kahoot non ha API pubbliche ufficiali per la creazione di quiz.
 * Questa classe fornisce funzioni base per l'integrazione dei risultati.
 */
class KahootAPI
{
    private Client $client;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config['kahoot'] ?? [];
        $this->client = new Client(['timeout' => 30]);
    }

    /**
     * Importa risultati da un quiz Kahoot (tramite CSV export)
     *
     * @param string $csvPath Path del file CSV esportato da Kahoot
     * @return array Risultati processati
     */
    public function importResultsFromCSV(string $csvPath): array
    {
        if (!file_exists($csvPath)) {
            throw new Exception("File CSV non trovato");
        }

        $results = [];
        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle); // Prima riga contiene intestazioni

        while (($row = fgetcsv($handle)) !== false) {
            $studentData = array_combine($headers, $row);

            $results[] = [
                'nome' => $studentData['Player Name'] ?? $studentData['Nome'] ?? '',
                'punteggio' => (int)($studentData['Total Score'] ?? $studentData['Punteggio Totale'] ?? 0),
                'risposte_corrette' => (int)($studentData['Correct Answers'] ?? $studentData['Risposte Corrette'] ?? 0),
                'risposte_totali' => (int)($studentData['Total Questions'] ?? $studentData['Domande Totali'] ?? 0),
                'percentuale' => $this->calculatePercentage($studentData)
            ];
        }

        fclose($handle);
        return $results;
    }

    /**
     * Converte punteggi Kahoot in voti su scala 1-10
     *
     * @param array $results Risultati da convertire
     * @return array Risultati con voti convertiti
     */
    public function convertScoresToGrades(array $results): array
    {
        $gradeConfig = $this->config['grade_conversion'] ?? [
            'enabled' => true,
            'max_score' => 1000,
            'scale_to' => 10,
            'passing_score' => 600
        ];

        if (!$gradeConfig['enabled']) {
            return $results;
        }

        foreach ($results as &$result) {
            // Calcola percentuale se non presente
            if (!isset($result['percentuale'])) {
                $result['percentuale'] = ($result['risposte_corrette'] / $result['risposte_totali']) * 100;
            }

            // Converti in voto 1-10
            $result['voto'] = $this->percentageToGrade($result['percentuale']);
        }

        return $results;
    }

    /**
     * Calcola percentuale da dati studente
     */
    private function calculatePercentage(array $studentData): float
    {
        if (isset($studentData['Percentage']) || isset($studentData['Percentuale'])) {
            return (float)($studentData['Percentage'] ?? $studentData['Percentuale']);
        }

        $correct = (int)($studentData['Correct Answers'] ?? $studentData['Risposte Corrette'] ?? 0);
        $total = (int)($studentData['Total Questions'] ?? $studentData['Domande Totali'] ?? 1);

        return ($correct / $total) * 100;
    }

    /**
     * Converte percentuale in voto 1-10
     */
    private function percentageToGrade(float $percentage): float
    {
        // Scala lineare: 0-100% -> 1-10
        $grade = 1 + ($percentage / 100) * 9;

        // Arrotonda a 0.25
        return round($grade * 4) / 4;
    }

    /**
     * Genera link Kahoot per un quiz
     *
     * @param string $kahootId ID del quiz Kahoot
     * @return string URL del quiz
     */
    public function getQuizLink(string $kahootId): string
    {
        return "https://create.kahoot.it/share/{$kahootId}";
    }

    /**
     * Genera link per giocare un quiz
     *
     * @param string $gamePin PIN del gioco
     * @return string URL per giocare
     */
    public function getPlayLink(string $gamePin): string
    {
        return "https://kahoot.it/?pin={$gamePin}";
    }
}
