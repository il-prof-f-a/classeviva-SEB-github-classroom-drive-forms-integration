# Stato del sistema e debug

*Capitolo 10 · controlli diagnostici e manutenzione*

Le pagine diagnostiche aiutano a distinguere un problema di PHP, database, configurazione, token o provider remoto. Sono consultabili prima di intervenire, ma alcune pagine affiancano ai controlli sicuri anche comandi di riparazione o cancellazione.

## Stato sistema

**Stato Sistema** riassume versione PHP, estensioni, Composer, fogli/tabelle del database, file di configurazione, integrazioni e numero di controlli superati. Un avviso indica una condizione da esaminare; un errore non significa automaticamente che ogni funzione sia indisponibile.

**Stato Sistema Avanzato** aggiunge pacchetti, tabelle, configurazione caricata e stato dei token. Può mostrare avvisi PHP generati dalla pagina stessa: registrali come indizio, poi riproduci il problema nella funzione interessata.

Non pubblicare screenshot o log diagnostici senza oscurare email, percorsi assoluti, host, identificativi, header, token e messaggi SQL.

## Test di sistema

La pagina **Test Sistema** controlla PHP, ZIP/XML/cURL/GD, PhpSpreadsheet, il file Excel principale e la scrivibilità di storage e log. Un test fallito va letto insieme alla configurazione dell’ambiente:

| Esito | Prima verifica |
| --- | --- |
| PHP/estensione | Versione e modulo caricati nel container. |
| PhpSpreadsheet | Dipendenze Composer e permessi di lettura. |
| Database o file Excel | Percorso, montaggio del volume e disponibilità del file. |
| Storage/log | Permessi della directory usata dal processo PHP. |

## Database e pulizia

**Gestione Database** elenca motore, tabelle/fogli e migrazioni pendenti; può offrire validazione, riparazione, inizializzazione e import/export. **Pulizia Tabelle DB** permette di scegliere una tabella e cancellare righe dopo un controllo. Queste azioni non sono necessarie per una diagnosi iniziale e richiedono backup verificato.

Procedura prudente:

1. acquisisci lo stato e i log senza modificare nulla;
2. controlla `.env` e i volumi Docker;
3. valida lo schema;
4. fai un backup esportabile;
5. solo dopo valuta una riparazione mirata;
6. evita inizializzazione da zero e cleanup su un ambiente con dati reali.

## Checklist rapida per un errore di login o integrazione

1. Il container PHP risponde e la sessione viene mantenuta?
2. L’utente è autenticato nel provider corretto?
3. Il token esiste, è valido e ha gli scope necessari?
4. Gli identificativi di corso, cartella, form o classe appartengono all’account corrente?
5. Il provider remoto risponde senza timeout o errori di autorizzazione?
6. I log mostrano un errore applicativo dopo la richiesta, senza includere segreti nella segnalazione?

Durante la ricognizione queste verifiche sono state usate in sola lettura; non sono stati eseguiti repair, inizializzazioni, cleanup, invii o pubblicazioni.

## Pagine diagnostiche esplorate

| Pagina | Cosa leggere |
| --- | --- |
| [Stato sistema](../public/status.php) | Riepilogo rapido di ambiente, configurazione e integrazioni. |
| [Stato sistema avanzato](../public/system_status.php) | Pacchetti, tabelle, configurazioni caricate e avvisi PHP. |
| [Test sistema](../public/test.php) | Estensioni PHP, librerie, file principali e permessi. |
| [Gestione database](../public/database_manager.php) | Schema, migrazioni, validazione e operazioni di manutenzione. |
| [Pulizia tabelle](../public/db_cleanup.php) | Controllo e cancellazione di righe per tabella. |
| [Gestione voti](../public/manage_grades.php) | Selezione di classe/materia e accesso al registro. |
| [Verifica voti](../public/verify_grades.php) | Stato di sincronizzazione delle valutazioni. |

Queste pagine sono state aperte senza usare i pulsanti di riparazione, inizializzazione, cleanup o pubblicazione. Nei report condivisi sostituisci sempre percorsi, host, email, identificativi e messaggi che contengono dati dell’ambiente.

- [← Gruppi e GitHub](09-gruppi-e-github.md)
- [Torna all’indice](index.md)
