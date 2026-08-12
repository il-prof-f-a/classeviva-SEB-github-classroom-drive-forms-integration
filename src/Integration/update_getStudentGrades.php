<?php
/**
 * Script per aggiornare il metodo getStudentGrades in ClasseVivaAPI
 * Passa da regvoti_dettaglio.php a regvoti.php
 */

$file = __DIR__ . '/ClasseVivaAPI.php';
$content = file_get_contents($file);

// Cerca il metodo getStudentGrades
$startMarker = 'public function getStudentGrades(string $studentId, string $classId, string $subjectId): array';
$endMarker = 'public function calculateGradeAverage(array $grades): float';

$startPos = strpos($content, $startMarker);
$endPos = strpos($content, $endMarker, $startPos);

if ($startPos === false || $endPos === false) {
    echo "❌ Errore: markers non trovati\n";
    exit(1);
}

// Nuovo metodo
$newMethod = '    public function getStudentGrades(string $studentId, string $classId, string $subjectId): array
    {
        // Fai login web se necessario
        if (!$this->phpSessionId) {
            $this->authenticateWeb();
        }

        try {
            // USA regvoti.php - mostra TUTTI gli studenti con dettagli completi
            $url = \'https://web.spaggiari.eu/cvv/app/default/regvoti.php\';

            $response = $this->client->get($url, [
                \'query\' => [
                    \'classe_id\' => $classId,
                    \'gruppo_id\' => \'\',
                    \'materia_id\' => $subjectId
                ],
                \'headers\' => [
                    \'Cookie\' => \'PHPSESSID=\' . $this->phpSessionId,
                    \'User-Agent\' => \'Mozilla/5.0\'
                ],
                \'timeout\' => 30
            ]);

            $html = (string) $response->getBody();

            $grades = [
                \'orale\' => [],
                \'scritto\' => [],
                \'pratico\' => []
            ];

            // Pattern per i 3 tipi di voto
            $typeMap = [
                \'S1_1_\' => \'scritto\',
                \'S1_2_\' => \'orale\',
                \'S1_3_\' => \'pratico\'
            ];

            // Cerca tutti i voti dello studente
            foreach ($typeMap as $prefix => $type) {
                for ($slot = 1; $slot <= 5; $slot++) {
                    $pattern = $prefix . $slot;

                    // Cerca TD con studente_id e data_no_barre
                    if (preg_match(
                        \'/<td[^>]*studente_id=["\\\']\' . $studentId . \'["\\\'][^>]*data_no_barre=["\\\']\' . $pattern . \'["\\\'][^>]*>/i\',
                        $html,
                        $tdMatch
                    )) {
                        $tdTag = $tdMatch[0];

                        $gradeInfo = [
                            \'slot\' => $slot,
                            \'type\' => $type,
                            \'description_code\' => $pattern,
                            \'value\' => null,
                            \'date\' => null,
                            \'notes\' => \'\',
                            \'evento_id\' => null
                        ];

                        // Valore
                        if (preg_match(\'/voto_valore=["\\\'"]?([^"\\\'>\\s]+)["\\\'"]?/i\', $tdTag, $m)) {
                            $val = $m[1];
                            if (!empty($val) && $val != \'0.000\') {
                                $gradeInfo[\'value\'] = $val;
                            }
                        }

                        if ($gradeInfo[\'value\'] === null) {
                            continue;
                        }

                        // Data
                        if (preg_match(\'/mydata=["\\\'"]?(\\d{2}-\\d{2}-\\d{4})["\\\'"]?/i\', $tdTag, $m)) {
                            $dateParts = explode(\'-\', $m[1]);
                            if (count($dateParts) === 3) {
                                $gradeInfo[\'date\'] = $dateParts[2] . \'-\' . $dateParts[1] . \'-\' . $dateParts[0];
                            }
                        }

                        // Note
                        if (preg_match(\'/nota1=["\\\'"]?([^"\\\']*)["\\\'"]?/i\', $tdTag, $m)) {
                            $gradeInfo[\'notes\'] = urldecode($m[1]);
                        }

                        // Evento ID
                        if (preg_match(\'/evento_id=["\\\'"]?(\\d+)["\\\'"]?/i\', $tdTag, $m)) {
                            $gradeInfo[\'evento_id\'] = $m[1];
                        }

                        $grades[$type][] = $gradeInfo;
                    }
                }
            }

            return $grades;

        } catch (GuzzleException $e) {
            throw new Exception("Errore recupero voti studente: " . $e->getMessage());
        }
    }

    ';

// Sostituisci
$before = substr($content, 0, $startPos);
$after = substr($content, $endPos);
$newContent = $before . $newMethod . $after;

// Backup
$backup = $file . '.backup_regvoti_' . date('Ymd_His');
copy($file, $backup);
echo "📦 Backup: $backup\n";

// Scrivi
file_put_contents($file, $newContent);

echo "✅ Metodo getStudentGrades() aggiornato per usare regvoti.php\n";
echo "✅ Ora restituisce array con 'orale', 'scritto', 'pratico' con tutti i dettagli\n";
