# Selettori condivisi per i test nel wizard UDA

## Obiettivo

Semplificare il sesto step del wizard di creazione UDA permettendo di collegare un test o un'attività già esistente tramite un selettore guidato, senza trasformare il wizard in un ambiente di creazione e pubblicazione. Le operazioni avanzate restano nella pagina `public/uda_tests.php`, disponibile dopo la creazione dell'UDA.

Il wizard deve continuare a supportare il collegamento manuale per tutte le piattaforme, mentre l'automazione viene offerta solo quando esiste un'autorizzazione e una mappatura già configurata.

## Messaggio e perimetro del wizard

Lo step Test mostrerà una nota informativa:

> Qui puoi collegare un test o un'attività già esistente tramite link. Dopo la creazione dell'UDA, dalla sezione “Test e attività” potrai creare Google Forms, importare/esportare contenuti, pubblicare su Classroom e gestire gli assignment GitHub.

Il wizard non creerà Google Forms, compiti Classroom o assignment GitHub e non pubblicherà contenuti sulle piattaforme esterne.

## Architettura

### Selettore condiviso

Creare un componente JavaScript riutilizzabile per:

- caricare una raccolta di elementi da un endpoint;
- filtrare localmente per testo;
- visualizzare una lista accessibile e ricercabile;
- restituire l'elemento scelto alla pagina chiamante;
- mostrare stato di caricamento, lista vuota, autorizzazione mancante ed errore;
- permettere alla pagina chiamante di compilare i propri campi.

Il componente non conosce il modello TEST e non salva dati. In questo modo può essere utilizzato nello step 5 dell'importazione domande, nel wizard e successivamente in `uda_tests.php` senza duplicare la logica di ricerca e rendering.

### Normalizzazione server-side

Gli endpoint devono restituire payload omogenei, con almeno:

- `id` e `title`;
- `student_url` e `teacher_url`, quando disponibili;
- `description`;
- `source` e gli identificativi specifici della piattaforma;
- metadati utili alla visualizzazione.

La pagina `uda_create.php` mapperà il risultato ai propri campi e salverà sempre:

- `url_studenti` come link destinato allo studente;
- `url_docente` come link di gestione/risultati, se disponibile;
- `url` come alias legacy di `url_studenti`;
- gli ID Classroom/GitHub quando presenti.

I campi storici `num_domande`, `durata_minuti` e `punteggio_max` resteranno nel database e continueranno a essere valorizzati con i default già previsti, senza essere reintrodotti nell'interfaccia del wizard.

## Flussi per piattaforma

### Google Forms

Riutilizzare `GoogleFormsCatalog`, `ajax_list_google_forms.php` e la logica di ricerca già presente in `public/import_questions.php` e `public/assets/js/import-questions.js`.

La lista sarà filtrabile per titolo, autore e data e mostrerà anche il numero di risposte. Il catalogo dovrà fornire entrambi i link quando l'API li rende disponibili:

- link docente/gestione al modulo in modifica o alla pagina risposte;
- link studenti/responder al modulo compilabile.

Se il catalogo non dispone del responder URL, il server lo ricaverà dal Form API usando l'ID del modulo, senza costruire URL basati su dati non verificati. In assenza di autorizzazione Drive/Forms verrà mostrato il messaggio esistente con il collegamento alle integrazioni Google e resterà disponibile l'inserimento manuale.

La selezione compilerà nome, descrizione, URL studenti e URL docente; il tipo piattaforma sarà `google-forms`.

### Google Classroom

Riutilizzare `ajax_get_classroom_courses.php` e `ajax_get_classroom_assignments.php`, già utilizzati dal popup di `public/uda_tests.php`. La logica di normalizzazione dei compiti dovrà essere spostata in un helper condiviso o in un metodo di `GoogleClassroomAPI`, lasciando l'endpoint come adattatore HTTP.

Nel wizard saranno mostrati soltanto i corsi Classroom associati alle classi/materie selezionate nello step 2 tramite `CLASSROOM_MAPPINGS`. Se non esiste una mappatura, il selettore mostrerà una spiegazione e il fallback manuale.

Dopo la scelta del corso verrà caricata una lista ricercabile dei compiti, con titolo, stato, scadenza, argomento/topic e data di aggiornamento. La selezione compilerà:

- nome e descrizione;
- link studenti/docente (`alternateLink`, uguale quando l'API non distingue i ruoli);
- `classroom_course_id`;
- `classroom_assignment_id`;
- `classroom_topic_id`;
- piattaforma `google-classroom`.

Il wizard non mostrerà il ramo “Crea nuovo compito”: quello resterà nella gestione avanzata dei test.

### GitHub Classroom

Riutilizzare `GitHubIntegration::listAssignments()` e la mappatura `GITHUB_CLASSROOMS`. Creare un endpoint sottile e un normalizzatore condiviso con il flusso di `github_classroom_mapping.php`, così da gestire le diverse forme della risposta GitHub (`title`/`name`, `invite_link`/`invitation_link`, URL docente e slug).

Saranno mostrati solo gli assignment della GitHub Classroom associata alla Classe-Materia selezionata. La selezione compilerà:

- nome assignment;
- link invito studenti;
- link gestione docente, se restituito dall'API;
- `github_classroom_id`;
- `github_assignment_id`;
- `url_assignment_student` e `url_assignment_teacher`;
- piattaforma `github`.

Se GitHub non è autorizzato, la mappatura non esiste o il token non dispone dei permessi Classroom, verrà mostrato un messaggio non bloccante e resterà disponibile il collegamento manuale.

### Kahoot, Socrative e Altro

Nessuna automazione. Verranno mantenuti i campi manuali per il link studenti e, opzionalmente, per il link docente/risultati.

## Collegamento con le classi assegnate

Il wizard non deve inventare associazioni. Le mappature disponibili saranno determinate dopo la selezione delle classi nello step 2:

1. identificare le coppie ClasseViva classe/materia selezionate;
2. cercare le corrispondenti righe in `CLASSROOM_MAPPINGS` e `GITHUB_CLASSROOMS`;
3. proporre solo i corsi/classroom realmente associati;
4. se non è disponibile una corrispondenza univoca, richiedere la scelta esplicita oppure usare il fallback manuale.

## Gestione errori e autorizzazioni

- Token assente o scaduto: messaggio chiaro e link alle integrazioni, senza errori PHP visibili.
- Scope insufficiente: indicare che è necessaria una nuova autorizzazione.
- Nessun elemento: lista vuota con spiegazione e inserimento manuale disponibile.
- Errore API temporaneo: messaggio nella sezione del selettore, senza perdere i dati già inseriti nel test.
- URL incompleto dell'elemento selezionato: non consentire il salvataggio automatico; mantenere il campo manuale per la correzione.

## Test e criteri di accettazione

### Test automatici

- Google Forms: normalizzazione dei link, metadati e filtro del catalogo.
- Classroom: normalizzazione di un assignment e gestione di campi mancanti.
- GitHub: normalizzazione delle varianti di titolo/link e associazione degli ID.
- Selettore: lista vuota, ricerca, selezione e gestione degli errori.
- Mapping: nessuna proposta automatica quando manca una mappatura.
- Persistenza wizard: `url`, `url_studenti`, `url_docente` e ID esterni salvati correttamente.

### Verifica manuale

Per ciascuna piattaforma verificare:

1. apertura dello step e visualizzazione del messaggio informativo;
2. caricamento del catalogo autorizzato;
3. filtro testuale;
4. selezione di un elemento e compilazione dei campi;
5. modifica manuale dei campi prima del riepilogo;
6. creazione dell'UDA e verifica del record nella pagina Test e attività;
7. comportamento senza autorizzazione o senza mappatura.

## Fuori ambito

- creazione di test o assignment dal wizard;
- pubblicazione su Google Classroom o GitHub Classroom;
- modifica dello schema database;
- rimozione dei campi legacy;
- deploy su staging o produzione.

