# Voti provider-neutral e pubblicazione Classroom — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Salvare voti di rubrica e laboratorio senza una mappatura ClasseViva, ordinare/preselezionare i corsi Classroom dell’UDA e garantire l’uso dei template Forms standard/CBM.

**Architecture:** Il salvataggio locale userà `id_gruppo`/`id_studente` tramite `StudentReferenceGateway`; ClasseViva resterà un capability gate solo per la pubblicazione esterna. Un helper statico separerà l’ordinamento Classroom dalla pagina HTTP, mentre `GoogleFormsBuilder` resterà il punto unico per la copia dei template.

**Tech Stack:** PHP 8.2, adapter database provider-neutral, Google Classroom/Forms API, suite statica PHP in `tests/`.

---

### Task 1: Regression tests provider-neutral

**Files:**
- Create: `tests/public/provider_neutral_grades_and_classroom_publish.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Write failing assertions** per: assenza del gate rigido nella rubrica; filtro/scrittura `id_gruppo`; laboratorio con capability separata per save/publish; link `uda_grades.php`; ordinamento e `selected`; selezione template configurati.
- [ ] **Step 2: Run the focused test** con `php tests/public/provider_neutral_grades_and_classroom_publish.php` e verificare che fallisca sul codice attuale.
- [ ] **Step 3: Registrare il test** nella sezione public di `tests/run.php`.

### Task 2: Risoluzione gruppo/studenti e salvataggio rubrica

**Files:**
- Modify: `public/rubrica_orale_v2.php`
- Test: `tests/public/provider_neutral_grades_and_classroom_publish.php`

- [ ] **Step 1: Risolvere il gruppo dall’assegnazione UDA** con `UdaGroupRepository` e scegliere l’integrazione Google/CV solo per gli identificativi esterni.
- [ ] **Step 2: Caricare gli studenti dal roster del gruppo** e usare `id_studente` come chiave locale quando non esiste un ID CV.
- [ ] **Step 3: Salvare/aggiornare `VALUTAZIONI_RUBRICA` filtrando per `id_gruppo`, `id_studente`, UDA e rubrica; mantenere i campi CV solo quando disponibili.
- [ ] **Step 4: Rimuovere il form GET annidato** e rendere la selezione classe compatibile con il form POST.
- [ ] **Step 5: Ricaricare i valori usando il filtro gruppo**, mantenendo il filtro CV per i gruppi legacy già mappati.

### Task 3: Salvataggio laboratorio senza CV e link voti

**Files:**
- Modify: `public/laboratorio_griglia.php`
- Test: `tests/public/provider_neutral_grades_and_classroom_publish.php`

- [ ] **Step 1: Risolvere `$groupId` dall’UDA** quando la coppia CV non restituisce un’integrazione.
- [ ] **Step 2: Separare il salvataggio locale** dal controllo `supportsCvForPair(..., 'publish_grade')`; il POST salva in `VOTI`/`VALUTAZIONI_LABORATORIO` anche senza CV.
- [ ] **Step 3: Lasciare il gate CV** solo sul ramo `pubblica_plusminus`/annotazione esterna.
- [ ] **Step 4: Aggiungere il link** `uda_grades.php?id=<UDA>` accanto ai pulsanti esistenti.

### Task 4: Ordinamento e preselezione Classroom

**Files:**
- Create or modify: `src/Core/ClassroomCourseOrdering.php`
- Modify: `public/publish_test_to_classroom.php`
- Test: `tests/public/provider_neutral_grades_and_classroom_publish.php`

- [ ] **Step 1: Aggiungere una funzione pura** che riceva corsi e ID mappati e ritorni corsi stabili con i mappati in testa.
- [ ] **Step 2: Caricare le mappature Google dell’UDA** con `UdaGroupRepository` e `TeachingGroupIntegrationRepository`.
- [ ] **Step 3: Ordinare i corsi e marcare `selected` sul primo corso** nel markup.
- [ ] **Step 4: Gestire lista vuota e assenza di mapping** senza warning PHP.

### Task 5: Template Forms standard/CBM

**Files:**
- Modify: `src/Integration/GoogleFormsBuilder.php` only if needed after test inspection
- Modify: `public/generate_google_form.php` or `public/test_wizard.php` only if a call bypasses the shared builder
- Test: `tests/public/provider_neutral_grades_and_classroom_publish.php`

- [ ] **Step 1: Verificare i due call site** (`generate_google_form.php`, `test_wizard.php`) e la configurazione `template_id`/`template_id_cbm`.
- [ ] **Step 2: Correggere solo eventuali bypass**, assicurando che CBM passi `createFormWithConfidence` e lo standard `createForm`.
- [ ] **Step 3: Conservare `templateCopyError` e il fallback esistente**, senza mascherare errori.

### Task 6: Verifica, memoria e commit

**Files:**
- Modify: `.ai/current_state.md`, `.ai/tasks_todo.md`, `.ai/session_handoff.md`

- [ ] **Step 1: Eseguire test focused e `php tests/run.php`**, più lint PHP sui file modificati.
- [ ] **Step 2: Eseguire controlli statici sui diff e verificare che non siano stati introdotti token/segreti.**
- [ ] **Step 3: Aggiornare la memoria AI con cause, soluzione e test.**
- [ ] **Step 4: Creare commit applicativo separato dal commit della specifica.**

