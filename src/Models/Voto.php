<?php

namespace App\Models;

/**
 * Voto - Rappresenta una valutazione di uno studente
 */
class Voto
{
    public ?string $id_voto;
    public ?string $id_uda;
    public ?string $id_studente;
    public ?string $nome_studente;
    public ?string $cognome_studente;
    public ?string $id_classe;
    public ?string $tipo_valutazione; // orale, scritto, pratico, test
    public ?float $voto;
    public ?string $giudizio;
    public ?string $data_valutazione;
    public ?bool $pubblicato_registro;
    public ?string $file_rubrica; // Path al file Excel della rubrica compilata
    public ?array $dettaglio_rubrica; // Array con i punteggi per indicatore

    public static function fromArray(array $data): self
    {
        $voto = new self();
        $voto->id_voto = $data['id_voto'] ?? null;
        $voto->id_uda = $data['id_uda'] ?? null;
        $voto->id_studente = $data['id_studente'] ?? null;
        $voto->nome_studente = $data['nome_studente'] ?? null;
        $voto->cognome_studente = $data['cognome_studente'] ?? null;
        $voto->id_classe = $data['id_classe'] ?? null;
        $voto->tipo_valutazione = $data['tipo_valutazione'] ?? null;
        $voto->voto = isset($data['voto']) ? (float)$data['voto'] : null;
        $voto->giudizio = $data['giudizio'] ?? null;
        $voto->data_valutazione = $data['data_valutazione'] ?? null;
        $voto->pubblicato_registro = isset($data['pubblicato_registro']) ? (bool)$data['pubblicato_registro'] : false;
        $voto->file_rubrica = $data['file_rubrica'] ?? null;
        $voto->dettaglio_rubrica = isset($data['dettaglio_rubrica']) && is_string($data['dettaglio_rubrica'])
            ? json_decode($data['dettaglio_rubrica'], true)
            : ($data['dettaglio_rubrica'] ?? null);

        return $voto;
    }

    public function toArray(): array
    {
        return [
            'id_voto' => $this->id_voto,
            'id_uda' => $this->id_uda,
            'id_studente' => $this->id_studente,
            'nome_studente' => $this->nome_studente,
            'cognome_studente' => $this->cognome_studente,
            'id_classe' => $this->id_classe,
            'tipo_valutazione' => $this->tipo_valutazione,
            'voto' => $this->voto,
            'giudizio' => $this->giudizio,
            'data_valutazione' => $this->data_valutazione,
            'pubblicato_registro' => $this->pubblicato_registro ? 1 : 0,
            'file_rubrica' => $this->file_rubrica,
            'dettaglio_rubrica' => is_array($this->dettaglio_rubrica)
                ? json_encode($this->dettaglio_rubrica)
                : $this->dettaglio_rubrica
        ];
    }
}
