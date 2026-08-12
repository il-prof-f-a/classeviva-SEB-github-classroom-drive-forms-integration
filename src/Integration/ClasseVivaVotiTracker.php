<?php

namespace App\Integration;

use Exception;

/**
 * ClasseVivaVotiTracker
 *
 * Gestisce il salvataggio dei voti nel registro VOTI locale per tracciabilità
 */
class ClasseVivaVotiTracker
{
    private $dbAdapter;
    private array $config;

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
        try {
            $this->dbAdapter = \App\Core\Database\DatabaseFactory::create($config);
        } catch (\Exception $e) {
            throw new Exception("Unable to initialize database adapter: " . $e->getMessage());
        }
    }

    /**
     * Salva voto nel registro VOTI locale e ritorna l'ID per tracciabilità
     *
     * @param array $gradeData Dati del voto da salvare:
     *   - student_id: ID studente
     *   - class_id: ID classe
     *   - subject_id: ID materia (opzionale)
     *   - subject_name: Nome materia
     *   - grade_type: Tipo voto (orale, scritto, pratico)
     *   - grade_value: Valore voto
     *   - date: Data valutazione
     *   - notes: Note aggiuntive
     *   - uda_id: ID UDA se associato
     * @param string|null $studentName Nome completo studente (opzionale)
     * @return string ID del record VOTI inserito
     * @throws Exception Se inserimento fallisce
     */
    public function saveGradeToVoti(array $gradeData, ?string $studentName = null): string
    {
        // Genera ID univoco per voto
        $votoId = 'VOT_CV_' . uniqid();

        // Prepara i dati per il foglio VOTI
        $votoData = [
            'id_voto' => $votoId,
            'id_uda' => $gradeData['uda_id'] ?? '',
            'id_classe_cv' => $gradeData['class_id'],
            'id_studente_cv' => $gradeData['student_id'],
            'id_materia_cv' => $gradeData['subject_id'] ?? '',
            'tipo_voto' => 'classeviva_' . $gradeData['grade_type'],
            'voto' => is_numeric($gradeData['grade_value']) ? (float)$gradeData['grade_value'] : 0,
            'giudizio' => '',
            'descrizione' => $this->buildNotes($gradeData),
            'data_valutazione' => $gradeData['date'],
            'data_creazione' => date('Y-m-d H:i:s'),
            'pubblicato' => 1,
            'id_annotazione_cv' => $gradeData['response_id'] ?? '',
            'num_evidenze_positive' => 0,
            'num_evidenze_negative' => 0,
            'num_evidenze_totali' => 0,
            'id_utente' => $gradeData['id_utente'] ?? '',
            'link_origine' => $gradeData['link_origine'] ?? ''
        ];

        // Inserisci nel database
        try {
            $this->dbAdapter->insertVoto($votoData);
            return $votoId;
        } catch (\Exception $e) {
            throw new Exception("Errore salvataggio voto in VOTI: " . $e->getMessage());
        }
    }

    /**
     * Costruisce il campo note per il voto
     *
     * @param array $gradeData Dati del voto
     * @return string Note formattate
     */
    private function buildNotes(array $gradeData): string
    {
        $notes = [];

        $noteText = $gradeData['notes_2'] ?? ($gradeData['notes'] ?? '');
        if (!empty($noteText)) {
            $notes[] = $noteText;
        }

        $notes[] = "Inserito automaticamente da portale UDA";
        $notes[] = "Tipo: " . ucfirst($gradeData['grade_type']);

        if (!empty($gradeData['subject_name'])) {
            $notes[] = "Materia: " . $gradeData['subject_name'];
        }

        return implode(' | ', $notes);
    }

    /**
     * Aggiorna un record VOTI con l'ID evento ClasseViva
     *
     * @param string $votoId ID del voto locale
     * @param string $eventoId ID evento da ClasseViva
     * @return bool Success
     */
    public function updateWithClasseVivaId(string $votoId, string $eventoId): bool
    {
        try {
            return $this->dbAdapter->updateVoto($votoId, [
                'id_voto_classeviva' => $eventoId
            ]);
        } catch (\Exception $e) {
            error_log("Errore aggiornamento ID ClasseViva per voto $votoId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera un voto dal registro VOTI
     *
     * @param string $votoId ID del voto
     * @return array|null Dati del voto o null se non trovato
     */
    public function getVotoById(string $votoId): ?array
    {
        try {
            $allVoti = $this->dbAdapter->findAll('VOTI');
            foreach ($allVoti as $voto) {
                if (($voto['id_voto'] ?? '') === $votoId) {
                    return $voto;
                }
            }
            return null;
        } catch (\Exception $e) {
            error_log("Errore recupero voto $votoId: " . $e->getMessage());
            return null;
        }
    }
}
