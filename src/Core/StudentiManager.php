<?php

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use App\Integration\ClasseVivaAPI;
use Exception;

/**
 * StudentiManager - Gestisce studenti da ClasseViva
 *
 * APPROCCIO GDPR-COMPLIANT:
 * - Salva SOLO ID studente ClasseViva e riferimento classe nel database locale
 * - Nome e cognome recuperati on-demand da ClasseViva API
 * - Nessun dato personale salvato permanentemente
 *
 * Funzionalità:
 * - Sincronizzazione studenti da ClasseViva
 * - Cache ID studenti per velocizzare interfacce
 * - Recupero dati personali on-demand
 * - Gestione studenti per classe e UDA
 */
class StudentiManager
{
    private DatabaseAdapterInterface $db;
    private ClasseVivaAPI $api;
    private array $config;

    // Cache in-memory per ridurre chiamate API
    private array $studentiCache = [];

    public function __construct(DatabaseAdapterInterface $db, ClasseVivaAPI $api, array $config)
    {
        $this->db = $db;
        $this->api = $api;
        $this->config = $config;
    }

    /**
     * Sincronizza studenti di una classe da ClasseViva
     *
     * Salva solo ID studente e riferimento classe (GDPR-compliant)
     * Nome e cognome non salvati, recuperati on-demand
     *
     * @param string $idClasseCV ID classe ClasseViva
     * @param string $nomeClasse Nome classe (es: "3C")
     * @return array Statistiche sync: ['sincronizzati' => n, 'nuovi' => n, 'disattivati' => n]
     */
    public function sincronizzaStudentiClasse(string $idClasseCV, string $nomeClasse): array
    {
        try {
            // Recupera studenti da ClasseViva
            $studentiCV = $this->api->getStudentiClasse($idClasseCV);

            $stats = [
                'sincronizzati' => 0,
                'nuovi' => 0,
                'disattivati' => 0
            ];

            // Recupera studenti esistenti per questa classe
            $studentiLocali = $this->db->findAll('STUDENTI');
            $studentiClasseLocali = array_filter($studentiLocali, fn($s) => ($s['id_classe_cv'] ?? '') === $idClasseCV);

            // Mappa studenti locali per ID
            $studentiLocaliMap = [];
            foreach ($studentiClasseLocali as $s) {
                $studentiLocaliMap[$s['id_studente_cv'] ?? ''] = $s;
            }

            // ID studenti da ClasseViva
            $idStudentiCV = array_map(fn($s) => $s['id'], $studentiCV);

            // Aggiungi/aggiorna studenti da ClasseViva
            foreach ($studentiCV as $studenteCV) {
                $idStudenteCV = $studenteCV['id'];

                $dati = [
                    'id_studente_cv' => $idStudenteCV,
                    'id_classe_cv' => $idClasseCV,
                    'nome_classe' => $nomeClasse,
                    'data_sincronizzazione' => date('Y-m-d H:i:s'),
                    'attivo' => 1
                ];

                if (isset($studentiLocaliMap[$idStudenteCV])) {
                    // Studente esiste, aggiorna
                    $this->db->updateRow('STUDENTI', ['id_studente_cv' => $idStudenteCV], $dati);
                } else {
                    // Studente nuovo, inserisci
                    $this->db->insertRow('STUDENTI', $dati);
                    $stats['nuovi']++;
                }

                $stats['sincronizzati']++;
            }

            // Disattiva studenti non più presenti in ClasseViva
            foreach ($studentiLocaliMap as $idStudenteCV => $studenteLocale) {
                if (!in_array($idStudenteCV, $idStudentiCV)) {
                    $this->db->updateRow('STUDENTI', ['id_studente_cv' => $idStudenteCV], ['attivo' => 0]);
                    $stats['disattivati']++;
                }
            }

            // Pulisci cache
            unset($this->studentiCache[$idClasseCV]);

            return $stats;

        } catch (Exception $e) {
            throw new Exception("Errore sincronizzazione studenti classe $idClasseCV: " . $e->getMessage());
        }
    }

    /**
     * Sincronizza tutte le classi del docente
     *
     * @return array Statistiche totali sync
     */
    public function sincronizzaTutteLeClassi(): array
    {
        try {
            // Recupera tutte le classi del docente
            $classi = $this->api->getClasses();

            $statsGlobali = [
                'classi_sincronizzate' => 0,
                'studenti_sincronizzati' => 0,
                'nuovi' => 0,
                'disattivati' => 0
            ];

            foreach ($classi as $classe) {
                $idClasseCV = $classe['id'] ?? $classe['classId'] ?? '';
                $nomeClasse = $classe['name'] ?? $classe['className'] ?? $idClasseCV;

                $stats = $this->sincronizzaStudentiClasse($idClasseCV, $nomeClasse);

                $statsGlobali['classi_sincronizzate']++;
                $statsGlobali['studenti_sincronizzati'] += $stats['sincronizzati'];
                $statsGlobali['nuovi'] += $stats['nuovi'];
                $statsGlobali['disattivati'] += $stats['disattivati'];
            }

            return $statsGlobali;

        } catch (Exception $e) {
            throw new Exception("Errore sincronizzazione tutte le classi: " . $e->getMessage());
        }
    }

    /**
     * Recupera studenti di una classe con nomi da ClasseViva
     *
     * @param string $idClasseCV ID classe ClasseViva
     * @param bool $soloAttivi Se true, solo studenti attivi
     * @return array Array di studenti con id, nome, cognome
     */
    public function getStudentiClasse(string $idClasseCV, bool $soloAttivi = true): array
    {
        // Controlla cache
        $cacheKey = $idClasseCV . '_' . ($soloAttivi ? 'attivi' : 'tutti');
        if (isset($this->studentiCache[$cacheKey])) {
            return $this->studentiCache[$cacheKey];
        }

        try {
            // Recupera studenti locali
            $studentiLocali = $this->db->findAll('STUDENTI');
            $studentiClasse = array_filter($studentiLocali, function ($s) use ($idClasseCV, $soloAttivi) {
                $stessaClasse = ($s['id_classe_cv'] ?? '') === $idClasseCV;
                $attivo = !$soloAttivi || ($s['attivo'] ?? 0) == 1;
                return $stessaClasse && $attivo;
            });

            if (empty($studentiClasse)) {
                // Nessuno studente sincronizzato, prova a sincronizzare ora
                $nomeClasse = 'Classe ' . $idClasseCV;
                $this->sincronizzaStudentiClasse($idClasseCV, $nomeClasse);

                // Ricarica
                $studentiLocali = $this->db->findAll('STUDENTI');
                $studentiClasse = array_filter($studentiLocali, function ($s) use ($idClasseCV, $soloAttivi) {
                    $stessaClasse = ($s['id_classe_cv'] ?? '') === $idClasseCV;
                    $attivo = !$soloAttivi || ($s['attivo'] ?? 0) == 1;
                    return $stessaClasse && $attivo;
                });
            }

            // Recupera nomi da ClasseViva API (on-demand, GDPR-compliant)
            $studentiConNomi = [];
            foreach ($studentiClasse as $studenteLocale) {
                $idStudenteCV = $studenteLocale['id_studente_cv'] ?? '';

                try {
                    // Recupera dati personali da API ClasseViva
                    $studenteCV = $this->api->getStudente($idStudenteCV);

                    $studentiConNomi[] = [
                        'id' => $idStudenteCV,
                        'nome' => $studenteCV['nome'] ?? '',
                        'cognome' => $studenteCV['cognome'] ?? '',
                        'nome_completo' => ($studenteCV['cognome'] ?? '') . ' ' . ($studenteCV['nome'] ?? ''),
                        'id_classe_cv' => $studenteLocale['id_classe_cv'] ?? '',
                        'nome_classe' => $studenteLocale['nome_classe'] ?? ''
                    ];
                } catch (Exception $e) {
                    // Se API fallisce, usa placeholder
                    error_log("Errore recupero dati studente $idStudenteCV: " . $e->getMessage());
                    $studentiConNomi[] = [
                        'id' => $idStudenteCV,
                        'nome' => 'N/D',
                        'cognome' => 'Studente ' . substr($idStudenteCV, -4),
                        'nome_completo' => 'Studente ' . substr($idStudenteCV, -4),
                        'id_classe_cv' => $studenteLocale['id_classe_cv'] ?? '',
                        'nome_classe' => $studenteLocale['nome_classe'] ?? ''
                    ];
                }
            }

            // Ordina per cognome
            usort($studentiConNomi, fn($a, $b) => strcmp($a['cognome'], $b['cognome']));

            // Salva in cache
            $this->studentiCache[$cacheKey] = $studentiConNomi;

            return $studentiConNomi;

        } catch (Exception $e) {
            throw new Exception("Errore recupero studenti classe: " . $e->getMessage());
        }
    }

    /**
     * Recupera studente singolo con dati personali da ClasseViva
     *
     * @param string $idStudenteCV ID studente ClasseViva
     * @return array|null Dati studente o null se non trovato
     */
    public function getStudente(string $idStudenteCV): ?array
    {
        try {
            $studenteCV = $this->api->getStudente($idStudenteCV);

            return [
                'id' => $idStudenteCV,
                'nome' => $studenteCV['nome'] ?? '',
                'cognome' => $studenteCV['cognome'] ?? '',
                'nome_completo' => ($studenteCV['cognome'] ?? '') . ' ' . ($studenteCV['nome'] ?? '')
            ];

        } catch (Exception $e) {
            error_log("Errore recupero studente $idStudenteCV: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ottiene lista classi sincronizzate
     *
     * @return array Array di classi uniche con conteggio studenti
     */
    public function getClassiSincronizzate(): array
    {
        $studentiLocali = $this->db->findAll('STUDENTI');

        $classiMap = [];
        foreach ($studentiLocali as $studente) {
            $idClasse = $studente['id_classe_cv'] ?? '';
            $nomeClasse = $studente['nome_classe'] ?? '';
            $attivo = ($studente['attivo'] ?? 0) == 1;

            if (!isset($classiMap[$idClasse])) {
                $classiMap[$idClasse] = [
                    'id_classe_cv' => $idClasse,
                    'nome_classe' => $nomeClasse,
                    'studenti_attivi' => 0,
                    'studenti_disattivati' => 0
                ];
            }

            if ($attivo) {
                $classiMap[$idClasse]['studenti_attivi']++;
            } else {
                $classiMap[$idClasse]['studenti_disattivati']++;
            }
        }

        return array_values($classiMap);
    }

    /**
     * Verifica se uno studente esiste ed è attivo
     *
     * @param string $idStudenteCV ID studente ClasseViva
     * @return bool True se studente esiste ed è attivo
     */
    public function studenteEsiste(string $idStudenteCV): bool
    {
        $studentiLocali = $this->db->findAll('STUDENTI');

        foreach ($studentiLocali as $studente) {
            if (($studente['id_studente_cv'] ?? '') === $idStudenteCV && ($studente['attivo'] ?? 0) == 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pulisce cache in-memory
     */
    public function clearCache(): void
    {
        $this->studentiCache = [];
    }
}
