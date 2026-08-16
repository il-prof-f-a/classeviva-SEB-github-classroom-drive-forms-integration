# Editor dei gruppi didattici e mappatura studenti — Specifica di progetto

**Data:** 2026-08-15  
**Stato:** proposta da validare prima dell'implementazione  
**Ambito:** nuova gestione dei `GRUPPI_DIDATTICI` e rifattorizzazione dello step 2 del wizard UDA

## Obiettivo

Introdurre una pagina unica per creare e gestire i gruppi didattici, cioè le
assegnazioni interne classe-materia, indipendenti da ClasseViva, Google
Classroom e GitHub Classroom.

Lo step 2 di `public/uda_create.php` non creerà più direttamente associazioni
ClasseViva: mostrerà e assegnerà uno o più gruppi didattici già esistenti. La
creazione e la modifica dei gruppi avverranno nella nuova pagina, con ritorno
al wizard e ricaricamento dell'elenco.

## Decisioni approvate

- Il gruppo ha un `id_gruppo` interno e può esistere senza provider esterni.
- Un gruppo può collegare ClasseViva, Google Classroom, GitHub Classroom,
  oppure nessuno o solo alcuni di essi.
- Per ogni provider è previsto inizialmente un solo collegamento principale
  attivo per gruppo.
- Un identificativo esterno dello stesso provider non può appartenere a due
  gruppi dello stesso utente.
- Una UDA può essere assegnata a più gruppi e un gruppo a più UDA.
- La mappatura studenti usa una tabella unica con colonne dinamiche per i
  provider configurati.
- Nomi ed email degli studenti sono dati di visualizzazione temporanei e non
  vengono persistiti.
- Gli studenti possono restare non mappati e possono essere collegati in un
  secondo momento.
- La pagina mantiene la grafica esistente della piattaforma; non sono previsti
  mockup separati.
- Non verranno eseguiti commit automatici né modifiche alla produzione.

## Perimetro funzionale

### Nuova pagina

Creare `public/teaching_groups.php` con due modalità:

- `teaching_groups.php` o `teaching_groups.php?id=...`: dati di base e
  integrazioni;
- `teaching_groups.php?id=...&tab=students`: mappatura studenti.

La pagina deve supportare:

1. elenco filtrabile dei gruppi dell'utente;
2. creazione di un gruppo senza provider;
3. modifica e disattivazione del gruppo;
4. collegamento, sostituzione e rimozione dei tre provider;
5. apertura della mappatura studenti solo dopo il salvataggio del gruppo;
6. ritorno al wizard tramite `return_to=uda_create.php#2`.

### Step 2 del wizard

In `public/uda_create.php`:

- sostituire i selettori dinamici classe/materia con un selettore di gruppi;
- permettere la selezione multipla degli `id_gruppo`;
- mostrare nome gruppo, classe-materia, anno e riepilogo provider;
- aggiungere il link **Gestisci gruppi didattici**;
- preservare i gruppi già selezionati nella bozza/sessionStorage;
- al ritorno dalla pagina editor ricaricare il catalogo e mantenere lo step 2;
- permettere di proseguire anche con zero gruppi;
- non richiedere automaticamente il token ClasseViva.

Al salvataggio UDA utilizzare `UdaGroupRepository::assign()` per ogni gruppo.
La facciata legacy potrà continuare a fornire campi compatibili alle pagine
non ancora rifattorizzate, ma il nuovo flusso non dovrà creare record legacy.

## Modello dati

Usare le tabelle moderne già previste dal dominio provider-neutral.

### `GRUPPI_DIDATTICI`

Contiene dati interni: `id_gruppo`, nome, classe-materia, anno scolastico,
descrizione, stato e proprietario.

### `GRUPPI_INTEGRAZIONI`

Contiene i collegamenti esterni:

- `provider=classeviva`, `tipo_risorsa=classe_materia`,
  `external_context_id=id_classe_cv`, `external_subject_id=id_materia_cv`;
- `provider=google_classroom`, `tipo_risorsa=course`,
  `external_context_id=id_corso_google`;
- `provider=github_classroom`, `tipo_risorsa=roster`,
  `external_context_id=id_roster_github`.

Il provider adapter può conservare metadati tecnici non identificativi in
`metadata_json`. I dati devono essere validati e sempre filtrati per
`id_utente`.

### `UDA_GRUPPI`

È il collegamento principale tra UDA e gruppo. L'eventuale descrizione
`classi_target` dell'UDA resta un dato descrittivo editabile, non la relazione
funzionale.

### Studenti

- `STUDENTI`: identità interna;
- `STUDENTI_IDENTITA_ESTERNE`: coppia provider/ID esterno e contesto;
- `GRUPPI_STUDENTI`: appartenenza al gruppo;
- `STUDENTI_RISORSE_ESTERNE`: repository, assignment e altre risorse.

Non aggiungere colonne `id_studente_cv`, `id_studente_gc`, nomi o email alle
tabelle di dominio.

## Flusso delle integrazioni

La nuova pagina utilizzerà servizi applicativi, non chiamate API direttamente
dalla GUI:

- `TeachingGroupService` per CRUD e regole del gruppo;
- `TeachingGroupIntegrationRepository` per link e vincoli provider;
- cataloghi esistenti per elencare corsi Google e roster GitHub;
- ClasseViva soltanto quando il token è disponibile e l'utente apre il relativo
  selettore;
- `StudentRosterService` per la lettura temporanea dei roster;
- `StudentIdentityResolver` e repository studenti per la persistenza degli ID.

Se un provider non è autorizzato o non risponde:

- il gruppo e gli altri provider restano utilizzabili;
- viene mostrato uno stato esplicativo nella sola sezione interessata;
- non viene aperto un popup ClasseViva se l'azione non richiede ClasseViva;
- il collegamento può essere completato successivamente.

## Mappatura studenti

La modalità studenti mostrerà una riga per studente interno e una colonna per
ogni provider configurato. I dati descrittivi ricevuti dalle API rimangono in
memoria per la sessione della pagina.

Funzioni previste:

- caricamento del roster per provider;
- suggerimenti automatici non vincolanti;
- selezione manuale dell'ID esterno;
- salvataggio e aggiornamento atomico;
- scollegamento di un'identità;
- filtro per mappati, non mappati e conflitti;
- possibilità di lasciare righe incomplete.

Il fuzzy matching esistente sarà estratto in un helper condiviso e usato solo
per proporre abbinamenti, mai per salvarli automaticamente senza conferma.

## Compatibilità con le pagine esistenti

- `map_classes.php` e `github_classroom_mapping.php` verranno mantenute come
  punti di compatibilità, ma i salvataggi confluiranno nei gruppi moderni.
- `map_students.php` verrà adattata a ricevere `id_gruppo` oppure reindirizzata
  alla modalità studenti del nuovo editor.
- I resolver esistenti continueranno a produrre temporaneamente i campi legacy
  necessari alle pagine non ancora convertite.
- Nessuna tabella legacy verrà usata dal nuovo wizard.
- I link di ritorno manterranno `return_to` validato con allowlist locale; non
  saranno consentiti redirect arbitrari.

## Fasi di implementazione

1. **Contratti e test:** test fallenti per CRUD gruppo, vincoli provider,
   assegnazione UDA, scoping utente e assenza PII.
2. **Servizi e repository:** completare i contratti moderni e aggiungere un
   catalogo unico dei gruppi con riepilogo integrazioni.
3. **Pagina editor:** creare la GUI base, i collegamenti provider e i ritorni
   al wizard.
4. **Mappatura studenti:** integrare roster, identità esterne e salvataggio
   atomico nella tabella unica.
5. **Wizard:** sostituire il selettore classe/materia con il selettore gruppi,
   mantenendo bozze, ancore e compatibilità.
6. **Compatibilità:** aggiornare le vecchie pagine per delegare ai servizi
   moderni senza duplicare la logica.
7. **Verifica:** suite PHP, SQLite, MySQL Docker, lint e collaudo browser
   supervisionato.

## Piano di test proposto

Livello: **full**.

Copertura minima:

- gruppo vuoto, gruppo con uno/due/tre provider;
- unicità provider e isolamento tra utenti;
- ritorno al wizard e aggiornamento dell'elenco;
- assegnazione multipla UDA-gruppi;
- mappatura studenti completa, parziale, corretta e annullata;
- identità duplicate o in conflitto;
- nomi/email assenti dal database;
- provider non autorizzato o API indisponibile;
- parità SQLite/MySQL;
- regressione delle vecchie pagine di mappatura;
- popup ClasseViva solo per operazioni che lo richiedono.

## Non-obiettivi della prima implementazione

- redesign generale delle altre pagine;
- supporto a più corsi dello stesso provider nello stesso gruppo;
- sincronizzazione automatica periodica dei roster;
- eliminazione immediata delle pagine e tabelle legacy;
- deploy su staging o produzione.

## Criteri di accettazione

La modifica sarà accettabile quando:

1. un gruppo può essere creato senza ClasseViva;
2. lo step 2 assegna solo `id_gruppo` esistenti;
3. i tre provider possono essere collegati separatamente;
4. una UDA può avere più gruppi;
5. la mappatura studenti usa ID interni ed esterni, senza PII persistita;
6. il ritorno al wizard mantiene l'ancora `#2` e ricarica i gruppi;
7. le pagine legacy continuano a funzionare tramite adapter;
8. i test full SQLite/MySQL passano;
9. nessun segreto viene modificato o incluso;
10. nessun commit viene creato automaticamente.
