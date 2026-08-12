<?php
/**
 * Script per aggiungere i metodi di recupero voti a ClasseVivaAPI
 *
 * USAGE: php add_grades_methods.php
 */

$file = __DIR__ . '/ClasseVivaAPI.php';

// Leggi il file
$content = file_get_contents($file);

// Verifica se i metodi sono già presenti
if (strpos($content, 'function getStudentGrades') !== false) {
    echo "✅ I metodi sono già presenti in ClasseVivaAPI.php\n";
    exit(0);
}

// Metodi da aggiungere (prima della chiusura della classe)
$methodsToAdd = '
    /**
     * Recupera tutti i voti di uno studente per una specifica materia
     *
     * Parsa la pagina regvoti.php e estrae tutti i voti (orali, scritti, pratici)
     * presenti nel registro per lo studente specificato.
     *
     * @param string $studentId ID studente ClasseViva
     * @param string $classId ID classe ClasseViva
     * @param string $subjectId ID materia ClasseViva
     * @return array Array con i voti divisi per tipo
     * @throws Exception Se recupero fallisce
     */
    public function getStudentGrades(string $studentId, string $classId, string $subjectId): array
    {
        // Fai login web se necessario
        if (!$this->phpSessionId) {
            $this->authenticateWeb();
        }

        try {
            $url = \'https://web.spaggiari.eu/cvv/app/default/regvoti.php\';

            $response = $this->client->get($url, [
                \'query\' => [
                    \'classe_id\' => $classId,
                    \'gruppo_id\' => \'\',
                    \'materia_id\' => $subjectId
                ],
                \'headers\' => [
                    \'Cookie\' => \'PHPSESSID=\' . $this->phpSessionId,
                    \'User-Agent\' => \'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36\'
                ],
                \'timeout\' => 30
            ]);

            $html = (string) $response->getBody();

            $grades = [
                \'orale\' => [],
                \'scritto\' => [],
                \'pratico\' => []
            ];

            $typePatterns = [
                \'scritto\' => \'S1_1_\',
                \'orale\' => \'S1_2_\',
                \'pratico\' => \'S1_3_\'
            ];

            $studentPattern = \'studente_id=\' . $studentId;
            $studentPos = strpos($html, $studentPattern);

            if ($studentPos === false) {
                return $grades;
            }

            $section = substr($html, $studentPos, 10000);

            foreach ($typePatterns as $type => $prefix) {
                for ($slot = 1; $slot <= 5; $slot++) {
                    $pattern = $prefix . $slot;

                    if (strpos($section, $pattern) !== false) {
                        $gradeInfo = $this->extractGradeInfo($section, $pattern, $studentId);

                        if ($gradeInfo !== null) {
                            $gradeInfo[\'slot\'] = $slot;
                            $gradeInfo[\'type\'] = $type;
                            $gradeInfo[\'description_code\'] = $pattern;
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
     * Estrae informazioni dettagliate di un singolo voto dall\'HTML
     *
     * @param string $html Sezione HTML contenente il voto
     * @param string $pattern Pattern descrizione (es: S1_2_1)
     * @param string $studentId ID studente
     * @return array|null Informazioni voto o null se non estratte
     */
    private function extractGradeInfo(string $html, string $pattern, string $studentId): ?array
    {
        $pos = strpos($html, $pattern);
        if ($pos === false) {
            return null;
        }

        $start = max(0, $pos - 500);
        $context = substr($html, $start, 1500);

        $gradeInfo = [
            \'value\' => null,
            \'date\' => null,
            \'notes\' => \'\',
            \'evento_id\' => null
        ];

        if (preg_match(\'/valore_display[=\s]*["\\\']?([0-9.]+|a|i)["\\\']?/i\', $context, $matches)) {
            $gradeInfo[\'value\'] = $matches[1];
        } elseif (preg_match(\'/valore[=\s]*["\\\']?(-?[0-9.]+)["\\\']?/i\', $context, $matches)) {
            $val = $matches[1];
            if ($val == \'-5\') {
                $gradeInfo[\'value\'] = \'a\';
            } elseif ($val == \'-4\' || $val == \'4\') {
                $gradeInfo[\'value\'] = \'i\';
            } else {
                $gradeInfo[\'value\'] = $val;
            }
        }

        if (preg_match(\'/data[=\s]*["\\\']?(\d{2}-\d{2}-\d{4})["\\\']?/i\', $context, $matches)) {
            $dateParts = explode(\'-\', $matches[1]);
            if (count($dateParts) === 3) {
                $gradeInfo[\'date\'] = $dateParts[2] . \'-\' . $dateParts[1] . \'-\' . $dateParts[0];
            }
        }

        if (preg_match(\'/nota_1[=\s]*["\\\']([^"\\\']*)["\\\']/i\', $context, $matches)) {
            $gradeInfo[\'notes\'] = urldecode($matches[1]);
        }

        if (preg_match(\'/evento_id[=\s]*["\\\']?(\d+)["\\\']?/i\', $context, $matches)) {
            $gradeInfo[\'evento_id\'] = $matches[1];
        }

        if ($gradeInfo[\'value\'] === null) {
            return null;
        }

        return $gradeInfo;
    }

    /**
     * Calcola la media dei voti
     *
     * @param array $grades Array voti da getStudentGrades()
     * @param string|null $type Tipo specifico o null per tutte le medie
     * @return array|float
     */
    public function calculateGradeAverage(array $grades, ?string $type = null): array|float
    {
        if ($type !== null) {
            if (!isset($grades[$type]) || empty($grades[$type])) {
                return 0.0;
            }

            $sum = 0;
            $count = 0;

            foreach ($grades[$type] as $grade) {
                $value = $grade[\'value\'] ?? null;
                if (is_numeric($value)) {
                    $sum += (float) $value;
                    $count++;
                }
            }

            return $count > 0 ? round($sum / $count, 2) : 0.0;
        }

        $averages = [];

        foreach ([\'orale\', \'scritto\', \'pratico\'] as $gradeType) {
            $averages[$gradeType] = $this->calculateGradeAverage($grades, $gradeType);
        }

        $allGrades = array_merge(
            $grades[\'orale\'] ?? [],
            $grades[\'scritto\'] ?? [],
            $grades[\'pratico\'] ?? []
        );

        $sum = 0;
        $count = 0;

        foreach ($allGrades as $grade) {
            $value = $grade[\'value\'] ?? null;
            if (is_numeric($value)) {
                $sum += (float) $value;
                $count++;
            }
        }

        $averages[\'generale\'] = $count > 0 ? round($sum / $count, 2) : 0.0;

        return $averages;
    }
';

// Trova la posizione dell'ultima parentesi graffa (chiusura della classe)
$lastBrace = strrpos($content, '}');

if ($lastBrace === false) {
    echo "❌ Errore: non trovata la chiusura della classe\n";
    exit(1);
}

// Inserisci i metodi prima della chiusura
$newContent = substr($content, 0, $lastBrace) . $methodsToAdd . "\n}\n";

// Backup del file originale
$backupFile = $file . '.backup_' . date('Ymd_His');
copy($file, $backupFile);
echo "📦 Backup creato: $backupFile\n";

// Scrivi il nuovo contenuto
file_put_contents($file, $newContent);

echo "✅ Metodi aggiunti con successo a ClasseVivaAPI.php\n";
echo "📝 Aggiunti 3 metodi:\n";
echo "   - getStudentGrades()\n";
echo "   - extractGradeInfo()\n";
echo "   - calculateGradeAverage()\n";
