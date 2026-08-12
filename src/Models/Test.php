<?php

namespace App\Models;

/**
 * Test - Rappresenta un test (prerequisiti, intermedio, finale)
 */
class Test
{
    public ?string $id_test;
    public ?string $id_uda;
    public ?string $tipo; // prerequisiti, intermedio, finale
    public ?string $nome;
    public ?string $descrizione;
    public ?string $piattaforma; // kahoot, google_forms, custom
    public ?string $url_kahoot;
    public ?string $kahoot_id;
    public ?string $url_gform;
    public ?string $gform_id;
    public ?string $data_somministrazione;
    public ?bool $pubblicato;
    public ?int $num_domande;

    public static function fromArray(array $data): self
    {
        $test = new self();
        $test->id_test = $data['id_test'] ?? null;
        $test->id_uda = $data['id_uda'] ?? null;
        $test->tipo = $data['tipo'] ?? null;
        $test->nome = $data['nome'] ?? null;
        $test->descrizione = $data['descrizione'] ?? null;
        $test->piattaforma = $data['piattaforma'] ?? null;
        $test->url_kahoot = $data['url_kahoot'] ?? null;
        $test->kahoot_id = $data['kahoot_id'] ?? null;
        $test->url_gform = $data['url_gform'] ?? null;
        $test->gform_id = $data['gform_id'] ?? null;
        $test->data_somministrazione = $data['data_somministrazione'] ?? null;
        $test->pubblicato = isset($data['pubblicato']) ? (bool)$data['pubblicato'] : false;
        $test->num_domande = isset($data['num_domande']) ? (int)$data['num_domande'] : null;

        return $test;
    }

    public function toArray(): array
    {
        return [
            'id_test' => $this->id_test,
            'id_uda' => $this->id_uda,
            'tipo' => $this->tipo,
            'nome' => $this->nome,
            'descrizione' => $this->descrizione,
            'piattaforma' => $this->piattaforma,
            'url_kahoot' => $this->url_kahoot,
            'kahoot_id' => $this->kahoot_id,
            'url_gform' => $this->url_gform,
            'gform_id' => $this->gform_id,
            'data_somministrazione' => $this->data_somministrazione,
            'pubblicato' => $this->pubblicato ? 1 : 0,
            'num_domande' => $this->num_domande
        ];
    }
}
