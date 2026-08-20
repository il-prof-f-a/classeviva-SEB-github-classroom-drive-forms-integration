# Voti provider-neutral e pubblicazione Classroom — Design

## Obiettivo

Consentire l’assegnazione e la consultazione dei voti anche quando il gruppo didattico non ha una mappatura ClasseViva, mantenendo ClasseViva opzionale per le sole operazioni di pubblicazione esterna. La pagina di pubblicazione dei test deve inoltre privilegiare la Classroom mappata all’UDA e la generazione dei Forms deve usare i template dedicati standard/CBM già configurati.

## Perimetro funzionale

1. **Rubrica orale**
   - Il caricamento della rubrica, degli studenti e dei livelli usa il gruppo didattico e l’identità interna/provider-neutral.
   - Il salvataggio di `VALUTAZIONI_RUBRICA` non richiede token né mapping ClasseViva.
   - Le operazioni ClasseViva (annotazione/pubblicazione) restano esplicitamente condizionate alla disponibilità della relativa integrazione.
   - Il form di selezione classe non deve annidarsi nel form POST di salvataggio.

2. **Griglia laboratorio**
   - Il salvataggio locale di `VOTI`/`VALUTAZIONI_LABORATORIO` non viene rifiutato per assenza di ClasseViva.
   - La pubblicazione esterna continua a richiedere una coppia ClasseViva valida.
   - Accanto ad “Aggiorna” e “Mostra descrizioni” viene aggiunto “Visualizza voti assegnati”, collegato a `uda_grades.php?id=<UDA>`.

3. **Pubblicazione test su Classroom**
   - I corsi attivi vengono ordinati stabilmente: prima quelli collegati ai gruppi Classroom assegnati all’UDA, poi gli altri.
   - Il primo corso risultante viene preselezionato, anche quando non esistono mappature (fallback al primo corso disponibile).

4. **Template Google Forms**
   - La creazione di Forms standard usa `google.forms.template_id`.
   - La creazione di Forms CBM usa `google.forms.template_id_cbm`.
   - Si riutilizza `GoogleFormsBuilder` e il relativo comportamento di copia template/fallback già adottato dai flussi UDA; nessuna duplicazione della logica di autenticazione Drive o di ricostruzione mapping CBM.

## Architettura e flusso dati

Le pagine usano `UdaGroupRepository`, `TeachingGroupIntegrationRepository` e `StudentReferenceGateway` come confine del dominio. Il gruppo interno (`id_gruppo`) è la chiave per lettura/scrittura locale; gli identificativi ClasseViva/Google Classroom sono solo riferimenti esterni esposti dal gateway. La capacità di pubblicare su ClasseViva viene verificata solo nel ramo di pubblicazione, non nel ramo di persistenza locale.

Per la rubrica, il gruppo viene risolto dall’assegnazione UDA e gli studenti vengono caricati attraverso le identità disponibili. Il filtro delle valutazioni usa il gruppo interno quando manca `id_classe_cv`. Per la griglia laboratorio il POST conserva il contesto del gruppo e salva il voto locale prima di ogni eventuale tentativo di pubblicazione esterna.

## Error handling

- L’assenza di mappatura ClasseViva non è un errore per il salvataggio locale.
- Errori di autenticazione o di API ClasseViva sono mostrati solo quando l’utente chiede la pubblicazione esterna.
- Un errore di copia template viene mantenuto visibile e produce il fallback esistente alla creazione da zero, senza perdere il test.
- L’ordinamento Classroom è stabile e non altera i corsi restituiti dall’API oltre alla priorità delle mappature.

## Verifica (livello full)

I test coprono:

- salvataggio/lettura di una valutazione orale con gruppo senza integrazione ClasseViva;
- mantenimento del vincolo ClasseViva per la pubblicazione esterna;
- salvataggio locale della griglia laboratorio senza mapping e presenza del link `uda_grades.php`;
- ordinamento e preselezione con mapping, senza mapping e con lista Classroom vuota;
- selezione del template standard e CBM nel builder, inclusa la segnalazione del fallback quando la copia fallisce;
- regressioni dei flussi già esistenti con mapping ClasseViva.

