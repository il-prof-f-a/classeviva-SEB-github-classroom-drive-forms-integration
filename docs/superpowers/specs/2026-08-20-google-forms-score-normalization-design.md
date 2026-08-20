# Normalizzazione punteggi Google Forms e CBM

**Data:** 2026-08-20  
**Task:** BUG-001 — Import Google Forms: punteggi errati  
**Livello test:** full

## Obiettivo

Correggere l'importazione dei risultati Google Forms affinché il denominatore dei punteggi classici e CBM corrisponda sempre alla somma dei punti configurati nelle domande valutate del modulo. Le domande senza risposte corrette devono continuare a contribuire al punteggio massimo e all'analisi.

## Formula confermata

La cronologia del progetto conferma che nei commit `230ebd1e` e `6d1138e4` la normalizzazione era:

```php
$percentuale = ($totalScore / $punteggioMax) * 100;
$cbmPercentuale = ($cbmTotal / $punteggioMax) * 100;
```

Per questa correzione, `punteggioMax` non sarà letto dai massimi osservati nelle risposte. Sarà la somma dei `grading.pointValue` delle domande valutate del Google Form, escluse le domande di confidenza.

Con i parametri CBM predefiniti, `cbmPercentuale` può essere negativa o superiore al 100%; non sarà limitata artificialmente. Ad esempio, una risposta corretta con confidenza massima vale tre volte il peso classico della domanda.

## Causa del difetto

L'import attuale ricava il peso massimo di ogni domanda dal massimo punteggio ottenuto dagli studenti. Se nessuno risponde correttamente, quella domanda assume peso zero. La somma di tali valori viene usata come denominatore e sovrascrive `TEST.punteggio_max`.

Inoltre, la percentuale CBM viene divisa per il punteggio classico ottenuto dal singolo studente anziché per il punteggio massimo del modulo. Anteprima e pagina di analisi ricostruiscono a loro volta i pesi dai risultati osservati, propagando l'errore.

## Architettura proposta

### Modello di punteggio condiviso

La lettura della struttura del modulo sarà separata dalla pagina di importazione in un componente puro e testabile. Il componente riceverà gli item Google Forms già caricati e produrrà:

- mappa `questionId => pointValue` per tutte le domande valutate;
- elenco delle domande escluse perché dedicate alla confidenza;
- totale dei punti configurati;
- eventuali anomalie strutturali, come una domanda valutabile senza punteggio positivo.

Le chiavi saranno i `questionId`, perché sono gli identificativi presenti nelle risposte Google Forms. Gli `itemId` potranno essere usati solo per risolvere mapping storici.

### Fonte autorevole e fallback

La fonte primaria sarà `Question.getGrading().getPointValue()` restituita dalla Forms API.

Per l'analisi di dati già persistiti:

1. usare i pesi salvati in `TEST_CBM_MAPPING.max_score` quando completi;
2. se mancanti e il modulo è accessibile, ricostruirli dalla struttura Forms e salvarli nel mapping;
3. se non è possibile ottenere una mappa completa, mostrare un avviso esplicito e non ricavare i pesi dai punteggi ottenuti dagli studenti.

Non sarà reintrodotto il fallback basato sul massimo osservato delle risposte.

### Importazione

Durante `read_responses` l'importazione:

1. caricherà la struttura del modulo;
2. individuerà le domande di confidenza tramite il mapping CBM;
3. costruirà la mappa dei punti configurati;
4. calcolerà `formMax` come somma della mappa;
5. calcolerà il punteggio classico e CBM con la stessa base `formMax`;
6. aggiornerà `TEST.punteggio_max` esclusivamente con `formMax`, riparando anche valori precedentemente corrotti;
7. aggiornerà `TEST_CBM_MAPPING.max_score` per i test CBM;
8. conserverà la mappa nei dettagli dell'anteprima.

Le formule saranno:

```text
classicPercent = classicTotal / formMax * 100
cbmPercent = cbmTotal / formMax * 100
```

Un modulo senza alcuna domanda valutata o con totale non positivo produrrà un errore comprensibile e interromperà il calcolo dei voti.

### Anteprima e analisi CBM

L'anteprima userà il `max_score` autorevole già associato alle risposte e i pesi configurati inclusi nei dettagli per domanda. Non ricostruirà il denominatore dai risultati degli studenti.

La pagina di analisi userà i pesi persistiti nel mapping. Per i dati storici con pesi mancanti tenterà il recupero dalla Forms API. Domande mai risposte correttamente continueranno quindi a comparire nell'accuratezza, nel CBM medio e nei totali.

La pagina non userà più `score_classico` per inferire il peso massimo di una domanda.

## Persistenza e compatibilità

Non sono necessarie nuove colonne: `TEST_CBM_MAPPING.max_score` esiste già. I dati storici saranno riparati quando il test verrà nuovamente importato o quando l'analisi riuscirà a leggere la struttura del modulo.

La correzione non modifica il modello CBM configurabile, la scelta tra voto classico e voto CBM, né il flusso di pubblicazione verso il registro.

## Gestione errori

- Mapping CBM incompleto: import classico consentito, CBM disabilitato con avviso esistente.
- Domanda di confidenza erroneamente valutata: esclusa dal totale tramite mapping e segnalata nei dati diagnostici.
- Domanda principale senza `pointValue` positivo: errore di configurazione del modulo; nessun voto calcolato con un denominatore parziale.
- Forms API non disponibile durante l'analisi storica: usare solo mapping persistito completo; altrimenti mostrare un avviso senza inventare pesi.
- Totale punti nullo: interrompere l'elaborazione con messaggio esplicito.

## Strategia di test full

I test saranno aggiunti sotto `tests/` e registrati nel runner del progetto. Il ciclo sarà TDD: ogni test di regressione dovrà fallire sul codice attuale prima dell'implementazione.

Casi obbligatori:

- modulo da 15 domande da un punto, soltanto tre mai risposte correttamente: massimo 15 e risultato `3/15`;
- modulo con totale configurato 32: risultati `2/32` e `9/32`;
- domanda con zero risposte corrette: peso conservato e presenza nell'analisi;
- esclusione delle domande di confidenza dal totale;
- CBM normalizzato su `formMax`, non sul punteggio dello studente;
- CBM negativo e CBM superiore al 100% non troncati;
- aggiornamento di `TEST.punteggio_max` soltanto dal totale configurato;
- persistenza e riuso di `TEST_CBM_MAPPING.max_score`;
- errore esplicito per modulo senza punti valutabili;
- compatibilità del flusso non-CBM e della pagina di analisi CBM.

Al termine saranno eseguiti i test mirati, il lint PHP dei file modificati e la suite completa `php tests/run.php`. Eventuali fallimenti preesistenti o dipendenti dall'ambiente saranno distinti dai fallimenti introdotti dalla modifica.

## Criteri di accettazione

- Il test CBM indicato mostra `3/15`, non `3/3`.
- Il test non-CBM indicato mostra `2/32` e `9/32`, non `2/11` e `9/11`.
- Il denominatore non dipende dalle risposte corrette presenti nel campione.
- Classico, CBM, anteprima, grafici e analisi usano la stessa mappa di punti configurati.
- I valori CBM mantengono l'intervallo previsto dal modello, inclusi negativi e valori oltre il 100%.
- Nessun dato viene derivato dal massimo punteggio osservato per domanda.
- I test di regressione e la suite rilevante passano.
