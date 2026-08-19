<?php

namespace App\Core;

use AppCoreDatabaseDatabaseAdapterInterface;
use App\Core\Database\DatabaseAdapterInterface;
use App\Models\ValutazioneLaboratorio;
use Exception;

/**
 * LaboratorioManager - Gestisce le valutazioni laboratorio con sistema +/-
 *
 * Funzionalità:
 * - Gestione categorie competenze trasversali
 * - Gestione indicatori di valutazione
 * - Creazione e gestione valutazioni laboratorio
 * - Calcolo voti da evidenze +/-
 * - Pubblicazione su ClasseViva
 */
class LaboratorioManager
{
    private DatabaseAdapterInterface $db;
    private array $config;

    public function __construct(DatabaseAdapterInterface $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    private function buildAppUrl(string $path): string
    {
        if (function_exists('app_url')) {
            return app_url($path);
        }

        $base = '';
        if (function_exists('env')) {
            $base = (string)env('APP_URL', '');
        }

        $trimmedPath = trim($path, '/');
        if ($base === '') {
            return $trimmedPath ? '/' . $trimmedPath : '/';
        }

        $base = rtrim($base, '/');
        return $trimmedPath ? $base . '/' . $trimmedPath : $base;
    }

    // ========== CATEGORIE COMPETENZE ==========

    /**
     * Recupera tutte le categorie di competenze trasversali
     */
    public function getCategorie(): array
    {
        return $this->db->findAll('CATEGORIE_COMPETENZE');
    }

    /**
     * Recupera una categoria per ID
     */
    public function getCategoria(string $id): ?array
    {
        return $this->db->findOne('CATEGORIE_COMPETENZE', 'id_categoria', $id);
    }

    // ========== INDICATORI ==========

    /**
     * Recupera tutti gli indicatori attivi
     *
     * @param string|null $idCategoria Filtra per categoria
     * @return array
     */
    public function getIndicatori(?string $idCategoria = null): array
    {
        $indicatori = $this->db->findAll('INDICATORI_LABORATORIO');

        if ($idCategoria) {
            $indicatori = array_filter($indicatori, function($ind) use ($idCategoria) {
                return ($ind['id_categoria'] ?? '') === $idCategoria && ($ind['attivo'] ?? 1) == 1;
            });
        } else {
            $indicatori = array_filter($indicatori, function($ind) {
                return ($ind['attivo'] ?? 1) == 1;
            });
        }

        // Ordina per ordine
        usort($indicatori, function($a, $b) {
            return ($a['ordine'] ?? 0) <=> ($b['ordine'] ?? 0);
        });

        return array_values($indicatori);
    }

    /**
     * Recupera un indicatore per ID
     */
    public function getIndicatore(string $id): ?array
    {
        return $this->db->findOne('INDICATORI_LABORATORIO', 'id_indicatore', $id);
    }

    /**
     * Recupera indicatori con le relative categorie (join)
     */
    public function getIndicatoriConCategorie(): array
    {
        $indicatori = $this->getIndicatori();
        $categorie = $this->getCategorie();

        // Crea mappa categorie per ID
        $categorieMap = [];
        foreach ($categorie as $cat) {
            $categorieMap[$cat['id_categoria']] = $cat;
        }

        // Arricchisci indicatori con dati categoria
        foreach ($indicatori as &$ind) {
            $idCat = $ind['id_categoria'] ?? null;
            if ($idCat && isset($categorieMap[$idCat])) {
                $ind['categoria_nome'] = $categorieMap[$idCat]['nome'];
                $ind['categoria_colore'] = $categorieMap[$idCat]['colore_hex'] ?? '6c757d';
            }
        }

        return $indicatori;
    }

    // ========== VALUTAZIONI ==========

    /**
     * Crea una nuova valutazione per uno studente
     *
     * @param string $udaId ID UDA
     * @param string $attivitaId ID attività (materiale/test)
     * @param string $studenteId ID studente
     * @param string $nomeStudente Nome completo studente
     * @param string $classeId ID classe
     * @param array $evidenze Array di evidenze ['id_indicatore' => ['valore' => '+/-', 'commento' => '...']]
     * @return ValutazioneLaboratorio
     */
    public function creaValutazione(
        string $udaId,
        string $attivitaId,
        string $studenteId,
        string $nomeStudente,
        string $classeId,
        array $evidenze
    ): ValutazioneLaboratorio {
        $valutazione = new ValutazioneLaboratorio();
        $valutazione->id_valutazione = 'VLAB_' . uniqid();
        $valutazione->id_uda = $udaId;
        $valutazione->id_attivita = $attivitaId;
        $valutazione->id_studente = $studenteId;
        $valutazione->nome_studente = $nomeStudente;
        $valutazione->id_classe = $classeId;
        $valutazione->data_valutazione = date('Y-m-d');
        $valutazione->pubblicato = false;

        // Prepara array evidenze completo con dati indicatori
        $indicatori = $this->getIndicatoriConCategorie();
        $evidenzeComplete = [];

        foreach ($evidenze as $idIndicatore => $dati) {
            // Trova indicatore
            $indicatore = null;
            foreach ($indicatori as $ind) {
                if ($ind['id_indicatore'] === $idIndicatore) {
                    $indicatore = $ind;
                    break;
                }
            }

            if (!$indicatore) {
                continue;
            }

            $evidenzeComplete[$idIndicatore] = [
                'id_indicatore' => $idIndicatore,
                'nome_indicatore' => $indicatore['nome'],
                'peso' => (int)($indicatore['peso'] ?? 1),
                'valore' => $dati['valore'] ?? '',
                'commento' => $dati['commento'] ?? ''
            ];
        }

        $valutazione->evidenze = $evidenzeComplete;

        // Calcola voto
        $valutazione->voto_calcolato = $valutazione->calcolaVoto(true);

        return $valutazione;
    }

    /**
     * Salva una valutazione nel database
     *
     * Nota: Ogni evidenza viene salvata come una riga separata nel foglio VALUTAZIONI_LABORATORIO
     */
    public function salvaValutazione(ValutazioneLaboratorio $valutazione): bool
    {
        // Prima elimina eventuali valutazioni esistenti per questa combinazione studente/attività
        $this->eliminaValutazioniStudenteAttivita($valutazione->id_studente, $valutazione->id_attivita);

        // Inserisci ogni evidenza come riga separata
        foreach ($valutazione->evidenze as $idIndicatore => $evidenza) {
            $rowData = [
                'id_valutazione' => $valutazione->id_valutazione,
                'id_uda' => $valutazione->id_uda,
                'id_attivita' => $valutazione->id_attivita,
                'id_studente' => $valutazione->id_studente,
                'nome_studente' => $valutazione->nome_studente,
                'id_classe' => $valutazione->id_classe,
                'id_indicatore' => $idIndicatore,
                'valore' => $evidenza['valore'] ?? '',
                'commento' => $evidenza['commento'] ?? '',
                'data_valutazione' => $valutazione->data_valutazione,
                'voto_calcolato' => $valutazione->voto_calcolato,
                'pubblicato' => $valutazione->pubblicato ? 1 : 0,
                'data_pubblicazione' => $valutazione->data_pubblicazione,
                'id_voto_classeviva' => $valutazione->id_voto_classeviva
            ];

            $this->db->insertRow('VALUTAZIONI_LABORATORIO', $rowData);
        }

        // Salva anche nel foglio VOTI per tracciabilità
        $this->salvaVotoInRegistro($valutazione);

        return true;
    }

    /**
     * Salva il voto nel foglio VOTI per integrazione con il registro
     */
    private function salvaVotoInRegistro(ValutazioneLaboratorio $valutazione): bool
    {
        $votoData = [
            'id_voto' => 'VOT_LAB_' . uniqid(),
            'id_uda' => $valutazione->id_uda,
            'id_classe' => $valutazione->id_classe,
            'id_studente' => $valutazione->id_studente,
            'cognome' => '', // Estratto dal nome_studente se necessario
            'nome' => $valutazione->nome_studente,
            'tipo_valutazione' => 'laboratorio',
            'id_test' => $valutazione->id_attivita,
            'data_valutazione' => $valutazione->data_valutazione,
            'voto_numerico' => $valutazione->voto_calcolato,
            'voto_percentuale' => ($valutazione->voto_calcolato / 10) * 100,
            'giudizio_sintetico' => $valutazione->generaGiudizio(),
            'giudizio_esteso' => $valutazione->generaGiudizioEsteso(),
            'file_rubrica' => '',
            'pubblicato_registro' => $valutazione->pubblicato ? 1 : 0,
            'data_pubblicazione' => $valutazione->data_pubblicazione,
            'id_voto_classeviva' => $valutazione->id_voto_classeviva,
            'note' => 'Valutazione laboratorio sistema +/-',
            'link_origine' => $this->buildAppUrl(
                'public/laboratorio_valutazione.php?id_uda=' . urlencode((string)$valutazione->id_uda)
                . '&attivita_id=' . urlencode((string)$valutazione->id_attivita)
            ),
            'dettaglio_rubrica' => json_encode([
                'tipo' => 'laboratorio',
                'id_valutazione' => $valutazione->id_valutazione,
                'evidenze' => $valutazione->evidenze,
                'statistiche' => $valutazione->getStatistiche()
            ])
        ];

        return $this->db->insertRow('VOTI', $votoData);
    }

    /**
     * Elimina valutazioni esistenti per studente/attività
     */
    private function eliminaValutazioniStudenteAttivita(string $studenteId, string $attivitaId): void
    {
        $spreadsheet = $this->db->getConnection();
        $sheet = $spreadsheet->getSheetByName('VALUTAZIONI_LABORATORIO');

        if ($sheet === null) {
            return;
        }

        $headers = $sheet->rangeToArray("A1:" . $sheet->getHighestColumn() . "1")[0];
        $studenteCol = array_search('id_studente', $headers);
        $attivitaCol = array_search('id_attivita', $headers);

        if ($studenteCol === false || $attivitaCol === false) {
            return;
        }

        $lastRow = $sheet->getHighestRow();

        // Itera al contrario per evitare problemi con indici
        for ($row = $lastRow; $row >= 2; $row--) {
            $studenteValue = $sheet->getCellByColumnAndRow($studenteCol + 1, $row)->getValue();
            $attivitaValue = $sheet->getCellByColumnAndRow($attivitaCol + 1, $row)->getValue();

            if ($studenteValue === $studenteId && $attivitaValue === $attivitaId) {
                $sheet->removeRow($row);
            }
        }

        // Salva modifiche
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $dbPath = isset($this->config['database']['master_file'])
            ? ROOT_PATH . '/' . $this->config['database']['master_file']
            : ROOT_PATH . '/database/uda_master.xlsx';
        $writer->save($dbPath);
    }

    /**
     * Recupera valutazioni per UDA e attività
     */
    public function getValutazioniPerAttivita(string $udaId, string $attivitaId): array
    {
        $rows = $this->db->findAll('VALUTAZIONI_LABORATORIO');

        // Filtra per UDA e attività
        $rows = array_filter($rows, function($row) use ($udaId, $attivitaId) {
            return ($row['id_uda'] ?? '') === $udaId && ($row['id_attivita'] ?? '') === $attivitaId;
        });

        // Raggruppa per studente
        $valutazioniPerStudente = [];

        foreach ($rows as $row) {
            $studenteId = $row['id_studente'] ?? '';

            if (!isset($valutazioniPerStudente[$studenteId])) {
                $valutazioniPerStudente[$studenteId] = [
                    'id_valutazione' => $row['id_valutazione'],
                    'id_uda' => $row['id_uda'],
                    'id_attivita' => $row['id_attivita'],
                    'id_studente' => $studenteId,
                    'nome_studente' => $row['nome_studente'],
                    'id_classe' => $row['id_classe'],
                    'data_valutazione' => $row['data_valutazione'],
                    'voto_calcolato' => $row['voto_calcolato'],
                    'pubblicato' => $row['pubblicato'],
                    'data_pubblicazione' => $row['data_pubblicazione'],
                    'id_voto_classeviva' => $row['id_voto_classeviva'],
                    'evidenze' => []
                ];
            }

            // Aggiungi evidenza
            $idIndicatore = $row['id_indicatore'] ?? '';
            if ($idIndicatore) {
                $valutazioniPerStudente[$studenteId]['evidenze'][$idIndicatore] = [
                    'id_indicatore' => $idIndicatore,
                    'valore' => $row['valore'],
                    'commento' => $row['commento'] ?? ''
                ];
            }
        }

        // Converti in array di ValutazioneLaboratorio
        $valutazioni = [];
        foreach ($valutazioniPerStudente as $data) {
            $valutazioni[] = ValutazioneLaboratorio::fromArray($data);
        }

        return $valutazioni;
    }

    /**
     * Recupera valutazione per studente e attività
     */
    public function getValutazioneStudente(string $studenteId, string $attivitaId): ?ValutazioneLaboratorio
    {
        $rows = $this->db->findAll('VALUTAZIONI_LABORATORIO');

        $evidenze = [];
        $valutazioneData = null;

        foreach ($rows as $row) {
            if (($row['id_studente'] ?? '') === $studenteId && ($row['id_attivita'] ?? '') === $attivitaId) {
                // Prima evidenza: salva dati generali
                if ($valutazioneData === null) {
                    $valutazioneData = [
                        'id_valutazione' => $row['id_valutazione'],
                        'id_uda' => $row['id_uda'],
                        'id_attivita' => $row['id_attivita'],
                        'id_studente' => $studenteId,
                        'nome_studente' => $row['nome_studente'],
                        'id_classe' => $row['id_classe'],
                        'data_valutazione' => $row['data_valutazione'],
                        'voto_calcolato' => $row['voto_calcolato'],
                        'pubblicato' => $row['pubblicato'],
                        'data_pubblicazione' => $row['data_pubblicazione'],
                        'id_voto_classeviva' => $row['id_voto_classeviva']
                    ];
                }

                // Aggiungi evidenza
                $idIndicatore = $row['id_indicatore'] ?? '';
                if ($idIndicatore) {
                    $evidenze[$idIndicatore] = [
                        'id_indicatore' => $idIndicatore,
                        'valore' => $row['valore'],
                        'commento' => $row['commento'] ?? ''
                    ];
                }
            }
        }

        if ($valutazioneData === null) {
            return null;
        }

        $valutazioneData['evidenze'] = $evidenze;
        return ValutazioneLaboratorio::fromArray($valutazioneData);
    }

    /**
     * Pubblica una valutazione su ClasseViva
     */
    public function pubblicaValutazione(string $idValutazione): bool
    {
        // TODO: Implementare integrazione con ClasseVivaAPI
        // Per ora, marca solo come pubblicato

        $rows = $this->db->findAll('VALUTAZIONI_LABORATORIO');

        foreach ($rows as $row) {
            if (($row['id_valutazione'] ?? '') === $idValutazione) {
                // Aggiorna tutte le righe con questo id_valutazione
                $this->db->updateRow('VALUTAZIONI_LABORATORIO', 'id_valutazione', $idValutazione, [
                    'pubblicato' => 1,
                    'data_pubblicazione' => date('Y-m-d H:i:s')
                ]);
            }
        }

        return true;
    }

    /**
     * Recupera statistiche per UDA
     */
    public function getStatisticheUDA(string $udaId): array
    {
        $rows = $this->db->findAll('VALUTAZIONI_LABORATORIO');

        $valutazioni = array_filter($rows, function($row) use ($udaId) {
            return ($row['id_uda'] ?? '') === $udaId;
        });

        $votiUnici = [];
        foreach ($valutazioni as $row) {
            $idVal = $row['id_valutazione'] ?? '';
            if ($idVal && !isset($votiUnici[$idVal])) {
                $votiUnici[$idVal] = (float)($row['voto_calcolato'] ?? 0);
            }
        }

        $voti = array_values($votiUnici);

        return [
            'totale_valutazioni' => count($voti),
            'voto_medio' => count($voti) > 0 ? array_sum($voti) / count($voti) : 0,
            'voto_minimo' => count($voti) > 0 ? min($voti) : 0,
            'voto_massimo' => count($voti) > 0 ? max($voti) : 0,
            'pubblicati' => count(array_filter($valutazioni, function($r) {
                return ($r['pubblicato'] ?? 0) == 1;
            }))
        ];
    }
}
