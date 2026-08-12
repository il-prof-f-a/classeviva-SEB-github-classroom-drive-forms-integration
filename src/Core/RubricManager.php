<?php

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Models\Rubrica;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Exception;

/**
 * RubricManager - Gestisce le rubriche di valutazione
 *
 * Funzionalità:
 * - Import rubrica da file Excel template
 * - Creazione rubrica per UDA
 * - Compilazione rubrica per studente
 * - Calcolo voto e generazione giudizio
 * - Export rubrica compilata in Excel
 */
class RubricManager
{
    private DatabaseAdapterInterface $db;
    private array $config;

    public function __construct(DatabaseAdapterInterface $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Importa una rubrica dal file Excel template (Rubrica valutazione orale VUOTA.xlsx)
     *
     * @param string $filePath Percorso del file Excel template
     * @param string $udaId ID della UDA a cui associare la rubrica
     * @param array $argomenti Array di argomenti per gli indicatori di contenuto
     * @return Rubrica La rubrica creata
     * @throws Exception Se il file non può essere letto
     */
    public function importRubricaDaTemplate(string $filePath, string $udaId, array $argomenti = []): Rubrica
    {
        // Usa DatabaseManager per caricare il file in modo sicuro
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('MASTER');

        if ($sheet === null) {
            throw new Exception("Foglio MASTER non trovato nel template");
        }

        $rubrica = new Rubrica();
        $rubrica->id_rubrica = 'RUB_' . uniqid();
        $rubrica->id_uda = $udaId;
        $rubrica->nome_rubrica = 'Rubrica Valutazione Orale';
        $rubrica->tipo_valutazione = 'orale';
        $rubrica->indicatori = [];

        // Leggi gli indicatori fissi (righe 2-4)
        $indicatoriFissi = [
            2 => ['nome' => 'ESPOSIZIONE', 'peso' => 4, 'tipo' => 'fisso'],
            3 => ['nome' => 'MODO DI ESPRIMERSI', 'peso' => 4, 'tipo' => 'fisso'],
            4 => ['nome' => 'ORGANIZZAZIONE NELLE MODALITA\' DI ESPOSIZIONE', 'peso' => 4, 'tipo' => 'fisso']
        ];

        foreach ($indicatoriFissi as $riga => $info) {
            $indicatore = $this->leggiIndicatoreDaRiga($sheet, $riga, $info['nome'], $info['peso'], $info['tipo']);
            $rubrica->indicatori[] = $indicatore;
        }

        // Leggi gli indicatori di contenuto (righe 6-8 o personalizzate)
        $righeContenuti = [6, 7, 8];
        foreach ($righeContenuti as $idx => $riga) {
            $nomeArgomento = isset($argomenti[$idx])
                ? $argomenti[$idx]
                : $sheet->getCell('B' . $riga)->getValue();

            // Salta se l'argomento è vuoto
            if (empty($nomeArgomento) || $nomeArgomento === 'argomento ' . ($idx + 1)) {
                continue;
            }

            $indicatore = $this->leggiIndicatoreDaRiga($sheet, 5, $nomeArgomento, 8, 'contenuto');
            // Sovrascrivi il nome con l'argomento specifico
            $indicatore['nome'] = $nomeArgomento;
            $rubrica->indicatori[] = $indicatore;
        }

        // Salva la rubrica nel database
        $this->salvaRubrica($rubrica);

        return $rubrica;
    }

    /**
     * Legge un indicatore da una riga del foglio Excel
     */
    private function leggiIndicatoreDaRiga($sheet, int $riga, string $nome, int $peso, string $tipo): array
    {
        $indicatore = [
            'nome' => $nome,
            'peso' => $peso,
            'tipo' => $tipo,
            'ordine' => $riga,
            'livelli' => []
        ];

        // Leggi i 5 livelli dalle colonne C, E, G, I, K
        $colonneDescrizioni = ['C', 'E', 'G', 'I', 'K'];
        $percentuali = [0.0, 0.25, 0.5, 0.75, 1.0];

        foreach ($colonneDescrizioni as $idx => $colonna) {
            $livello = $idx + 1;
            $cellValue = $sheet->getCell($colonna . $riga)->getValue() ?? '';

            // Converti RichText in stringa se necessario
            if (is_object($cellValue) && method_exists($cellValue, '__toString')) {
                $descrizione = (string) $cellValue;
            } else if (is_object($cellValue) && method_exists($cellValue, 'getPlainText')) {
                $descrizione = $cellValue->getPlainText();
            } else {
                $descrizione = (string) $cellValue;
            }

            $indicatore['livelli'][$livello] = [
                'descrizione' => $descrizione,
                'punteggio' => $percentuali[$idx]
            ];
        }

        return $indicatore;
    }

    /**
     * Crea una rubrica personalizzata per una UDA
     *
     * @param string $udaId ID della UDA
     * @param string $nomeRubrica Nome della rubrica
     * @param array $indicatoriCustom Array di indicatori personalizzati
     * @return Rubrica
     */
    public function creaRubricaPersonalizzata(string $udaId, string $nomeRubrica, array $indicatoriCustom): Rubrica
    {
        $rubrica = new Rubrica();
        $rubrica->id_rubrica = 'RUB_' . uniqid();
        $rubrica->id_uda = $udaId;
        $rubrica->nome_rubrica = $nomeRubrica;
        $rubrica->tipo_valutazione = 'personalizzata';
        $rubrica->indicatori = $indicatoriCustom;

        $this->salvaRubrica($rubrica);

        return $rubrica;
    }

    /**
     * Recupera una rubrica per ID
     * Ricostruisce la rubrica da tutte le righe con lo stesso id_rubrica
     */
    public function getRubrica(string $idRubrica): ?Rubrica
    {
        $rows = $this->db->findAll('RUBRICA');

        // Filtra le righe per questo id_rubrica
        $indicatoriRows = array_filter($rows, function($row) use ($idRubrica) {
            return ($row['id_rubrica'] ?? '') === $idRubrica;
        });

        if (empty($indicatoriRows)) {
            return null;
        }

        // Ordina per ordine
        usort($indicatoriRows, function($a, $b) {
            return ($a['ordine'] ?? 0) <=> ($b['ordine'] ?? 0);
        });

        // Ricostruisci la rubrica
        $firstRow = reset($indicatoriRows);

        $rubrica = new Rubrica();
        $rubrica->id_rubrica = $idRubrica;
        $rubrica->id_uda = $firstRow['id_uda'] ?? '';
        $rubrica->nome_rubrica = 'Rubrica Valutazione Orale';
        $rubrica->tipo_valutazione = 'orale';
        $rubrica->indicatori = [];

        foreach ($indicatoriRows as $row) {
            $indicatore = [
                'nome' => $row['nome_indicatore'] ?? '',
                'peso' => (int)($row['peso'] ?? 0),
                'tipo' => $row['note'] ?? 'fisso', // Recupera il tipo dalle note
                'ordine' => (int)($row['ordine'] ?? 0),
                'livelli' => [
                    1 => ['descrizione' => $row['livello_1_desc'] ?? '', 'punteggio' => 0.0],
                    2 => ['descrizione' => $row['livello_2_desc'] ?? '', 'punteggio' => 0.25],
                    3 => ['descrizione' => $row['livello_3_desc'] ?? '', 'punteggio' => 0.5],
                    4 => ['descrizione' => $row['livello_4_desc'] ?? '', 'punteggio' => 0.75],
                    5 => ['descrizione' => $row['livello_5_desc'] ?? '', 'punteggio' => 1.0],
                ]
            ];

            $rubrica->indicatori[] = $indicatore;
        }

        return $rubrica;
    }

    /**
     * Recupera tutte le rubriche per una UDA
     */
    public function getRubricheByUDA(string $udaId): array
    {
        $rows = $this->db->findAll('RUBRICA');
        $rubriche = [];

        foreach ($rows as $row) {
            if (isset($row['id_uda']) && $row['id_uda'] === $udaId) {
                $rubriche[] = Rubrica::fromArray($row);
            }
        }

        return $rubriche;
    }

    /**
     * Salva una rubrica nel database
     * Nota: Il foglio RUBRICA salva ogni indicatore come una riga separata
     */
    public function salvaRubrica(Rubrica $rubrica): bool
    {
        // Elimina eventuali righe esistenti per questa rubrica
        $this->eliminaIndicatoriRubrica($rubrica->id_rubrica);

        // Inserisci ogni indicatore come riga separata
        foreach ($rubrica->indicatori as $ordine => $indicatore) {
            $rowData = [
                'id_rubrica' => $rubrica->id_rubrica,
                'id_uda' => $rubrica->id_uda,
                'nome_indicatore' => $indicatore['nome'],
                'descrizione' => '', // Descrizione generale dell'indicatore (opzionale)
                'livello_1_desc' => $indicatore['livelli'][1]['descrizione'] ?? '',
                'livello_2_desc' => $indicatore['livelli'][2]['descrizione'] ?? '',
                'livello_3_desc' => $indicatore['livelli'][3]['descrizione'] ?? '',
                'livello_4_desc' => $indicatore['livelli'][4]['descrizione'] ?? '',
                'livello_5_desc' => $indicatore['livelli'][5]['descrizione'] ?? '',
                'peso' => $indicatore['peso'],
                'ordine' => $ordine + 1,
                'note' => $indicatore['tipo'] ?? '', // Usa note per salvare il tipo (fisso/contenuto)
                'pubblicato' => 0, // Non pubblicato per default
                'data_pubblicazione' => null,
                'id_annotazione_cv' => null
                // id_utente viene aggiunto automaticamente da UserScopedDatabaseAdapter
            ];

            $this->db->insertRow('RUBRICA', $rowData);
        }

        // Salva i metadati della rubrica in un foglio separato se necessario
        // Per ora salviamo le valutazioni compilate nel foglio VOTI

        return true;
    }

    /**
     * Elimina gli indicatori di una rubrica
     */
    private function eliminaIndicatoriRubrica(string $idRubrica): void
    {
        // Con SQLite/Database Adapter, elimina le righe tramite PDO diretto
        try {
            $pdo = $this->db->getConnection();

            // Esegui una query DELETE per tutte le righe con questo id_rubrica
            $stmt = $pdo->prepare("DELETE FROM RUBRICA WHERE id_rubrica = :id_rubrica");
            $stmt->execute([':id_rubrica' => $idRubrica]);
        } catch (\Exception $e) {
            // Se fallisce, potrebbe essere che la tabella non esiste ancora
            // Ignora l'errore in questo caso
        }
    }

    /**
     * Compila una rubrica per uno studente
     *
     * @param string $idRubrica ID della rubrica
     * @param string $idStudente ID dello studente
     * @param string $nomeStudente Nome completo dello studente
     * @param array $livelliSelezionati Array associativo ['nome_indicatore' => livello]
     * @return array Risultato con voto e giudizio
     */
    public function compilaRubrica(string $idRubrica, string $idStudente, string $nomeStudente, array $livelliSelezionati): array
    {
        $rubrica = $this->getRubrica($idRubrica);

        if (!$rubrica) {
            throw new Exception("Rubrica non trovata: $idRubrica");
        }

        // Calcola il voto
        $voto = $rubrica->calcolaVoto($livelliSelezionati);

        // Genera il giudizio
        $giudizio = $rubrica->generaGiudizio($livelliSelezionati);

        // Salva la valutazione compilata
        $rubrica->valutazione_compilata = [
            'id_studente' => $idStudente,
            'nome_studente' => $nomeStudente,
            'data_valutazione' => date('Y-m-d'),
            'livelli_selezionati' => $livelliSelezionati,
            'voto_calcolato' => $voto,
            'giudizio' => $giudizio
        ];

        $rubrica->voto_calcolato = $voto;
        $rubrica->giudizio_generato = $giudizio;

        // Aggiorna la rubrica nel database
        $this->salvaRubrica($rubrica);

        return [
            'voto' => $voto,
            'giudizio' => $giudizio,
            'dettaglio' => $rubrica->valutazione_compilata
        ];
    }

    /**
     * Genera un file Excel compilato della rubrica
     *
     * @param string $idRubrica ID della rubrica
     * @param string $outputPath Percorso di output del file
     * @return string Percorso del file generato
     */
    public function generaExcelCompilato(string $idRubrica, string $outputPath): string
    {
        $rubrica = $this->getRubrica($idRubrica);

        if (!$rubrica || !$rubrica->valutazione_compilata) {
            throw new Exception("Rubrica non trovata o non compilata");
        }

        // Carica il template usando DatabaseManager (metodo sicuro)
        $spreadsheet = $this->db->loadTemplate('Rubrica valutazione orale VUOTA.xlsx');

        // Usa il foglio "studente data"
        $sheet = $spreadsheet->getSheetByName('studente data');

        if ($sheet === null) {
            throw new Exception("Foglio 'studente data' non trovato");
        }

        // Compila i dati dello studente e la data
        // (Assumiamo che ci siano celle specifiche per nome studente, classe, data, voto, giudizio)

        // Compila i livelli selezionati
        $livelliSelezionati = $rubrica->valutazione_compilata['livelli_selezionati'];

        // Mappa indicatori a righe
        $mappaRighe = [
            'ESPOSIZIONE' => 2,
            'MODO DI ESPRIMERSI' => 3,
            'ORGANIZZAZIONE NELLE MODALITA\' DI ESPOSIZIONE' => 4
        ];

        // Aggiungi gli argomenti dinamicamente
        $rigaArgomento = 6;
        foreach ($rubrica->indicatori as $indicatore) {
            if ($indicatore['tipo'] === 'contenuto') {
                $mappaRighe[$indicatore['nome']] = $rigaArgomento++;
            }
        }

        // Mappa livelli a colonne (dove inserire "X" o segno di spunta)
        $mappaColonne = [
            1 => 'D',
            2 => 'F',
            3 => 'H',
            4 => 'J',
            5 => 'L'
        ];

        // Compila la rubrica con i livelli selezionati
        foreach ($livelliSelezionati as $nomeIndicatore => $livello) {
            if (isset($mappaRighe[$nomeIndicatore]) && isset($mappaColonne[$livello])) {
                $riga = $mappaRighe[$nomeIndicatore];
                $colonna = $mappaColonne[$livello];

                // Inserisci "X" nella cella corrispondente
                $sheet->setCellValue($colonna . $riga, 'X');
            }
        }

        // Scrivi voto e giudizio (assumiamo righe 9 e 10 colonna H)
        $sheet->setCellValue('I9', $rubrica->voto_calcolato);
        $sheet->setCellValue('I10', $rubrica->giudizio_generato);

        // Salva il file
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Elimina una rubrica (tutti gli indicatori)
     */
    public function eliminaRubrica(string $idRubrica): bool
    {
        $this->eliminaIndicatoriRubrica($idRubrica);
        return true;
    }

    /**
     * Calcola statistiche per una rubrica
     *
     * @param string $idRubrica ID della rubrica
     * @return array Statistiche (media voti, distribuzione livelli, ecc.)
     */
    public function calcolaStatistiche(string $idRubrica): array
    {
        // Trova tutti i voti associati a questa rubrica
        $voti = $this->db->findAll('VOTI');
        $votiRubrica = array_filter($voti, function($voto) use ($idRubrica) {
            $dettaglio = isset($voto['dettaglio_rubrica']) && is_string($voto['dettaglio_rubrica'])
                ? json_decode($voto['dettaglio_rubrica'], true)
                : ($voto['dettaglio_rubrica'] ?? []);

            return isset($dettaglio['id_rubrica']) && $dettaglio['id_rubrica'] === $idRubrica;
        });

        $votiNumerici = array_map(function($voto) {
            return (float)($voto['voto_numerico'] ?? 0);
        }, $votiRubrica);

        $stats = [
            'totale_valutazioni' => count($votiNumerici),
            'voto_medio' => count($votiNumerici) > 0 ? array_sum($votiNumerici) / count($votiNumerici) : 0,
            'voto_minimo' => count($votiNumerici) > 0 ? min($votiNumerici) : 0,
            'voto_massimo' => count($votiNumerici) > 0 ? max($votiNumerici) : 0,
            'distribuzione' => [
                'insufficiente' => 0,  // < 6
                'sufficiente' => 0,    // 6-6.99
                'discreto' => 0,       // 7-7.99
                'buono' => 0,          // 8-8.99
                'ottimo' => 0          // 9-10
            ]
        ];

        foreach ($votiNumerici as $voto) {
            if ($voto < 6) {
                $stats['distribuzione']['insufficiente']++;
            } elseif ($voto < 7) {
                $stats['distribuzione']['sufficiente']++;
            } elseif ($voto < 8) {
                $stats['distribuzione']['discreto']++;
            } elseif ($voto < 9) {
                $stats['distribuzione']['buono']++;
            } else {
                $stats['distribuzione']['ottimo']++;
            }
        }

        return $stats;
    }
}
