# UDA periodi e import Classroom – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Semplificare i campi UDA, calcolare automaticamente le date dal periodo ClasseViva, sincronizzare le classi assegnate e arricchire l’import Classroom senza modificare lo schema del database.

**Architecture:** A small pure helper will calculate academic periods from the existing ClasseViva configuration. Shared helpers will synchronize `classi_target` and merge Classroom course metadata into notes. Existing database columns remain readable/writable for backward compatibility, while create/edit screens stop exposing project and duration and replace manual dates with a period selector.

**Tech Stack:** PHP 8.2, existing database adapter, vanilla JavaScript, current PHP test runner in `tests/run.php`.

---

### Task 1: Add full regression tests first

**Files:**
- Create: `tests/uda_editor/test_uda_periods.php`
- Create: `tests/uda_editor/test_classroom_metadata.php`
- Modify: `tests/run.php`

- [ ] Test two-period, three-period, combined-period, full-year and invalid configuration calculations.
- [ ] Test class-target synchronization, Classroom note merge without replacing user notes, and active status only after successful resource import.
- [ ] Run the focused tests and confirm they fail before implementation.

### Task 2: Implement shared pure helpers

**Files:**
- Create: `src/Utils/AcademicPeriodHelper.php`
- Create: `src/Utils/UdaMetadataHelper.php`

- [ ] Implement deterministic period options/date ranges using September 1 and June 30, with configured `period_date_1`/`period_date_2`.
- [ ] Implement class-name normalization/synchronization and idempotent Classroom section/room note merging.
- [ ] Run focused tests until green.

### Task 3: Update UDA create/edit screens

**Files:**
- Modify: `public/uda_create.php`
- Modify: `public/uda_edit.php`

- [ ] Remove project and duration controls; replace manual date controls with period selector and hidden generated dates.
- [ ] Make class targets read-only/replaced by an assigned-class summary and link to assignment page.
- [ ] Populate discipline, topic, notes and status from Classroom import using the shared helper.
- [ ] Keep legacy POST/database fields accepted but do not expose deleted fields in new forms.

### Task 4: Synchronize assignments and dependent views

**Files:**
- Modify: `public/uda_assign.php`
- Modify: `public/uda_view.php`
- Modify: `public/uda_publish.php`
- Modify: `src/Core/ExportManager.php`

- [ ] Rebuild `classi_target` from current `CLASSI_ASSEGNATE` after add/remove operations.
- [ ] Preserve assignment-level dates while using UDA period dates as defaults.
- [ ] Remove duration/project presentation and Classroom publication text where the fields are no longer part of the new workflow.
- [ ] Keep old values readable in legacy UDA records where needed.

### Task 5: Verify full suite and inspect diff

- [ ] Run `php tests/run.php` and PHP syntax lint.
- [ ] Check that database schema files were not changed and no commit was created.
- [ ] Review all remaining references to the removed UI fields and report any intentional compatibility references.
