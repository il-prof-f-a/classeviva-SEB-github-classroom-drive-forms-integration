# UDA Smart

UDA Smart è una piattaforma PHP per progettare e gestire Unità di Apprendimento, materiali, obiettivi, test, rubriche e valutazioni, con integrazioni opzionali per Google Workspace for Education, GitHub Classroom, ClasseViva e servizi di intelligenza artificiale.

Il repository contiene il codice distribuibile e template privi di credenziali. Non servono Composer, PHP o MySQL installati sul computer se si usa Docker.

## Avvio rapido con Docker

Requisiti:

- Git;
- Docker Desktop oppure Docker Engine con Docker Compose v2.

Da PowerShell, Terminale o Bash:

```bash
git clone https://github.com/il-prof-f-a/classeviva-SEB-github-classroom-drive-forms-integration
cd classeviva-SEB-github-classroom-drive-forms-integration
docker compose up --build
```

Aprire [http://localhost:8080](http://localhost:8080). Al primo avvio Docker:

- crea MySQL;
- installa le dipendenze Composer nell'immagine;
- crea e aggiorna automaticamente lo schema applicativo;
- genera una chiave di cifratura persistente nel volume locale se `ENCRYPTION_KEY` non è configurata.

La pagina iniziale è disponibile subito. Per effettuare il login occorre configurare Google OAuth come descritto sotto.

Comandi utili:

```bash
docker compose logs -f app
docker compose ps
docker compose down
docker compose up -d --build
```

Per usare una porta diversa:

```bash
APP_PORT=8090 docker compose up --build
```

In PowerShell:

```powershell
$env:APP_PORT = '8090'
docker compose up --build
```

`docker compose down -v` elimina anche database, storage e chiave di cifratura locali: usarlo solo quando si vuole davvero azzerare l'ambiente.

## Configurazione locale

Docker può mostrare la pagina iniziale anche senza file locale. Per abilitare login e integrazioni, creare `config/.env` partendo dall'esempio:

Linux/macOS:

```bash
cp .env.example config/.env
```

Windows PowerShell:

```powershell
Copy-Item .env.example config/.env
```

Il file `config/.env` è ignorato da Git. Le variabili fornite da Docker, dal server o dalla CI hanno precedenza sui valori del file.

In locale Docker monta `config/` nel container in sola lettura: in questo modo `config/.env` e `config/google_credentials.json` sono disponibili all'applicazione senza essere copiati nell'immagine o pubblicati nel repository. Con la porta predefinita, `APP_URL` e le callback OAuth effettive usano `http://localhost:8080`; impostando `APP_PORT`, Docker aggiorna insieme URL applicativo e callback Google/GitHub.

Le impostazioni minime da controllare sono:

```dotenv
APP_URL=http://localhost:8080
APP_ENV=local
APP_DEBUG=false
ADMIN_EMAILS=email@email.it
ENCRYPTION_KEY=
SESSION_IDLE_TIMEOUT=3600
```

`ADMIN_EMAILS` accetta più indirizzi separati da virgola e abilita le pagine amministrative di stato e manutenzione. `SESSION_IDLE_TIMEOUT` indica in secondi per quanto tempo una sessione inattiva può conservare l'autenticazione (valore predefinito: 3600). Lasciando vuota `ENCRYPTION_KEY` in Docker, la chiave viene generata e conservata nel volume `app_storage`. Per deploy reali è preferibile impostare esplicitamente la chiave e conservarne un backup sicuro.

Per generarla manualmente:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

La chiave cifra token, password e segreti salvati nel database. Non cambiarla dopo aver memorizzato integrazioni: i valori esistenti non sarebbero più decifrabili.

## Google OAuth e Google Workspace

Il login usa Google OAuth; Drive, Classroom, Forms e Sheets sono integrazioni opzionali. La configurazione ufficiale aggiornata è descritta nella [guida OAuth per applicazioni web](https://developers.google.com/identity/protocols/oauth2/web-server) e nella [guida per abilitare le API Google Workspace](https://developers.google.com/workspace/guides/enable-apis).

1. Creare o selezionare un progetto in Google Cloud.

2. Configurare la schermata consenso OAuth.

3. Abilitare le API richieste dalle funzioni che si vogliono usare: Google Drive, Google Classroom, Google Forms e Google Sheets.

4. Creare un client OAuth di tipo **Web application**.

5. Registrare esattamente questi redirect URI per l'ambiente locale:
   
   - `http://localhost:8080/public/oauth_callback.php` per il login;
   - `http://localhost:8080/public/google_auth.php` per Drive/Classroom/Forms.

6. Scaricare il JSON del client e salvarlo come `config/google_credentials.json`. La struttura attesa è mostrata in `config/google_credentials.example.json`.

7. Impostare in `config/.env`:

```dotenv
GOOGLE_ENABLED=true
GOOGLE_CREDENTIALS_FILE=config/google_credentials.json
GOOGLE_LOGIN_REDIRECT_URI=http://localhost:8080/public/oauth_callback.php
GOOGLE_REDIRECT_URI=http://localhost:8080/public/google_auth.php
GOOGLE_DRIVE_ENABLED=true
GOOGLE_CLASSROOM_ENABLED=true
GOOGLE_FORMS_ENABLED=true
GOOGLE_SHEETS_ENABLED=true
```

Il redirect URI deve coincidere esattamente con quello registrato in Google Cloud, inclusi schema, porta, percorso e slash finali. Il token OAuth viene creato dal flusso applicativo e non deve essere aggiunto al repository.

Per l'importazione guidata dei test Google Forms l'autorizzazione richiede anche lo scope `https://www.googleapis.com/auth/drive.metadata.readonly` (Drive metadata in sola lettura), oltre agli scope Forms body/responses. Questo permette di elencare i Forms già presenti nel Drive dell'utente, mostrando titolo, autore, data di creazione e numero di risposte. Dopo l'aggiunta dello scope, gli utenti già autorizzati devono cliccare nuovamente su **Autorizza Accesso a Google** o **Rinnova Token** nella sezione Integrazioni Google.

In `public/import_questions.php`, scegliendo **Google Forms**, la piattaforma carica l'elenco dei moduli accessibili nel Drive e offre una ricerca per titolo, autore o data. Selezionando un modulo viene compilato automaticamente il **Link docente Google Forms**; il link manuale resta disponibile come fallback. Se il token manca, è scaduto o non contiene lo scope Drive metadata, la pagina mostra un pulsante verso la sezione Google delle Integrazioni e, al termine dell'autorizzazione, un pulsante per tornare all'importazione.

Nella modalità **JSON** dell'import strutturato il template viene caricato in una textarea editabile. Il pulsante accanto al selettore file legge il JSON localmente nel browser e lo copia nella textarea; **Mostra anteprima** invia il contenuto della textarea al server. CSV ed Excel continuano a usare il caricamento file tradizionale.

Dopo **Mostra anteprima** la pagina nasconde i pannelli di selezione e mostra subito sotto il banner soltanto le domande da importare. Le domande sono visualizzate con la stessa card condivisa usata dal wizard e da `uda_questions.php`; il pulsante di modifica apre lo stesso editor per tipo, risposta, opzioni e parole chiave. **Annulla** torna alla pagina di importazione senza importare dati. La struttura tecnica non viene mostrata: per ottenere il formato si usano i pulsanti di download dei template.

Per staging e produzione sostituire `APP_URL` e i due redirect con URL HTTPS dell'ambiente corrispondente e registrarli nel relativo client OAuth.

## Wizard di creazione UDA

Il wizard segue un flusso in sette passaggi:

1. **Informazioni generali**: titolo, argomento, disciplina, metodologia, anno, periodo, descrizione, note e stato. Questi dati sono compilati manualmente; non viene eseguito alcun import automatico dall’argomento Classroom.
2. **Classi e integrazioni**: assegnare uno o più gruppi didattici interni. Il gruppo può esistere senza ClasseViva e può essere collegato in seguito a ClasseViva, Google Classroom o GitHub Classroom. Le mappature globali esistenti vengono preselezionate; i pulsanti di configurazione consentono di tornare al wizard senza perdere le selezioni.
3. **Materiali**: aggiungere materiali manualmente, da Drive oppure caricare le risorse dei corsi Google Classroom mappati.
4. **Obiettivi**: cercare e selezionare gli obiettivi dal catalogo.
5. **Domande**: usare l’editor condiviso o `import_questions.php` per importare JSON, CSV, Excel e, quando disponibile, Google Forms collegati.
6. **Test**: collegare Forms, compiti Classroom e assignment GitHub dai cataloghi filtrati sulle classi selezionate; Kahoot, Socrative e altri strumenti restano collegamenti manuali.
7. **Riepilogo**: controllare dati, classi, integrazioni, contenuti e origine degli elementi prima del salvataggio.

Le mappature vengono salvate nelle integrazioni globali e sono quindi riutilizzabili dalle UDA successive. La presenza di almeno una mappatura Classroom o GitHub porta automaticamente una nuova UDA dallo stato `bozza` allo stato `attiva`; non è obbligatorio collegare ClasseViva per creare il gruppo o l'UDA.

## Modello dati provider-neutral

Il database applicativo è SQL-only e supporta `sqlite` e `mysql`. Una classe-materia è rappresentata da un record interno in `GRUPPI_DIDATTICI`; i collegamenti a provider esterni sono righe indipendenti in `GRUPPI_INTEGRAZIONI`. Un'UDA usa `UDA_GRUPPI`, quindi può essere assegnata a gruppi senza dipendere da una chiave ClasseViva.

Gli studenti hanno un `id_studente` interno. Gli identificativi ClasseViva, Google Classroom e GitHub Classroom vivono in `STUDENTI_IDENTITA_ESTERNE`; assignment, repository e submission sono risorse in `STUDENTI_RISORSE_ESTERNE`. Nomi, cognomi ed email non vengono persistiti: quando servono all'interfaccia vengono recuperati dal provider nel contesto della richiesta.

### Editor dei gruppi didattici

In locale l'editor è disponibile su
[`http://localhost:8080/public/teaching_groups.php`](http://localhost:8080/public/teaching_groups.php).
È possibile creare prima il gruppo interno (anche senza provider), collegare in seguito
ClasseViva, Google Classroom o GitHub Classroom e aprire la scheda **Studenti** per
sincronizzare i roster e associare gli identificativi esterni allo studente interno.
Lo step 2 del wizard UDA seleziona questi gruppi tramite il relativo `id_gruppo` e il
collegamento **Gestisci gruppi didattici** consente di tornare all'editor.

Lo smoke test riproducibile del flusso completo SQLite (gruppo vuoto, tre provider,
assegnazione di una UDA a due gruppi, roster, match, riga non mappata e riapertura del database) è:

```bash
php tests/e2e/teaching_groups_editor.php
```

Con Docker, dopo il rebuild dell'immagine (`docker compose up -d --build app`),
il test si esegue montando temporaneamente la directory non distribuita dei test:

```bash
docker compose run --rm -T -v "./tests:/var/www/html/tests:ro" app \
  php tests/e2e/teaching_groups_editor.php
```

Per cambiare backend impostare in `config/.env`:

```dotenv
DB_TYPE=sqlite
DB_SQLITE_FILE=storage/uda.sqlite
```

oppure compilare `DB_TYPE=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD`. L'avvio applica automaticamente la migrazione versionata e gli indici. Per azzerare solo il dominio didattico locale, preservando utenti, integrazioni e cataloghi:

```bash
php scripts/reset_teaching_domain.php --dry-run
php scripts/reset_teaching_domain.php --apply --confirm=RESET-TEACHING-DOMAIN
```

Lo script rifiuta ambienti di produzione e salva sempre un report locale.

La rimozione fisica delle sei tabelle legacy Ã¨ una migrazione separata e non
automatica. Per i dettagli della procedura locale e staging consultare
[`docs/deployment/legacy-table-migration.md`](docs/deployment/legacy-table-migration.md).

## GitHub Classroom

Creare una OAuth App seguendo la [documentazione ufficiale GitHub](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app). Per il locale usare:

- Homepage URL: `http://localhost:8080`;
- Authorization callback URL: `http://localhost:8080/public/github_callback.php`.

Configurare quindi:

```dotenv
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
```

Il callback non è un parametro configurabile: l'applicazione lo deriva sempre da `APP_URL` aggiungendo `/public/github_callback.php`. Copiare l'URL risultante, mostrato anche nella pagina **Integrazioni**, nel campo **Authorization callback URL** della OAuth App GitHub.

Client ID e Client Secret possono essere salvati per singolo utente dalla pagina **Integrazioni**. Non inserire mai client secret reali nei file tracciati da Git.

## ClasseViva, AI ed email

ClasseViva viene abilitata con `CLASSEVIVA_ENABLED=true`. L'integrazione usa API non ufficiali e non documentate, individuate mediante attività di reverse engineering; non è autorizzata, approvata o supportata da ClasseViva/Spaggiari e può smettere di funzionare senza preavviso.

Il login è avviato dall'utente nel browser, ma username e password transitano via HTTPS nel processo PHP dell'installazione, che esegue una singola richiesta di login REST verso ClasseViva. Non è quindi corretto descrivere questa integrazione come esclusivamente browser-side. Le credenziali non vengono scritte in log, file, sessione o database e le variabili applicative vengono eliminate subito dopo la richiesta; PHP e il sistema operativo non possono tuttavia garantire la cancellazione fisica immediata di ogni copia dalla memoria RAM.

Dopo il login il server conserva soltanto il token REST nella sessione PHP corrente. Il token REST viene usato per ottenere `PHPSESSID` direttamente da ClasseViva, senza inviare di nuovo username e password. Anche `PHPSESSID` resta nella sessione PHP e viene rigenerato dal token REST quando la sessione web non è più valida. Se il token REST risulta scaduto o non valido, token e `PHPSESSID` vengono eliminati e l'interfaccia richiede nuovamente le credenziali tramite popup.

Token REST e `PHPSESSID` non sono salvati in `INTEGRAZIONI_UTENTE` né in altre tabelle del database. Il browser riceve solo il cookie identificativo della sessione dell'applicazione, configurato `HttpOnly`, `SameSite=Lax`, senza scadenza persistente e `Secure` quando il sito usa HTTPS. Il logout elimina i token, distrugge i dati di sessione e fa scadere il cookie. Alla chiusura del browser il cookie di sessione non è più disponibile; l'eventuale dato server-side non è cancellabile in modo sincrono alla chiusura di tutte le finestre e viene rimosso dal garbage collector PHP dopo il timeout di inattività definito da `SESSION_IDLE_TIMEOUT`.

L'utilizzatore deve verificare di essere autorizzato ad accedere ai dati e di rispettare condizioni d'uso, regolamenti scolastici e normativa applicabile. L'uso dell'integrazione avviene sotto la responsabilità dell'utilizzatore; nei limiti consentiti dalla legge, l'autore non risponde di sospensioni dell'account, indisponibilità, perdita o alterazione di dati, violazioni di condizioni contrattuali o altri danni derivanti dall'uso delle API non ufficiali.

I provider AI sono indipendenti e facoltativi:

```dotenv
OPENAI_API_KEY=
CLAUDE_API_KEY=
GEMINI_API_KEY=
```

Creare le chiavi solo dai portali ufficiali del provider: [OpenAI](https://platform.openai.com/docs/quickstart), [Anthropic](https://docs.anthropic.com/en/api/admin-api/apikeys/get-api-key) e [Google Gemini](https://ai.google.dev/gemini-api/docs/api-key). Le chiavi vanno conservate solo nel file ignorato, nel secret manager del deploy o nella configurazione cifrata per utente.

Per l'invio email impostare `MAIL_ENABLED=true` e compilare le variabili `MAIL_*` presenti in `.env.example` con i dati SMTP del proprio fornitore.

## File locali, generati o segreti

Questi file non sono distribuiti e non devono essere committati:

| File/percorso                                                      | Come viene creato                                                                                                                                                              |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `config/.env`                                                      | Copia di `.env.example`, compilata localmente                                                                                                                                  |
| `config/google_credentials.json`                                   | Download del client OAuth Web da Google Cloud                                                                                                                                  |
| `config/google_token.json`                                         | Eventuale token legacy generato dal flusso OAuth; i token utente correnti sono nel database cifrato                                                                            |
| `database/uda_master.xlsx`, `*.db`, `*.sql`                        | Dati runtime o dump locali                                                                                                                                                     |
| `storage/logs`, `storage/cache`, `storage/temp`, `storage/uploads` | Creati automaticamente dall'applicazione                                                                                                                                       |
| `templates/*.seb`                                                  | Creato con SEB Configuration Tool e caricato dall'utente. Deve essere un template non cifrato contenente `{{link_test}}`; configurare localmente la propria password di uscita |
| `vendor/`                                                          | Creato da Composer oppure incluso nell'immagine Docker                                                                                                                         |

I file `.example` e i template Excel inclusi nel repository contengono soltanto struttura o dati dimostrativi anonimizzati.

## Installazione senza Docker

Servono PHP da 8.2 a 8.4, Composer, MySQL 8/MariaDB compatibile e le estensioni elencate in `composer.json`.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example config/.env
php scripts/setup_database.php --wait=30 --validate
php -S 127.0.0.1:8080 scripts/dev_router.php
```

Prima di `setup_database.php`, creare database e utente MySQL e compilare `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` in `config/.env`. Il document root deve restare la radice del progetto perché il login è in `/` e l'applicazione è in `/public`.

## Test

Suite statica e funzionale, inclusi ciclo session-only ClasseViva, scambio token REST verso `PHPSESSID`, timeout e logout:

```bash
composer test
```

Con lo stack Docker attivo si può includere il test reale di inizializzazione MySQL:

```bash
docker compose run --rm -T -v "./tests:/var/www/html/tests:ro" -e TEST_MYSQL=true app php tests/run.php
```

La pipeline GitHub Actions esegue validazione Composer, lint PHP, controlli di pubblicabilità e inizializzazione dello schema su MySQL 8.4.

## Sicurezza e pubblicazione

- Verificare `git status --ignored` prima di ogni pubblicazione.
- Non aggiungere `.env`, dump SQL, database runtime, token OAuth, credenziali JSON o log.
- Usare `APP_DEBUG=false` fuori dall'ambiente locale.
- Conservare `ENCRYPTION_KEY` fuori dal repository e includerla nei backup sicuri.
- Eseguire prima i test in locale, poi nello staging previsto dal progetto e infine in produzione; credenziali e dati non devono essere copiati tra ambienti senza una decisione esplicita.

## Licenza

Il progetto è distribuito da Francesco Adriani con licenza [PolyForm Noncommercial License 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0) (`PolyForm-Noncommercial-1.0.0`). Sono consentiti uso, modifica e distribuzione esclusivamente per finalità non commerciali e secondo i termini della licenza ufficiale. Il file `LICENSE` riporta il riferimento legale e l'URL dei termini completi.

La licenza si applica esclusivamente ai materiali sui quali l'autore può concedere tali diritti. Librerie, marchi, formati, template e altri componenti di terze parti restano soggetti alle rispettive licenze e condizioni d'uso. La restrizione non commerciale rende questo progetto source-available per usi non commerciali, non open source secondo la definizione OSI.
