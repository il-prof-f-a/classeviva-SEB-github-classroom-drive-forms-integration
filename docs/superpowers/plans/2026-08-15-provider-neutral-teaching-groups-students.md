# Gruppi didattici e identità studente indipendenti dai provider — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sostituire la dipendenza strutturale da classi, materie e studenti ClasseViva con identificativi interni stabili, mantenendo inizialmente invariata la GUI e consentendo a nuove UDA, assegnazioni, test e valutazioni di funzionare con MySQL o SQLite anche senza ClasseViva.

**Architecture:** Il dominio userà `id_gruppo` e `id_studente`; gli ID ClasseViva, Google Classroom e GitHub Classroom saranno confinati in tabelle di integrazione. Una facciata temporanea continuerà a fornire alla GUI i campi legacy. I dati didattici storici non saranno migrati: un reset circoscritto ricreerà il dominio preservando utenti, configurazioni delle integrazioni e cataloghi. Excel e Google Sheets non saranno più backend database; resteranno import/export e integrazioni applicative.

**Tech Stack:** PHP 8.2, PDO MySQL, PDO SQLite, Docker Compose, test runner PHP esistente, JavaScript senza framework, Bootstrap 5, PhpSpreadsheet per file/template.

**Vincoli:** Repo di codice principale, nessun worktree, nessun commit automatico, production intatta. Livello test **full**. Nomi/email studenti mai persistiti. Bootstrap resta l’unico responsabile del popup ClasseViva, solo per azioni che richiedono realmente CV.

---

## Decisioni e reset autorizzato

- I dati didattici attuali sono di prova e possono essere persi senza rollback.
- Nessuna colonna di compatibilità `id_studente_cv` nelle tabelle di dominio.
- Classe-materia diventa un gruppo interno con `id_gruppo` opaco.
- Un gruppo può esistere senza provider o collegarsi a CV, Google e GitHub.
- Uno studente ha `id_studente`; gli ID esterni sono identità collegate.
- GUI invariata ora; generalizzazione visiva in una fase successiva.
- Persistenza supportata: solo SQLite e MySQL.
- Google Sheets resta integrazione applicativa, non database.
- Excel/CSV restano import/export/template, non persistenza primaria.

### Tabelle preservate

`UTENTI`, `INTEGRAZIONI_UTENTE`, `OBIETTIVI_MASTER`, `INDICATORI_LABORATORIO`, `CATEGORIE_COMPETENZE`, `GITHUB_REPO_TEMPLATES`, `SCHEMA_MIGRATIONS`.

### Dominio da ricreare

`UDA_ANAGRAFICA`, `UDA_GRUPPI`, `UDA_PUBBLICAZIONI`, `GRUPPI_DIDATTICI`, `GRUPPI_INTEGRAZIONI`, `STUDENTI`, `STUDENTI_IDENTITA_ESTERNE`, `GRUPPI_STUDENTI`, `STUDENTI_RISORSE_ESTERNE`, materiali, obiettivi UDA, rubriche, valutazioni, voti, domande, test/CBM, assignment/submission GitHub, condivisioni e inviti.

### Tabelle legacy da eliminare

`CLASSI_ASSEGNATE`, `CLASSI`, `CLASSROOM_MAPPINGS`, `GITHUB_CLASSROOMS`, `MAPPATURA_STUDENTI`, `GITHUB_ASSIGNMENT_STUDENT_MAP`.

## Schema di destinazione

```text
GRUPPI_DIDATTICI
  id_gruppo, nome_gruppo, nome_classe, nome_materia, anno_scolastico,
  descrizione, stato, data_creazione, ultima_modifica, id_utente

GRUPPI_INTEGRAZIONI
  id_collegamento, id_gruppo, provider, tipo_risorsa,
  external_context_id, external_subject_id, external_name, principale,
  stato, metadata_json, data_creazione, ultima_modifica, id_utente

UDA_GRUPPI
  id_assegnazione, id_uda, id_gruppo, data_assegnazione, data_inizio,
  data_fine, note, stato, id_utente

UDA_PUBBLICAZIONI
  id_pubblicazione, id_uda, id_gruppo, provider, external_resource_id,
  external_url, stato, data_pubblicazione, metadata_json, id_utente

STUDENTI
  id_studente, stato, data_creazione, ultima_modifica, id_utente

STUDENTI_IDENTITA_ESTERNE
  id_identita, id_studente, provider, external_user_id, external_context_id,
  tipo_identificatore, stato, data_prima_associazione, ultima_verifica,
  metadata_json, id_utente

GRUPPI_STUDENTI
  id_iscrizione, id_gruppo, id_studente, provider_origine,
  external_context_id, stato, data_inizio, data_fine,
  ultima_sincronizzazione, id_utente

STUDENTI_RISORSE_ESTERNE
  id_risorsa, id_studente, provider, external_context_id,
  external_resource_id, external_url, tipo_risorsa, metadata_json,
  data_creazione, ultima_modifica, id_utente
```

La coppia CV usa `external_context_id=id_classe_cv` ed `external_subject_id=id_materia_cv`. `metadata_json` non contiene PII. Le tabelle valutazione usano solo `id_gruppo` e `id_studente`; gli esiti esterni usano `provider_pubblicazione` ed `external_publication_id`.

---

## Task 1: Congelare il contratto con test fallenti

**Files:** Create `tests/architecture/provider_neutral_schema.php`, `tests/architecture/sql_only_persistence.php`, `tests/architecture/no_student_pii.php`; modify `tests/run.php`.

- [ ] Registrare i test nel runner con esecuzione isolata.
- [ ] Verificare nuove tabelle/colonne e vietare ID CV nel dominio, indicando tabella/colonna in errore.
- [ ] Verificare `DatabaseFactory::getSupportedTypes() === ['sqlite','sqlite3','mysql']` e rifiuto di Excel/Sheets.
- [ ] Vietare nel dominio `nome_studente`, `email_studente`, `id_studente_cv`, `id_studente_gc`, `github_username`, `roster_identifier`; consentirli solo agli adapter provider in memoria.
- [ ] Eseguire i tre test e confermare FAIL pertinenti senza fatal del test.

## Task 2: Separare persistenza SQL e file tabellari

**Files:** Create `src/Core/SpreadsheetFileService.php`, `tests/database/spreadsheet_file_service.php`; modify `DatabaseAdapterInterface`, adapter MySQL/SQLite/user-scoped, `ObiettiviManager`, `RubricManager`, `TemplateManager`, `LaboratorioManager`, `tests/run.php`.

- [ ] Testare caricamento `.xlsx` temporaneo e rifiuto di path traversal.
- [ ] Implementare `SpreadsheetFileService::__construct(array $allowedRoots)`, `loadExternalFile()`, `loadTemplate()` centralizzando le validazioni esistenti.
- [ ] Rimuovere `loadExternalFile/loadTemplate` dal contratto database e dagli adapter.
- [ ] Iniettare il servizio file nei manager, lasciando il CRUD all’adapter.
- [ ] Eseguire `php tests/database/spreadsheet_file_service.php`, `php tests/import_questions/test_template_download.php`, `php tests/import_questions/test_json_textarea.php`; atteso PASS.

## Task 3: Rendere il factory SQL-only

**Files:** Modify `src/Core/Database/DatabaseFactory.php`, `bootstrap.php`, `.env.example`, `scripts/setup_database.php`, test architetturale.

- [ ] Impostare SQLite come default.
- [ ] Supportare solo SQLite/MySQL.
- [ ] Eliminare `DB_MASTER_FILE` e configurazione Google Sheets come persistenza, mantenendo le API Google applicative.
- [ ] Eseguire test factory, bootstrap env precedence e URL locale; atteso PASS.

## Task 4: Migrazioni SQL versionate e indici equivalenti

**Files:** Create `src/Core/Database/SchemaMigrationRunner.php`, `SchemaIndexDefinitions.php`, `tests/database/schema_v2_sqlite.php`; modify schema e adapter MySQL/SQLite, `tests/database_mysql.php`, runner.

- [ ] Su SQLite temporaneo testare `SCHEMA_MIGRATIONS`, applicazione singola di `20260815_001_provider_neutral_domain`, schema/indici e idempotenza.
- [ ] Estendere il test MySQL sul DB Docker dedicato.
- [ ] Definire unique: gruppo; provider/context/subject; UDA/gruppo; studente; provider/external user; gruppo/studente; risorsa esterna.
- [ ] Implementare `pending()` e `migrate()` con report `applied/skipped/errors/timestamps`; una migrazione fallita non viene registrata.
- [ ] Eseguire test schema su SQLite e con `TEST_MYSQL=true`; atteso PASS.

## Task 5: Reset didattico esplicito e protetto

**Files:** Create `src/Core/Database/TeachingDomainReset.php`, `scripts/reset_teaching_domain.php`, `tests/database/teaching_domain_reset.php`; modify runner.

- [ ] Testare su SQLite temporaneo che utente, integrazione e catalogo restino, mentre UDA/gruppo/studente/valutazione spariscano.
- [ ] Accettare `--dry-run`, `--apply`, `--confirm=RESET-TEACHING-DOMAIN`; rifiutare `APP_ENV=production`.
- [ ] Usare allowlist e ordine espliciti; nessun nome tabella da input.
- [ ] Produrre JSON con ambiente, tipo DB, preservate/eliminate/create, conteggi, schema version ed errori.
- [ ] Eseguire `php tests/database/teaching_domain_reset.php`; atteso PASS senza credenziali reali.

## Task 6: Dominio dei gruppi didattici

**Files:** Create `TeachingGroupRepository.php`, `TeachingGroupIntegrationRepository.php`, `TeachingGroupService.php`, `TeachingGroupResolver.php`, `tests/domain/teaching_groups.php`; modify runner.

- [ ] Testare gruppo senza provider; link CV/Google/GitHub; risoluzione equivalente; conflitti; isolamento utente; disattivazione link.
- [ ] Implementare repository dipendenti solo da `DatabaseAdapterInterface`, mai API/sessioni.
- [ ] Generare ID opachi (`GRP_` + random bytes), mai derivati dai provider.
- [ ] Implementare resolver per ID interno o chiave esterna; il dominio riceve sempre `id_gruppo`.
- [ ] Eseguire `php tests/domain/teaching_groups.php`; atteso PASS.

API minima:

```php
TeachingGroupRepository::create(array $data): array;
TeachingGroupRepository::findById(string $id): ?array;
TeachingGroupRepository::listActive(): array;
TeachingGroupIntegrationRepository::link(array $data): array;
TeachingGroupIntegrationRepository::findByExternal(string $provider, string $contextId, ?string $subjectId): ?array;
TeachingGroupIntegrationRepository::listForGroup(string $groupId): array;
```

## Task 7: Collegare UDA e gruppi senza cambiare GUI

**Files:** Create `UdaGroupRepository.php`, `UdaPublicationRepository.php`, `src/Utils/LegacyTeachingGroupView.php`, `tests/domain/uda_group_assignments.php`; modify `UDAManager`, `UdaIntegrationResolver`, `uda_create.php`, `uda_assign.php`, `uda_edit.php`, `uda_view.php` e test wizard.

- [ ] Simulare selezione CV e verificare creazione/risoluzione gruppo, link CV e riga `UDA_GRUPPI` senza ID CV.
- [ ] Creare la facciata vista con chiavi temporanee `id_classe`, `id_materia_cv`, `nome_classe`, `nome_materia`, `pubblicato_classroom`, `classroom_url` senza reinserirle nello schema.
- [ ] Spostare CRUD su repository gruppo/pubblicazione mantenendo le firme pubbliche necessarie alle pagine.
- [ ] Rifare il resolver come `id_uda -> UDA_GRUPPI -> GRUPPI_INTEGRAZIONI`.
- [ ] Eseguire test domain e wizard integration/optional/metadata; atteso PASS e markup invariato.

## Task 8: Migrare le pagine di mappatura

**Files:** Modify `map_classes.php`, `classroom_mapping.php`, `github_classroom_mapping.php`, endpoint cataloghi wizard, cataloghi Classroom/GitHub; create `tests/domain/group_provider_mappings.php`; modify test wizard mapping.

- [ ] Testare payload correnti, link dei tre provider allo stesso gruppo, preselezione e ritorno `#step-2`.
- [ ] Eliminare letture/scritture di `CLASSROOM_MAPPINGS` e `GITHUB_CLASSROOMS`.
- [ ] Far ricavare ai cataloghi course/classroom ID tramite `id_gruppo`; fallback CV solo nella facciata temporanea.
- [ ] Eseguire test mapping e catalog markup; atteso PASS senza variazioni visive.

## Task 9: Identità studente e membership

**Files:** Create `StudentRepository.php`, `StudentIdentityRepository.php`, `GroupStudentRepository.php`, `StudentResourceRepository.php`, `StudentIdentityResolver.php`, `StudentRosterService.php`, `tests/domain/student_identities.php`; modify runner.

- [ ] Testare creazione da CV, aggiunta Google/GitHub, due gruppi, idempotenza, conflitto identità, multiutente e assenza PII.
- [ ] Implementare `resolve(provider,id)`, `resolveOrCreate(provider,id)`, `merge(source,target)`.
- [ ] `merge()` sposta identità, membership, voti e risorse in transazione e rifiuta conflitti.
- [ ] Il roster service riceve display label in memoria ma persiste solo ID/provider/membership.
- [ ] Eseguire test identità e privacy; atteso PASS.

## Task 10: Sincronizzazione studenti ClasseViva

**Files:** Modify `StudentiManager.php`, `ClasseVivaAPI.php`, `studenti_sync.php`, quattro endpoint `ajax_load_*students*`; create `tests/integration/classeviva_student_sync.php`; modify runner.

- [ ] Con risposta CV fittizia, mostrare etichette in risposta ma salvare solo ID interno, identità CV e membership.
- [ ] Invertire la dipendenza: `StudentiManager` riceve roster, non costruisce `ClasseVivaAPI`.
- [ ] Usare `id_gruppo` o risolverlo dalla coppia CV prima della sync.
- [ ] Eseguire test sync e session auth; atteso PASS.

## Task 11: Mappatura studenti e voti Google Classroom

**Files:** Modify `map_students.php`, `import_classroom_grades.php`, `GoogleClassroomAPI.php`; create test mapping identità e import voti; modify runner.

- [ ] Mappare ID CV+Google allo stesso studente interno senza `MAPPATURA_STUDENTI`.
- [ ] Importare un voto con `id_gruppo/id_studente`; nome/email solo in anteprima.
- [ ] Risolvere gruppo dal corso Google e studente dall’identità Google.
- [ ] Limitare il fuzzy name matching a proposta manuale da confermare.
- [ ] Eseguire i due test d’integrazione; atteso PASS.

## Task 12: Voti, rubriche, laboratorio, PiùOMeno e CBM

**Files:** Modify pagine/API rubrica, `OralRubricController`, `RubricManager`, pagine/manager laboratorio, PiùOMeno, import Forms/CBM/Kahoot/Excel, gestione voti; create `internal_student_grades.php`, `internal_student_cbm.php`; modify runner.

- [ ] Creare fixture DB temporanee con utente, gruppo, studente multi-identità, UDA e test.
- [ ] Testare scritture: voto manuale, rubrica, laboratorio, PiùOMeno, Forms/CBM, Kahoot/Excel.
- [ ] Verificare che ogni riga usi ID interni e nessun ID provider.
- [ ] Risolvere display name in memoria; fallback etichetta neutra da ID interno.
- [ ] Per pubblicazione CV risolvere identità al confine e salvare provider/external publication ID generici.
- [ ] Eseguire test grades, CBM e schema; atteso PASS.

## Task 13: GitHub Classroom e repository studente

**Files:** Modify pagine `github_assignment_*`, `github_assignments.php`, pagine rubriche GitHub, `GitHubIntegration.php`, `GitHubAssignmentCatalog.php`; create `tests/integration/github_student_resources.php`; modify runner.

- [ ] Collegare assignment a UDA/gruppo e risolvere la GitHub Classroom da `GRUPPI_INTEGRAZIONI`.
- [ ] Salvare identità GitHub e repository/assignment in `STUDENTI_RISORSE_ESTERNE`; submission con `id_studente`.
- [ ] Non persistere roster identifier, nome o email.
- [ ] Se esiste solo GitHub, creare studente interno e consentire successivo merge con CV/Google.
- [ ] Eseguire il test; atteso PASS.

## Task 14: ClasseViva come capability opzionale

**Files:** Modify `bootstrap.php`, `ClasseVivaTokenGuard.php`, quick-login partial, `uda_publish.php`, `pubblicazione_cv.php`; create `tests/classeviva_optional_capability.php`; modify runner.

- [ ] Testare che visualizzazione UDA, test e import Classroom non richiedano popup CV.
- [ ] Testare che pubblicazione/sync CV richiedano popup unico solo con token assente/invalido.
- [ ] Introdurre dichiarazione esplicita tipo `REQUIRES_CLASSEVIVA`, default false.
- [ ] Risolvere gli ID CV da gruppo/studente subito prima della chiamata e gestire collegamento assente.
- [ ] Eseguire test capability e session auth; atteso PASS e nessun doppio popup.

## Task 15: Eliminare legacy e adapter non supportati

**Files:** Modify `map_courses.php`, `pubblicazione_cv.php`, `OralRubricController`; modify/delete `rubrica_orale_test.php`; delete `DatabaseManager.php`, `ExcelDatabaseAdapter.php`, `GoogleSheetsDatabaseAdapter.php`; modify schema; create `no_legacy_domain_identifiers.php`.

- [ ] Migrare gli ultimi usi diretti di `DatabaseManager`; rimuovere l’endpoint test se diagnostico.
- [ ] Eliminare adapter solo quando `rg` non trova type hint/costruttori.
- [ ] Eliminare le sei tabelle legacy e colonne dominio `_cv`.
- [ ] Guard statico: ID CV consentiti solo in `ClasseVivaAPI`, repository identità/integrazioni, facciata legacy, fixture e reset.
- [ ] Eseguire:

```powershell
rg -n "new DatabaseManager|ExcelDatabaseAdapter|GoogleSheetsDatabaseAdapter|DB_MASTER_FILE" src public bootstrap.php config .env.example
php tests/architecture/no_legacy_domain_identifiers.php
```

Expected: `rg` senza risultati e test PASS.

## Task 16: Amministrazione, setup e documentazione

**Files:** Modify `public/database_manager.php`, `scripts/setup_database.php`, `src/Core/Database/README.md`, `README.md`, `.env.example`, `compose.yaml`, `../llm/pages/deployment.md`; create `docs/architecture/provider-neutral-domain.md`, `tests/database_manager_sql_only.php`; modify `tests/run.php`.

- [ ] Pagina database: solo SQLite/MySQL, versione schema, migrazioni e indici; niente conversioni Excel/Sheets o path sensibili.
- [ ] Documentare configurazioni `DB_TYPE=sqlite` + path oppure MySQL host/port/name/user/password con valori fittizi.
- [ ] Documentare gruppo vs integrazione, studente vs identità, PII assente, display just-in-time, uso senza CV, Excel solo file.
- [ ] Memoria privata: modello, locale→preproduzione→produzione, produzione fuori scope, tabelle preservate, segreti esclusi dal deploy.
- [ ] Eseguire test pagina admin, env admin e env precedence; atteso PASS.

## Task 17: Validazione completa locale SQLite

**Files:** Create `tests/e2e/new_uda_provider_neutral.php`; modify runner.

- [ ] Smoke E2E su DB temporaneo: utente, gruppo senza provider, link CV/Google/GitHub, UDA, assegnazione, studente tre identità, voto Google, valutazione, risoluzione CV simulata, riapertura DB.
- [ ] Lint PHP completo con `rg --files -g '*.php' -g '!vendor/**'` e `php -l` su ogni file.
- [ ] Eseguire `composer test`; atteso tutti PASS.
- [ ] Avviare `docker compose up -d --build`, controllare `ps` e ultimi 200 log app/db.
- [ ] Collaudo browser: wizard senza popup CV inutile, UDA opzionale, mappature con ritorno step 2, domande/test, import risultato, popup CV unico solo per azioni CV.

## Task 18: Parità MySQL Docker

**Files:** Modify `compose.yaml`, `tests/database_mysql.php`; create `tests/e2e/new_uda_provider_neutral_mysql.php`; modify runner.

- [ ] Usare un DB test separato, mai dump di preproduzione o credenziali del provider hosting.
- [ ] Eseguire `docker compose up -d --build db app`, impostare `TEST_MYSQL=true`, eseguire `composer test`, rimuovere env.
- [ ] Confrontare colonne, indici, duplicati, scoping utente e idempotenza migrazioni.
- [ ] Atteso: stesso contratto e stessi PASS di SQLite.

## Task 19: Reset locale autorizzato e nuovi dati

**Files:** Generate ignored `storage/reports/reset-teaching-domain-<timestamp>.json`.

- [ ] Eseguire `php scripts/reset_teaching_domain.php --dry-run`; verificare ambiente e allowlist.
- [ ] Eseguire `php scripts/reset_teaching_domain.php --apply --confirm=RESET-TEACHING-DOMAIN`.
- [ ] Verificare account/integrazioni/cataloghi preservati e dominio vuoto.
- [ ] Creare da GUI: UDA senza gruppo; UDA con gruppo CV; stesso gruppo Google/GitHub; studente multi-provider; test e voto.
- [ ] Riavviare app, controllare log e persistenza; nessun errore legacy.

## Task 20: Deploy e reset preproduzione

**Files:** Modify `../scripts/deploy_stage.py`, `../tests/staging_deploy/test_deploy_stage.py`, `../llm/pages/deployment.md`; generate report ignorati nella repo privata.

- [ ] Manifest: includere solo codice pubblico; escludere `.env`, credentials/token, DB locali, backup e report sensibili.
- [ ] Dry-run basato sul commit, elenco add/modify/delete, nessuna cancellazione massiva remota.
- [ ] Distribuzione graduale senza sovrascrivere file critici stage.
- [ ] Reset stage tramite CLI o wrapper temporaneo con conferma/report; nessun endpoint permanente e rimozione immediata wrapper.
- [ ] Ripetere il collaudo sull'ambiente di preproduzione con dati nuovi e controllare log/API.
- [ ] Registrare esplicitamente che l'ambiente di produzione non è stato modificato.

## Task 21: Revisione finale e commit manuale

**Files:** Review all changes; update docs/memory only if findings changed.

- [ ] Eseguire guard statici per ID/tabelle legacy e adapter rimossi.
- [ ] Eseguire `git diff --check`, `composer test`, poi suite con `TEST_MYSQL=true`.
- [ ] Report: schema/versione, tabelle eliminate/preservate, file, test, collaudi, limiti GUI, production intatta, segreti assenti.
- [ ] Non eseguire `git add`, `git commit` o `git push`; mostrare `git status --short` all’utente.

---

## Stato di esecuzione — 2026-08-15

Completati e verificati: contratti/schema provider-neutral, persistenza SQL-only,
migrazioni e indici SQLite/MySQL, reset locale protetto, repository gruppi e
assegnazioni UDA, facciate di mapping Classroom/GitHub, identità e membership
studenti, normalizzazione dei voti/rubriche/submission, sync roster ClasseViva,
capability ClasseViva opt-in, rimozione degli adapter Excel/Google Sheets e
test E2E locali. Restano da eseguire in una fase separata: collaudo browser
completo, eventuale migrazione delle ultime pagine legacy, reset con nuovi dati
scelti dall'utente e qualsiasi deploy di preproduzione. Production resta fuori
scope e non è stata contattata.

## Evidenze ultimo checkpoint locale

- Lint PHP: 259/259 file senza errori di sintassi.
- Suite Docker: 121 test superati; un solo warning riguarda esclusivamente il file segreto locale `config/.env`, che ha un insieme/ordine di chiavi diverso da `.env.example`.
- MySQL schema, E2E e reset: PASS; SQLite schema: PASS.
- Container app/db healthy; smoke HTTP locale: risposta 302 verso il login.
- Il vendor PHP dell'host non e' completo; la suite host non e' quindi rappresentativa. La suite Docker usa il vendor Composer completo.

Aggiornamento successivo: le sei tabelle legacy sono state eliminate dal MySQL
Docker locale e rimosse dalle definizioni automatiche dello schema. La suite
Docker aggiornata è a `123 passati, 0 falliti`; il nuovo test verifica che il
bootstrap non ricrei le tabelle. È stato preparato lo script staging
`scripts/migrate_legacy_tables.php`, ma non è stato eseguito né caricato su
FTP.

## Checkpoint manuali consigliati

1. Contratti e persistenza SQL-only — Task 1–3.
2. Schema v2 e reset protetto — Task 4–5.
3. Gruppi e assegnazioni UDA — Task 6–8.
4. Studenti e identità — Task 9–11.
5. Valutazioni e GitHub — Task 12–13.
6. CV opzionale e rimozione legacy — Task 14–15.
7. Documentazione e collaudo locale — Task 16–19.
8. Preproduzione — Task 20.
9. Revisione finale — Task 21.

## Comandi di verifica finali

```powershell
rg -n "id_studente_cv|id_classe_cv|id_materia_cv|CLASSROOM_MAPPINGS|GITHUB_CLASSROOMS|MAPPATURA_STUDENTI|CLASSI_ASSEGNATE" src public scripts --glob '!src/Integration/ClasseVivaAPI.php' --glob '!src/Utils/LegacyTeachingGroupView.php'
rg -n "DB_MASTER_FILE|ExcelDatabaseAdapter|GoogleSheetsDatabaseAdapter|new DatabaseManager" . --glob '!vendor/**' --glob '!.git/**'
git diff --check
composer test
$env:TEST_MYSQL='true'; composer test
Remove-Item Env:TEST_MYSQL
git status --short
```

Expected: nessuna occorrenza non autorizzata, nessun errore whitespace, tutte le suite PASS, solo modifiche pertinenti non committate.

## Criteri di accettazione

- UDA creabile senza assegnazioni o assegnabile a gruppi interni.
- Gruppo funzionante senza CV e collegabile a CV/Google/GitHub.
- Test e valutazioni usano solo ID interni.
- Nomi/email studenti mai persistiti.
- ID CV risolti solo durante operazioni CV.
- Popup CV unico e contestuale.
- Parità SQLite/MySQL.
- Excel/CSV solo import/export/template; Sheets non backend DB.
- Reset preserva utenti, integrazioni e cataloghi.
- GUI invariata in questa fase.
- Test con dati nuovi dopo reset.
- Nessun segreto nella repo pubblicabile/deploy.
- Nessun commit automatico.
- Production intatta.
