<?php

namespace App\Models;

/**
 * Classe - Rappresenta una classe scolastica
 */
class Classe
{
    public ?string $id_classe;
    public ?string $nome_classe;
    public ?string $anno_scolastico;
    public ?string $id_classroom; // ID del corso Google Classroom
    public ?int $num_studenti;
    public ?array $studenti; // Array di studenti

    public static function fromArray(array $data): self
    {
        $classe = new self();
        $classe->id_classe = $data['id_classe'] ?? null;
        $classe->nome_classe = $data['nome_classe'] ?? null;
        $classe->anno_scolastico = $data['anno_scolastico'] ?? null;
        $classe->id_classroom = $data['id_classroom'] ?? null;
        $classe->num_studenti = isset($data['num_studenti']) ? (int)$data['num_studenti'] : null;
        $classe->studenti = $data['studenti'] ?? [];

        return $classe;
    }

    public function toArray(): array
    {
        return [
            'id_classe' => $this->id_classe,
            'nome_classe' => $this->nome_classe,
            'anno_scolastico' => $this->anno_scolastico,
            'id_classroom' => $this->id_classroom,
            'num_studenti' => $this->num_studenti,
            'studenti' => $this->studenti
        ];
    }
}
