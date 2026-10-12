# Guida navigabile UDA 4L Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Creare una guida HTML locale e navigabile per la home e i flussi dell’UDA UML associata ai gruppi 4L e 4L Informatica.

**Architecture:** La guida è un insieme statico di pagine HTML con un foglio CSS comune e schermate locali. L’indice e i breadcrumb collegano le pagine tra loro; il pulsante Guida della home apre il readme Markdown, che porta all’indice HTML. Le spiegazioni dei pulsanti sono confrontate con le pagine PHP che gestiscono le relative richieste.

**Tech Stack:** HTML, CSS, immagini JPG, PHP esistente.

**Spec:** docs/superpowers/specs/2026-10-12-guida-utente-4l.md

## Global Constraints

- Lavorare nella directory principale del progetto; nessun worktree.
- Limitare i dati d’esempio e le schermate ai gruppi 4L e 4L Informatica e all’UDA UDA_20260820_6a8732bc55837.
- Non creare o alterare dati dell’app mentre si documenta.
- Non esporre l’indirizzo e-mail dell’account né dati di gruppi fuori perimetro.
- La guida descrive la pubblicazione sul registro e non include la funzione esclusa dall’utente.
- Tenere la guida statica e autonoma, senza dipendenze esterne.
- Modificare fuori da guide/ solo il collegamento Guida in public/index.php.
- L’immagine Docker copia il codice in fase di build e non monta il sorgente dell’app; per controllare /guide nell’istanza locale occorre ricostruire e riavviare solo il servizio app, lasciando intatti database e volumi.

## Review Focus

- Link o immagini con percorso errato: il controllo completo in Task 4 verifica ogni destinazione locale e ogni src.
- Dati fuori perimetro nella schermata o nel testo: Task 1 verifica manualmente ciascuna immagine e didascalia.
- Differenza tra i gruppi nella scheda e il campo riepilogativo del modulo di modifica: Task 2 spiega entrambe le viste senza attribuire loro lo stesso dato.
- Pulsante con effetto persistente descritto come semplice navigazione: Task 2 confronta ogni pulsante e link con il gestore PHP prima di spiegare l’effetto.
- Sezione del registro presentata come già utilizzabile senza voti: Task 2 dichiara lo stato attuale dell’UDA e documenta le condizioni rilevate nel codice.

---

### Task 1: Preparare le schermate della guida

**Files:**
- Create: guide/assets/home-4l.jpg
- Create: guide/assets/uda-uml.jpg
- Create: guide/assets/modifica-uda.jpg
- Create: guide/assets/materiali.jpg
- Create: guide/assets/obiettivi.jpg
- Create: guide/assets/domande.jpg
- Create: guide/assets/test.jpg
- Create: guide/assets/laboratorio.jpg
- Create: guide/assets/voti-registro.jpg
- Create: guide/assets/rubrica-orale.jpg
- Create: guide/assets/esportazione.jpg

**Interfaces:**
- Consumes: screenshot già acquisiti dall’istanza Docker durante la ricognizione.
- Produces: immagini JPG locali con contenuto leggibile, ritagliate e prive di dati identificativi.

- [ ] Copiare le schermate utili in guide/assets e acquisire la griglia laboratorio solo per il collegamento della classe ammessa mostrato dalla UDA.
- [ ] Ritagliare la schermata home per rimuovere l’e-mail e la schermata UDA per mantenere i controlli trattati nella guida.
- [ ] Verificare anche la schermata del modulo di modifica prima di inserirla nella sezione UDA.
- [ ] Ritagliare la schermata obiettivi prima delle card non collegate all’UDA, mantenendo filtri e riepilogo.
- [ ] Aprire ogni immagine ritagliata e verificare leggibilità, coerenza con la pagina e assenza di dati fuori perimetro.

### Task 2: Creare pagine HTML e navigazione comune

**Files:**
- Create: guide/guide.css
- Create: guide/readme.md
- Create: guide/index.html
- Create: guide/01-home.html
- Create: guide/02-uda.html
- Create: guide/03-materiali-obiettivi.html
- Create: guide/04-domande.html
- Create: guide/05-test.html
- Create: guide/06-valutazioni.html
- Create: guide/07-esportazione.html

**Interfaces:**
- Consumes: specifica, schermate verificate e gestori delle pagine PHP del flusso UDA.
- Produces: pagine collegate con menu, breadcrumb, indice e link contestuali.

- [ ] Creare uno stile comune con navigazione persistente, titoli chiari, immagini ridimensionabili e tabelle dei controlli.
- [ ] Scrivere l’indice e la pagina Markdown iniziale.
- [ ] Scrivere la pagina home con filtri, ordinamento, riga UDA e azioni di navigazione incluse nel perimetro.
- [ ] Scrivere la scheda UDA, il modulo di modifica e la gestione dei gruppi; chiarire che il dettaglio mostra 4L e 4L Informatica mentre il campo riepilogativo di modifica riporta 4L Informatica.
- [ ] Scrivere materiali e obiettivi con spiegazione di ricerca, selezione/importazione e salvataggio senza assumere contenuti non associati.
- [ ] Scrivere domande e test/attività, descrivendo ogni controllo visibile e gli effetti dedotti dai relativi gestori.
- [ ] Scrivere valutazioni orale/laboratorio, inserimento voti e pubblicazione sul registro; riportare che la UDA osservata non contiene voti e che nessun voto è stato aggiunto.
- [ ] Scrivere esportazione con opzioni, sezioni, password e risultato previsto senza generare un archivio.
- [ ] Aggiungere alt text e didascalie a ogni screenshot; collegare tutte le pagine correlate.

### Task 3: Collegare la home del portale al readme

**Files:**
- Modify: public/index.php

**Interfaces:**
- Consumes: guide/readme.md servito dalla radice statica dell’app.
- Produces: pulsante Guida che punta a ../guide/readme.md.

- [ ] Sostituire soltanto l’URL del pulsante Guida in public/index.php con ../guide/readme.md, mantenendo etichetta e stile.
- [ ] Verificare che il percorso relativo da /public/index.php risolva a /guide/readme.md.

### Task 4: Verifica completa della guida nell’istanza locale

**Files:**
- Verify: guide/**/*.html, guide/readme.md, guide/assets/*, public/index.php

**Interfaces:**
- Consumes: tutte le pagine create e il collegamento home.
- Produces: verifica documentata di percorsi, navigazione, schermate e resa browser.

- [ ] Eseguire git diff --check nel repository app.
- [ ] Eseguire uno script locale di sola lettura che controlla ogni href locale e src immagine di tutte le pagine HTML e verifica che i file esistano.
- [ ] Ricostruire e riavviare soltanto il servizio app per rendere la cartella guide disponibile sulla porta 8080; non ricreare o rimuovere i volumi.
- [ ] Aprire readme.md e ogni pagina HTML tramite il browser, verificare navigazione, immagini e leggibilità a larghezza desktop e stretta.
- [ ] Tornare alla home dell’app e verificare che Guida apra /guide/readme.md.
- [ ] Rivedere il diff finale per confermare che public/index.php sia l’unico file applicativo modificato.
