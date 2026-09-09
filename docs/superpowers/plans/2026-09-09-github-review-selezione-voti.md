# Selezione voti nella review GitHub Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** consentire di scegliere esplicitamente quali voti GitHub salvare, con riepilogo di conferma e flag “Mostra nomi e repository” attivo in apertura.

**Architecture:** la pagina manterrà un checkbox canonico per ogni riga del form (`salva_voto[idx]`). Il popup di riepilogo userà checkbox sincronizzati con quelli della tabella, senza duplicare i campi POST. Il server salverà solo gli indici selezionati e con voto diverso da `skip`; il voto applicato dalla rubrica selezionerà automaticamente la riga.

**Tech Stack:** PHP server-rendered, Bootstrap modal, JavaScript vanilla, adapter database esistente, test PHP statici e suite applicativa.

---

### Task 1: Bloccare il comportamento desiderato con test

**Files:**
- Modify: `tests/public/github_assignment_review_saved_grades.php`
- Create: `tests/public/github_assignment_review_selection.php`

- [x] **Step 1: aggiungere asserzioni per il filtro server-side**

Verificare che la pagina legga `salva_voto`, filtri gli indici non selezionati e continui a escludere `skip` prima di `insertRow('VOTI', ...)`.

- [x] **Step 2: aggiungere asserzioni per UI e sincronizzazione**

Verificare la presenza di:

```text
review-show-names checked
salva_voto[
saveVotesModal
Salva voti selezionati
rubricApplyBtn
salva_voto
```

e delle funzioni JavaScript che sincronizzano checkbox tabella/popup.

- [x] **Step 3: eseguire i test e confermare il RED**

Comando: `php tests\public\github_assignment_review_saved_grades.php` e `php tests\public\github_assignment_review_selection.php`.

Risultato atteso: fallimento delle nuove asserzioni perché l’implementazione non esiste ancora.

### Task 2: Limitare il salvataggio ai voti selezionati

**Files:**
- Modify: `public/github_assignment_review.php:1139-1240`

- [x] **Step 1: leggere gli indici selezionati dal POST**

Normalizzare `$_POST['salva_voto']` in una mappa di indici interi e, nel ciclo dei voti, saltare ogni indice assente dalla mappa prima del controllo `skip`.

- [x] **Step 2: mantenere il comportamento esistente per i voti selezionati**

Per ogni riga selezionata e non `skip`, conservare la costruzione delle note famiglia/interne, l’inserimento in `VOTI` e il conteggio `inserted`.

- [x] **Step 3: eseguire lint e test RED→GREEN**

Comandi: `php -l public\github_assignment_review.php` e i due test del Task 1. Risultato atteso: test verdi.

### Task 3: Checkbox per riga e pulsanti di apertura riepilogo

**Files:**
- Modify: `public/github_assignment_review.php:1400-1665`

- [x] **Step 1: attivare di default “Mostra nomi e repository”**

Aggiungere `checked` all’input `#review-show-names`; mantenere il valore iniziale coerente con `body.review-names`.

- [x] **Step 2: aggiungere il checkbox canonico accanto al voto**

Per ogni riga con studente creare `input type="checkbox" name="salva_voto[idx]" value="1" class="save-vote-checkbox"` deselezionato di default, con `data-index`, `data-student-name`, `data-github`, `data-blind-student` e `data-vote-select`.

- [x] **Step 3: sostituire i pulsanti di salvataggio**

Inserire un pulsante in alto, sopra il flag dei nomi, e uno in fondo al form, entrambi con testo `Salva voti selezionati`, `type="button"` e `data-bs-target="#saveVotesModal"`.

### Task 4: Popup riepilogativo e sincronizzazione client-side

**Files:**
- Modify: `public/github_assignment_review.php` (markup modal e script finale)

- [x] **Step 1: costruire il modal riepilogo**

Aggiungere `#saveVotesModal` con una tabella contenente checkbox non nominati, studente, GitHub e voto corrente. Il modal deve includere anche righe con voti preesistenti caricati dal database.

- [x] **Step 2: implementare la vista cieca**

Usare `show-name`/`show-blind` e testi stabili `Studente 1`, `Studente 2`, ecc. quando `#review-show-names` è disattivato; aggiornare il riepilogo al cambio del flag.

- [x] **Step 3: sincronizzare le selezioni**

Il checkbox della tabella e quello del modal devono aggiornarsi a vicenda. Il cambio del voto deve aggiornare il testo del voto nel modal.

- [x] **Step 4: collegare la rubrica**

Quando `rubricApplyBtn` applica un voto valido alla select della riga, impostare anche il checkbox di salvataggio a selezionato e sincronizzare il checkbox del modal.

- [x] **Step 5: confermare il salvataggio**

Il pulsante di conferma del modal deve chiudere il modal e inviare il form principale. Il server rimane l’autorità finale e scarta comunque i voti non selezionati o `skip`.

### Task 5: Verifica completa

**Files:**
- Verify: `public/github_assignment_review.php`
- Verify: `tests/public/github_assignment_review_saved_grades.php`
- Verify: `tests/public/github_assignment_review_selection.php`

- [x] **Step 1: eseguire i test mirati**

`php tests\public\github_assignment_review_saved_grades.php`

`php tests\public\github_assignment_review_selection.php`

`php -l public\github_assignment_review.php`

- [x] **Step 2: eseguire la suite completa**

`php tests\run.php`

- [x] **Step 3: verificare il container locale**

`docker compose up -d --build app`

`docker compose exec -T app php -l /var/www/html/public/github_assignment_review.php`

`docker compose ps`

- [x] **Step 4: controllare il diff**

`git diff --check -- public/github_assignment_review.php tests/public/github_assignment_review_saved_grades.php tests/public/github_assignment_review_selection.php`
