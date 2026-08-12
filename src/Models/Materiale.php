<?php

namespace App\Models;

/**
 * Materiale - Rappresenta un materiale didattico dell'UDA
 */
class Materiale
{
    public ?string $id_materiale;
    public ?string $id_uda;
    public ?string $tipo; // documento, presentazione, video, link, altro
    public ?string $nome;
    public ?string $descrizione;
    public ?string $url_drive;
    public ?string $file_id_drive;
    public ?string $local_path;
    public ?bool $generato_ai;
    public ?string $data_creazione;
    public ?string $data_modifica;

    public static function fromArray(array $data): self
    {
        $materiale = new self();
        $materiale->id_materiale = $data['id_materiale'] ?? null;
        $materiale->id_uda = $data['id_uda'] ?? null;
        $materiale->tipo = $data['tipo'] ?? null;
        $materiale->nome = $data['nome'] ?? null;
        $materiale->descrizione = $data['descrizione'] ?? null;
        $materiale->url_drive = $data['url_drive'] ?? null;
        $materiale->file_id_drive = $data['file_id_drive'] ?? null;
        $materiale->local_path = $data['local_path'] ?? null;
        $materiale->generato_ai = isset($data['generato_ai']) ? (bool)$data['generato_ai'] : false;
        $materiale->data_creazione = $data['data_creazione'] ?? null;
        $materiale->data_modifica = $data['data_modifica'] ?? null;

        return $materiale;
    }

    public function toArray(): array
    {
        return [
            'id_materiale' => $this->id_materiale,
            'id_uda' => $this->id_uda,
            'tipo' => $this->tipo,
            'nome' => $this->nome,
            'descrizione' => $this->descrizione,
            'url_drive' => $this->url_drive,
            'file_id_drive' => $this->file_id_drive,
            'local_path' => $this->local_path,
            'generato_ai' => $this->generato_ai ? 1 : 0,
            'data_creazione' => $this->data_creazione,
            'data_modifica' => $this->data_modifica
        ];
    }
}
