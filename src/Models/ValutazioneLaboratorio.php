<?php

namespace App\Models;

/**
 * ValutazioneLaboratorio - Rappresenta una valutazione laboratorio con sistema +/-
 *
 * Struttura:
 * - Una valutazione è legata a UDA + Attività + Studente
 * - Contiene multiple evidenze (+ o -) per diversi indicatori
 * - Il voto viene calcolato dal rapporto evidenze positive/totali pesato
 */
class ValutazioneLaboratorio
{
    public ?string $id_valutazione;
    public ?string $id_uda;
    public ?string $id_attivita; // ID materiale o test
    public ?string $id_studente;
    public ?string $nome_studente;
    public ?string $id_classe;
    public ?string $data_valutazione;
    public ?float $voto_calcolato;
    public ?bool $pubblicato;
    public ?string $data_pubblicazione;
    public ?string $id_voto_classeviva;

    /**
     * Evidenze: array di valutazioni per indicatore
     * [
     *   'IND_001' => [
     *     'id_indicatore' => 'IND_001',
     *     'nome_indicatore' => 'Uso delle informazioni',
     *     'valore' => '+',  // '+' o '-'
     *     'peso' => 3,
     *     'commento' => 'Ha utilizzato bene...'
     *   ],
     *   ...
     * ]
     */
    public array $evidenze;

    public static function fromArray(array $data): self
    {
        $valutazione = new self();
        $valutazione->id_valutazione = $data['id_valutazione'] ?? null;
        $valutazione->id_uda = $data['id_uda'] ?? null;
        $valutazione->id_attivita = $data['id_attivita'] ?? null;
        $valutazione->id_studente = $data['id_studente'] ?? null;
        $valutazione->nome_studente = $data['nome_studente'] ?? null;
        $valutazione->id_classe = $data['id_classe'] ?? null;
        $valutazione->data_valutazione = $data['data_valutazione'] ?? null;
        $valutazione->voto_calcolato = isset($data['voto_calcolato']) ? (float)$data['voto_calcolato'] : null;
        $valutazione->pubblicato = isset($data['pubblicato']) ? (bool)$data['pubblicato'] : false;
        $valutazione->data_pubblicazione = $data['data_pubblicazione'] ?? null;
        $valutazione->id_voto_classeviva = $data['id_voto_classeviva'] ?? null;

        $valutazione->evidenze = isset($data['evidenze']) && is_string($data['evidenze'])
            ? json_decode($data['evidenze'], true)
            : ($data['evidenze'] ?? []);

        return $valutazione;
    }

    public function toArray(): array
    {
        return [
            'id_valutazione' => $this->id_valutazione,
            'id_uda' => $this->id_uda,
            'id_attivita' => $this->id_attivita,
            'id_studente' => $this->id_studente,
            'nome_studente' => $this->nome_studente,
            'id_classe' => $this->id_classe,
            'data_valutazione' => $this->data_valutazione,
            'voto_calcolato' => $this->voto_calcolato,
            'pubblicato' => $this->pubblicato ? 1 : 0,
            'data_pubblicazione' => $this->data_pubblicazione,
            'id_voto_classeviva' => $this->id_voto_classeviva,
            'evidenze' => is_array($this->evidenze)
                ? json_encode($this->evidenze, JSON_UNESCAPED_UNICODE)
                : $this->evidenze
        ];
    }

    /**
     * Calcola il voto in base alle evidenze
     *
     * Metodo semplice: percentuale di evidenze positive
     * Formula: (positivi / totali) * 10
     *
     * Metodo pesato: considera il peso degli indicatori
     * Formula: (somma(positivi * pesi) / somma(pesi)) * 10
     *
     * @param bool $pesato Se true usa il metodo pesato
     * @return float Voto in scala 0-10
     */
    public function calcolaVoto(bool $pesato = true): float
    {
        if (empty($this->evidenze)) {
            return 0;
        }

        if ($pesato) {
            return $this->calcolaVotoPesato();
        } else {
            return $this->calcolaVotoSemplice();
        }
    }

    /**
     * Calcolo semplice: conta +/- senza considerare i pesi
     */
    private function calcolaVotoSemplice(): float
    {
        $positivi = 0;
        $totali = count($this->evidenze);

        foreach ($this->evidenze as $evidenza) {
            if (($evidenza['valore'] ?? '') === '+') {
                $positivi++;
            }
        }

        if ($totali === 0) {
            return 0;
        }

        $percentuale = $positivi / $totali;
        $voto = $percentuale * 10;

        // Arrotonda a 0.25
        return round($voto * 4) / 4;
    }

    /**
     * Calcolo pesato: considera il peso di ogni indicatore
     */
    private function calcolaVotoPesato(): float
    {
        $punteggioPositivo = 0;
        $pesoTotale = 0;

        foreach ($this->evidenze as $evidenza) {
            $peso = (int)($evidenza['peso'] ?? 1);
            $pesoTotale += $peso;

            if (($evidenza['valore'] ?? '') === '+') {
                $punteggioPositivo += $peso;
            }
        }

        if ($pesoTotale === 0) {
            return 0;
        }

        $percentuale = $punteggioPositivo / $pesoTotale;
        $voto = $percentuale * 10;

        // Arrotonda a 0.25
        return round($voto * 4) / 4;
    }

    /**
     * Genera un giudizio sintetico basato sul voto
     */
    public function generaGiudizio(): string
    {
        $voto = $this->voto_calcolato ?? $this->calcolaVoto();

        if ($voto >= 9) {
            return 'Ottimo';
        } elseif ($voto >= 8) {
            return 'Distinto';
        } elseif ($voto >= 7) {
            return 'Buono';
        } elseif ($voto >= 6) {
            return 'Sufficiente';
        } else {
            return 'Insufficiente';
        }
    }

    /**
     * Genera un giudizio esteso con dettaglio evidenze
     */
    public function generaGiudizioEsteso(): string
    {
        $voto = $this->voto_calcolato ?? $this->calcolaVoto();
        $giudizio = $this->generaGiudizio();

        $positivi = 0;
        $negativi = 0;

        foreach ($this->evidenze as $evidenza) {
            if (($evidenza['valore'] ?? '') === '+') {
                $positivi++;
            } else {
                $negativi++;
            }
        }

        $totali = count($this->evidenze);

        $testo = "Valutazione attività laboratorio: $giudizio (voto: $voto/10). ";
        $testo .= "Evidenze positive: $positivi/$totali. ";

        // Aggiungi dettaglio indicatori negativi se presenti
        if ($negativi > 0) {
            $indicatoriNegativi = [];
            foreach ($this->evidenze as $evidenza) {
                if (($evidenza['valore'] ?? '') === '-') {
                    $nome = $evidenza['nome_indicatore'] ?? 'Indicatore';
                    $indicatoriNegativi[] = strtolower($nome);
                }
            }

            if (!empty($indicatoriNegativi)) {
                $testo .= "Da migliorare: " . implode(', ', $indicatoriNegativi) . ". ";
            }
        }

        // Aggiungi commenti se presenti
        $commenti = [];
        foreach ($this->evidenze as $evidenza) {
            if (!empty($evidenza['commento'])) {
                $commenti[] = $evidenza['commento'];
            }
        }

        if (!empty($commenti)) {
            $testo .= "Note: " . implode(' ', $commenti);
        }

        return trim($testo);
    }

    /**
     * Restituisce statistiche sulle evidenze
     */
    public function getStatistiche(): array
    {
        $positivi = 0;
        $negativi = 0;

        foreach ($this->evidenze as $evidenza) {
            if (($evidenza['valore'] ?? '') === '+') {
                $positivi++;
            } else {
                $negativi++;
            }
        }

        $totali = count($this->evidenze);
        $percentualePositivi = $totali > 0 ? ($positivi / $totali) * 100 : 0;

        return [
            'totali' => $totali,
            'positivi' => $positivi,
            'negativi' => $negativi,
            'percentuale_positivi' => round($percentualePositivi, 2),
            'voto' => $this->voto_calcolato ?? $this->calcolaVoto()
        ];
    }

    /**
     * Verifica se la valutazione è completa
     */
    public function isCompleta(): bool
    {
        return !empty($this->evidenze) && !empty($this->id_studente) && !empty($this->id_attivita);
    }

    /**
     * Verifica se può essere pubblicata
     */
    public function isPubblicabile(): bool
    {
        return $this->isCompleta() && !$this->pubblicato && $this->voto_calcolato !== null;
    }
}
