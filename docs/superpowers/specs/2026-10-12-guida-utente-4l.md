# Specifica: guida navigabile del portale per l’UDA di 4L

## Obiettivo

Realizzare una guida utente HTML, in italiano, che parta dalla home del portale e documenti con schermate e istruzioni pratiche le funzioni collegate all’UDA “UML” dell’anno 2026-27, associata ai gruppi 4L e 4L Informatica.

## Perimetro funzionale

La guida copre:

- home: navigazione, filtri, ordinamento della tabella UDA e azioni disponibili sulla riga dell’UDA selezionata;
- scheda UDA: lettura dei dati, modifica, assegnazione dei gruppi 4L ammessi e collegamenti alle sezioni;
- materiali e obiettivi: aggiunta/consultazione dei materiali, selezione dal repository e importazione degli obiettivi;
- domande: consultazione, importazione, creazione e generazione di domande per questa UDA;
- test e attività: schede, collegamenti, importazione dei risultati e modifiche disponibili;
- rubriche orale e laboratorio: accesso, selezione contestuale del gruppo ammesso e operazioni mostrate dal codice;
- voti e registro: inserimento manuale, modifica/eliminazione delle valutazioni esistenti e flusso per la pubblicazione sul registro;
- esportazione: sezioni selezionabili e contenuti dell’archivio generato;
- navigazione di ritorno verso la home e verso la sezione UDA collegata.

La documentazione di ogni controllo distingue i collegamenti di navigazione dalle azioni che salvano, eliminano, esportano o pubblicano. Il comportamento è verificato nell’interfaccia e nel codice della relativa pagina.

## Vincoli

- Limitare i dati d’esempio e le schermate a 4L, 4L Informatica e all’UDA indicata.
- Non creare UDA, voti, obiettivi, domande, materiali o test, e non salvare, eliminare, esportare o pubblicare dati durante la ricognizione.
- Usare nomi segnaposto negli esempi con studenti.
- Non includere nell’elaborato la funzione che l’utente ha escluso; la sezione di pubblicazione tratta solo il registro.
- Ritagliare o oscurare dagli screenshot l’indirizzo e-mail dell’account e qualsiasi dettaglio fuori perimetro.
- Scrivere in HTML e CSS statici, senza framework o dipendenze esterne per la guida.
- Rendere ogni pagina navigabile tramite indice, breadcrumb e collegamenti alle pagine correlate.

## Struttura prevista

- guide/readme.md: pagina introduttiva collegata dal pulsante Guida della home, con collegamento alla guida HTML.
- guide/index.html: indice e percorso consigliato.
- guide/01-home.html: home e filtri.
- guide/02-uda.html: scheda, modifica e gruppi.
- guide/03-materiali-obiettivi.html: materiali e obiettivi.
- guide/04-domande.html: gestione delle domande.
- guide/05-test.html: test e attività.
- guide/06-valutazioni.html: rubriche, laboratorio, voti e registro.
- guide/07-esportazione.html: esportazione.
- guide/assets/: schermate locali con testo alternativo e didascalia.
- guide/guide.css: stile comune, leggibile su schermi stretti e larghi.

## Criteri di accettazione

1. Dalla home, il pulsante Guida apre guide/readme.md; da questo si raggiunge guide/index.html.
2. Ogni pagina HTML ha collegamenti validi all’indice, alla pagina precedente/successiva e alle pagine collegate nel flusso.
3. Ogni funzione in perimetro riporta lo scopo, i passaggi principali e il comportamento dei pulsanti/link, inclusi gli effetti delle azioni che non sono state eseguite.
4. Le schermate corrispondono alle pagine descritte e non espongono indirizzi e-mail né contenuti di altri gruppi.
5. I contenuti sul registro distinguono il modulo manuale, l’elenco dei voti e la pubblicazione; spiegano quando l’azione appare o è disponibile secondo il codice.
6. Le pagine si aprono dal percorso statico /guide/ nell’app Docker e la guida non richiede risorse web esterne.
7. La revisione completa verifica tutti i link interni e le immagini, il collegamento della home e la resa nel browser.
