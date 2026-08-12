<?php

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Core\ObiettiviManager;
use App\Models\Obiettivo;

/**
 * QuestionGenerator - Genera automaticamente domande basate su obiettivi didattici
 *
 * Utilizza la tassonomia di Bloom per creare domande appropriate al livello cognitivo:
 * - Livello 1 (Ricordare): Definizioni, liste, riconoscimento
 * - Livello 2 (Comprendere): Spiegazioni, parafrasi, esempi
 * - Livello 3 (Applicare): Problemi pratici, casi d'uso
 * - Livello 4 (Analizzare): Confronti, cause-effetti, classificazioni
 * - Livello 5 (Valutare): Giudizi critici, scelte motivate
 * - Livello 6 (Creare): Progettazione, composizione, generazione
 */
class QuestionGenerator
{
    private DatabaseAdapterInterface $db;
    private ObiettiviManager $obiettiviManager;
    private array $config;

    // Template domande per livello Bloom
    private const TEMPLATE_DOMANDE = [
        1 => [ // Ricordare
            'aperta' => [
                "Definisci {argomento}",
                "Elenca i principali {argomento}",
                "Quali sono le caratteristiche di {argomento}?",
                "Identifica {argomento}",
                "Descrivi brevemente {argomento}"
            ],
            'multipla' => [
                "Quale delle seguenti è la definizione corretta di {argomento}?",
                "Quale tra questi è un esempio di {argomento}?",
                "Quale delle seguenti affermazioni su {argomento} è vera?",
            ],
            'vero_falso' => [
                "{argomento} è definito come...",
                "Le caratteristiche principali di {argomento} includono...",
                "{argomento} fa parte di..."
            ]
        ],
        2 => [ // Comprendere
            'aperta' => [
                "Spiega con parole tue {argomento}",
                "Qual è la differenza tra {argomento1} e {argomento2}?",
                "Fornisci un esempio pratico di {argomento}",
                "Riassumi i concetti chiave di {argomento}",
                "Illustra il funzionamento di {argomento}"
            ],
            'multipla' => [
                "Quale delle seguenti spiega meglio {argomento}?",
                "Un esempio di {argomento} nella pratica è:",
                "Quale affermazione descrive correttamente {argomento}?"
            ],
            'vero_falso' => [
                "{argomento} può essere spiegato come...",
                "Un esempio di {argomento} è...",
                "{argomento} funziona attraverso..."
            ]
        ],
        3 => [ // Applicare
            'aperta' => [
                "Applica {argomento} per risolvere questo problema: {contesto}",
                "Come utilizzeresti {argomento} in {contesto}?",
                "Dimostra l'uso di {argomento} in un caso pratico",
                "Implementa una soluzione usando {argomento}",
                "Risolvi questo esercizio applicando {argomento}"
            ],
            'multipla' => [
                "In quale situazione applicheresti {argomento}?",
                "Quale soluzione usa correttamente {argomento}?",
                "Quale dei seguenti è il modo corretto di applicare {argomento}?"
            ],
            'vero_falso' => [
                "{argomento} si applica in questo contesto...",
                "Per risolvere questo problema si usa {argomento} in questo modo...",
                "Questa soluzione applica correttamente {argomento}..."
            ]
        ],
        4 => [ // Analizzare
            'aperta' => [
                "Analizza le componenti di {argomento}",
                "Confronta {argomento1} e {argomento2}, evidenziando similitudini e differenze",
                "Quali sono le cause principali di {argomento}?",
                "Esamina la relazione tra {argomento1} e {argomento2}",
                "Scomponi {argomento} nei suoi elementi costitutivi"
            ],
            'multipla' => [
                "Quale delle seguenti è la principale differenza tra {argomento1} e {argomento2}?",
                "Quale componente di {argomento} è responsabile di...?",
                "Quale delle seguenti analisi di {argomento} è corretta?"
            ],
            'vero_falso' => [
                "La principale differenza tra {argomento1} e {argomento2} è...",
                "{argomento} può essere scomposto in questi elementi...",
                "La relazione tra {argomento1} e {argomento2} è di tipo..."
            ]
        ],
        5 => [ // Valutare
            'aperta' => [
                "Valuta i pro e i contro di {argomento}",
                "Giustifica la scelta di {argomento} rispetto ad alternative",
                "Critica l'approccio proposto in {argomento}",
                "Quale soluzione consiglieresti per {contesto}? Motiva la tua scelta",
                "Esprimi un giudizio motivato su {argomento}"
            ],
            'multipla' => [
                "Quale delle seguenti è la critica più valida a {argomento}?",
                "Quale soluzione è preferibile in questo contesto?",
                "Quale dei seguenti giudizi su {argomento} è più corretto?"
            ],
            'vero_falso' => [
                "Il principale vantaggio di {argomento} è...",
                "{argomento} è preferibile a {alternativa} perché...",
                "La critica principale a {argomento} è..."
            ]
        ],
        6 => [ // Creare
            'aperta' => [
                "Progetta un {argomento} che soddisfi questi requisiti: {contesto}",
                "Crea una soluzione innovativa per {argomento}",
                "Sviluppa un modello per {argomento}",
                "Proponi un nuovo approccio a {argomento}",
                "Componi un {argomento} originale che integri {argomento1} e {argomento2}"
            ],
            'multipla' => [
                "Quale delle seguenti è la soluzione più innovativa per {argomento}?",
                "Quale progetto integra meglio {argomento1} e {argomento2}?",
                "Quale approccio creativo sarebbe più efficace per {argomento}?"
            ],
            'vero_falso' => [
                "Un approccio innovativo a {argomento} potrebbe essere...",
                "Questo progetto per {argomento} è originale perché...",
                "Una soluzione creativa per {argomento} è..."
            ]
        ]
    ];

    // Verbi chiave per livello Bloom (per analisi testuale)
    private const VERBI_BLOOM = [
        1 => ['definire', 'elencare', 'identificare', 'descrivere', 'nominare', 'riconoscere', 'ricordare'],
        2 => ['spiegare', 'interpretare', 'riassumere', 'classificare', 'confrontare', 'comprendere', 'illustrare'],
        3 => ['applicare', 'utilizzare', 'implementare', 'risolvere', 'dimostrare', 'eseguire', 'operare'],
        4 => ['analizzare', 'scomporre', 'distinguere', 'esaminare', 'confrontare', 'categorizzare', 'relazionare'],
        5 => ['valutare', 'giudicare', 'criticare', 'giustificare', 'argomentare', 'raccomandare', 'difendere'],
        6 => ['creare', 'progettare', 'costruire', 'sviluppare', 'inventare', 'comporre', 'formulare']
    ];

    public function __construct(DatabaseAdapterInterface $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->obiettiviManager = new ObiettiviManager($db, $config);
    }

    /**
     * Genera domande automaticamente per una UDA basandosi sui suoi obiettivi
     *
     * @param string $idUda ID della UDA
     * @param array $opzioni Opzioni di generazione:
     *   - num_domande_per_obiettivo: numero domande per obiettivo (default: 3)
     *   - tipi_domanda: array di tipi ['aperta', 'multipla', 'vero_falso'] (default: tutti)
     *   - solo_livelli_bloom: array di livelli Bloom da considerare (default: tutti)
     *   - salva_database: bool, se salvare nel database (default: true)
     * @return array Statistiche generazione: ['generate' => int, 'salvate' => int, 'domande' => array]
     */
    public function generaDomandePerUDA(string $idUda, array $opzioni = []): array
    {
        // Opzioni default
        $numDomandePerObiettivo = $opzioni['num_domande_per_obiettivo'] ?? 3;
        $tipiDomanda = $opzioni['tipi_domanda'] ?? ['aperta', 'multipla', 'vero_falso'];
        $soloLivellBloom = $opzioni['solo_livelli_bloom'] ?? null;
        $salvaSuDB = $opzioni['salva_database'] ?? true;

        // Recupera obiettivi UDA
        $obiettivi = $this->obiettiviManager->getObiettiviPerUDA($idUda);

        if (empty($obiettivi)) {
            return [
                'generate' => 0,
                'salvate' => 0,
                'errore' => 'Nessun obiettivo trovato per questa UDA',
                'domande' => []
            ];
        }

        $domandeGenerate = [];
        $domandeSalvate = 0;

        foreach ($obiettivi as $obiettivo) {
            $livelloBloom = (int)($obiettivo->livello_tassonomia ?? 2);

            // Filtra per livelli Bloom se specificato
            if ($soloLivellBloom && !in_array($livelloBloom, $soloLivellBloom)) {
                continue;
            }

            // Genera domande per questo obiettivo
            $domande = $this->generaDomandePerObiettivo(
                $obiettivo,
                $numDomandePerObiettivo,
                $tipiDomanda,
                $livelloBloom
            );

            foreach ($domande as $domanda) {
                $domandeGenerate[] = $domanda;

                // Salva nel database se richiesto
                if ($salvaSuDB) {
                    $idDomanda = $this->salvaDomanda($idUda, $domanda, $obiettivo->id_obiettivo);
                    if ($idDomanda) {
                        $domandeSalvate++;
                        $domanda['id_domanda'] = $idDomanda;
                    }
                }
            }
        }

        return [
            'generate' => count($domandeGenerate),
            'salvate' => $domandeSalvate,
            'domande' => $domandeGenerate
        ];
    }

    /**
     * Genera domande per un singolo obiettivo
     */
    private function generaDomandePerObiettivo(
        Obiettivo $obiettivo,
        int $numDomande,
        array $tipiDomanda,
        int $livelloBloom
    ): array {
        $domande = [];
        $argomento = $this->estraiArgomento($obiettivo);

        // Distribuisci domande tra i tipi richiesti
        $tipiDisponibili = array_intersect($tipiDomanda, ['aperta', 'multipla', 'vero_falso']);
        $numTipi = count($tipiDisponibili);

        if ($numTipi === 0) {
            return [];
        }

        $domandePerTipo = (int)ceil($numDomande / $numTipi);

        foreach ($tipiDisponibili as $tipo) {
            for ($i = 0; $i < $domandePerTipo && count($domande) < $numDomande; $i++) {
                $domanda = $this->generaSingolaDomanda($tipo, $livelloBloom, $argomento, $obiettivo);
                if ($domanda) {
                    $domande[] = $domanda;
                }
            }
        }

        return $domande;
    }

    /**
     * Genera una singola domanda
     */
    private function generaSingolaDomanda(
        string $tipo,
        int $livelloBloom,
        string $argomento,
        Obiettivo $obiettivo
    ): ?array {
        $templates = self::TEMPLATE_DOMANDE[$livelloBloom][$tipo] ?? [];

        if (empty($templates)) {
            return null;
        }

        // Seleziona template casuale
        $template = $templates[array_rand($templates)];

        // Sostituisci placeholder
        $testoDomanda = $this->applicaTemplate($template, $argomento, $obiettivo);

        $domanda = [
            'testo' => $testoDomanda,
            'tipo' => $tipo,
            'livello_bloom' => $livelloBloom,
            'livello_bloom_desc' => Obiettivo::LIVELLI_BLOOM[$livelloBloom] ?? 'N/D',
            'argomento' => $argomento,
            'obiettivo_riferimento' => $obiettivo->descrizione ?? '',
            'punti_suggeriti' => $this->calcolaPuntiSuggeriti($tipo, $livelloBloom)
        ];

        // Aggiungi opzioni per domande a scelta multipla
        if ($tipo === 'multipla') {
            $domanda['opzioni'] = $this->generaOpzioniMultipla($argomento, $livelloBloom);
        }

        return $domanda;
    }

    /**
     * Applica template sostituendo placeholder
     */
    private function applicaTemplate(string $template, string $argomento, Obiettivo $obiettivo): string
    {
        $testo = str_replace('{argomento}', $argomento, $template);

        // Altri placeholder
        $testo = str_replace('{competenza}', $obiettivo->competenza ?? 'la competenza', $testo);
        $testo = str_replace('{contesto}', 'il contesto del progetto', $testo);

        // Argomenti multipli per domande comparative (genera varianti)
        $testo = str_replace('{argomento1}', $argomento, $testo);
        $testo = str_replace('{argomento2}', 'alternative a ' . $argomento, $testo);
        $testo = str_replace('{alternativa}', 'soluzioni alternative', $testo);

        return $testo;
    }

    /**
     * Estrae l'argomento principale dalla descrizione dell'obiettivo
     */
    private function estraiArgomento(Obiettivo $obiettivo): string
    {
        $descrizione = $obiettivo->descrizione ?? '';

        // Cerca parole chiave se disponibili
        if (!empty($obiettivo->parole_chiave)) {
            $parole = explode(',', $obiettivo->parole_chiave);
            return trim($parole[0]);
        }

        // Altrimenti usa la descrizione abbreviata
        $parole = explode(' ', $descrizione);

        // Rimuovi verbi Bloom comuni all'inizio
        $tuttiVerbi = [];
        foreach (self::VERBI_BLOOM as $verbi) {
            $tuttiVerbi = array_merge($tuttiVerbi, $verbi);
        }

        while (!empty($parole) && in_array(strtolower($parole[0]), $tuttiVerbi)) {
            array_shift($parole);
        }

        // Prendi le prime 3-5 parole come argomento
        $argomento = implode(' ', array_slice($parole, 0, 5));

        return $argomento ?: 'l\'argomento';
    }

    /**
     * Genera opzioni per domanda a scelta multipla
     */
    private function generaOpzioniMultipla(string $argomento, int $livelloBloom): array
    {
        // Questa è una versione semplificata
        // In un sistema più avanzato, si potrebbero usare AI o database di distrattori
        return [
            ['testo' => '[Opzione corretta - da completare manualmente]', 'corretta' => true],
            ['testo' => '[Opzione errata 1 - da completare manualmente]', 'corretta' => false],
            ['testo' => '[Opzione errata 2 - da completare manualmente]', 'corretta' => false],
            ['testo' => '[Opzione errata 3 - da completare manualmente]', 'corretta' => false],
        ];
    }

    /**
     * Calcola punti suggeriti per la domanda
     */
    private function calcolaPuntiSuggeriti(string $tipo, int $livelloBloom): int
    {
        $base = match($tipo) {
            'aperta' => 5,
            'multipla' => 2,
            'vero_falso' => 1,
            default => 3
        };

        // Aumenta punti per livelli Bloom più alti
        $moltiplicatore = match($livelloBloom) {
            1 => 1.0,
            2 => 1.2,
            3 => 1.5,
            4 => 1.8,
            5 => 2.0,
            6 => 2.5,
            default => 1.0
        };

        return (int)ceil($base * $moltiplicatore);
    }

    /**
     * Salva domanda nel database
     */
    private function salvaDomanda(string $idUda, array $domanda, string $idObiettivo): ?string
    {
        $idDomanda = 'DOM_' . uniqid();

        $dati = [
            'id_domanda' => $idDomanda,
            'id_uda' => $idUda,
            'id_obiettivo' => $idObiettivo,
            'testo_domanda' => $domanda['testo'],
            'tipo_domanda' => $domanda['tipo'],
            'livello_bloom' => $domanda['livello_bloom'],
            'argomento' => $domanda['argomento'],
            'punti' => $domanda['punti_suggeriti'],
            'opzioni_json' => isset($domanda['opzioni']) ? json_encode($domanda['opzioni']) : null,
            'generata_auto' => 1,
            'data_creazione' => date('Y-m-d H:i:s')
        ];

        try {
            $this->db->insertRow('DOMANDE_INTERROGAZIONE', $dati);
            return $idDomanda;
        } catch (\Exception $e) {
            error_log("Errore salvataggio domanda: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Genera test completo con domande bilanciate per livelli Bloom
     */
    public function generaTestBilanciato(
        string $idUda,
        int $numDomandeTarget = 20,
        array $distribuzioneBloom = []
    ): array {
        // Distribuzione default: privilegia livelli intermedi
        if (empty($distribuzioneBloom)) {
            $distribuzioneBloom = [
                1 => 0.15, // 15% Ricordare
                2 => 0.20, // 20% Comprendere
                3 => 0.25, // 25% Applicare
                4 => 0.20, // 20% Analizzare
                5 => 0.15, // 15% Valutare
                6 => 0.05  // 5% Creare
            ];
        }

        $domandePerLivello = [];
        foreach ($distribuzioneBloom as $livello => $percentuale) {
            $num = (int)round($numDomandeTarget * $percentuale);
            if ($num > 0) {
                $domandePerLivello[$livello] = $num;
            }
        }

        // Genera domande per ogni livello
        $tutteLeDomande = [];
        foreach ($domandePerLivello as $livello => $num) {
            $risultato = $this->generaDomandePerUDA($idUda, [
                'num_domande_per_obiettivo' => $num,
                'solo_livelli_bloom' => [$livello],
                'salva_database' => false // Non salvare ancora
            ]);

            $tutteLeDomande = array_merge($tutteLeDomande, $risultato['domande']);
        }

        // Mischia e prendi esattamente il numero target
        shuffle($tutteLeDomande);
        $testFinale = array_slice($tutteLeDomande, 0, $numDomandeTarget);

        return [
            'num_domande' => count($testFinale),
            'domande' => $testFinale,
            'distribuzione' => $this->analizzaDistribuzione($testFinale)
        ];
    }

    /**
     * Analizza distribuzione livelli Bloom in un set di domande
     */
    private function analizzaDistribuzione(array $domande): array
    {
        $distribuzione = array_fill(1, 6, 0);

        foreach ($domande as $domanda) {
            $livello = $domanda['livello_bloom'] ?? 2;
            $distribuzione[$livello]++;
        }

        return $distribuzione;
    }

    /**
     * Genera statistiche sulle domande generate per una UDA
     */
    public function getStatisticheDomandeUDA(string $idUda): array
    {
        $domande = $this->db->findAll('DOMANDE_INTERROGAZIONE');
        $domandeUDA = array_filter($domande, fn($d) => ($d['id_uda'] ?? '') === $idUda);

        $stats = [
            'totale' => count($domandeUDA),
            'per_tipo' => ['aperta' => 0, 'multipla' => 0, 'vero_falso' => 0],
            'per_livello_bloom' => array_fill(1, 6, 0),
            'generate_auto' => 0,
            'manuali' => 0
        ];

        foreach ($domandeUDA as $domanda) {
            $tipo = $domanda['tipo_domanda'] ?? 'aperta';
            $stats['per_tipo'][$tipo] = ($stats['per_tipo'][$tipo] ?? 0) + 1;

            $livello = (int)($domanda['livello_bloom'] ?? 2);
            $stats['per_livello_bloom'][$livello]++;

            if (!empty($domanda['generata_auto'])) {
                $stats['generate_auto']++;
            } else {
                $stats['manuali']++;
            }
        }

        return $stats;
    }
}
