# Portal Navigation Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task with checkpoints.

**Goal:** Remove the obsolete standalone student-sync page from portal navigation, clarify the ClasseViva grades page label, and remove broken diagnostic actions from the advanced system-status page.

**Architecture:** Keep the roster synchronization services and the teaching-groups workflow intact. Change only the portal entry points and visible labels, delete the legacy page file, and use one static regression test that inspects the affected PHP sources.

**Tech Stack:** PHP server-rendered pages, repository-native PHP test runner (`tests/run.php`), Git.

---

### Task 1: Add the focused navigation regression test

**Files:**
- Create: `tests/public/portal_navigation_cleanup.php`
- Test runner: `tests/run.php` (only if the test must be registered; inspect runner conventions first)

- [ ] **Step 1: Write assertions for the requested surface**

  The test must load `public/index.php`, `public/manage_grades.php`, `public/verify_grades.php`, and `public/system_status.php` as text and assert:

  - `public/studenti_sync.php` does not exist.
  - No loaded portal source contains `studenti_sync.php` as a link.
  - The visible grades navigation contains `Gestione voti Classe Viva`.
  - `system_status.php` contains neither `Azioni Disponibili` nor `tests/test_database.php` nor `tests/test_integrations.php`.

- [ ] **Step 2: Run the focused test before implementation**

  Run `php tests/public/portal_navigation_cleanup.php`.

  Expected result: FAIL because the legacy page, old labels, and action section still exist.

### Task 2: Apply the portal cleanup

**Files:**
- Delete: `public/studenti_sync.php`
- Modify: `public/index.php`
- Modify: `public/manage_grades.php`
- Modify: `public/verify_grades.php`
- Modify: `public/partials/guida_content.php`
- Modify: `public/system_status.php`

- [ ] **Step 1: Remove the standalone student page and dashboard link**

  Delete `public/studenti_sync.php`. Remove only the dashboard anchor whose `href` is `studenti_sync.php`; leave `StudentiManager` and `teaching_groups.php` roster synchronization untouched.

- [ ] **Step 2: Rename the visible ClasseViva grades entry points**

  Change the dashboard and grades-management header action labels, and the verification page title, to `Gestione voti Classe Viva`. Keep `verify_grades.php` as the route.

- [ ] **Step 3: Remove the obsolete system-status actions card**

  Delete the complete `<!-- Actions -->` block from `public/system_status.php`, including both broken test links and the `Torna alla Dashboard` link.

- [ ] **Step 4: Update guide links without changing routes**

  Change guide text that calls the verification page “Verifica Voti Pubblicati” or exposes `verify_grades.php` as a raw label to `Gestione voti Classe Viva`; retain the working route.

### Task 3: Verify and commit

- [ ] **Step 1: Run the focused regression test**

  Run `php tests/public/portal_navigation_cleanup.php`.

  Expected result: PASS.

- [ ] **Step 2: Run the repository test suite**

  Run `php tests/run.php` and record the pass/failure counts. A pre-existing environment failure must be reported rather than hidden.

- [ ] **Step 3: Run syntax and diff checks**

  Run `php -l public/index.php`, `php -l public/manage_grades.php`, `php -l public/verify_grades.php`, `php -l public/system_status.php`, and `git diff --check`.

- [ ] **Step 4: Update project memory**

  Update the private `.ai/current_state.md` and `.ai/tasks_todo.md` with the removed legacy page, retained group-roster service, renamed ClasseViva grades entry, and removed system-status actions.

- [ ] **Step 5: Commit the implementation**

  Commit the source, test, and memory changes with a message such as `refactor: remove legacy student sync navigation`.
