# GitHub Review Async Loading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendere indipendenti e concorrenti i caricamenti di LOC, metadati GitHub (issue, branch, tag e commit), grafo branch/commit e contributo/percorso dello studente nella pagina `github_assignment_review.php`, mostrando ogni risultato appena disponibile senza bloccare gli altri.

**Architecture:** La pagina manterrà gli endpoint già separati (`repo_loc`, `repo_metadata`, `repo_contributions`, `commit_details`) e introdurrà un coordinatore client-side per ogni riga/repository. Il coordinatore avvierà subito le richieste indipendenti, aggiornerà pannelli distinti con stati `loading/success/error`, applicherà il contributo dello studente al grafo e ai metadati quando arriva, e userà un pool limitato per i dettagli dei commit. Una generazione di caricamento e `AbortController` impediranno che una risposta vecchia sovrascriva una riga riaperta.

**Tech Stack:** PHP con JavaScript inline già presente in `public/github_assignment_review.php`, Fetch API, `Promise.allSettled`, `AbortController`, helper `runWithConcurrency`, endpoint PHP JSON esistenti, test PHP strutturali e test browser Playwright/Webapp-testing.

**Spec:** richiesta utente del 1 ottobre 2026: caricamento asincrono e contemporaneo di LOC, issue, commit, flusso/branch e percorso dello studente, con comparsa progressiva dei dati e nessun deploy fino al completamento.

## Global Constraints

- Lavorare esclusivamente sulla branch `branch/github-review-async-pipeline` e nella directory principale del repository; non usare worktree.
- Nessun deploy stage/produzione durante l’implementazione; il deploy sarà eseguito solo dopo completamento e approvazione separata.
- Conservare i contratti JSON degli endpoint esistenti; eventuali estensioni devono essere retrocompatibili.
- Non usare un caricamento globale seriale: LOC e metadati devono partire nella stessa apertura dei dettagli.
- Un errore di un pannello non deve cancellare né bloccare i dati già arrivati negli altri pannelli.
- I commit dello studente possono aprire i dettagli automaticamente come oggi; i commit degli altri restano compressi per default.
- I log diagnostici già introdotti devono continuare a distinguere repository, studente, fase, durata e request generation senza registrare token o dati sensibili.
- Ogni incremento funzionale deve avere un commit separato e una verifica mirata prima del commit; Docker e deploy sono fuori da questa feature fino alla chiusura.

## Review Focus

- **Risposta fuori ordine:** una risposta lenta della richiesta precedente non deve sostituire dati della nuova apertura della stessa riga; il test verificherà il controllo `generation`/`AbortController`.
- **Errore parziale:** se `repo_contributions` fallisce, LOC, issue, branch, commit e grafo devono restare visualizzati; il test verificherà la presenza dei pannelli riusciti e dell’errore locale.
- **Cache incoerente:** i metadati possono essere riusati solo per la stessa repository/ref; le attribuzioni dello studente devono essere ricalcolate senza mostrare dati di uno studente precedente.
- **Commit numerosi:** il caricamento dei dettagli deve rispettare il limite del pool e aggiornare ogni commit senza creare centinaia di richieste simultanee.
- **Interazione ripetuta:** apri/chiudi/riapri e doppio click su “Mostra dettagli” non devono duplicare pannelli, richieste o eventi.

---

### Task 1: Bloccare il comportamento concorrente con test strutturali e fixture di stato

**Files:**
- Create: `tests/public/github_review_async_loading.php`
- Modify: `tests/run.php: elenco dei test pubblici`
- Inspect only: `public/github_assignment_review.php` nelle funzioni `renderRepoMetadata`, `loadRepoMetadata`, `loadRepoContributions`, `loadRepoLoc`, `renderWorktreeGraph`, `runWithConcurrency` e listener `.show-details-btn`

**Interfaces:**
- Consumes: il markup/script attuale della review e i quattro action name JSON esistenti.
- Produces: asserzioni che fissano i nomi delle funzioni e il protocollo del coordinatore usato dai task successivi.

- [ ] **Step 1: Scrivere il test rosso per i contratti di caricamento**

  In `tests/public/github_review_async_loading.php`, leggere il file con `file_get_contents` e aggiungere asserzioni che richiedano:

  ```php
  assertContains($html, 'function startGithubReviewLoads', 'manca il coordinatore per la riga');
  assertContains($html, 'Promise.allSettled', 'manca la gestione indipendente delle richieste');
  assertContains($html, 'new AbortController', 'manca l annullamento delle richieste obsolete');
  assertContains($html, 'github-review-generation', 'manca il controllo di generazione della riga');
  assertContains($html, 'data-panel="loc"', 'manca il placeholder LOC');
  assertContains($html, 'data-panel="metadata"', 'manca il placeholder metadati');
  assertContains($html, 'data-panel="graph"', 'manca il placeholder grafo');
  assertContains($html, 'data-panel="contributions"', 'manca il placeholder contributo studente');
  assertContains($html, 'runWithConcurrency', 'manca il pool per i dettagli commit');
  ```

  Il test deve inoltre estrarre il blocco del listener “Mostra dettagli” e fallire se contiene la sequenza seriale `await loadRepoLoc` seguita da `await loadRepoMetadata`.

- [ ] **Step 2: Eseguire il test per confermare il rosso**

  Run: `php tests/public/github_review_async_loading.php`

  Expected: FAIL perché il coordinatore, i placeholder e il controllo di generazione non sono ancora presenti.

- [ ] **Step 3: Registrare il test nel runner esistente**

  Inserire `tests/public/github_review_async_loading.php` nella lista di `tests/run.php` mantenendo il formato degli altri test pubblici.

- [ ] **Step 4: Rieseguire il test rosso dal runner**

  Run: `php tests/run.php --filter=github_review_async_loading` (se il runner non supporta `--filter`, eseguire `php tests/public/github_review_async_loading.php`).

  Expected: stesso FAIL mirato, senza modificare la logica applicativa.

- [ ] **Step 5: Commit del test rosso**

  ```bash
  git add tests/public/github_review_async_loading.php tests/run.php
  git commit -m "test: specify async GitHub review loading contract"
  ```

### Task 2: Separare i pannelli e avviare LOC/metadati in parallelo

**Files:**
- Modify: `public/github_assignment_review.php` markup della riga dettagli e funzioni JavaScript tra `renderRepoMetadata` e il listener `.show-details-btn`
- Test: `tests/public/github_review_async_loading.php`

**Interfaces:**
- Consumes: i placeholder e le asserzioni del Task 1; `loadRepoLoc(container, repoFull, ref, force)` e `loadRepoMetadata(container, repoFull, studentId)` esistenti.
- Produces: `startGithubReviewLoads(row, context)` che restituisce `{generation, controller, promises}` e aggiorna i pannelli `loc`, `metadata`, `graph`, `contributions` senza attese seriali.

- [ ] **Step 1: Aggiungere il test per le richieste iniziali concorrenti**

  Estendere il test con asserzioni sul coordinatore:

  ```php
  assertContains($html, 'const locPromise = loadRepoLoc', 'LOC non parte dal coordinatore');
  assertContains($html, 'const metadataPromise = loadRepoMetadata', 'metadati non partono dal coordinatore');
  assertContains($html, 'Promise.allSettled([locPromise, metadataPromise])', 'LOC e metadati non sono coordinati in parallelo');
  assertContains($html, 'setPanelState', 'manca uno stato indipendente per pannello');
  ```

- [ ] **Step 2: Aggiungere i placeholder visuali e gli stati iniziali**

  Nel markup generato per ogni repository inserire pannelli con gli attributi:

  ```html
  <div class="github-review-panel" data-panel="loc" aria-live="polite">Caricamento LOC…</div>
  <div class="github-review-panel" data-panel="metadata" aria-live="polite">Caricamento issue, branch e commit…</div>
  <div class="github-review-panel" data-panel="graph" aria-live="polite">In attesa dei dati del grafo…</div>
  <div class="github-review-panel" data-panel="contributions" aria-live="polite">Caricamento percorso studente…</div>
  ```

  Conservare le classi cromatiche e il markup finale già usato da LOC, issue, branch, commit e grafo; i placeholder devono essere sostituiti, non duplicati.

- [ ] **Step 3: Implementare lo stato per riga e l’annullamento**

  Aggiungere un helper JavaScript con questa interfaccia:

  ```js
  function createGithubReviewLoadState(row) {
      const previous = row._githubReviewLoadState;
      if (previous?.controller) previous.controller.abort();
      return {
          generation: (previous?.generation || 0) + 1,
          controller: new AbortController(),
          repoFull: row.dataset.repoFull || '',
          ref: row.dataset.repoRef || '',
          promises: []
      };
  }

  function isGithubReviewStateCurrent(row, state) {
      return row._githubReviewLoadState === state && !state.controller.signal.aborted;
  }

  function setPanelState(row, panelName, state, content) {
      const panel = row.querySelector(`[data-panel="${panelName}"]`);
      if (!panel) return;
      panel.dataset.state = state;
      panel.innerHTML = content;
  }
  ```

  Tutte le callback dovranno verificare `isGithubReviewStateCurrent` prima di modificare il DOM.

- [ ] **Step 4: Implementare `startGithubReviewLoads` con LOC e metadati simultanei**

  La funzione dovrà seguire questa forma, adattando i parametri reali della pagina:

  ```js
  function startGithubReviewLoads(row, context) {
      const state = createGithubReviewLoadState(row);
      row._githubReviewLoadState = state;
      const locPromise = loadRepoLoc(row.querySelector('[data-panel="loc"]'), state.repoFull, state.ref, true, state.controller.signal);
      const metadataPromise = loadRepoMetadata(row.querySelector('[data-panel="metadata"]'), state.repoFull, context.studentId, state.controller.signal);
      state.promises = [locPromise, metadataPromise];

      locPromise.then(data => {
          if (!isGithubReviewStateCurrent(row, state)) return;
          renderRepoLoc(row.querySelector('[data-panel="loc"]'), data);
          setPanelState(row, 'loc', 'success', row.querySelector('[data-panel="loc"]').innerHTML);
      }).catch(error => {
          if (error.name === 'AbortError' || !isGithubReviewStateCurrent(row, state)) return;
          setPanelState(row, 'loc', 'error', renderGithubReviewRetry('LOC', error));
      });

      metadataPromise.then(data => {
          if (!isGithubReviewStateCurrent(row, state)) return;
          renderRepoMetadata(row.querySelector('[data-panel="metadata"]'), data, state.repoFull);
          renderWorktreeGraph(row.querySelector('[data-panel="graph"]'), data, state.repoFull);
          setPanelState(row, 'metadata', 'success', row.querySelector('[data-panel="metadata"]').innerHTML);
          setPanelState(row, 'graph', 'success', row.querySelector('[data-panel="graph"]').innerHTML);
          return loadRepoContributions(row, data, context, state);
      }).catch(error => {
          if (error.name === 'AbortError' || !isGithubReviewStateCurrent(row, state)) return;
          setPanelState(row, 'metadata', 'error', renderGithubReviewRetry('metadati', error));
      });

      Promise.allSettled([locPromise, metadataPromise]).then(() => state);
      return {generation: state.generation, controller: state.controller, promises: state.promises};
  }
  ```

  `loadRepoContributions` partirà al completamento dei metadati, mentre LOC e metadati non devono attendersi a vicenda. Se il grafo non necessita del contributo, deve comparire subito con i dati metadata e poi essere aggiornato.

- [ ] **Step 5: Sostituire il listener seriale “Mostra dettagli”**

  Il listener dovrà preparare i pannelli, chiamare `startGithubReviewLoads(row, context)` una sola volta per apertura e non contenere più una catena `await loadRepoLoc` → `await loadRepoMetadata` → `await loadRepoContributions`. La gestione dei commit dello studente passerà al Task 4.

- [ ] **Step 6: Eseguire il test strutturale e il lint PHP**

  Run: `php tests/public/github_review_async_loading.php` e `php -l public/github_assignment_review.php`.

  Expected: PASS per i contratti di concorrenza e sintassi PHP valida.

- [ ] **Step 7: Commit del caricamento base concorrente**

  ```bash
  git add public/github_assignment_review.php tests/public/github_review_async_loading.php
  git commit -m "feat: load GitHub review panels concurrently"
  ```

### Task 3: Rendere progressivi grafo e percorso/contributo dello studente

**Files:**
- Modify: `public/github_assignment_review.php` funzioni `loadRepoContributions`, `renderWorktreeGraph` e rendering della sezione percorso studente
- Modify: `src/Integration/GitHubIntegration.php` solo se il contratto server deve accettare `ref`, `studentId` o un request id aggiuntivo senza rompere le chiamate esistenti
- Test: `tests/public/github_review_async_loading.php`

**Interfaces:**
- Consumes: `metadataPromise` e stato `{generation, controller}` del Task 2.
- Produces: `loadRepoContributions(row, metadata, context, state)` che aggiorna solo `contributions`, corregge attribuzioni LOC/branch/issue/commit e ripittura il grafo senza ricaricare i pannelli già riusciti.

- [ ] **Step 1: Aggiungere test per il patch progressivo del contributo**

  Aggiungere al test:

  ```php
  assertContains($html, 'function loadRepoContributions', 'manca il caricamento del percorso studente');
  assertContains($html, 'data-panel="contributions"', 'manca il pannello percorso studente');
  assertContains($html, 'renderWorktreeGraph', 'il grafo non viene ripristinato dopo il contributo');
  assertContains($html, 'contributionsPromise', 'manca una promessa separata per il contributo');
  ```

- [ ] **Step 2: Avviare il contributo dopo i metadati senza bloccare LOC**

  In `metadataPromise.then`, chiamare `loadRepoContributions` e aggiornare lo stato del solo pannello `contributions`; non svuotare LOC o metadata se la richiesta fallisce. Passare `state.controller.signal` al fetch e includere `state.generation` nel contesto client.

- [ ] **Step 3: Applicare ownership e percorso in modo incrementale**

  Quando arrivano le attribuzioni:

  1. renderizzare il riepilogo del percorso studente;
  2. aggiornare `n di m` delle LOC e le evidenziazioni branch/issue/commit già presenti;
  3. chiamare nuovamente `renderWorktreeGraph` solo per applicare i colori ownership, senza ripetere la richiesta metadata;
  4. lasciare i commit degli altri compressi e quelli dello studente aperti come nel comportamento corrente.

- [ ] **Step 4: Uniformare errori e retry per ogni pannello**

  Implementare `renderGithubReviewRetry(label, error)` con pulsante `data-retry-panel` che richiama solo la fase fallita nella stessa generazione. Il messaggio deve essere leggibile e non mostrare eccezioni interne, request id o token; i dettagli restano nei log diagnostici server.

- [ ] **Step 5: Verificare race condition e fallimento parziale con test browser**

  Aggiungere uno scenario Playwright nella suite webapp-testing (o uno script di test locale eseguibile con il runner disponibile) che intercetti `fetch` e ritardi le risposte in ordine inverso. Verificare che:

  - il pannello LOC possa comparire prima dei metadati;
  - il grafo compaia con i metadati e venga poi colorato con il contributo;
  - un 500 su `repo_contributions` mostri errore solo nel pannello percorso;
  - chiusura e riapertura non permettano alla risposta della prima generazione di modificare la seconda.

- [ ] **Step 6: Eseguire test mirati e commit**

  Run: `php tests/public/github_review_async_loading.php`, `php -l public/github_assignment_review.php`, `php -l src/Integration/GitHubIntegration.php` (se modificato) e lo scenario browser.

  ```bash
  git add public/github_assignment_review.php src/Integration/GitHubIntegration.php tests/public/github_review_async_loading.php
  git commit -m "feat: stream student contribution and graph updates"
  ```

### Task 4: Caricare i dettagli commit con concorrenza limitata

**Files:**
- Modify: `public/github_assignment_review.php` listener/rendering dei commit e helper `runWithConcurrency`
- Test: `tests/public/github_review_async_loading.php`

**Interfaces:**
- Consumes: lista commit già renderizzata da `renderRepoMetadata`, ownership calcolata dal Task 3 e `runWithConcurrency(items, limit, fn)` esistente.
- Produces: `loadCommitDetailsProgressively(row, commits, context, state)` con limite esplicito `GITHUB_REVIEW_COMMIT_CONCURRENCY = 4` e stato per singolo commit.

- [ ] **Step 1: Scrivere il test per il pool commit**

  Aggiungere asserzioni:

  ```php
  assertContains($html, 'function loadCommitDetailsProgressively', 'manca il caricamento progressivo dei dettagli commit');
  assertContains($html, 'GITHUB_REVIEW_COMMIT_CONCURRENCY = 4', 'il limite del pool non è esplicito');
  assertContains($html, 'runWithConcurrency(commits', 'i dettagli commit non usano il pool');
  assertContains($html, 'data-commit-state="loading"', 'manca lo stato loading del singolo commit');
  ```

- [ ] **Step 2: Aggiungere il placeholder per ogni commit**

  Renderizzare titolo, autore/data, branch, tag e issue subito dai metadati; nella zona dettagli inserire `data-commit-state="loading"` per i commit dello studente e `data-commit-state="collapsed"` per quelli degli altri.

- [ ] **Step 3: Implementare il caricamento limitato e progressivo**

  Usare:

  ```js
  const GITHUB_REVIEW_COMMIT_CONCURRENCY = 4;
  function loadCommitDetailsProgressively(row, commits, context, state) {
      return runWithConcurrency(commits.filter(commit => commit.studentOwned), GITHUB_REVIEW_COMMIT_CONCURRENCY, commit =>
          loadCommitDetails(commit, context.repoFull, state.controller.signal)
              .then(details => {
                  if (!isGithubReviewStateCurrent(row, state)) return;
                  renderCommitDetails(commit.sha, details);
              })
      );
  }
  ```

  I commit degli altri non devono essere richiesti automaticamente; il click sul titolo deve richiedere il dettaglio del singolo commit usando lo stesso `AbortController` e lasciare la riga compressa finché l’utente non la espande.

- [ ] **Step 4: Aggiungere test di limite e ordine visivo**

  Nel test browser simulare 8 commit con durate diverse e verificare che non vengano eseguite più di 4 richieste contemporaneamente, che i dettagli compaiano nella riga corretta e che un commit grigio resti compresso.

- [ ] **Step 5: Eseguire i test e committare**

  Run: `php tests/public/github_review_async_loading.php`, `php -l public/github_assignment_review.php` e lo scenario browser dei commit.

  ```bash
  git add public/github_assignment_review.php tests/public/github_review_async_loading.php
  git commit -m "feat: stream GitHub commit details with bounded concurrency"
  ```

### Task 5: Rifinitura accessibilità, osservabilità e regressione completa

**Files:**
- Modify: `public/github_assignment_review.php` stati ARIA, retry, chiusura dettagli e cleanup
- Modify: `src/Core/Security/DiagnosticsLogger.php` solo per aggiungere un campo fase se i log concorrenti risultano indistinguibili; non registrare payload sensibili
- Test: `tests/public/github_review_async_loading.php`, eventuali test diagnostici esistenti in `tests/public/github_review_diagnostics.php`
- Docs: `docs/superpowers/plans/2026-10-01-github-review-async-loading.md` checklist di avanzamento, senza cambiare la specifica

**Interfaces:**
- Consumes: tutte le API client dei Task 2–4.
- Produces: review robusta a caricamenti paralleli, errori parziali, riaperture e richieste lente.

- [ ] **Step 1: Aggiungere stati accessibili e feedback progressivo**

  Ogni pannello deve avere `aria-live="polite"`, testo iniziale “Caricamento …”, messaggio di errore con retry e nessun contenuto vuoto ambiguo. Il focus non deve saltare quando un pannello viene aggiornato.

- [ ] **Step 2: Pulire le richieste alla chiusura o cambio riga**

  Il listener di chiusura dettagli deve eseguire `row._githubReviewLoadState?.controller.abort()` e lasciare il DOM in stato riapribile; una nuova apertura crea una generazione incrementata e non riusa il risultato di `contributions` precedente.

- [ ] **Step 3: Rendere la cache metadata dipendente da repository e ref**

  Cambiare la chiave di `githubMetadataCache` da solo `repoFull` a `repoFull + '@' + ref`, mantenendo la cache metadata esistente e lasciando non cachiate le attribuzioni dello studente. Aggiungere una verifica strutturale che la chiave includa `ref`.

- [ ] **Step 4: Verificare i log per richieste parallele**

  Eseguire una review locale con logging diagnostico attivo e verificare che LOC, metadata e contributions abbiano fasi/durate/request id distinti, senza password, token GitHub o contenuti completi dei file.

- [ ] **Step 5: Eseguire la suite completa e controlli finali**

  Run:

  ```bash
  php tests/run.php
  Get-ChildItem public,src -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
  git diff --check
  ```

  Expected: tutti i test PASS, nessun errore di sintassi, nessun whitespace error e nessun deploy eseguito.

- [ ] **Step 6: Aggiornare Docker solo come verifica locale, se richiesto dal flusso del progetto**

  Eseguire il rebuild locale senza pubblicazione e ripetere i test browser contro il container; non sostituire configurazioni `.env` e non inviare file al server stage.

- [ ] **Step 7: Commit finale della feature**

  ```bash
  git add public/github_assignment_review.php src/Core/Security/DiagnosticsLogger.php tests/public/github_review_async_loading.php tests/public/github_review_diagnostics.php docs/superpowers/plans/2026-10-01-github-review-async-loading.md
  git commit -m "feat: complete async GitHub review loading pipeline"
  ```

  Prima del commit verificare con `git status --short` che non siano stati inclusi i file già modificati manualmente dall’utente e non pertinenti alla feature.

## Self-review del piano

- **Copertura:** LOC e metadati partono insieme nel Task 2; grafo e percorso studente sono progressivi nel Task 3; commit e limite di concorrenza sono nel Task 4; errori, cache, abort e accessibilità sono nel Task 5.
- **Coerenza delle interfacce:** `startGithubReviewLoads`, `createGithubReviewLoadState`, `isGithubReviewStateCurrent`, `setPanelState` e `loadCommitDetailsProgressively` sono definiti prima del loro uso e condividono `row`, `context` e `state`.
- **Regressioni coperte:** risposte fuori ordine, errore parziale, cache per ref, molti commit e riaperture sono elencati in Review Focus e testati nei task proprietari.
- **Vincoli operativi:** la branch è già stata creata, il piano non autorizza deploy e ogni incremento prevede un commit separato.
