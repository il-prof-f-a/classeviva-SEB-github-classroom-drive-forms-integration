<?php

namespace App\Integration;

use GuzzleHttp\Exception\GuzzleException;
use Exception;

/**
 * ClasseVivaGradesExtension - Estensione per recupero voti dal registro
 *
 * Metodi aggiuntivi per recuperare e analizzare i voti già esistenti
 * nel registro elettronico ClasseViva.
 *
 * Questi metodi possono essere integrati in ClasseVivaAPI o usati standalone.
 */
trait ClasseVivaGradesExtension
{
    /**
     * Recupera tutti i voti di uno studente per una specifica materia
     *
     * Parsa la pagina regvoti.php e estrae tutti i voti (orali, scritti, pratici)
     * presenti nel registro per lo studente specificato.
     *
     * @param string $studentId ID studente ClasseViva
     * @param string $classId ID classe ClasseViva
     * @param string $subjectId ID materia ClasseViva
     * @return array Array con i voti divisi per tipo:
     *   [
     *     'orale' => [
     *       ['slot' => 1, 'value' => '8', 'date' => '2025-11-10', 'description' => 'S1_2_1', ...],
     *       ['slot' => 2, 'value' => '7', 'date' => '2025-11-12', 'description' => 'S1_2_2', ...]
     *     ],
     *     'scritto' => [...],
     *     'pratico' => [...]
     *   ]
     * @throws Exception Se recupero fallisce
     */
    public function getStudentGrades(string $studentId, string $classId, string $subjectId): array
    {
        // Fai login web se necessario
        if (!$this->phpSessionId) {
            $this->authenticateWeb();
        }

        try {
            // Recupera HTML della pagina registro
            $url = 'https://web.spaggiari.eu/cvv/app/default/regvoti.php';

            $response = $this->client->get($url, [
                'query' => [
                    'classe_id' => $classId,
                    'gruppo_id' => '',
                    'materia_id' => $subjectId
                ],
                'headers' => [
                    'Cookie' => 'PHPSESSID=' . $this->phpSessionId,
                    'User-Agent' => 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36'
                ],
                'timeout' => 30
            ]);

            $html = (string) $response->getBody();

            // Inizializza array risultato
            $grades = [
                'orale' => [],
                'scritto' => [],
                'pratico' => []
            ];

            // Pattern per identificare i voti
            $typePatterns = [
                'scritto' => 'S1_1_',  // Scritto
                'orale' => 'S1_2_',    // Orale
                'pratico' => 'S1_3_'   // Pratico
            ];

            // Cerca la sezione HTML dello studente
            $studentPattern = 'studente_id=' . $studentId;
            $studentPos = strpos($html, $studentPattern);

            if ($studentPos === false) {
                // Studente non trovato nella pagina (potrebbe non essere in questa classe/materia)
                return $grades;
            }

            // Estrai una sezione di HTML intorno allo studente
            // ClasseViva usa una struttura tipo: <tr data-studente-id="12345">...</tr>
            // oppure form con studente_id=12345
            $section = substr($html, $studentPos, 10000);

            // Per ogni tipo di voto (orale, scritto, pratico)
            foreach ($typePatterns as $type => $prefix) {
                // Cerca tutti gli slot (1-5) per questo tipo
                for ($slot = 1; $slot <= 5; $slot++) {
                    $pattern = $prefix . $slot;

                    // Cerca il pattern nel HTML (es: S1_2_1)
                    if (strpos($section, $pattern) !== false) {
                        // Pattern trovato - questo slot è occupato
                        // Ora estrai i dettagli del voto

                        // Cerca il valore del voto vicino al pattern
                        // Il pattern tipico è: valore_display=7.5 o valore=7.5
                        $gradeInfo = $this->extractGradeInfo($section, $pattern, $studentId);

                        if ($gradeInfo !== null) {
                            $gradeInfo['slot'] = $slot;
                            $gradeInfo['type'] = $type;
                            $gradeInfo['description_code'] = $pattern;
                            $grades[$type][] = $gradeInfo;
                        }
                    }
                }
            }

            return $grades;

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero voti studente: " . $e->getMessage());
        }
    }

    /**
     * Estrae informazioni dettagliate di un singolo voto dall'HTML
     *
     * @param string $html Sezione HTML contenente il voto
     * @param string $pattern Pattern descrizione (es: S1_2_1)
     * @param string $studentId ID studente
     * @return array|null Informazioni voto o null se non estratte
     */
    private function extractGradeInfo(string $html, string $pattern, string $studentId): ?array
    {
        // Trova la posizione del pattern
        $pos = strpos($html, $pattern);
        if ($pos === false) {
            return null;
        }

        // Estrai un contesto intorno al pattern (500 caratteri prima e dopo)
        $start = max(0, $pos - 500);
        $context = substr($html, $start, 1500);

        $gradeInfo = [
            'value' => null,
            'date' => null,
            'notes' => '',
            'evento_id' => null
        ];

        // Estrai valore del voto
        // Pattern: valore_display="7.5" o valore_display='7.5' o valore_display=7.5
        if (preg_match('/valore_display[=\s]*["\']?([0-9.]+|a|i)["\']?/i', $context, $matches)) {
            $gradeInfo['value'] = $matches[1];
        } elseif (preg_match('/valore[=\s]*["\']?(-?[0-9.]+)["\']?/i', $context, $matches)) {
            // Converti valori speciali
            $val = $matches[1];
            if ($val == '-5') {
                $gradeInfo['value'] = 'a'; // Assente
            } elseif ($val == '-4' || $val == '4') {
                $gradeInfo['value'] = 'i'; // Impreparato
            } else {
                $gradeInfo['value'] = $val;
            }
        }

        // Estrai data
        // Pattern: data="13-11-2025" o data='13-11-2025'
        if (preg_match('/data[=\s]*["\']?(\d{2}-\d{2}-\d{4})["\']?/i', $context, $matches)) {
            // Converti da dd-mm-yyyy a yyyy-mm-dd
            $dateParts = explode('-', $matches[1]);
            if (count($dateParts) === 3) {
                $gradeInfo['date'] = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
            }
        }

        // Estrai note/descrizione
        if (preg_match('/nota_1[=\s]*["\']([^"\']*)["\']?/i', $context, $matches)) {
            $gradeInfo['notes'] = urldecode($matches[1]);
        }

        // Estrai evento_id (utile per modifiche future)
        if (preg_match('/evento_id[=\s]*["\']?(\d+)["\']?/i', $context, $matches)) {
            $gradeInfo['evento_id'] = $matches[1];
        }

        // Se non abbiamo almeno il valore, il parsing è fallito
        if ($gradeInfo['value'] === null) {
            return null;
        }

        return $gradeInfo;
    }

    /**
     * Recupera tutti i voti di una classe intera per una materia
     *
     * Utile per generare report, statistiche o backup del registro.
     *
     * @param string $classId ID classe ClasseViva
     * @param string $subjectId ID materia ClasseViva
     * @return array Array di studenti con i loro voti:
     *   [
     *     'student_id' => [
     *       'orale' => [...],
     *       'scritto' => [...],
     *       'pratico' => [...]
     *     ],
     *     ...
     *   ]
     * @throws Exception Se recupero fallisce
     */
    public function getClassGrades(string $classId, string $subjectId): array
    {
        // Prima ottieni la lista degli studenti della classe
        $students = $this->getStudentiClasse($classId);

        $classGrades = [];

        foreach ($students as $student) {
            $studentId = $student['id'];

            try {
                $grades = $this->getStudentGrades($studentId, $classId, $subjectId);
                $classGrades[$studentId] = $grades;
            } catch (Exception $e) {
                // Registra errore ma continua con altri studenti
                $classGrades[$studentId] = [
                    'error' => $e->getMessage(),
                    'orale' => [],
                    'scritto' => [],
                    'pratico' => []
                ];
            }
        }

        return $classGrades;
    }

    /**
     * Calcola la media dei voti di uno studente per tipo
     *
     * @param array $grades Array voti come restituito da getStudentGrades()
     * @param string|null $type Se specificato, calcola solo per quel tipo (orale/scritto/pratico)
     * @return array|float Array con medie per tipo o singola media se $type specificato
     */
    public function calculateGradeAverage(array $grades, ?string $type = null): array|float
    {
        if ($type !== null) {
            // Calcola media per singolo tipo
            if (!isset($grades[$type]) || empty($grades[$type])) {
                return 0.0;
            }

            $sum = 0;
            $count = 0;

            foreach ($grades[$type] as $grade) {
                $value = $grade['value'] ?? null;

                // Ignora valori speciali (a, i) nel calcolo della media
                if (is_numeric($value)) {
                    $sum += (float) $value;
                    $count++;
                }
            }

            return $count > 0 ? round($sum / $count, 2) : 0.0;
        }

        // Calcola medie per tutti i tipi
        $averages = [];

        foreach (['orale', 'scritto', 'pratico'] as $gradeType) {
            $averages[$gradeType] = $this->calculateGradeAverage($grades, $gradeType);
        }

        // Calcola media generale (tutti i voti)
        $allGrades = array_merge(
            $grades['orale'] ?? [],
            $grades['scritto'] ?? [],
            $grades['pratico'] ?? []
        );

        $sum = 0;
        $count = 0;

        foreach ($allGrades as $grade) {
            $value = $grade['value'] ?? null;
            if (is_numeric($value)) {
                $sum += (float) $value;
                $count++;
            }
        }

        $averages['generale'] = $count > 0 ? round($sum / $count, 2) : 0.0;

        return $averages;
    }
}
