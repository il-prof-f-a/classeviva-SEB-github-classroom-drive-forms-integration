<?php

namespace App\Models;

/**
 * UDA Model - Rappresenta una singola Unità di Apprendimento.
 *
 * Le sue proprietà corrispondono alle colonne del foglio 'UDA_ANAGRAFICA'.
 */
class UDA
{
    public ?string $id_uda;
    public ?string $titolo;
    public ?string $argomento;
    public ?string $descrizione;
    public ?string $progetto;
    public ?string $metodologia;
    public ?int $durata_ore;
    public ?string $note;
    public ?string $classi_target;
    public ?string $stato;
    public ?string $disciplina;
    public ?string $data_inizio;
    public ?string $data_fine;
    public ?string $anno_scolastico;
    public ?string $data_creazione;
    public ?string $ultima_modifica;

    /**
     * Metodo factory per creare un'istanza di UDA da un array di dati (es. una riga Excel).
     */
    public static function fromArray(array $data): self
    {
        $uda = new self();
        $uda->id_uda = $data['id_uda'] ?? null;
        $uda->titolo = $data['titolo'] ?? null;
        $uda->argomento = $data['argomento'] ?? null;
        $uda->descrizione = $data['descrizione'] ?? null;
        $uda->progetto = $data['progetto'] ?? null;
        $uda->metodologia = $data['metodologia'] ?? null;
        $uda->durata_ore = isset($data['durata_ore']) ? (int)$data['durata_ore'] : null;
        $uda->note = $data['note'] ?? null;
        $uda->classi_target = $data['classi_target'] ?? null;
        $uda->stato = $data['stato'] ?? null;
        $uda->disciplina = $data['disciplina'] ?? ($data['materia'] ?? null);
        $uda->data_inizio = $data['data_inizio'] ?? null;
        $uda->data_fine = $data['data_fine'] ?? null;
        $uda->anno_scolastico = $data['anno_scolastico'] ?? null;
        $uda->data_creazione = $data['data_creazione'] ?? null;
        $uda->ultima_modifica = $data['ultima_modifica'] ?? null;

        return $uda;
    }
}
