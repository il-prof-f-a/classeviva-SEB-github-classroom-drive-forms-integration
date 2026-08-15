# Ripristino dump legacy JSON e migrazione provider-neutral - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with checkpoints.

**Goal:** Ripristinare nell'ambiente Docker locale il dump MySQL JSON più recente disponibile, associando i dati del vecchio utente alla sua identità Google attuale e trasformando classi, mappature e studenti nella nuova struttura provider-neutral.

**Architecture:** Il dump viene letto senza ricreare tabelle legacy. Le tabelle applicative ancora compatibili vengono importate con l'adapter SQL; le sei aree legacy rimosse passano attraverso le facciate già presenti (`ProviderNeutralMappingService`, `LegacyUdaDataGateway`, `LegacyStudentMappingGateway`, `LegacyGithubStudentMapGateway`, `StudentReferenceGateway`). Le righe vengono filtrate per l'email sorgente e il proprietario viene riscritto sull'utente locale corrente.

**Tech Stack:** PHP 8.2 CLI nel container app, MySQL 8.4 Docker, adapter `DatabaseAdapterInterface`, dump JSON generato dal vecchio backend.

---

### Task 1: Protezione e verifica della sorgente

**Files:**
- Read: `database/backup/uda_mysql_2026-02-08_11-35-31.json`
- Create: `database/backup/local-before-legacy-restore-<timestamp>.sql`

- [x] Verificare che il dump contenga l'utente sorgente indicato al momento dell'esecuzione, 18 UDA e le tabelle legacy necessarie.
- [x] Eseguire un dump SQL del database locale corrente prima di qualsiasi modifica.
- [x] Interrompere il ripristino se il dump sorgente o il backup locale non sono leggibili.

### Task 2: Importer idempotente del JSON legacy

**Files:**
- Create: `scripts/restore_legacy_json.php`
- Test: `tests/database/legacy_json_restore_mapping.php`

- [x] Implementare opzioni `--source`, `--source-email`, `--target-email`, `--dry-run` e `--apply`.
- [x] Risolvere l'utente corrente tramite `UTENTI.email` senza stampare token o configurazioni sensibili.
- [x] Filtrare le righe sul vecchio `id_utente` associato all'email sorgente; riscrivere `id_utente`, `id_utente_owner` e `id_utente_invitato` sull'utente corrente.
- [x] Importare le tabelle non legacy preservando gli ID applicativi e rimuovendo solo colonne non presenti nello schema corrente.
- [x] Importare `CLASSROOM_MAPPINGS` e `GITHUB_CLASSROOMS` attraverso `ProviderNeutralMappingService`, memorizzando la corrispondenza tra ID legacy e nuovo `id_collegamento`.
- [x] Importare `CLASSI_ASSEGNATE` attraverso `LegacyUdaDataGateway` e usare la corrispondenza classe/materia per assegnare i gruppi alle UDA.
- [x] Importare studenti e riferimenti provider tramite il resolver provider-neutral; importare le membership Classroom attraverso `LegacyStudentMappingGateway` usando il nuovo ID di collegamento.
- [x] Importare i collegamenti GitHub attraverso `LegacyGithubStudentMapGateway`, conservando assignment, repository e confidenza.
- [x] Saltare l'inserimento dell'utente sorgente e aggiornare solo i dati anagrafici non sensibili dell'utente locale quando necessario.
- [x] Rendere il comando idempotente: una seconda esecuzione non deve duplicare righe con lo stesso ID.

### Task 3: Esecuzione controllata locale

**Files:**
- Create: `storage/reports/legacy-json-restore-<timestamp>.json`

- [x] Eseguire `--dry-run` e verificare i conteggi attesi per UDA, materiali, domande, test, studenti, gruppi e integrazioni.
- [x] Eseguire `--apply` solo contro `DB_HOST=db`, `DB_DATABASE=uda_system` del compose locale.
- [x] Registrare nel report sorgente, utente mappato, righe importate, righe saltate, errori e ID gruppo/provider creati.
- [ ] Verificare che il database non contenga più dati associati al vecchio `id_utente`.

### Task 4: Verifica applicativa

**Files:**
- Modify only if required: `README.md`, `docs/deployment/legacy-table-migration.md`

- [x] Riavviare il servizio app senza rimuovere il volume MySQL.
- [x] Verificare con SQL i conteggi delle tabelle canoniche e la presenza dell'utente corrente.
- [ ] Aprire localmente dashboard, UDA, domande, test e integrazioni con l'utente Google corrente.
- [ ] Eseguire i test database/domain e `git diff --check`.
- [x] Non creare commit e non modificare staging/produzione.
