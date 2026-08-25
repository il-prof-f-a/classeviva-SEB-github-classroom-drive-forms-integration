<?php

namespace App\Core;

/**
 * SchemaDefinitions - Definisce la struttura di tutti i fogli del database
 *
 * Questa classe contiene le definizioni dei fogli e delle loro colonne.
 * Usato dagli adapter SQL per creare automaticamente tabelle mancanti.
 */
class SchemaDefinitions
{
    private static ?array $columnsCache = null;

    /**
     * Ritorna la definizione di tutti i fogli del database
     *
     * Formato:
     * [
     *   'NOME_FOGLIO' => [
     *     'columns' => ['col1', 'col2', ...],
     *     'description' => 'Descrizione del foglio'
     *   ]
     * ]
     */
    public static function getAllSheets(): array
    {
        $sheets = [
            'UDA_ANAGRAFICA' => [
                'columns' => [
                    'id_uda', 'titolo', 'descrizione', 'materia', 'classe',
                    'anno_scolastico', 'data_inizio', 'data_fine', 'ore_previste',
                    'competenze', 'abilita', 'conoscenze', 'metodologie',
                    'strumenti', 'valutazione', 'stato', 'data_creazione',
                    'ultima_modifica',
                    // Proprietario UDA (utente che l'ha creata)
                    'id_utente_owner',
                    // Id utente standard per scoping generico
                    'id_utente'
                ],
                'description' => 'Anagrafica UDA - Dati principali delle Unità di Apprendimento'
            ],

            'SCHEMA_MIGRATIONS' => [
                'columns' => [
                    'versione', 'descrizione', 'applicata_il', 'checksum'
                ],
                'description' => 'Registro delle migrazioni dello schema SQL'
            ],

            'GRUPPI_DIDATTICI' => [
                'columns' => [
                    'id_gruppo', 'nome_gruppo', 'nome_classe', 'nome_materia',
                    'anno_scolastico', 'descrizione', 'stato', 'data_creazione',
                    'ultima_modifica', 'id_utente'
                ],
                'description' => 'Gruppi didattici interni indipendenti dai provider'
            ],

            'GRUPPI_INTEGRAZIONI' => [
                'columns' => [
                    'id_collegamento', 'id_gruppo', 'provider', 'tipo_risorsa',
                    'external_context_id', 'external_subject_id', 'external_name',
                    'principale', 'stato', 'metadata_json', 'data_creazione',
                    'ultima_modifica', 'id_utente'
                ],
                'description' => 'Collegamenti dei gruppi ai provider esterni'
            ],

            'UDA_GRUPPI' => [
                'columns' => [
                    'id_assegnazione', 'id_uda', 'id_gruppo', 'data_assegnazione',
                    'data_inizio', 'data_fine', 'note', 'stato', 'id_utente'
                ],
                'description' => 'Assegnazioni UDA a gruppi didattici'
            ],

            'UDA_PUBBLICAZIONI' => [
                'columns' => [
                    'id_pubblicazione', 'id_uda', 'id_gruppo', 'provider',
                    'external_resource_id', 'external_url', 'stato',
                    'data_pubblicazione', 'metadata_json', 'id_utente',
                    'material_id', 'material_url'
                ],
                'description' => 'Pubblicazioni UDA verso provider esterni'
            ],

            'STUDENTI' => [
                'columns' => [
                    'id_studente', 'stato', 'data_creazione', 'ultima_modifica',
                    'id_utente'
                ],
                'description' => 'Studenti interni senza dati anagrafici persistiti'
            ],

            'STUDENTI_IDENTITA_ESTERNE' => [
                'columns' => [
                    'id_identita', 'id_studente', 'provider', 'external_user_id',
                    'external_context_id', 'tipo_identificatore', 'stato',
                    'data_prima_associazione', 'ultima_verifica', 'metadata_json',
                    'id_utente'
                ],
                'description' => 'Identità studente sui provider esterni'
            ],

            'GRUPPI_STUDENTI' => [
                'columns' => [
                    'id_iscrizione', 'id_gruppo', 'id_studente', 'provider_origine',
                    'external_context_id', 'stato', 'data_inizio', 'data_fine',
                    'ultima_sincronizzazione', 'id_utente'
                ],
                'description' => 'Membership interne dei gruppi didattici'
            ],

            'STUDENTI_RISORSE_ESTERNE' => [
                'columns' => [
                    'id_risorsa', 'id_studente', 'provider', 'external_context_id',
                    'external_resource_id', 'external_url', 'tipo_risorsa',
                    'metadata_json', 'data_creazione', 'ultima_modifica', 'id_utente'
                ],
                'description' => 'Risorse esterne associate agli studenti'
            ],

            'MATERIALI' => [
                'columns' => [
                    'id_materiale', 'id_uda', 'tipo', 'titolo', 'descrizione',
                    'url', 'file_path', 'data_caricamento', 'ordine', 'id_utente'
                ],
                'description' => 'Materiali didattici associati alle UDA'
            ],

            'OBIETTIVI' => [
                'columns' => [
                    'id_obiettivo', 'id_uda', 'codice', 'descrizione',
                    'tassonomia_bloom', 'tipo', 'ordine', 'id_utente'
                ],
                'description' => 'Obiettivi didattici delle UDA'
            ],

            'OBIETTIVI_MASTER' => [
                'columns' => [
                    'id_obiettivo', 'codice', 'descrizione', 'tassonomia_bloom',
                    'materia', 'anno', 'data_creazione', 'stato', 'id_utente'
                ],
                'description' => 'Database master obiettivi didattici riutilizzabili'
            ],

            'INDICATORI_LABORATORIO' => [
                'columns' => [
                    'id_indicatore', 'id_categoria', 'codice', 'nome',
                    'descrizione', 'livello_tassonomia', 'peso', 'ordine', 'attivo',
                    'id_utente'
                ],
                'description' => 'Indicatori per valutazione laboratorio'
            ],

            'CATEGORIE_COMPETENZE' => [
                'columns' => [
                    'id_categoria', 'codice', 'nome', 'descrizione',
                    'colore', 'ordine', 'attiva', 'id_utente'
                ],
                'description' => 'Categorie di competenze per laboratorio'
            ],

            'VALUTAZIONI_LABORATORIO' => [
                'columns' => [
                    'id_valutazione', 'id_uda', 'id_gruppo', 'id_studente',
                    'id_indicatore', 'nome_indicatore', 'valore',
                    'data_inserimento', 'data_registrazione', 'id_annotazione_cv',
                    'commento', 'prof', 'id_utente'
                ],
                'description' => 'Valutazioni laboratorio registrate (oltre 2h da PLUSMINUS_QUEUE)'
            ],

            'PLUSMINUS_QUEUE' => [
                'columns' => [
                    'id_evidenza', 'id_uda', 'id_gruppo', 'id_studente',
                    'id_indicatore', 'nome_indicatore', 'valore',
                    'data_inserimento', 'registrato', 'data_registrazione',
                    'id_annotazione_cv', 'commento', 'prof', 'id_utente'
                ],
                'description' => 'Coda evidenze PiùOMeno con finestra modificabile 2h'
            ],

            'VOTI' => [
                'columns' => [
                    'id_voto', 'id_uda', 'id_gruppo', 'id_studente',
                    'tipo_voto', 'voto', 'giudizio',
                    'descrizione', 'data_valutazione', 'data_creazione',
                    'pubblicato', 'provider_pubblicazione', 'external_publication_id', 'num_evidenze_positive',
                    'num_evidenze_negative', 'num_evidenze_totali', 'id_utente',
                    'link_origine'
                ],
                'description' => 'Voti aggregati (rubrica orale, laboratorio)'
            ],

            'RUBRICA' => [
                'columns' => [
                    'id_rubrica', 'id_uda', 'nome_indicatore', 'descrizione',
                    'livello_1_desc', 'livello_2_desc', 'livello_3_desc',
                    'livello_4_desc', 'livello_5_desc', 'peso', 'ordine', 'note',
                    'pubblicato', 'data_pubblicazione', 'id_annotazione_cv', 'id_utente'
                ],
                'description' => 'Indicatori di rubrica orale e obiettivi'
            ],
            'VALUTAZIONI_RUBRICA' => [
                'columns' => [
                    'id_valutazione', 'id_uda', 'id_rubrica', 'id_gruppo', 'id_studente',
                    'data_valutazione', 'voto_finale',
                    'giudizio', 'note', 'pubblicato', 'id_annotazione_cv',
                    'voto_numerico', 'pubblicato_cv', 'dati_json',
                    'id_utente'
                ],
                'description' => 'Valutazioni rubrica orale'
            ],

            'RUBRICA_DETTAGLI' => [
                'columns' => [
                    'id_dettaglio', 'id_valutazione', 'id_obiettivo',
                    'nome_obiettivo', 'livello', 'punteggio', 'note', 'id_utente'
                ],
                'description' => 'Dettagli valutazione rubrica per obiettivo'
            ],

            'DOMANDE_INTERROGAZIONE' => [
                'columns' => [
                    'id_domanda', 'id_uda', 'argomento', 'domanda',
                    'risposta_attesa', 'parole_chiave', 'difficolta',
                    'tempo_risposta_min', 'ordine_consigliato', 'tipo_domanda',
                    'note', 'data_creazione', 'id_utente'
                ],
                'description' => 'Domande tipo per interrogazioni orali'
            ],

            'TEST' => [
                'columns' => [
                    'id_test',
                    'id_uda',
                    'id_gruppo',
                    'tipo_test',
                    'nome',
                    'descrizione',
                    'piattaforma',
                    'url',
                    'id_esterno',
                    'num_domande',
                    'durata_minuti',
                    'punteggio_max',
                    'soglia_sufficienza',
                    'data_creazione',
                    'data_somministrazione',
                    'pubblicato',
                    'risultati_importati',
                    'note',
                    'id_utente',
                    'url_gestione',
                    'url_studenti',
                    'url_docente',
                    'classroom_course_id',
                    'classroom_assignment_id',
                    'classroom_topic_id',
                    'classroom_url',
                    'allegati_json',
                    'ora_consegna',
                    'cbm_enabled',
                    'cbm_levels_json',
                    'cbm_scoring_model',
                    'cbm_form_config_json',
                    'github_classroom_id',
                    'github_assignment_id',
                    'url_assignment_student',
                    'url_assignment_teacher',
                    'repo_default_branch',
                    'github_config_json'
                ],
                'description' => 'Test e verifiche (Kahoot, Google Forms, ecc.)'
            ],

            'TEST_CBM_MAPPING' => [
                'columns' => [
                    'id_mapping',
                    'id_test',
                    'id_domanda',
                    'form_item_id',
                    'confidence_item_id',
                    'ordine',
                    'punteggio_domanda',
                    'max_score',
                    'config_json',
                    'id_utente'
                ],
                'description' => 'Mapping domanda/confidenza per test CBM (Google Forms e affini)'
            ],

            'TEST_CBM_RISPOSTE' => [
                'columns' => [
                    'id_risposta',
                    'id_test',
                    'id_domanda',
                    'id_gruppo',
                    'id_studente',
                    'google_response_id',
                    'domanda_label',
                    'confidenza_livello',
                    'confidenza_valore',
                    'corretta',
                    'score_cba',
                    'score_classico',
                    'penalita',
                    'punteggio_normalizzato',
                    'timestamp_risposta',
                    'raw_json',
                    'id_utente'
                ],
                'description' => 'Risposte e punteggi CBM per singolo studente/domanda'
            ],

            'GITHUB_ASSIGNMENTS' => [
                'columns' => [
                    'id_assignment', 'id_uda', 'id_classroom_map',
                    'github_assignment_id', 'assignment_name', 'assignment_type',
                    'invitation_link', 'slug', 'deadline', 'starter_code_url',
                    'max_teams', 'max_members', 'auto_grading_config',
                    'pubblicato_gc', 'data_creazione', 'data_pubblicazione',
                    'stato', 'note', 'id_utente'
                ],
                'description' => 'Assignment GitHub Classroom collegati alle UDA'
            ],

            'GITHUB_SUBMISSIONS' => [
                'columns' => [
                    'id_submission', 'id_assignment', 'id_studente',
                    'repository_url', 'accepted_at',
                    'last_commit_at', 'status', 'grade', 'feedback', 'id_utente'
                ],
                'description' => 'Tracciamento submission studenti GitHub (futuro)'
            ],

            'GITHUB_ASSIGNMENT_STUDENT_LINKS' => [
                'columns' => [
                    'id_map',
                    'id_assignment',
                    'student_repository_url',
                    'id_studente',
                    'acceptance_code',
                    'github_username',
                    'accepted_at',
                    'match_confidence',
                    'note',
                    'data_creazione',
                    'id_utente'
                ],
                'description' => 'Associazione interna studente ↔ repository GitHub per assignment'
            ],

            'GITHUB_REPO_LOC_SNAPSHOTS' => [
                'columns' => [
                    'id_snapshot',
                    'id_test',
                    'repo_full_name',
                    'repo_html_url',
                    'ref',
                    'default_branch',
                    'id_studente',
                    'loc_total',
                    'loc_code',
                    'loc_comment',
                    'loc_blank',
                    'loc_json',
                    'source',
                    'data_creazione',
                    'id_utente'
                ],
                'description' => 'Snapshot LOC repository GitHub (per assignment)'
            ],

            'GITHUB_REPO_TEMPLATES' => [
                'columns' => [
                    'id_template', 'nome', 'url_repository', 'descrizione',
                    'visibilita', 'linguaggio', 'categoria', 'data_creazione',
                    'ultima_modifica', 'attivo', 'note', 'id_utente'
                ],
                'description' => 'Repository template GitHub per assignment'
            ],
            'UTENTI' => [
                'columns' => [
                    'id_utente',
                    'email',
                    'nome',
                    'cognome',
                    'ruolo',
                    'google_id',
                    'created_at',
                    'last_login_at',
                    'stato',
                    'mostra_guida',
                    'privacy_consent_given',
                    'privacy_consent_date',
                    'privacy_consent_ip',
                    'privacy_consent_token',
                    'privacy_consent_version',
                    'marketing_consent',
                    'marketing_consent_date'
                ],
                'description' => 'Utenti del portale (docenti, admin) con tracciamento consensi privacy GDPR'
            ],

            'INTEGRAZIONI_UTENTE' => [
                'columns' => [
                    'id_integrazione',
                    'id_utente',
                    'provider',      // es: google, classeviva, github, kahoot
                    'config_json',   // JSON con dati specifici del provider
                    'created_at',
                    'updated_at',
                    'attivo'
                ],
                'description' => 'Dati di integrazione per utente (token OAuth, credenziali ClasseViva, ecc.)'
            ],

            'UDA_CONDIVISIONI' => [
                'columns' => [
                    'id_condivisione',
                    'id_uda',
                    'id_utente',
                    'permesso',      // view | edit
                    'created_at'
                ],
                'description' => 'Condivisione UDA tra utenti del portale'
            ],

            'INVITI_UDA' => [
                'columns' => [
                    'id_invito',
                    'id_uda',
                    'email_invitato',
                    'id_utente_invitato',
                    'token',
                    'permesso',
                    'stato',         // pending | accepted | expired | revoked
                    'expires_at',
                    'created_at',
                    'id_utente'
                ],
                'description' => 'Inviti a collaborare su una UDA tramite link'
            ]
        ];
        $columnsMap = self::loadSchemaColumnsMap();
        $canonicalTables = [
            'SCHEMA_MIGRATIONS', 'GRUPPI_DIDATTICI', 'GRUPPI_INTEGRAZIONI',
            'UDA_GRUPPI', 'UDA_PUBBLICAZIONI', 'STUDENTI',
            'STUDENTI_IDENTITA_ESTERNE', 'GRUPPI_STUDENTI',
            'STUDENTI_RISORSE_ESTERNE', 'GITHUB_ASSIGNMENT_STUDENT_LINKS',
        ];
        if (!empty($columnsMap)) {
            foreach ($sheets as $sheetName => &$definition) {
                $upper = strtoupper($sheetName);
                if (!in_array($upper, $canonicalTables, true)
                    && isset($columnsMap[$upper])
                    && is_array($columnsMap[$upper])
                    && !empty($columnsMap[$upper])) {
                    $definition['columns'] = array_values(array_unique(array_merge(
                        $definition['columns'],
                        $columnsMap[$upper]
                    )));
                }
            }
            unset($definition);
        }
        return $sheets;
    }

    /**
     * Ritorna la definizione di un singolo foglio
     */
    public static function getSheetDefinition(string $sheetName): ?array
    {
        $sheets = self::getAllSheets();
        return $sheets[$sheetName] ?? null;
    }

    /**
     * Verifica se un foglio è definito nello schema
     */
    public static function isSheetDefined(string $sheetName): bool
    {
        $sheets = self::getAllSheets();
        return isset($sheets[$sheetName]);
    }

    /**
     * Ritorna le colonne di un foglio
     */
    public static function getSheetColumns(string $sheetName): ?array
    {
        $definition = self::getSheetDefinition($sheetName);
        return $definition['columns'] ?? null;
    }

    private static function loadSchemaColumnsMap(): array
    {
        if (self::$columnsCache !== null) {
            return self::$columnsCache;
        }

        $path = self::getSchemaColumnsFilePath();
        if (!file_exists($path)) {
            return self::$columnsCache = [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return self::$columnsCache = [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return self::$columnsCache = [];
        }

        $normalized = [];
        foreach ($data as $table => $columns) {
            $normalized[strtoupper($table)] = array_values((array)$columns);
        }

        // Durante la transizione al dominio provider-neutral non permettere
        // che il file di override reintroduca chiavi provider-specifiche o PII
        // nelle tabelle di dominio. Gli ID esterni restano ammessi solo nelle
        // tabelle GRUPPI_INTEGRAZIONI e STUDENTI_IDENTITA_ESTERNE.
        $providerTables = ['GRUPPI_INTEGRAZIONI', 'STUDENTI_IDENTITA_ESTERNE'];
        $forbiddenDomainColumns = [
            'id_studente_cv', 'id_studente_gc', 'id_classe_cv', 'id_materia_cv',
            'nome_studente', 'email_studente', 'github_username', 'roster_identifier',
        ];
        foreach ($normalized as $table => &$columns) {
            if (in_array($table, $providerTables, true)) {
                continue;
            }
            $columns = array_values(array_diff($columns, $forbiddenDomainColumns));
        }
        unset($columns);

        return self::$columnsCache = $normalized;
    }

    private static function getSchemaColumnsFilePath(): string
    {
        return dirname(dirname(__DIR__)) . '/database/schema_columns.json';
    }
}
