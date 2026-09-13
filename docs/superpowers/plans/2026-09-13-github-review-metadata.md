# Piano: metadati GitHub nella Review degli assignment

> **Per l'agente che esegue:** usa la skill `superpowers:executing-plans` e completa le attività in ordine.

**Obiettivo:** mostrare nella GitHub Review issue, branch, tag, riferimenti issue nei commit e branch di origine, senza persistenza DB e senza regressioni a voti/LOC.

**Approccio:** aggiungere metodi API read-only a `GitHubIntegration`, normalizzare i payload in un servizio puro testabile e caricare i metadati repository con un'azione POST asincrona dalla UI esistente.

## Attività

### 1. Test-first per il normalizzatore

**File:** `tests/domain/github_review_metadata.php` (nuovo)

- Inserire fixture issue/timeline/commit/tag/branch/PR.
- Verificare esclusione delle pull request, deduplica dei commit collegati, parsing di `#12` e `owner/repo#12`, mapping tag per SHA e precedenza `head.ref` → branch HEAD → non determinabile.
- Eseguire il test e confermare il fallimento prima dell'implementazione.

### 2. API GitHub read-only

**File:** `src/Integration/GitHubIntegration.php`

- Aggiungere validazione comune per slug repository, SHA e numero issue.
- Aggiungere `listRepoIssues`, `listIssueTimeline`, `listRepoBranches`, `listRepoTags`, `listCommitBranches` e `listCommitPullRequests`.
- Aggiungere helper paginato per i commit/metadati con limite configurabile e indicatore `truncated`.
- Mantenere token OAuth solo nell'integrazione server-side e riutilizzare `apiRequest`.

### 3. Servizio puro di normalizzazione

**File:** `src/Core/GitHubReviewMetadata.php` (nuovo)

- Implementare parser riferimenti issue e URL GitHub costruiti da owner/repository validati.
- Normalizzare issue/timeline, tag e branch in strutture compatte per la view.
- Collegare i commit timeline ai commit elencati e produrre l'indicazione trasparente del branch.

### 4. Endpoint e raccolta metadati nella Review

**File:** `public/github_assignment_review.php`

- Aggiungere l'azione POST `repo_metadata` con CSRF/autenticazione e validazione `owner/repo`.
- Recuperare commit paginati, issue `state=all`, timeline, branch, tag e branch/PR dei commit con gestione errori parziali.
- Conservare voto e LOC indipendenti dai metadati; non scrivere nel database.
- Restituire JSON con `commits`, `issues`, `branches`, `tags`, `branch_origins`, `truncated` e `warnings`.

### 5. Rendering e interazione UI

**File:** `public/github_assignment_review.php`

- Aggiungere contenitori metadati nella sezione dettagli repository.
- Renderizzare issue (stato/date/descrizione/commit links), contatore/elenco branch e riferimenti issue.
- Applicare label `bg-dark text-white` ai tag nella lista cronologica.
- Mostrare il branch di origine/attuale per ciascun commit con link.
- Caricare una sola volta i metadati all'apertura di “Mostra dettagli”, mostrare caricamento/errore non bloccante e sanificare tutto con `escapeHtml`.

### 6. Test di integrazione statica e regressione

**File:** `tests/public/github_review_metadata.php`, `tests/run.php`

- Verificare handler POST, endpoint presenti, output encoding, label tag, icona issue, sezioni issue/branch e gestione `truncated`.
- Registrare i nuovi test in `tests/run.php`.
- Eseguire lint dei file PHP modificati e la suite completa.

### 7. Verifica locale

- Eseguire `php tests/run.php`.
- Ricostruire/avviare Docker locale e verificare HTTP 200 della Review senza autenticazione pubblica esposta.
- Controllare `git diff`, rimuovere `.tokensave/` generato dagli strumenti e lasciare il tree privo di artefatti.

## Comandi di verifica

```powershell
php tests/domain/github_review_metadata.php
php tests/public/github_review_metadata.php
php -l src/Integration/GitHubIntegration.php
php -l src/Core/GitHubReviewMetadata.php
php -l public/github_assignment_review.php
php tests/run.php
docker compose up -d --build
```
