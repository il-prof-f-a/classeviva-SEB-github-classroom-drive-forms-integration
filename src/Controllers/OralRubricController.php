<?php

namespace App\Controllers;

use App\Database\DatabaseManager;
use App\Integration\ClasseVivaAPI;
use Exception;

/**
 * OralRubricController - Gestione rubrica valutazioni orali di laboratorio
 *
 * Identico a piuomeno ma integrato nel sistema UDA
 */
class OralRubricController
{
    private DatabaseManager $db;
    private ClasseVivaAPI $clvApi;

    public function __construct(DatabaseManager $db, ClasseVivaAPI $clvApi)
    {
        $this->db = $db;
        $this->clvApi = $clvApi;
    }

    /**
     * Carica indicatori, descrittori e voti per uno studente
     *
     * Logica identica a carica_votazioni.php di piuomeno
     *
     * @param int $idStudente ID studente ClasseViva
     * @param int $idMateria ID materia
     * @param bool $visualizzaVotiVecchi Se mostrare anche voti registrati
     * @return array Struttura con indicatori, descrittori e voti
     */
    public function loadEvaluations(int $idStudente, int $idMateria, bool $visualizzaVotiVecchi = false): array
    {
        $visualizzaVotiVecchiFlag = $visualizzaVotiVecchi ? 1 : 0;

        // Timestamp per voti recenti (ultime 2 ore, come piuomeno)
        $currentTimestamp = time();
        $twoHoursAgoTimestamp = $currentTimestamp - (2 * 3600);

        // Query indicatori (come piuomeno: id_materia=0 OR id_materia=selectedMateria)
        $queryIndicatori = "SELECT *,
                                   `categorie`.descrizione as categoria,
                                   `indicatori`.nome as nomeEsteso,
                                   `indicatori`.descrizione as descrizioneEstesa
                            FROM `indicatori`, `categorie`
                            WHERE `indicatori`.id_categoria = `categorie`.id_categoria
                              AND (`id_materia` = 0 OR `id_materia` = ?)
                            ORDER BY `indicatori`.priorità, `indicatori`.id_categoria, id_indicatore";

        $conn = $this->db->getConnection();
        $stmt = $conn->prepare($queryIndicatori);
        $stmt->bind_param("i", $idMateria);
        $stmt->execute();
        $result = $stmt->get_result();

        $indicatori = [];

        while ($row = $result->fetch_assoc()) {
            $indicatore = $row;
            $idIndicatore = $row['id_indicatore'];

            // Carica voti vecchi per questo indicatore
            $indicatore['voti_vecchi'] = [];
            $queryVotiVecchi = "SELECT * FROM voti
                                WHERE (registrato = ? OR registrato = 0)
                                  AND id_descrittore IN (
                                      SELECT id_descrittore
                                      FROM descrittori
                                      WHERE id_indicatore = ?
                                  )
                                  AND id_studente = ?
                                  AND id_materia = ?
                                  AND data < FROM_UNIXTIME(?)";

            $stmtVV = $conn->prepare($queryVotiVecchi);
            $stmtVV->bind_param("iiiii", $visualizzaVotiVecchiFlag, $idIndicatore, $idStudente, $idMateria, $twoHoursAgoTimestamp);
            $stmtVV->execute();
            $resultVV = $stmtVV->get_result();

            while ($rowVV = $resultVV->fetch_assoc()) {
                $indicatore['voti_vecchi'][] = $rowVV;
            }
            $stmtVV->close();

            // Carica descrittori per questo indicatore
            $indicatore['descrittori'] = [];
            $queryDescrittori = "SELECT * FROM descrittori
                                 WHERE id_indicatore = ?
                                 ORDER BY voto_corrispondente";

            $stmtD = $conn->prepare($queryDescrittori);
            $stmtD->bind_param("i", $idIndicatore);
            $stmtD->execute();
            $resultD = $stmtD->get_result();

            while ($rowD = $resultD->fetch_assoc()) {
                $descrittore = $rowD;
                $idDescrittore = $rowD['id_descrittore'];

                // Cerca voto recente (ultime 2 ore) per questo descrittore
                $queryVotoRecente = "SELECT * FROM voti
                                     WHERE (registrato = ? OR registrato = 0)
                                       AND id_descrittore = ?
                                       AND id_studente = ?
                                       AND id_materia = ?
                                       AND data >= FROM_UNIXTIME(?)";

                $stmtVR = $conn->prepare($queryVotoRecente);
                $stmtVR->bind_param("iiiii", $visualizzaVotiVecchiFlag, $idDescrittore, $idStudente, $idMateria, $twoHoursAgoTimestamp);
                $stmtVR->execute();
                $resultVR = $stmtVR->get_result();

                if ($rowVR = $resultVR->fetch_assoc()) {
                    $descrittore['voto_recente'] = $rowVR;
                }
                $stmtVR->close();

                $indicatore['descrittori'][] = $descrittore;
            }
            $stmtD->close();

            $indicatori[] = $indicatore;
        }
        $stmt->close();

        return [
            'success' => true,
            'indicatori' => $indicatori
        ];
    }

    /**
     * Salva una valutazione +/- per uno studente
     *
     * Logica identica a salva_votazione.php di piuomeno
     *
     * @param int $idDescrittore ID descrittore (+/-)
     * @param int $idStudente ID studente ClasseViva
     * @param int $idMateria ID materia
     * @param int $voto Voto (1=+, 0=-, -1=rimuovi)
     * @param string $username Username docente
     * @param string $commento Commento opzionale
     * @return array Risultato operazione
     */
    public function saveEvaluation(
        int $idDescrittore,
        int $idStudente,
        int $idMateria,
        int $voto,
        string $username,
        string $commento = ''
    ): array {
        $conn = $this->db->getConnection();

        // Timestamp per voti recenti (ultime 2 ore)
        $twoHoursAgoTimestamp = time() - (2 * 3600);

        // Elimina voti recenti per questo descrittore/studente/materia (come piuomeno)
        $sqlDelete = "DELETE FROM voti
                      WHERE id_descrittore = ?
                        AND id_studente = ?
                        AND id_materia = ?
                        AND data >= FROM_UNIXTIME(?)";

        $stmtDelete = $conn->prepare($sqlDelete);
        $stmtDelete->bind_param("iiii", $idDescrittore, $idStudente, $idMateria, $twoHoursAgoTimestamp);
        $stmtDelete->execute();
        $stmtDelete->close();

        // Se voto >= 0, inserisci nuovo voto
        if ($voto >= 0) {
            $sql = "INSERT INTO voti (id_descrittore, id_studente, id_materia, voto, data, registrato, trascritto, prof, commento)
                    VALUES (?, ?, ?, ?, NOW(), 0, 0, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiiiss", $idDescrittore, $idStudente, $idMateria, $voto, $username, $commento);

            if ($stmt->execute()) {
                $lastId = $stmt->insert_id;
                $stmt->close();

                return [
                    'success' => true,
                    'id' => $lastId,
                    'message' => 'Inserimento riuscito'
                ];
            } else {
                $error = $stmt->error;
                $stmt->close();

                return [
                    'success' => false,
                    'id' => 0,
                    'message' => "Errore nell'inserimento: " . $error
                ];
            }
        }

        // Voto rimosso (voto = -1)
        return [
            'success' => true,
            'id' => 0,
            'message' => 'Voto rimosso'
        ];
    }

    /**
     * Ottiene la lista degli studenti per una classe
     * Combina ID dal database con nomi live da ClasseViva
     *
     * @param int $idClasse ID classe ClasseViva
     * @return array Lista studenti con id e nome
     */
    public function getStudentsForClass(int $idClasse): array
    {
        $conn = $this->db->getConnection();

        // Recupera ID studenti dal database
        $query = "SELECT id_studente FROM studenti WHERE id_classe = ? ORDER BY id_studente";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $idClasse);
        $stmt->execute();
        $result = $stmt->get_result();

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = [
                'id_studente' => (string)$row['id_studente'],
                'nome' => null // Sarà riempito da ClasseViva
            ];
        }
        $stmt->close();

        // Ottieni nomi live da ClasseViva
        try {
            $clvStudents = $this->clvApi->getStudentiClasse((string)$idClasse);

            // Crea mappa ID -> Nome
            $nameMap = [];
            foreach ($clvStudents as $clvStudent) {
                $nameMap[$clvStudent['id']] = trim($clvStudent['nome'] . ' ' . $clvStudent['cognome']);
            }

            // Merge nomi
            foreach ($students as &$student) {
                if (isset($nameMap[$student['id_studente']])) {
                    $student['nome'] = $nameMap[$student['id_studente']];
                } else {
                    $student['nome'] = 'CLV-' . $student['id_studente'];
                }
            }
            unset($student);

        } catch (Exception $e) {
            // Fallback: usa placeholder
            foreach ($students as &$student) {
                $student['nome'] = 'CLV-' . $student['id_studente'];
            }
            unset($student);
        }

        return $students;
    }

    /**
     * Ottiene informazioni sull'UDA
     *
     * @param string $udaId ID UDA
     * @return array|null Info UDA
     */
    public function getUdaInfo(string $udaId): ?array
    {
        try {
            // SECURITY FIX: Sanitizza input per prevenire path traversal
            // Permetti solo caratteri alfanumerici, underscore e trattini
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $udaId)) {
                error_log("SECURITY: Tentativo path traversal bloccato - udaId: {$udaId}");
                return null;
            }

            // Costruisci path e validalo
            $baseDir = realpath(__DIR__ . '/../../data/udas');
            if ($baseDir === false) {
                error_log("ERRORE: Directory data/udas non trovata");
                return null;
            }

            $udaFile = $baseDir . '/' . $udaId . '.xlsx';

            // Verifica che il path finale sia dentro la directory permessa
            $realPath = realpath($udaFile);
            if ($realPath === false || strpos($realPath, $baseDir) !== 0) {
                error_log("SECURITY: Tentativo accesso file fuori directory - path: {$udaFile}");
                return null;
            }

            if (!file_exists($realPath)) {
                return null;
            }

            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($realPath);
            $sheet = $spreadsheet->getSheetByName('Metadata');

            if (!$sheet) {
                return null;
            }

            return [
                'id' => $udaId,
                'titolo' => $sheet->getCell('B1')->getValue() ?? 'UDA',
                'descrizione' => $sheet->getCell('B2')->getValue() ?? ''
            ];
        } catch (Exception $e) {
            error_log("Errore getUdaInfo: " . $e->getMessage());
            return null;
        }
    }
}
