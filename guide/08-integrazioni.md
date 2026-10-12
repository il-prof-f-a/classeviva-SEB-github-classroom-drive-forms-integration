# Integrazioni e credenziali

*Capitolo 08 · configurazione e test dei connettori*

Le pagine di integrazione collegano l’applicazione a Google, ClasseViva, posta, AI e GitHub. Sono le pagine più sensibili del portale: mostrano stato dei token, scadenze, ambiti autorizzati, identificativi e parametri di connessione.

> **Privacy:** la ricognizione è stata eseguita in sola lettura. Non sono riportati qui email, token, identificativi, roster o screenshot delle schede che li mostrano.

## Configurazione utente

La pagina **Integrazioni Utente** raggruppa le configurazioni in schede separate.

| Scheda | Cosa contiene | Operazioni osservate |
| --- | --- | --- |
| Profilo/Scuola | Dati dell’utente e del contesto scolastico. | Consultazione e salvataggio del profilo. |
| ClasseViva | Stato del token, base URL, periodi e configurazione del registro. | Verifica token e salvataggio dei periodi. |
| Google | Token, scadenza, scope e identificativi Drive/Forms. | Test, revoca e salvataggio delle risorse collegate. |
| Mail | Provider SMTP, host, porta, cifratura, username e mittente. | Salvataggio e invio di una email di test. |
| AI | Provider, modello, chiave API e note. | Salvataggio e test del provider. |
| GitHub | Account autenticato, OAuth client e secret mascherato. | Test, salvataggio o disconnessione. |

### ClasseViva

Il modello corrente usa un token REST in sessione PHP: username e password possono essere usati durante la procedura di acquisizione e poi rimossi dalla configurazione persistente. La scheda espone anche base URL e periodi didattici. **PHPSESSID non è una chiave API** e non va copiato in repository, issue, screenshot o file di esempio.

Per una configurazione portabile usa variabili d’ambiente o il file locale ignorato da Git; nell’esempio pubblico lascia solo placeholder come `XXXXXXXXX`, mai token validi.

### Google, posta e AI

Google può mostrare scadenza e scope del token oltre agli identificativi di cartella, Forms e modelli. La posta richiede normalmente una password applicativa con 2FA; il campo vuoto può significare “mantieni quella già salvata”. Per AI la chiave va mantenuta fuori dalla repository e il provider va scelto in base alle regole dell’istituto.

Prima di usare la generazione AI controlla se il prompt contiene materiale didattico riservato o dati degli studenti. Prima di inviare una email di test controlla destinatario e mittente.

## Test integrazioni API

La pagina **Test Integrazioni API** è organizzata per Drive, Classroom, Forms e ClasseViva. I comandi hanno effetti diversi:

| Integrazione | Verifiche tipiche | Rischio |
| --- | --- | --- |
| Drive | Autenticazione, cartelle, elenco file, upload e creazione cartella. | Upload/creazione scrivono nel Drive. |
| Classroom | Corsi, argomenti, materiali, assignment e studenti. | Pubblicare o creare bozze modifica il corso. |
| Forms | Creazione quiz, lettura risposte e pulizia batch. | Creazione e cleanup modificano Forms. |
| ClasseViva | Autenticazione, classi, voto e annotazione. | Voto e annotazione sono visibili nel registro. |

Per una diagnosi iniziale usa solo le verifiche di autenticazione e gli elenchi. Non premere **cleanup**, **crea**, **inserisci voto**, **inserisci annotazione** o equivalenti in un ambiente reale senza un piano di ripristino.

## Pagine esplorate

| Pagina | Quando usarla |
| --- | --- |
| [Integrazioni utente](../public/user_integrations.php) | Configurare o verificare i provider dell’utente. |
| [Autenticazione Google](../public/google_auth.php) | Controllare token, scope, autorizzazione e revoca Google. |
| [Test integrazioni API](../public/test_api_integrations.php) | Separare test di autenticazione, lettura e scrittura. |
| [Template GitHub](../public/github_repo_templates.php) | Registrare i template disponibili per gli assignment. |

Le pagine sopra sono state aperte e consultate. La tabella è intenzionalmente priva di valori live: lo stato del token e gli identificativi cambiano per account e non devono diventare parte della documentazione pubblica.

- [Torna alla home](01-home.md)
- [Gruppi e GitHub →](09-gruppi-e-github.md)
