# Legacy Classes to Teaching Groups Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (recommended) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert legacy class/subject rows on staging into idempotent provider-neutral teaching groups and UDA assignments, with a full local simulation and online verification.

**Architecture:** Add a focused migration service that reads legacy rows through PDO, builds a deterministic plan, and applies it in one transaction without deleting legacy tables. A CLI/temporary HTTP wrapper exposes dry-run and apply modes; the wrapper is never part of the normal public deployment. Tests cover plan generation, conflict handling, local dump integration, and a second no-op run.

**Tech Stack:** PHP 8.2, PDO MySQL/SQLite, existing repositories/schema definitions, Docker MySQL, FTPS staging endpoint.

---

### Task 1: Add the failing plan-level test

**Files:**
- Create: `tests/database/legacy_classes_to_groups.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Write fixtures for 14 class-materia pairs, incomplete rows, duplicate Classroom course, and an existing deterministic group.**

  The test must create the canonical tables with `DatabaseFactory::createForTesting()`, create legacy tables with the exact legacy columns needed by the migration, insert a duplicate Classroom course for two pairs, and seed one deterministic `GRP_LEGACY_...` row plus its integrations.

- [ ] **Step 2: Assert the dry plan before implementation.**

  Assert that the plan reports 14 unique pairs, skips rows missing class or subject IDs, reports the duplicate Classroom course as a conflict, reuses the seeded deterministic group, and schedules no duplicate group/integration/assignment for existing rows.

- [ ] **Step 3: Run the focused test and verify the expected RED failure.**

  Run:

  ```powershell
  php tests/database/legacy_classes_to_groups.php
  ```

  Expected: failure because `LegacyClassesToGroupsMigration` does not yet exist.

- [ ] **Step 4: Register the test in `tests/run.php`.**

  Add an `exec()` entry matching the existing test runner convention and print `PASS: migrazione classi verso gruppi` or the collected failure.

### Task 2: Implement deterministic planning and application

**Files:**
- Create: `src/Core/Database/LegacyClassesToGroupsMigration.php`
- Test: `tests/database/legacy_classes_to_groups.php`

- [ ] **Step 1: Implement the public API.**

  Add:

  ```php
  public function plan(): array;
  public function apply(): array;
  ```

  The constructor receives `PDO $pdo` and an optional clock callback for deterministic tests. `plan()` performs only reads and returns `success`, `pairs`, `groups_to_create`, `integrations_to_create`, `assignments_to_create`, `skipped`, `conflicts`, and `counts`.

- [ ] **Step 2: Implement deterministic pair collection.**

  Read `CLASSROOM_MAPPINGS` and `CLASSI_ASSEGNATE`, normalize aliases (`id_classe_cv`/`id_classe`, `id_materia_cv`), require both IDs and `id_utente`, and key rows by `id_utente|class|subject`. Preserve the best non-empty class/subject names and academic-year/date values.

- [ ] **Step 3: Implement stable group IDs and existing-row reuse.**

  Generate `GRP_LEGACY_` plus the first 24 hex characters of SHA-256 over `id_utente|class|subject`. Read existing groups by ID and schedule inserts only when missing; never generate random IDs for legacy pairs.

- [ ] **Step 4: Implement integration planning and Classroom conflict handling.**

  Schedule one `classeviva` integration per pair. For Classroom, map each valid course to its pair; if an active course is already owned by another group, keep the existing owner and add a conflict entry rather than inserting a duplicate. Empty historical mapping rows become `skipped` entries.

- [ ] **Step 5: Implement idempotent UDA assignment planning.**

  For each `CLASSI_ASSEGNATE` row with a valid pair, resolve the deterministic group and check `(id_utente, id_uda, id_gruppo)` in `UDA_GRUPPI`. Schedule one insert only when absent, preserving `id_assegnazione` when unused and otherwise generating a deterministic hash-based assignment ID.

- [ ] **Step 6: Implement transactional apply.**

  Call `plan()`, start a PDO transaction, insert only scheduled rows with prepared statements, commit on success, and roll back/rethrow on any error. Return inserted/reused/skipped/conflict counts. Do not issue `DELETE`, `TRUNCATE`, or `DROP` against legacy tables.

- [ ] **Step 7: Run the focused test and verify GREEN.**

  Run:

  ```powershell
  php tests/database/legacy_classes_to_groups.php
  ```

  Expected: PASS for deterministic IDs, conflict handling, inserts, and idempotent second plan.

### Task 3: Add the migration wrapper and report contract

**Files:**
- Create: `scripts/migrate_classes_to_groups.php`
- Modify: `tests/database/legacy_classes_to_groups.php`

- [ ] **Step 1: Add CLI argument parsing.**

  Support `--dry-run` (default), `--apply`, `--confirm=MIGRATE-CLASSES-TO-GROUPS`, and `--output=<path>`. Reject apply without the exact confirmation and reject production targets.

- [ ] **Step 2: Initialize only the canonical schema prerequisites.**

  Load `bootstrap.php`, create the adapter without implicit data reset, call the existing schema initializer/migration runner to ensure canonical tables and indexes exist, then construct the migration service with the adapter PDO. Do not call any reset or legacy-table removal class.

- [ ] **Step 3: Emit a machine-readable report.**

  Write JSON under `storage/reports/` by default, containing mode, target, timestamps, source counts, planned counts, inserted counts, skipped rows, conflicts, and errors. Never include credentials or raw student data.

- [ ] **Step 4: Run wrapper lint and dry-run against the local application database.**

  Run:

  ```powershell
  php -l scripts/migrate_classes_to_groups.php
  php scripts/migrate_classes_to_groups.php --dry-run --target=local
  ```

  Expected: exit 0 and a JSON report with no writes.

### Task 4: Simulate against the fresh staging dump locally

**Files:**
- No source changes; private artifacts only under `credentials/`.

- [ ] **Step 1: Import `credentials/db-dumps/staging-20260820-1612.sql` into an isolated Docker schema.**

  Use a dedicated local schema (`uda_migration_sim_20260820`) and the local MySQL root account; do not alter `uda_system`.

- [ ] **Step 2: Run the migration wrapper against the isolated schema.**

  Override only process-local `DB_DATABASE`/`DB_HOST`/`DB_USERNAME`/`DB_PASSWORD` variables and run `--dry-run`, then `--apply --confirm=MIGRATE-CLASSES-TO-GROUPS`.

- [ ] **Step 3: Verify expected results.**

  Query the isolated schema and record: 14 deterministic groups, one ClasseViva integration per pair, 9 Classroom integrations after the known duplicate-course conflict, 42 UDA group assignments subject to existing-row reuse, and legacy tables unchanged.

- [ ] **Step 4: Execute the migration a second time and assert a no-op.**

  Run the same apply command again and verify zero new groups, integrations, or assignments; counts and row hashes must remain unchanged.

- [ ] **Step 5: Run the full local test suite and SQL diff checks.**

  ```powershell
  composer test
  git diff --check
  ```

### Task 5: Acquire the current online state and perform the staged migration

**Files:**
- Temporary remote files only: one-shot diagnostic/migration endpoint outside `public/` and its token.
- Private reports: `credentials/db-dumps/` and `credentials/manifests/`.

- [ ] **Step 1: Create a current online backup before any write.**

  Upload the one-shot dump helper through FTPS to `/uda-system/_stage_db_dump_once.php`, invoke it with an HMAC token in an HTTP header, download the resulting SQL via FTPS, verify byte count and SHA-256, and remove the helper/token/dump from staging. Stop on any mismatch.

- [ ] **Step 2: Run an online dry-run using the same migration artifact.**

  Upload the wrapper outside `public/`, protect it with a fresh header token, invoke `--dry-run` against the stage database, save the JSON report privately, and verify that the source counts match the current dump or explain any drift before applying.

- [ ] **Step 3: Apply online with explicit confirmation.**

  Invoke the temporary endpoint with `--apply --confirm=MIGRATE-CLASSES-TO-GROUPS`; require a successful transaction response and save the report. No production host is contacted.

- [ ] **Step 4: Verify post-migration online state.**

  Run a read-only status endpoint/query for counts and deterministic IDs; confirm legacy tables still exist, duplicate counts are unchanged, and all expected UDA assignments resolve to groups.

- [ ] **Step 5: Remove and verify all temporary remote artifacts.**

  Delete the temporary endpoint, token, and any generated remote report/dump; list the remote paths afterward and require absence.

### Task 6: Document and commit the migration

**Files:**
- Modify: `docs/deployment/legacy-table-migration.md` or add a focused migration runbook section.
- Modify: `llm/logs/operations-log.md` in the private repository if the operation log is updated in this session.

- [ ] **Step 1: Document the exact dry-run/apply commands and rollback backup path.**
- [ ] **Step 2: Record local and online counts, hashes, reports, and cleanup verification without secrets.**
- [ ] **Step 3: Run `git status --short`, `git diff --check`, and commit source/tests/docs.**

