<?php

namespace App\Models;

/**
 * Rubrica - Rappresenta una rubrica di valutazione
 *
 * Una rubrica è composta da indicatori, ciascuno con:
 * - Nome
 * - Descrizione
 * - Peso
 * - 5 livelli con descrizioni
 *
 * La rubrica può essere compilata per uno studente, selezionando
 * un livello per ogni indicatore e calcolando il voto finale.
 */
class Rubrica
{
    public ?string $id_rubrica;
    public ?string $id_uda;
    public ?string $nome_rubrica;
    public ?string $tipo_valutazione; // orale, presentazione, laboratorio
    public array $indicatori; // Array di indicatori

    /**
     * Struttura di un indicatore:
     * [
     *   'nome' => 'ESPOSIZIONE',
     *   'descrizione' => 'Capacità di esporre...',
     *   'peso' => 4,
     *   'ordine' => 1,
     *   'tipo' => 'fisso', // fisso o contenuto
     *   'livelli' => [
     *     1 => ['descrizione' => '...', 'punteggio' => 0.0],
     *     2 => ['descrizione' => '...', 'punteggio' => 0.25],
     *     3 => ['descrizione' => '...', 'punteggio' => 0.5],
     *     4 => ['descrizione' => '...', 'punteggio' => 0.75],
     *     5 => ['descrizione' => '...', 'punteggio' => 1.0],
     *   ]
     * ]
     */

    public ?array $valutazione_compilata; // Array con livelli selezionati per studente
    /**
     * Struttura valutazione compilata:
     * [
     *   'id_studente' => 'STUD_001',
     *   'nome_studente' => 'Mario Rossi',
     *   'data_valutazione' => '2025-11-05',
     *   'livelli_selezionati' => [
     *     'ESPOSIZIONE' => 3,
     *     'MODO DI ESPRIMERSI' => 4,
     *     'argomento_1' => 5,
     *     ...
     *   ],
     *   'voto_calcolato' => 7.5,
     *   'giudizio' => 'L'alunno ha dimostrato...'
     * ]
     */

    public ?float $voto_calcolato;
    public ?string $giudizio_generato;

    /**
     * Crea una Rubrica da array
     */
    public static function fromArray(array $data): self
    {
        $rubrica = new self();
        $rubrica->id_rubrica = $data['id_rubrica'] ?? null;
        $rubrica->id_uda = $data['id_uda'] ?? null;
        $rubrica->nome_rubrica = $data['nome_rubrica'] ?? null;
        $rubrica->tipo_valutazione = $data['tipo_valutazione'] ?? 'orale';

        // Decodifica indicatori se sono JSON
        $rubrica->indicatori = isset($data['indicatori']) && is_string($data['indicatori'])
            ? json_decode($data['indicatori'], true)
            : ($data['indicatori'] ?? []);

        // Decodifica valutazione compilata se è JSON
        $rubrica->valutazione_compilata = isset($data['valutazione_compilata']) && is_string($data['valutazione_compilata'])
            ? json_decode($data['valutazione_compilata'], true)
            : ($data['valutazione_compilata'] ?? null);

        $rubrica->voto_calcolato = isset($data['voto_calcolato']) ? (float)$data['voto_calcolato'] : null;
        $rubrica->giudizio_generato = $data['giudizio_generato'] ?? null;

        return $rubrica;
    }

    /**
     * Converte la Rubrica in array per il database
     */
    public function toArray(): array
    {
        return [
            'id_rubrica' => $this->id_rubrica,
            'id_uda' => $this->id_uda,
            'nome_rubrica' => $this->nome_rubrica,
            'tipo_valutazione' => $this->tipo_valutazione,
            'indicatori' => is_array($this->indicatori)
                ? json_encode($this->indicatori, JSON_UNESCAPED_UNICODE)
                : $this->indicatori,
            'valutazione_compilata' => is_array($this->valutazione_compilata)
                ? json_encode($this->valutazione_compilata, JSON_UNESCAPED_UNICODE)
                : $this->valutazione_compilata,
            'voto_calcolato' => $this->voto_calcolato,
            'giudizio_generato' => $this->giudizio_generato
        ];
    }

    /**
     * Calcola il voto in base ai livelli selezionati
     *
     * Formula: somma(livello_percentuale × peso) / somma(pesi) × 10
     *
     * Dove:
     * - livello_percentuale: 0.0 (liv1), 0.25 (liv2), 0.5 (liv3), 0.75 (liv4), 1.0 (liv5)
     * - peso: peso dell'indicatore
     *
     * @param array $livelliSelezionati Array associativo ['nome_indicatore' => livello]
     * @return float Voto calcolato in scala 0-10
     */
    public function calcolaVoto(array $livelliSelezionati): float
    {
        $punteggioTotale = 0;
        $pesoTotale = 0;

        foreach ($this->indicatori as $indicatore) {
            $nomeIndicatore = $indicatore['nome'];
            $peso = $indicatore['peso'];

            // Verifica se il livello è stato selezionato per questo indicatore
            if (!isset($livelliSelezionati[$nomeIndicatore])) {
                continue;
            }

            $livelloSelezionato = (int)$livelliSelezionati[$nomeIndicatore];

            // Calcola la percentuale in base al livello (1-5)
            // Livello 1 = 0%, 2 = 25%, 3 = 50%, 4 = 75%, 5 = 100%
            $percentuali = [
                1 => 0.0,
                2 => 0.25,
                3 => 0.5,
                4 => 0.75,
                5 => 1.0
            ];

            $percentuale = $percentuali[$livelloSelezionato] ?? 0;

            $punteggioTotale += $percentuale * $peso;
            $pesoTotale += $peso;
        }

        if ($pesoTotale === 0) {
            return 0;
        }

        $voto = ($punteggioTotale / $pesoTotale) * 10;

        // Arrotonda a 0.25 (quarti di voto)
        $voto = round($voto * 4) / 4;

        return $voto;
    }

    /**
     * Genera un giudizio automatico in base ai livelli selezionati
     *
     * @param array $livelliSelezionati Array associativo ['nome_indicatore' => livello]
     * @return string Giudizio testuale
     */
    public function generaGiudizio(array $livelliSelezionati): string
    {
        $giudizio = [];

        // Parole chiave per ogni livello
        $paroleChiave = [
            1 => ['gravemente insufficiente', 'confuso', 'non adeguato', 'frammentario'],
            2 => ['insufficiente', 'incerto', 'impreciso', 'con difficoltà'],
            3 => ['sufficiente', 'corretto', 'essenziale', 'accettabile'],
            4 => ['buono', 'approfondito', 'sicuro', 'completo'],
            5 => ['ottimo', 'eccellente', 'brillante', 'molto approfondito']
        ];

        // Analizza gli indicatori principali (non contenuti)
        foreach ($this->indicatori as $indicatore) {
            if ($indicatore['tipo'] === 'fisso') {
                $nomeIndicatore = $indicatore['nome'];
                $livello = $livelliSelezionati[$nomeIndicatore] ?? 3;

                // Prendi la descrizione del livello selezionato
                $descrizione = $indicatore['livelli'][$livello]['descrizione'] ?? '';

                // Estrai una frase chiave dalla descrizione
                if ($nomeIndicatore === 'ESPOSIZIONE') {
                    $giudizio[] = "Esposizione: " . $this->semplificaDescrizione($descrizione, $livello);
                } elseif ($nomeIndicatore === 'MODO DI ESPRIMERSI') {
                    $giudizio[] = "Comunicazione: " . $this->semplificaDescrizione($descrizione, $livello);
                } elseif (strpos($nomeIndicatore, 'ORGANIZZAZIONE') !== false) {
                    $giudizio[] = "Organizzazione: " . $this->semplificaDescrizione($descrizione, $livello);
                }
            }
        }

        // Analizza i contenuti
        $contenutiGiudizi = [];
        foreach ($this->indicatori as $indicatore) {
            if ($indicatore['tipo'] === 'contenuto') {
                $nomeIndicatore = $indicatore['nome'];
                $livello = $livelliSelezionati[$nomeIndicatore] ?? 3;

                $valutazione = $paroleChiave[$livello][0] ?? 'sufficiente';
                $contenutiGiudizi[] = "$nomeIndicatore: $valutazione";
            }
        }

        if (!empty($contenutiGiudizi)) {
            $giudizio[] = "Contenuti - " . implode('; ', $contenutiGiudizi) . ".";
        }

        return implode(' ', $giudizio);
    }

    /**
     * Semplifica una descrizione lunga in una frase breve
     */
    private function semplificaDescrizione(string $descrizione, int $livello): string
    {
        // Prende le prime 80 caratteri o fino al primo punto
        $frase = strtok($descrizione, '.');
        if (strlen($frase) > 80) {
            $frase = substr($frase, 0, 80) . '...';
        }
        return strtolower($frase);
    }

    /**
     * Esporta la rubrica compilata come array per Excel
     */
    public function esportaPerExcel(): array
    {
        $export = [
            'nome_rubrica' => $this->nome_rubrica,
            'tipo_valutazione' => $this->tipo_valutazione,
            'studente' => $this->valutazione_compilata['nome_studente'] ?? '',
            'data' => $this->valutazione_compilata['data_valutazione'] ?? date('Y-m-d'),
            'voto' => $this->voto_calcolato,
            'giudizio' => $this->giudizio_generato,
            'indicatori' => []
        ];

        foreach ($this->indicatori as $indicatore) {
            $nomeIndicatore = $indicatore['nome'];
            $livelloSelezionato = $this->valutazione_compilata['livelli_selezionati'][$nomeIndicatore] ?? null;

            $export['indicatori'][] = [
                'nome' => $nomeIndicatore,
                'peso' => $indicatore['peso'],
                'livello_selezionato' => $livelloSelezionato,
                'descrizione_livello' => $livelloSelezionato
                    ? ($indicatore['livelli'][$livelloSelezionato]['descrizione'] ?? '')
                    : ''
            ];
        }

        return $export;
    }
}
