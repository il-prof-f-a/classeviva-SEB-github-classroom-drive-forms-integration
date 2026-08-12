<?php

namespace App\Core;

/**
 * SchemaDefinitions - Definisce la struttura di tutti i fogli del database
 *
 * Questa classe contiene le definizioni dei fogli e delle loro colonne.
 * Usato da DatabaseManager per creare automaticamente fogli mancanti.
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

            'CLASSI_ASSEGNATE' => [
                'columns' => [
                    'id_assegnazione', 'id_uda', 'id_classe', 'nome_classe',
                    'id_materia_cv', 'nome_materia', 'data_assegnazione',
                    'data_inizio', 'data_fine', 'note', 'pubblicato_classroom',
                    'classroom_url', 'stato', 'id_utente'
                ],
                'description' => 'Assegnazioni UDA a Classi+Materie'
            ],

            'STUDENTI' => [
                'columns' => [
                    'id_studente_cv', 'id_classe_cv', 'nome_classe',
                    'data_sincronizzazione', 'attivo', 'id_utente'
                ],
                'description' => 'Studenti sincronizzati da ClasseViva (solo ID, GDPR-compliant)'
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
                    'id_valutazione', 'id_uda', 'id_materia_cv', 'id_classe_cv',
                    'id_studente_cv', 'id_indicatore', 'nome_indicatore', 'valore',
                    'data_inserimento', 'data_registrazione', 'id_annotazione_cv',
                    'commento', 'prof', 'id_utente'
                ],
                'description' => 'Valutazioni laboratorio registrate (oltre 2h da PLUSMINUS_QUEUE)'
            ],

            'PLUSMINUS_QUEUE' => [
                'columns' => [
                    'id_evidenza', 'id_uda', 'id_materia_cv', 'id_classe_cv',
                    'id_studente_cv', 'id_indicatore', 'nome_indicatore', 'valore',
                    'data_inserimento', 'registrato', 'data_registrazione',
                    'id_annotazione_cv', 'commento', 'prof', 'id_utente'
                ],
                'description' => 'Coda evidenze PiùOMeno con finestra modificabile 2h'
            ],

            'VOTI' => [
                'columns' => [
                    'id_voto', 'id_uda', 'id_studente_cv', 'id_classe_cv',
                    'id_materia_cv', 'tipo_voto', 'voto', 'giudizio',
                    'descrizione', 'data_valutazione', 'data_creazione',
                    'pubblicato', 'id_annotazione_cv', 'num_evidenze_positive',
                    'num_evidenze_negative', 'num_evidenze_totali', 'id_utente',
                    'link_origine'
                ],
                'description' => 'Voti aggregati (rubrica orale, laboratorio)'
            ],

            'CLASSROOM_MAPPINGS' => [
                'columns' => [
                    'id_mapping',
                    'id_classe_cv',
                    'nome_classe_cv',
                    'id_materia_cv',
                    'nome_materia_cv',
                    'id_corso_gc',
                    'nome_corso_gc',
                    'data_mapping',
                    'stato',
                    'note',
                    'id_utente',
                    'classeviva_class_id',
                    'classeviva_class_name',
                    'classeviva_subject_id',
                    'classeviva_subject_name',
                    'google_course_id',
                    'google_course_name',
                    'data_creazione',
                    'data_modifica',
                    'attivo'
                ],
                'description' => 'Mappatura Classe+Materia verso Corso Google Classroom'
            ],

            'MAPPATURA_STUDENTI' => [
                'columns' => [
                    'id_mappatura',
                    'id_mapping_materia',
                    'id_studente_cv',
                    'id_studente_gc',
                    'data_associazione',
                    'stato',
                    'confermato_da',
                    'note',
                    'id_utente'
                ],
                'description' => 'Mappatura studenti ClasseViva -> Google Classroom'
            ],



            'CLASSI' => [
                'columns' => [
                    'id_classe', 'nome', 'anno_scolastico', 'sezione',
                    'corso', 'attiva', 'id_utente'
                ],
                'description' => 'Classi (usato se non c\'è integrazione ClasseViva)'
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
                    'id_valutazione', 'id_uda', 'id_rubrica', 'id_studente_cv', 'id_classe_cv',
                    'id_materia_cv', 'data_valutazione', 'voto_finale',
                    'giudizio', 'note', 'pubblicato', 'id_annotazione_cv',
                    'nome_studente', 'voto_numerico', 'pubblicato_cv', 'dati_json',
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
                    'repo_default_branch'
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
                    'id_studente_cv',
                    'id_classe_cv',
                    'id_materia_cv',
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

            'GITHUB_CLASSROOMS' => [
                'columns' => [
                    'id_mapping', 'id_classe_cv', 'id_materia_cv',
                    'github_classroom_id', 'github_org_name', 'classroom_name',
                    'stato', 'data_creazione', 'note', 'id_utente'
                ],
                'description' => 'Mappatura Classe-Materia → GitHub Classroom'
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
                    'id_submission', 'id_assignment', 'id_studente_cv',
                    'github_username', 'repository_url', 'accepted_at',
                    'last_commit_at', 'status', 'grade', 'feedback', 'id_utente'
                ],
                'description' => 'Tracciamento submission studenti GitHub (futuro)'
            ],

            'GITHUB_ASSIGNMENT_STUDENT_MAP' => [
                'columns' => [
                    'id_map',
                    'id_assignment',
                    'github_username',
                    'roster_identifier',
                    'student_repository_url',
                    'id_studente_cv',
                    'match_confidence',
                    'note',
                    'data_creazione',
                    'id_utente'
                ],
                'description' => 'Associazione studenti ClasseViva ↔ GitHub per assignment'
            ],

            'GITHUB_REPO_LOC_SNAPSHOTS' => [
                'columns' => [
                    'id_snapshot',
                    'id_test',
                    'repo_full_name',
                    'repo_html_url',
                    'ref',
                    'default_branch',
                    'github_username',
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
        if (!empty($columnsMap)) {
            foreach ($sheets as $sheetName => &$definition) {
                $upper = strtoupper($sheetName);
                if (isset($columnsMap[$upper]) && is_array($columnsMap[$upper]) && !empty($columnsMap[$upper])) {
                    $definition['columns'] = $columnsMap[$upper];
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

        return self::$columnsCache = $normalized;
    }

    private static function getSchemaColumnsFilePath(): string
    {
        return dirname(dirname(__DIR__)) . '/database/schema_columns.json';
    }
}
