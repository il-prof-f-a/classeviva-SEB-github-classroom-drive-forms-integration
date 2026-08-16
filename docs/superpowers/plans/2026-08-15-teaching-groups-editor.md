# Editor dei gruppi didattici — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Creare un editor per i `GRUPPI_DIDATTICI` con integrazioni ClasseViva/Google/GitHub, mappatura studenti provider-neutral e selezione dei gruppi nello step 2 del wizard UDA.

**Architecture:** Il gruppo resta l'entità interna con `id_gruppo`; i collegamenti esterni restano in `GRUPPI_INTEGRAZIONI`; gli studenti usano `STUDENTI`, `STUDENTI_IDENTITA_ESTERNE` e `GRUPPI_STUDENTI`. La nuova pagina coordina repository e servizi esistenti, mentre `uda_create.php` invia soltanto gli ID dei gruppi. Le pagine legacy continueranno a usare facciate di compatibilità, ma non saranno più il punto di scrittura del nuovo wizard.

**Tech Stack:** PHP 8.2, PDO SQLite/MySQL, Docker Compose, test PHP esistenti, Bootstrap 5 e JavaScript senza framework.

---

## Vincoli di esecuzione

- Lavorare nella repo principale di codice: `S:/Progetti su github/uda-sysytem/classeviva-SEB-github-classroom-drive-forms-integration`.
- Non usare worktree.
- Non modificare staging o produzione.
- Non includere `.env`, token, password, dump o credenziali.
- Non eseguire `git add`, `git commit` o `git push`: il commit sarà manuale dell'utente.
- Livello di test: **full**.
- Prima di ogni modifica applicativa scrivere il test fallente corrispondente.

## Struttura dei file coinvolti

### File da creare

- `src/Core/TeachingGroupCatalogService.php` — catalogo user-scoped dei gruppi con riepilogo integrazioni per il wizard.
- `src/Core/TeachingGroupStudentService.php` — sincronizzazione roster, costruzione matrice studenti e collegamento/scollegamento identità.
- `public/teaching_groups.php` — editor dati base, integrazioni e tab studenti.
- `tests/domain/teaching_group_catalog.php` — contratto del catalogo e scoping utente.
- `tests/domain/teaching_group_student_matrix.php` — contratto matrice, merge e privacy.
- `tests/public/teaching_groups_page_markup.php` — controlli statici della nuova pagina.
- `tests/uda_editor/test_wizard_group_selector.php` — contratto statico dello step 2.

### File da modificare

- `src/Core/TeachingGroupRepository.php` — elenco completo, aggiornamento controllato e disattivazione del gruppo.
- `src/Core/TeachingGroupIntegrationRepository.php` — upsert per provider, sostituzione e rimozione sicura del collegamento.
- `src/Core/TeachingGroupService.php` — operazioni applicative con ownership e provider consentiti.
- `src/Core/TeachingGroupResolver.php` — risoluzione del gruppo per provider e riepilogo.
- `src/Core/StudentIdentityRepository.php` — ricerca delle identità per studente e provider senza dati descrittivi.
- `src/Core/StudentRosterService.php` — sync con contesto esterno e output display-only.
- `public/uda_create.php` — step 2 basato su `id_gruppo` e assegnazione UDA tramite `UdaGroupRepository`.
- `public/map_classes.php` — compatibilità: delega al gruppo e ritorno all'editor quando richiesto.
- `public/github_classroom_mapping.php` — compatibilità: delega al gruppo e ritorno all'editor quando richiesto.
- `public/map_students.php` — accetta `group_id` e inoltra alla tab studenti del nuovo editor.
- `tests/run.php` — registrazione dei test nuovi.
- `README.md` o guida della repo, solo se le istruzioni di navigazione devono essere aggiornate.

---

## Task 1: Congelare i contratti con test fallenti

**Files:**

- Create: `tests/domain/teaching_group_catalog.php`
- Create: `tests/domain/teaching_group_student_matrix.php`
- Create: `tests/public/teaching_groups_page_markup.php`
- Create: `tests/uda_editor/test_wizard_group_selector.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Scrivere il test del catalogo gruppi**

Usare lo stesso bootstrap autoload dei test esistenti e un database SQLite temporaneo. Il test deve creare due gruppi per `USR_A`, un gruppo per `USR_B`, tre integrazioni per il primo gruppo e verificare che il catalogo restituisca solo i gruppi di `USR_A` con questo contratto:

```php
$catalog = new TeachingGroupCatalogService($adapter, 'USR_A');
$rows = $catalog->listForWizard();
if (count($rows) !== 2) {
    $failures[] = 'il catalogo non è user-scoped';
}
$first = $rows[0];
foreach (['id_gruppo', 'nome_gruppo', 'nome_classe', 'nome_materia', 'providers'] as $key) {
    if (!array_key_exists($key, $first)) {
        $failures[] = "chiave catalogo mancante: {$key}";
    }
}
if (array_keys($first['providers']) !== ['classeviva', 'google_classroom', 'github_classroom']) {
    $failures[] = 'riepilogo provider incompleto o non ordinato';
}
```

- [ ] **Step 2: Scrivere il test della matrice studenti**

Il test deve sincronizzare roster ClasseViva, Google e GitHub sullo stesso gruppo, collegare due identità dello stesso studente, lasciare una riga non mappata e verificare che il database non contenga nomi/email:

```php
$service = new TeachingGroupStudentService($adapter, 'USR_A');
$service->syncRoster('GRP_1', 'classeviva', 'CV_CLASS_1', [
    ['external_user_id' => 'CV_STUDENT_1', 'display_name' => 'Nome temporaneo'],
]);
$service->syncRoster('GRP_1', 'google_classroom', 'GC_COURSE_1', [
    ['external_user_id' => 'GC_STUDENT_1', 'display_name' => 'Nome temporaneo'],
]);
$service->linkIdentities('GRP_1', [
    ['provider' => 'classeviva', 'external_user_id' => 'CV_STUDENT_1',
     'matches' => [['provider' => 'google_classroom', 'external_user_id' => 'GC_STUDENT_1']]],
]);
$raw = json_encode($adapter->findAll('STUDENTI_IDENTITA_ESTERNE'), JSON_THROW_ON_ERROR);
if (stripos($raw, 'Nome temporaneo') !== false || stripos($raw, '@') !== false) {
    $failures[] = 'PII persistita nella matrice studenti';
}
```

- [ ] **Step 3: Scrivere il test statico della pagina editor**

Leggere il file della pagina e verificare la presenza delle azioni POST, dei provider, della tab studenti, del ritorno locale e dell'assenza di credenziali nel markup:

```php
foreach (['create_group', 'update_group', 'link_provider', 'unlink_provider', 'save_student_mapping', 'tab=students', 'return_to'] as $needle) {
    if (!str_contains($markup, $needle)) {
        failPage("azione o collegamento mancante: {$needle}");
    }
}
foreach (['password', 'client_secret', 'refresh_token'] as $forbidden) {
    if (stripos($markup, 'name="' . $forbidden . '"') !== false) {
        failPage("segreto esposto nella pagina: {$forbidden}");
    }
}
```

- [ ] **Step 4: Scrivere il test statico del wizard**

Il test deve richiedere `id_gruppo[]`, il catalogo gruppi, il link all'editor con `return_to=uda_create.php#2`, la possibilità di zero selezioni e l'assenza dei nuovi campi classe/materia come input primario.

- [ ] **Step 5: Eseguire i test per confermare il fallimento**

Da PowerShell:

```powershell
docker compose exec -T app php tests/domain/teaching_group_catalog.php
docker compose exec -T app php tests/domain/teaching_group_student_matrix.php
docker compose exec -T app php tests/public/teaching_groups_page_markup.php
docker compose exec -T app php tests/uda_editor/test_wizard_group_selector.php
```

Atteso: fallimento esplicito per classi/servizi/pagina non ancora presenti, senza fatal non gestiti.

---

## Task 2: Rendere completi i repository dei gruppi

**Files:**

- Modify: `src/Core/TeachingGroupRepository.php`
- Modify: `src/Core/TeachingGroupIntegrationRepository.php`
- Modify: `src/Core/TeachingGroupService.php`
- Modify: `tests/domain/teaching_groups.php`

- [ ] **Step 1: Estendere il test dominio**

Aggiungere al test esistente i casi: gruppo senza provider; modifica del nome; disattivazione; un solo provider attivo per tipo; rifiuto di un external context già appartenente a un altro gruppo dello stesso utente.

- [ ] **Step 2: Implementare il catalogo base del repository**

Aggiungere a `TeachingGroupRepository`:

```php
public function listAll(): array;
public function deactivate(string $id): bool;
```

Entrambi devono filtrare `id_utente`; `deactivate()` deve impostare `stato=disattivo` e `ultima_modifica`, senza cancellare il record.

- [ ] **Step 3: Implementare upsert e rimozione per provider**

Aggiungere a `TeachingGroupIntegrationRepository`:

```php
public function findForGroupProvider(string $groupId, string $provider): ?array;
public function upsertForGroupProvider(string $groupId, array $data): array;
public function deactivateForGroupProvider(string $groupId, string $provider): bool;
```

`upsertForGroupProvider()` deve consentire soltanto `classeviva`, `google_classroom` e `github_classroom`, validare `external_context_id`, aggiornare il collegamento esistente dello stesso gruppo/provider e rifiutare un contesto già collegato a un altro gruppo dello stesso utente. Non usare input utente per il nome della tabella o della colonna.

- [ ] **Step 4: Aggiornare `TeachingGroupService`**

Esporre:

```php
public function createGroup(array $data): array;
public function updateGroup(string $groupId, array $changes): array;
public function linkProvider(string $groupId, array $data): array;
public function unlinkProvider(string $groupId, string $provider): bool;
```

Ogni metodo deve verificare l'esistenza del gruppo con il repository user-scoped prima di scrivere.

- [ ] **Step 5: Eseguire i test dominio**

```powershell
docker compose exec -T app php tests/domain/teaching_groups.php
```

Atteso: `PASS: gruppi didattici e integrazioni provider isolate per utente.`

---

## Task 3: Creare il catalogo per il wizard

**Files:**

- Create: `src/Core/TeachingGroupCatalogService.php`
- Modify: `tests/domain/teaching_group_catalog.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Definire l'interfaccia**

Implementare:

```php
final class TeachingGroupCatalogService
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForWizard(bool $activeOnly = true): array;

    /** @return array<string,mixed>|null */
    public function findForWizard(string $groupId): ?array;
}
```

- [ ] **Step 2: Implementare il riepilogo**

Per ogni gruppo, leggere solo i collegamenti user-scoped e produrre `providers` con chiavi sempre presenti e valore `null` quando non configurato:

```php
[
    'id_gruppo' => 'GRP_...',
    'nome_gruppo' => '4 Informatica',
    'nome_classe' => '4C',
    'nome_materia' => 'Informatica',
    'anno_scolastico' => '2025-26',
    'stato' => 'attivo',
    'providers' => [
        'classeviva' => ['external_context_id' => '...', 'external_subject_id' => '...', 'external_name' => '...'],
        'google_classroom' => null,
        'github_classroom' => null,
    ],
]
```

Non includere `metadata_json` grezzo nel payload del wizard se contiene dati non necessari.

- [ ] **Step 3: Registrare il test nel runner ed eseguirlo**

Inserire il percorso nella sezione dei test dominio di `tests/run.php`, poi eseguire:

```powershell
docker compose exec -T app php tests/domain/teaching_group_catalog.php
```

Atteso: `PASS: catalogo gruppi user-scoped con riepilogo provider.`

---

## Task 4: Generalizzare la mappatura studenti

**Files:**

- Create: `src/Core/TeachingGroupStudentService.php`
- Modify: `src/Core/StudentIdentityRepository.php`
- Modify: `src/Core/StudentRosterService.php`
- Modify: `tests/domain/teaching_group_student_matrix.php`
- Modify: `tests/domain/student_identities.php`

- [ ] **Step 1: Definire il servizio**

Implementare questi metodi, usando esclusivamente repository moderni:

```php
/** @param list<array<string,mixed>> $roster @return list<array<string,mixed>> */
public function syncRoster(string $groupId, string $provider, string $contextId, array $roster): array;

/** @return list<array<string,mixed>> */
public function matrix(string $groupId): array;

/** @param list<array<string,mixed>> $matches */
public function linkIdentities(string $groupId, array $matches): int;

public function unlinkIdentity(string $studentId, string $provider, string $externalUserId): bool;
```

- [ ] **Step 2: Costruire la matrice senza PII**

`matrix()` deve raggruppare per `id_studente` le identità e le membership del gruppo e restituire gli ID esterni. Le etichette ricevute dagli adapter provider possono essere passate a una struttura separata in memoria, ma non devono essere inserite in `STUDENTI`, `STUDENTI_IDENTITA_ESTERNE` o `GRUPPI_STUDENTI`.

- [ ] **Step 3: Gestire merge e conflitti**

Per un match tra provider diversi usare `StudentIdentityResolver::merge()`. Se un ID esterno è già associato a un diverso studente, restituire un errore di conflitto senza modifiche parziali. Per il salvataggio del blocco usare una transazione quando l'adapter la supporta; su SQLite applicare rollback in caso di eccezione.

- [ ] **Step 4: Eseguire i test**

```powershell
docker compose exec -T app php tests/domain/teaching_group_student_matrix.php
docker compose exec -T app php tests/domain/student_identities.php
```

Atteso: `PASS` per matrice, merge, studenti non mappati e assenza di nomi/email persistiti.

---

## Task 5: Implementare l'editor dei gruppi — dati base e integrazioni

**Files:**

- Create: `public/teaching_groups.php`
- Modify: `tests/public/teaching_groups_page_markup.php`

- [ ] **Step 1: Preparare il controller della pagina**

Seguire il bootstrap delle pagine esistenti:

```php
require_once __DIR__ . '/../bootstrap.php';
$db = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$groups = new TeachingGroupRepository($db, $userId);
$integrations = new TeachingGroupIntegrationRepository($db, $userId);
$service = new TeachingGroupService($groups, $integrations);
$returnTo = LocalReturnUrl::sanitize(
    $_GET['return_to'] ?? $_POST['return_to'] ?? null,
    'teaching_groups.php'
);
```

Il controller deve accettare soltanto le azioni `create_group`, `update_group`, `link_provider`, `unlink_provider` e `save_student_mapping`. Ogni POST deve verificare l'utente corrente e poi redirigere con un messaggio flash; non passare dati sensibili in query string.

- [ ] **Step 2: Costruire il form dati base**

Mostrare nome gruppo obbligatorio e classe-materia, anno, descrizione e stato. Il form di creazione deve poter essere salvato senza provider. Il form di modifica deve mostrare i provider già collegati, senza esporre token o segreti.

- [ ] **Step 3: Costruire le tre sezioni provider**

Per ogni provider mostrare stato, nome e identificativi non segreti. Riutilizzare i cataloghi/API esistenti per le opzioni:

- ClasseViva: classe + materia, soltanto se il token è disponibile;
- Google Classroom: corso autorizzato;
- GitHub Classroom: roster autorizzato.

Se l'autorizzazione manca, mostrare un messaggio e un link alla pagina integrazioni. La creazione del gruppo deve rimanere possibile.

- [ ] **Step 4: Aggiungere ritorno al wizard**

Quando `return_to=uda_create.php`, il pulsante deve puntare a `uda_create.php?integration_updated=1#2`. Per altri valori usare `LocalReturnUrl::sanitize()` e non concatenare redirect arbitrari.

- [ ] **Step 5: Eseguire il test markup**

```powershell
docker compose exec -T app php tests/public/teaching_groups_page_markup.php
```

Atteso: `PASS: editor gruppi senza segreti e con ritorni locali.`

---

## Task 6: Aggiungere la modalità mappatura studenti alla pagina

**Files:**

- Modify: `public/teaching_groups.php`
- Modify: `tests/public/teaching_groups_page_markup.php`

- [ ] **Step 1: Caricare il gruppo e i provider**

Richiedere `id` e `tab=students`; se il gruppo non appartiene all'utente corrente restituire una pagina 404/403 senza dati. Caricare le integrazioni attive e preparare solo le colonne provider presenti.

- [ ] **Step 2: Implementare i pulsanti di sincronizzazione**

Usare POST `sync_students` con `provider` e `external_context_id` già risolti dal collegamento salvato, mai con ID arbitrari del client. Il risultato deve mostrare il conteggio e le etichette in memoria.

- [ ] **Step 3: Renderizzare la matrice**

Visualizzare colonne `Studente interno`, `ClasseViva`, `Google Classroom`, `GitHub Classroom`, mostrando solo le colonne configurate. Fornire filtri `Tutti`, `Mappati`, `Non mappati`, `Conflitti`.

- [ ] **Step 4: Implementare salvataggio e scollegamento**

Ogni riga deve inviare provider e ID esterni; il server deve ricalcolare il gruppo e verificare che gli ID risultino nei roster caricati o nelle identità già autorizzate. Non fidarsi di nome/email inviati dal browser.

- [ ] **Step 5: Verificare manualmente la pagina**

```powershell
docker compose exec -T app php tests/public/teaching_groups_page_markup.php
```

Poi aprire localmente `http://localhost:8080/public/teaching_groups.php`, creare un gruppo senza provider, collegare provider disponibili e verificare che una riga non mappata resti modificabile.

---

## Task 7: Rifattorizzare lo step 2 del wizard

**Files:**

- Modify: `public/uda_create.php`
- Create/modify: `tests/uda_editor/test_wizard_group_selector.php`
- Modify: `tests/uda_editor/test_wizard_mapping_markup.php`

- [ ] **Step 1: Caricare il catalogo gruppi server-side**

Inizializzare `TeachingGroupCatalogService` con l'utente corrente e serializzare nel JavaScript soltanto il catalogo pubblico del wizard. Non caricare ClasseViva in modo obbligatorio per il solo rendering dello step.

- [ ] **Step 2: Sostituire il markup dello step 2**

Sostituire `classi-container`, i campi `classe_id[]`/`classe_materia[]` e i link provider per riga con:

```html
<select name="id_gruppo[]" class="form-select teaching-group-select" multiple>
  <!-- opzioni generate dal catalogo gruppi -->
</select>
<a href="teaching_groups.php?return_to=uda_create.php#2"
   class="btn btn-outline-primary">Gestisci gruppi didattici</a>
```

Mostrare sotto ogni opzione il riepilogo ClasseViva/Google/GitHub; spiegare che il gruppo rappresenta l'assegnazione classe-materia interna.

- [ ] **Step 3: Aggiornare sessionStorage e hash**

Salvare `selectedGroupIds` nella bozza; conservare `#2` al ritorno e ripristinare le selezioni senza creare righe vuote. Mantenere il passaggio agli step successivi anche con lista vuota.

- [ ] **Step 4: Aggiornare il salvataggio POST**

Nel ramo `create_uda`, validare ogni `id_gruppo` con `TeachingGroupCatalogService::findForWizard()` e assegnare:

```php
$udaGroups = new UdaGroupRepository($dbAdapter, $userId);
foreach (array_unique(array_filter($_POST['id_gruppo'] ?? [])) as $groupId) {
    if ($catalog->findForWizard((string)$groupId) === null) {
        throw new RuntimeException('Gruppo didattico non disponibile.');
    }
    $udaGroups->assign($udaId, (string)$groupId);
}
```

Non accettare `nome_classe`, `id_classe` o `id_materia_cv` come fonte della relazione nuova.

- [ ] **Step 5: Eseguire i test wizard**

```powershell
docker compose exec -T app php tests/uda_editor/test_wizard_group_selector.php
docker compose exec -T app php tests/uda_editor/test_wizard_mapping_markup.php
```

Atteso: `PASS` per selector gruppi, ritorno `#2`, zero selezioni e assenza di doppia scrittura legacy.

---

## Task 8: Adattare le pagine legacy senza duplicare la logica

**Files:**

- Modify: `public/map_classes.php`
- Modify: `public/github_classroom_mapping.php`
- Modify: `public/map_students.php`
- Modify: `src/Core/ProviderNeutralMappingService.php`
- Modify: `tests/domain/provider_neutral_mappings.php`

- [ ] **Step 1: Accettare `id_gruppo` nelle mappature esistenti**

Quando il nuovo editor invia `id_gruppo`, passarlo a `ProviderNeutralMappingService` e aggiornare il collegamento esistente del gruppo/provider. Mantenere il fallback per il vecchio payload ClasseViva durante la transizione.

- [ ] **Step 2: Uniformare i ritorni**

Per `return_to=uda_create.php` usare sempre `uda_create.php?integration_updated=1#2`; per `return_to=teaching_groups.php` usare `teaching_groups.php?id=<id_gruppo>&integration_updated=1`. Applicare l'allowlist già usata da `LocalReturnUrl`.

- [ ] **Step 3: Reindirizzare la mappatura studenti**

Se `map_students.php` riceve `group_id`, mostrare un link alla tab studenti del nuovo editor e non inizializzare un mapping legacy non necessario. Il payload storico `mapping_id` resta supportato per le UDA esistenti.

- [ ] **Step 4: Eseguire i test di compatibilità**

```powershell
docker compose exec -T app php tests/domain/provider_neutral_mappings.php
docker compose exec -T app php tests/uda_editor/test_wizard_mapping_markup.php
```

Atteso: pagine legacy funzionanti e scrittura moderna invariata.

---

## Task 9: Collaudo full SQLite/MySQL e browser locale

**Files:**

- Modify: `tests/run.php`
- Modify: `README.md` solo per istruzioni finali verificate
- Create: `tests/e2e/teaching_groups_editor.php`

- [ ] **Step 1: Scrivere lo smoke test SQLite**

Lo smoke test deve eseguire in ordine: creazione gruppo vuoto; link tre provider; assegnazione UDA a due gruppi; sync roster; match di due identità; riga non mappata; riapertura del database e verifica degli ID.

- [ ] **Step 2: Eseguire lint e suite completa**

```powershell
docker compose exec -T app php tests/e2e/teaching_groups_editor.php
docker compose exec -T app composer test
docker compose exec -T app php -r "foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.')) as \$f) if (strtolower(\$f->getExtension())==='php' && !str_contains(\$f->getPathname(), '/vendor/')) { \$o=[]; \$c=0; exec('php -l '.escapeshellarg(\$f->getPathname()), \$o, \$c); if (\$c) exit(1); }"
```

Atteso: tutti i test PASS e nessun errore di sintassi.

- [ ] **Step 3: Eseguire la suite MySQL Docker**

```powershell
$env:TEST_MYSQL='true'
docker compose exec -T app composer test
Remove-Item Env:TEST_MYSQL
```

Atteso: stesso contratto SQLite/MySQL, senza usare il dump o le credenziali di staging.

- [ ] **Step 4: Collaudo browser supervisionato**

Verificare localmente:

1. aprire `uda_create.php#2` senza token ClasseViva;
2. aprire **Gestisci gruppi didattici**;
3. creare un gruppo senza provider;
4. tornare al wizard e verificare elenco aggiornato/selezione;
5. collegare Google o GitHub se autorizzati;
6. aprire la tab studenti e lasciare una riga non mappata;
7. assegnare due gruppi a una nuova UDA;
8. verificare che il popup ClasseViva compaia solo aprendo un'azione ClasseViva.

---

## Task 10: Documentazione e consegna manuale

**Files:**

- Modify: `README.md` o documentazione tecnica della repo
- Modify: `docs/architecture/provider-neutral-domain.md` se necessario
- Generate only in ignored storage: `storage/reports/teaching-groups-editor-<timestamp>.json`

- [ ] **Step 1: Documentare il flusso utente**

Spiegare che lo step 2 assegna gruppi didattici, che il gruppo può essere creato senza provider e che le integrazioni si configurano successivamente.

- [ ] **Step 2: Documentare privacy e provider opzionali**

Specificare che i nomi/email dei roster sono usati solo per la visualizzazione e che il database conserva identificativi interni/esterni e membership.

- [ ] **Step 3: Produrre il report locale**

Il report deve contenere soltanto file modificati, test eseguiti, esiti, versione schema e ambiente `local`; non includere SQL dump, token o contenuti `.env`.

- [ ] **Step 4: Verificare il diff senza commit**

```powershell
git diff --check
git status --short
```

Atteso: nessun errore whitespace, soltanto i file pertinenti e nessun commit creato.

## Criteri finali di accettazione

- Un gruppo didattico può essere creato senza ClasseViva.
- Lo step 2 invia solo `id_gruppo[]` e supporta selezione multipla o nessuna selezione.
- I tre provider sono collegabili indipendentemente e con vincoli di unicità user-scoped.
- Una UDA può essere assegnata a più gruppi mediante `UDA_GRUPPI`.
- La mappatura studenti collega ID interni ed esterni, supporta righe incomplete e non persiste PII.
- Le pagine legacy continuano a funzionare attraverso i servizi moderni.
- I popup ClasseViva restano contestuali all'azione che richiede realmente ClasseViva.
- Suite full SQLite/MySQL e collaudo browser locale completati.
- Nessuna credenziale o modifica di staging/produzione coinvolta.
- Nessun commit automatico.
