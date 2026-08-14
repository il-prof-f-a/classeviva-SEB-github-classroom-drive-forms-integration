# Selettori condivisi per i test nel wizard UDA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consentire allo step Test del wizard UDA di collegare test Google Forms, compiti Google Classroom e assignment GitHub Classroom già esistenti tramite selettori ricercabili, mantenendo il fallback manuale e spostando le operazioni avanzate nella pagina Test e attività.

**Architecture:** Un picker JavaScript generico gestirà caricamento, ricerca, stati ed evento di selezione. Gli endpoint server restituiranno payload normalizzati per ciascuna piattaforma, verificando token, associazioni ClasseViva e autorizzazioni. `uda_create.php` mapperà il risultato ai campi TEST esistenti (`url`, `url_studenti`, `url_docente` e identificativi specifici) senza modificare lo schema.

**Tech Stack:** PHP 8.2, Google API Client, GitHub REST API, JavaScript browser senza framework, Bootstrap 5, test PHP/Node già presenti in `tests/`.

**Vincoli:** Lavorare nella repo principale di codice, non creare worktree e non eseguire commit automatici. I campi legacy del database restano intatti. Il wizard non crea né pubblica risorse esterne.

---

## Mappa dei file

### Nuovi file

- `src/Integration/ClassroomAssignmentCatalog.php` — recupero e normalizzazione dei compiti Google Classroom.
- `src/Integration/GitHubAssignmentCatalog.php` — normalizzazione degli assignment GitHub e gestione delle varianti della risposta API.
- `public/ajax_get_wizard_classroom_catalog.php` — endpoint autenticato per corsi/compiti Classroom associati alle classi selezionate.
- `public/ajax_get_wizard_github_catalog.php` — endpoint autenticato per assignment GitHub della classroom associata.
- `public/assets/js/catalog-picker.js` — picker generico con ricerca e stati UI.
- `tests/uda_editor/test_catalog_normalizers.php` — test PHP per i normalizzatori.
- `tests/uda_editor/test_catalog_picker.js` — test Node per il picker usando DOM minimale simulato.
- `tests/uda_editor/test_wizard_catalog_markup.php` — test statico del markup e dei contratti JS del wizard.

### File da modificare

- `src/Integration/GoogleFormsCatalog.php` — aggiungere URL studente e metadati Form necessari al picker, conservando le chiavi già usate dallo step 5.
- `public/ajax_list_google_forms.php` — esporre il payload esteso senza cambiare la gestione degli errori OAuth.
- `src/Integration/GoogleClassroomAPI.php` — delegare la normalizzazione dei courseWork al nuovo catalogo, mantenendo compatibilità con i chiamanti esistenti.
- `public/ajax_get_classroom_assignments.php` — usare il catalogo condiviso come adattatore compatibile per `uda_tests.php`.
- `src/Integration/GitHubIntegration.php` — lasciare `listAssignments()` invariato salvo eventuali dati di paginazione necessari al catalogo.
- `public/uda_create.php` — aggiungere il messaggio informativo, i pannelli piattaforma, gli attributi data per il picker e il mapping dei risultati nei campi del test.
- `public/assets/js/import-questions.js` — usare il picker condiviso per il catalogo Google Forms senza cambiare il comportamento dello step 5.
- `tests/run.php` — registrare i nuovi test PHP e Node.

## Task 1: Estendere e testare il catalogo Google Forms

**Files:**

- Modify: `src/Integration/GoogleFormsCatalog.php`
- Modify: `public/ajax_list_google_forms.php`
- Create: `tests/uda_editor/test_catalog_normalizers.php`

- [ ] **Step 1: Scrivere i test fallenti per il payload Google Forms**

In `tests/uda_editor/test_catalog_normalizers.php` aggiungere test che includano:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/Integration/GoogleFormsCatalog.php';

use App\Integration\GoogleFormsCatalog;

$form = GoogleFormsCatalog::normalizeFile([
    'id' => 'form-1',
    'name' => 'Verifica reti',
    'createdTime' => '2026-08-10T09:00:00Z',
    'webViewLink' => 'https://docs.google.com/forms/d/form-1/edit',
    'responderUri' => 'https://docs.google.com/forms/d/e/form-1/viewform',
    'description' => 'Test finale',
    'owners' => [['displayName' => 'Docente Test']],
], 4);

if (($form['student_url'] ?? '') !== 'https://docs.google.com/forms/d/e/form-1/viewform') {
    fwrite(STDERR, "FAIL: responder URL Google Forms\n");
    exit(1);
}
if (($form['teacher_url'] ?? '') === '' || ($form['description'] ?? '') !== 'Test finale') {
    fwrite(STDERR, "FAIL: metadati Google Forms\n");
    exit(1);
}
if (count(GoogleFormsCatalog::filter([$form], 'reti')) !== 1) {
    fwrite(STDERR, "FAIL: filtro catalogo Google Forms\n");
    exit(1);
}
echo "PASS: Google Forms catalog normalizer\n";
```

- [ ] **Step 2: Eseguire il test e verificare il fallimento atteso**

Run: `php tests/uda_editor/test_catalog_normalizers.php`

Expected: FAIL perché `normalizeFile()` non espone ancora `student_url` e `description`.

- [ ] **Step 3: Implementare la normalizzazione minima**

In `GoogleFormsCatalog::normalizeFile()`:

1. leggere `responderUri` dal file/API quando disponibile;
2. usare il Form API per ottenere `responderUri` e `info.description` solo quando il catalogo ha già il client autenticato;
3. usare il fallback `/viewform` solo per form ID compatibili e documentare il fallback nel payload;
4. restituire `student_url`, `teacher_url`, `description`, `id`, `title`, `author`, `created_at`, `response_count`;
5. mantenere `teacher_url` e tutte le chiavi già consumate da `import-questions.js`.

Aggiornare `ajax_list_google_forms.php` solo per serializzare il nuovo payload e mantenere `required_scopes`, `error_code`, HTTP 401/403 e messaggi esistenti.

- [ ] **Step 4: Verificare il test e la compatibilità esistente**

Run: `php tests/uda_editor/test_catalog_normalizers.php`

Expected: `PASS: Google Forms catalog normalizer`.

Run: `php tests/import_questions/test_google_forms_catalog.php`

Expected: PASS, senza modifiche alle chiavi precedenti.

## Task 2: Estrarre il catalogo Google Classroom

**Files:**

- Create: `src/Integration/ClassroomAssignmentCatalog.php`
- Modify: `src/Integration/GoogleClassroomAPI.php`
- Modify: `public/ajax_get_classroom_assignments.php`
- Create: `tests/uda_editor/test_catalog_normalizers.php`

- [ ] **Step 1: Aggiungere i casi di normalizzazione Classroom**

Nel test condiviso aggiungere:

```php
$assignment = \App\Integration\ClassroomAssignmentCatalog::normalize([
    'id' => 'cw-1',
    'title' => 'Verifica scheduling',
    'description' => 'Consegna il file',
    'state' => 'PUBLISHED',
    'maxPoints' => 10,
    'alternateLink' => 'https://classroom.google.com/c/course/a/cw-1/details',
    'topicId' => 'topic-1',
    'creationTime' => '2026-08-10T09:00:00Z',
    'updateTime' => '2026-08-11T09:00:00Z',
]);

if (($assignment['id'] ?? '') !== 'cw-1'
    || ($assignment['title'] ?? '') !== 'Verifica scheduling'
    || ($assignment['student_url'] ?? '') === ''
    || ($assignment['teacher_url'] ?? '') === '') {
    fwrite(STDERR, "FAIL: normalizzazione Classroom\n");
    exit(1);
}
```

- [ ] **Step 2: Eseguire il test per confermare il fallimento**

Run: `php tests/uda_editor/test_catalog_normalizers.php`

Expected: FAIL perché la classe non esiste.

- [ ] **Step 3: Implementare `ClassroomAssignmentCatalog`**

Creare metodi pubblici:

```php
public static function normalize(mixed $courseWork): array;
public static function isImportable(array $assignment): bool;
public static function filter(array $assignments, string $query): array;
```

`normalize()` deve restituire `id`, `title`, `description`, `state`, `max_points`, `due_date`, `topic_id`, `link`, `student_url`, `teacher_url`, `creation_time`, `update_time`, `source = google-classroom`, `work_type`. Accettare sia oggetti Google API sia array nei test.

`isImportable()` deve accettare solo `ASSIGNMENT` e `QUIZ_ASSIGNMENT`, mantenendo la regola già presente nell’endpoint.

- [ ] **Step 4: Far usare il catalogo all’endpoint esistente**

In `ajax_get_classroom_assignments.php` mantenere il parametro `course_id`, il JSON `{success, assignments}` e gli errori correnti, ma sostituire la costruzione inline con `ClassroomAssignmentCatalog::normalize()` e `isImportable()`.

In `GoogleClassroomAPI.php` aggiungere, se utile ai chiamanti, un metodo `getCourseAssignments(string $courseId): array` che effettui paginazione e restituisca il catalogo normalizzato. L’endpoint esistente può delegare a questo metodo; `uda_tests.php` non deve cambiare il contratto HTTP.

- [ ] **Step 5: Verificare Classroom e regressioni**

Run: `php tests/uda_editor/test_catalog_normalizers.php`

Expected: PASS per Google Forms e Classroom.

Run: `php -l src/Integration/ClassroomAssignmentCatalog.php; php -l public/ajax_get_classroom_assignments.php; php -l src/Integration/GoogleClassroomAPI.php`

Expected: nessun errore di sintassi.

## Task 3: Estrarre il catalogo GitHub Classroom

**Files:**

- Create: `src/Integration/GitHubAssignmentCatalog.php`
- Create: `public/ajax_get_wizard_github_catalog.php`
- Modify: `tests/uda_editor/test_catalog_normalizers.php`

- [ ] **Step 1: Scrivere il test GitHub fallente**

Nel test condiviso aggiungere:

```php
$githubAssignment = \App\Integration\GitHubAssignmentCatalog::normalize([
    'id' => 123,
    'title' => 'Laboratorio API',
    'slug' => 'laboratorio-api',
    'invite_link' => 'https://classroom.github.com/a/ABC123',
    'html_url' => 'https://classroom.github.com/classrooms/55/assignments/123',
]);

if (($githubAssignment['id'] ?? '') !== '123'
    || ($githubAssignment['student_url'] ?? '') !== 'https://classroom.github.com/a/ABC123'
    || ($githubAssignment['github_classroom_id'] ?? '') !== '55') {
    fwrite(STDERR, "FAIL: normalizzazione GitHub Classroom\n");
    exit(1);
}
```

- [ ] **Step 2: Eseguire il test e verificare il fallimento**

Run: `php tests/uda_editor/test_catalog_normalizers.php`

Expected: FAIL perché la classe non esiste.

- [ ] **Step 3: Implementare il normalizzatore GitHub**

Creare:

```php
public static function normalize(array $assignment, ?string $classroomId = null): array;
public static function filter(array $assignments, string $query): array;
```

Gestire le varianti già osservate in `github_classroom_mapping.php` e `github_assignment_review.php`: `title`/`name`/`assignment_title`, `invite_link`/`invitation_link`/`invite_url`, `html_url`/`url`/`teacher_url`, `slug`. Derivare `github_classroom_id` dall’URL solo quando manca il valore esplicito. Non fare chiamate API nel normalizzatore.

- [ ] **Step 4: Creare l’endpoint autenticato**

`ajax_get_wizard_github_catalog.php` deve:

1. caricare bootstrap e sessione;
2. richiedere `classroom_id` e le coppie ClasseViva selezionate;
3. verificare la mappatura in `GITHUB_CLASSROOMS` tramite `id_classe_cv`, `id_materia_cv` e `github_classroom_id` usando il database user-scoped;
4. chiamare `GitHubIntegration::loadTokenFromSession()` e rifiutare il catalogo se non autenticato;
5. paginare `listAssignments()` fino a esaurimento o a 10 pagine da 100 elementi;
6. restituire `{success:true, assignments:[...]}` oppure `{success:false, error_code, error}` senza stack trace.

La risposta non deve includere token o credenziali.

- [ ] **Step 5: Verificare il catalogo**

Run: `php tests/uda_editor/test_catalog_normalizers.php`

Expected: PASS per tutti i normalizzatori.

Run: `php -l src/Integration/GitHubAssignmentCatalog.php; php -l public/ajax_get_wizard_github_catalog.php`

Expected: nessun errore di sintassi.

## Task 4: Creare il picker JavaScript condiviso

**Files:**

- Create: `public/assets/js/catalog-picker.js`
- Modify: `public/assets/js/import-questions.js`
- Create: `tests/uda_editor/test_catalog_picker.js`

- [ ] **Step 1: Scrivere il test Node fallente**

Il test deve verificare ricerca e selezione con un mock DOM minimo:

```js
const assert = require('assert');
const { filterItems, getSelectedItem } = require('../../public/assets/js/catalog-picker.js');

const items = [
  { id: '1', title: 'Verifica reti', author: 'Docente' },
  { id: '2', title: 'Laboratorio API', author: 'Docente' }
];

assert.strictEqual(filterItems(items, 'reti').length, 1);
assert.strictEqual(getSelectedItem(items, '2').title, 'Laboratorio API');
console.log('PASS: catalog picker utilities');
```

- [ ] **Step 2: Eseguire il test e verificare il fallimento**

Run: `node tests/uda_editor/test_catalog_picker.js`

Expected: FAIL perché il modulo non esiste.

- [ ] **Step 3: Implementare il modulo senza framework**

Esportare sempre `filterItems(items, query)` e `getSelectedItem(items, id)` per i test Node. Nel browser esporre `window.CatalogPicker` con:

```js
new CatalogPicker({
  searchInput, listContainer, statusContainer,
  loadItems, renderItem, onSelect
});
```

Il picker deve usare `textContent` per i valori API, mantenere l’elemento selezionato evidenziato, filtrare titolo/autore/data, mostrare loading/empty/error e non cancellare i valori dei campi manuali in caso di errore.

- [ ] **Step 4: Integrare lo step 5 senza duplicare il comportamento**

Adattare `public/assets/js/import-questions.js` a `CatalogPicker` per il catalogo Forms. Conservare `window.loadGoogleFormsCatalog`, `formsUrlInput`, il messaggio di autorizzazione e il payload esistente, così i test dello step 5 restano validi.

- [ ] **Step 5: Verificare il picker**

Run: `node tests/uda_editor/test_catalog_picker.js`

Expected: `PASS: catalog picker utilities`.

Run: `php tests/import_questions/test_google_forms_catalog.php; php tests/import_questions/test_import_questions_markup.php`

Expected: PASS.

## Task 5: Integrare il catalogo nel wizard UDA

**Files:**

- Modify: `public/uda_create.php`
- Create: `public/ajax_get_wizard_classroom_catalog.php`
- Modify: `tests/uda_editor/test_wizard_catalog_markup.php`

- [ ] **Step 1: Scrivere il test statico del markup**

Il test deve leggere `public/uda_create.php` e verificare la presenza di:

```php
foreach ([
    'Dopo la creazione dell\'UDA',
    'catalog-picker.js',
    'google-forms',
    'google-classroom',
    'github',
    'test_url_studenti[]',
    'test_url_docente[]',
    'test_classroom_assignment_id[]'
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$needle}\n");
        exit(1);
    }
}
echo "PASS: wizard catalog markup\n";
```

- [ ] **Step 2: Creare l’endpoint Classroom vincolato alle mappature**

`ajax_get_wizard_classroom_catalog.php` deve ricevere `class_ids[]` e `subject_ids[]`, caricare le righe corrispondenti in `CLASSROOM_MAPPINGS`, restituire i corsi associati e, se viene passato `course_id`, i compiti normalizzati. Deve mantenere i corsi senza duplicati e rifiutare un `course_id` non presente nella mappatura user-scoped.

Payload minimo:

```json
{"success":true,"courses":[{"id":"...","name":"...","class_id":"...","subject_id":"..."}],"assignments":[]}
```

- [ ] **Step 3: Aggiungere il messaggio informativo e la selezione piattaforma**

Nel template `addTest()`:

1. mostrare il messaggio approvato;
2. aggiungere `github` alle piattaforme;
3. lasciare Kahoot/Socrative/Altro con input manuali;
4. aggiungere pannelli Forms, Classroom e GitHub con `data-catalog-picker` e campi di ricerca/lista;
5. mantenere i campi URL studenti/docente modificabili dopo la selezione;
6. non aggiungere i campi nascosti `num_domande`, `durata_minuti`, `punteggio_max` all’interfaccia.

- [ ] **Step 4: Collegare i picker al mapping del wizard**

Nel JavaScript di `uda_create.php`:

- leggere le coppie classe/materia presenti nello step 2;
- quando viene scelta Google Forms, chiamare `ajax_list_google_forms.php` e compilare nome, descrizione, `test_url_studenti[]`, `test_url_docente[]`;
- quando viene scelto Google Classroom, chiamare l’endpoint wizard con le coppie selezionate, mostrare solo i corsi associati, poi caricare i compiti dal corso scelto;
- quando viene scelto GitHub, chiamare l’endpoint wizard e compilare `test_url_studenti[]`, `test_url_docente[]`, `test_classroom_*` o `test_github_*` corretti;
- selezionare manualmente i link senza bloccare il salvataggio se il catalogo non è autorizzato;
- cancellare i valori specifici della piattaforma quando l’utente cambia piattaforma, evitando dati residui.

Per ogni risultato selezionato usare `textContent` e `encodeURIComponent`; non inserire direttamente HTML proveniente dalle API.

- [ ] **Step 5: Completare il mapping server-side del test**

Nel blocco POST di `uda_create.php` mantenere:

```php
'url' => $urlStud,
'url_studenti' => $urlStud,
'url_docente' => $urlDoc,
'classroom_course_id' => $_POST['test_classroom_course_id'][$index] ?? '',
'classroom_assignment_id' => $_POST['test_classroom_assignment_id'][$index] ?? '',
'classroom_topic_id' => $_POST['test_classroom_topic_id'][$index] ?? '',
```

Aggiungere, se il markup li prevede, `github_classroom_id`, `github_assignment_id`, `url_assignment_student` e `url_assignment_teacher`, senza rimuovere i fallback legacy.

- [ ] **Step 6: Verificare markup e sintassi**

Run: `php tests/uda_editor/test_wizard_catalog_markup.php`

Expected: `PASS: wizard catalog markup`.

Run: `php -l public/uda_create.php; php -l public/ajax_get_wizard_classroom_catalog.php`

Expected: nessun errore di sintassi.

## Task 6: Registrare i test e fare la verifica completa

**Files:**

- Modify: `tests/run.php`
- Modify: `tests/README.md`

- [ ] **Step 1: Registrare i test nel runner**

Inserire in `$editorTests`:

```php
'catalog normalizers' => __DIR__ . '/uda_editor/test_catalog_normalizers.php',
'wizard catalog markup' => __DIR__ . '/uda_editor/test_wizard_catalog_markup.php',
```

Nel blocco Node aggiungere:

```php
exec('node ' . escapeshellarg(__DIR__ . '/uda_editor/test_catalog_picker.js') . ' 2>&1', $catalogPickerOutput, $catalogPickerExitCode);
if ($catalogPickerExitCode === 0) {
    $passes++;
    echo "PASS: catalog picker JavaScript\n";
} else {
    $failures[] = 'Catalog picker JavaScript: ' . implode(' | ', $catalogPickerOutput);
    echo "FAIL: catalog picker JavaScript\n";
}
```

- [ ] **Step 2: Aggiornare la guida dei test**

In `tests/README.md` documentare:

- comando `php tests/run.php`;
- comando Node opzionale;
- test con token/mappatura assenti;
- verifica manuale locale dei tre cataloghi;
- divieto di inserire credenziali nei fixture.

- [ ] **Step 3: Eseguire la suite completa**

Run: `php tests/run.php`

Expected: `Summary: <numero> passed, 0 failed`.

Run: `node tests/uda_editor/test_catalog_picker.js`

Expected: `PASS: catalog picker utilities`.

- [ ] **Step 4: Eseguire la verifica manuale locale**

Con Docker locale attivo:

1. aprire `http://localhost:8080/public/uda_create.php`;
2. selezionare classi e materia nello step 2;
3. nello step Test verificare il messaggio informativo;
4. provare Google Forms con autorizzazione e selezione filtrata;
5. provare Google Classroom con mappatura e compito selezionato;
6. provare GitHub Classroom con mappatura e assignment selezionato;
7. provare Kahoot/Socrative/Altro con link manuale;
8. ripetere senza autorizzazioni e verificare il fallback manuale;
9. creare l’UDA e controllare `public/uda_tests.php`.

## Criteri di completamento

- Il catalogo Forms dello step 5 continua a funzionare e usa il picker condiviso.
- Il wizard non crea né pubblica risorse esterne.
- I cataloghi Classroom/GitHub mostrano solo risorse consentite dalle mappature selezionate.
- Kahoot, Socrative e Altro restano manuali.
- `url`, `url_studenti`, `url_docente` e gli ID esterni sono persistiti correttamente.
- Gli errori di autorizzazione non mostrano stack trace o credenziali.
- La suite automatica termina con zero errori.
- Nessun file viene committato o distribuito automaticamente.
