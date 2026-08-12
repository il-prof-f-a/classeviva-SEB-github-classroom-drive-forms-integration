<?php

namespace App\Models;

/**
 * Obiettivo - Rappresenta un obiettivo didattico/disciplinare
 *
 * Allineato alla struttura del foglio OBIETTIVI nel database Excel:
 * - id_obiettivo
 * - id_uda (null per obiettivi master)
 * - tipo_obiettivo (conoscenze, abilità, competenze)
 * - codice (codice ministeriale o custom)
 * - descrizione
 * - competenza
 * - livello_tassonomia (1-6 Bloom)
 * - peso (per calcolo valutazione)
 * - raggiunto (0/1)
 * - note
 */
class Obiettivo
{
    public ?string $id_obiettivo;
    public ?string $id_uda; // null = obiettivo master riutilizzabile
    public ?string $tipo_obiettivo; // conoscenze, abilità, competenze, competenza_trasversale, competenza_digitale
    public ?string $codice; // Codice ministeriale o custom
    public ?string $descrizione;
    public ?string $competenza;
    public ?int $livello_tassonomia; // 1-6 (Ricordare, Comprendere, Applicare, Analizzare, Valutare, Creare)
    public ?int $peso; // Per calcolo valutazione
    public ?int $raggiunto; // 0 = non raggiunto, 1 = raggiunto
    public ?string $note;

    // Campi aggiuntivi per obiettivi master
    public ?string $area_disciplinare; // Es: Informatica, Matematica, Italiano
    public ?string $parole_chiave; // Per ricerca
    public ?bool $riutilizzabile; // Se può essere riutilizzato in altre UDA

    /**
     * Livelli tassonomia di Bloom
     */
    public const LIVELLI_BLOOM = [
        1 => 'Ricordare',
        2 => 'Comprendere',
        3 => 'Applicare',
        4 => 'Analizzare',
        5 => 'Valutare',
        6 => 'Creare'
    ];

    /**
     * Tipi di obiettivo supportati
     */
    public const TIPI_OBIETTIVO = [
        'conoscenze' => 'Conoscenze (Sapere)',
        'abilità' => 'Abilità (Saper fare)',
        'competenze' => 'Competenze (Saper essere)',
        'competenza_trasversale' => 'Competenza Trasversale',
        'competenza_digitale' => 'Competenza Digitale'
    ];

    public static function fromArray(array $data): self
    {
        $obiettivo = new self();
        $obiettivo->id_obiettivo = $data['id_obiettivo'] ?? null;
        $obiettivo->id_uda = $data['id_uda'] ?? null;

        // Compatibilità con vecchio campo 'tipo'
        $obiettivo->tipo_obiettivo = $data['tipo_obiettivo'] ?? $data['tipo'] ?? null;

        $obiettivo->codice = $data['codice'] ?? null;
        $obiettivo->descrizione = $data['descrizione'] ?? null;
        $obiettivo->competenza = $data['competenza'] ?? null;

        // Compatibilità con vecchio campo 'livello_bloom'
        $obiettivo->livello_tassonomia = isset($data['livello_tassonomia'])
            ? (int)$data['livello_tassonomia']
            : (isset($data['livello_bloom']) ? (int)$data['livello_bloom'] : null);

        $obiettivo->peso = isset($data['peso']) ? (int)$data['peso'] : null;
        $obiettivo->raggiunto = isset($data['raggiunto']) ? (int)$data['raggiunto'] : 0;
        $obiettivo->note = $data['note'] ?? null;

        // Campi aggiuntivi
        $obiettivo->area_disciplinare = $data['area_disciplinare'] ?? null;
        $obiettivo->parole_chiave = $data['parole_chiave'] ?? null;
        $obiettivo->riutilizzabile = isset($data['riutilizzabile']) ? (bool)$data['riutilizzabile'] : true;

        return $obiettivo;
    }

    public function toArray(): array
    {
        return [
            'id_obiettivo' => $this->id_obiettivo,
            'id_uda' => $this->id_uda,
            'tipo_obiettivo' => $this->tipo_obiettivo,
            'codice' => $this->codice,
            'descrizione' => $this->descrizione,
            'competenza' => $this->competenza,
            'livello_tassonomia' => $this->livello_tassonomia,
            'peso' => $this->peso,
            'raggiunto' => $this->raggiunto,
            'note' => $this->note
        ];
    }

    /**
     * Converte per il foglio OBIETTIVI_MASTER (con campi aggiuntivi)
     */
    public function toMasterArray(): array
    {
        return array_merge($this->toArray(), [
            'area_disciplinare' => $this->area_disciplinare,
            'parole_chiave' => $this->parole_chiave,
            'riutilizzabile' => $this->riutilizzabile ? 1 : 0
        ]);
    }

    /**
     * Restituisce la descrizione del livello Bloom
     */
    public function getLivelloBloomDescrizione(): string
    {
        return self::LIVELLI_BLOOM[$this->livello_tassonomia] ?? 'Non specificato';
    }

    /**
     * Restituisce la descrizione del tipo obiettivo
     */
    public function getTipoObiettivoDescrizione(): string
    {
        return self::TIPI_OBIETTIVO[$this->tipo_obiettivo] ?? $this->tipo_obiettivo;
    }

    /**
     * Clona l'obiettivo per una nuova UDA
     */
    public function clonaPerUDA(string $nuovoUdaId): self
    {
        $clone = clone $this;
        $clone->id_obiettivo = 'OBT_' . uniqid();
        $clone->id_uda = $nuovoUdaId;
        $clone->raggiunto = 0;
        return $clone;
    }
}
