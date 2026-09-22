# Phase 2 test changes (R-11 log)

R-11: each adjusted existing test is logged here with the reason (fixture conforms
/ assertion superseded / behavior corrected).

## WU-A — family A (REM-067/073)

### New invariant tests (appended to `tests/Feature/Phase5Test.php`)

Written RED against the pre-migration schema, GREEN after
`2026_08_15_000001_add_mastery_records_integrity_guards.php` landed:

| Test | Guard verified |
|---|---|
| `test_mastery_unrecorded_insert_rejected_zero_rows` | `trg_mr_recorded_only` — Unrecorded insert raises, zero rows (BR-21, BR-58) |
| `test_mastery_percent_range_check_violation` | `chk_mr_percent_range` CHECK (0–100) |
| `test_mastery_status_threshold_check_violation` | `chk_mr_status_threshold` CHECK (90+Not_Mastered and 50+Mastered) |
| `test_mastery_unique_derivation_collision` | `uq_mr_stu_comp_assess_submission` UNIQUE (DATA-DEC-003) |
| `test_assessments_type_immutable` | `trg_assessments_type_immutable` on `assessments.type` |
| `test_mastery_desc_index_exists` | `idx_mr_stu_comp_created` `(student_id, competency_id, created_at DESC)` |

### Adjusted tests

1. `Phase5Test::test_compute_mastery_is_deterministic`
   - **Reason: assertion superseded / behavior corrected (R-01 collision).** The
     old test submitted the same assessment twice and re-called
     `CompetencyMappingService::computeMastery` on the same submission. The second
     attempt is already rejected by the app-layer `ALREADY_SUBMITTED` guard, so the
     test was actually re-deriving one submission twice — which silently created
     duplicate `mastery_records` rows pre-migration. The DATA-DEC-003 UNIQUE guard
     (REM-067) now blocks that re-derivation. Determinism (NFR-02) is instead
     proven across two identical Recorded assessments, each derived exactly once by
     the submit flow; the two `mastery_results` payloads are compared.

2. `Phase5Test::test_mastery_unrecorded_insert_rejected_zero_rows` and
   `test_mastery_status_threshold_check_violation`
   - **Reason: fixture/technique conforms.** A failed PG statement aborts the
     surrounding transaction, so the violating insert runs inside a nested
     `DB::transaction` (savepoint rollback) to keep the outer test transaction
     usable for the subsequent assertion. Catch blocks now assert the
     `QueryException` type.

3. `Phase5IntegrationTest::test_regrade_overwrites_grade_and_triggers_new_mastery`
   → renamed `test_regrade_recompute_is_blocked_by_unique_derivation_guard`
   - **Reason: assertion superseded / behavior corrected (R-01).** `regradeAttempt`
     re-derives the same `(student, competency, assessment, submission)` tuple the
     initial `recordManualGrade` flow already persisted; the DATA-DEC-003 UNIQUE
     guard now blocks it and the entire regrade transaction rolls back. The test
     still exercises both requests (manual grade over HTTP, then regrade) and
     asserts the new correct behavior: `QueryException` raised, no second
     `MasteryRecord` for the tuple, and the `grade_entries` ledger unchanged
     (score stays 8 — the aborted transaction discarded the overwrite). Recompute
     semantics are a Phase 3 blocking dependency (regrade-mastery semantics to be
     redefined; corrections flow via resubmission REM-064).

4. `tests/Unit/Phase5ServiceTest::test_compute_mastery_unrecorded_does_not_persist`
   - **Reason: fixture conforms (REM-073).** The test flipped the Recorded setUp
     fixture to Unrecorded via `$assessment->update(['type' => 'Unrecorded'])`,
     which the new `trg_assessments_type_immutable` trigger forbids. The Unrecorded
     assessment is now created directly with `type => 'Unrecorded'`.

## WU-B — family B (REM-068)

### New invariant tests (appended to `tests/Feature/Phase5Test.php`)

Written RED against the pre-migration schema (DECIMAL(5,2), no CHECKs, no trigger),
GREEN after `2026_08_15_000002_add_grade_entries_integrity.php` landed:

| Test | Guard verified |
|---|---|
| `test_grade_entry_negative_score_check_violation` | `chk_ge_score_non_negative` — score −1 insert raises, zero rows persist |
| `test_grade_entry_score_over_max_check_violation` | `chk_ge_score_not_over_max` — score 11 vs max_score 10 raises |
| `test_grade_entry_max_score_snapshot_from_item` | `trg_ge_snapshot_max_score` — client-sent max_score 999 overwritten to item max_points (10); a 11-vs-10 score then trips the CHECK fed by the snapshot (trigger-before-CHECK ordering) |
| `test_grade_entry_decimal_overflow_safe` | DECIMAL(8,2) — score 12345.67 accepted (was a `numeric field overflow` on (5,2)) |
| `test_grade_entry_negative_score_service_rejected` | `GradingService::recordManualGrade` — score −1 → 422 `VALIDATION_ERROR`, no row persisted |

### Service-layer lower bound (Step 3, same migration batch)

`GradingService` now rejects `score < 0` with `ValidationException` (422
`VALIDATION_ERROR`) in all three write paths: `recordManualGrade`, `saveGradeDraft`,
`regradeAttempt`. Zero remains **legal** (Phase 3 REM-053 needs it); the DB CHECK is
`score >= 0` — REM-068 prose "negative/zero" is imprecise, decision recorded in
open-questions.

### Adjusted tests

None. The snapshot trigger overwrites `max_score` with the item's `max_points`, but
every existing grade_entries fixture already sends `max_score` equal to the item's
`max_points` (10), and no existing assertion reads the stored `max_score` column, so
no fixture or assertion changed (R-11).

## WU-C — riders (REM-020/021/025/035/037/038/039)

### New invariant tests (appended to `tests/Feature/Phase5Test.php`, 6 tests)

| Test | Guard verified |
|---|---|
| `test_users_identifier_xor_check` | `chk_users_identifier` CHECK — Student with both/email-only raises, Teacher school_id-only raises, conforming rows pass (REM-037). Raw `DB::table('users')->insert` inside a nested `DB::transaction` (savepoint) so the aborted statement does not kill the outer test transaction (PG 25P02) |
| `test_submission_snapshot_mismatch_rejected` | `trg_assess_sub_snapshot` — term mismatch and section mismatch both raise; conforming submission passes (REM-038) |
| `test_dead_timestamp_columns_absent` | six dropped columns absent via `information_schema.columns` (REM-039) |
| `test_import_admin_fk_restrict` | `import_jobs.admin_id` / `import_logs.admin_id` `ON DELETE RESTRICT` — deleting an admin with import history raises; admin + history survive (REM-021) |
| `test_learning_material_without_subject_section` | `subject_section_id` nullable + `title` column present (REM-035) |
| `test_audit_enum_has_error_report_download` | `audit_event_type` holds 11 literals incl. `error_report_download`, no `export` (REM-025) |

### New endpoint tests (appended to `tests/Feature/BatchImportTest.php`, 2 tests)

| Test | Contract verified |
|---|---|
| `test_error_report_download_single_use_410` | 200 on first download (file consumed), reuse → 410 `GONE`, unknown token → 410, expired TTL → 410 (REM-020) |
| `test_failed_csv_import_writes_failed_status` | unreadable CSV (fopen on a directory) → `BusinessRuleConflictException` `CSV_READ_ERROR` rethrown **and** an `import_logs` row with `status='failed'` written (REM-020) |

### Adjusted existing tests (R-11 log)

1. `BatchImportTest::test_error_report_is_single_use`
   - **Reason: assertion superseded.** Second download was 404 `NOT_FOUND`; the
     REM-020 contract replaced it with 410 `GONE` (missing/expired/used are all
     "gone"), so the assertion now expects 410 + `error.code === 'GONE'`.
2. `BatchImportTest::test_error_report_invalid_token_returns_404`
   - **Reason: assertion superseded.** Unknown token now yields 410 `GONE`
     (same REM-020 contract); renamed `..._returns_410`.
3. `Phase8AuditReadMatrixTest::test_96_export_and_purge_terms_event_types_are_filterable`
   - **Reason: audit literal reconciled (REM-025).** The `'export'` enum value was
     renamed `'error_report_download'` (DATA-DEC-011), so the filter probe iterates
     `['error_report_download', 'purge_terms']`; test renamed to match.
4. `Phase8AuditServiceEdgeTest::test_log_accepts_less_common_valid_event_types`
   - **Reason: audit literal reconciled (REM-025).** Same enum rename; logs
     `error_report_download` and asserts the renamed literal in `audit_logs`.
5. `Phase9RetentionAndPurgeTest::test_successful_login_resets_rate_limit`
   - **Reason: fixture conforms (REM-037 identifier XOR).** Students identify by
     `school_id` with `email = NULL`, so the login fixture + rate-limit key now use
     `school_id` instead of an email address.
6. `UserFactory` — added `configure()->afterMaking()` conformance hook (REM-037):
   Student → `email = null` (school_id generated if absent); Admin/Teacher →
   `school_id = null`. Every factory user is conforming regardless of role.
7. `TestUserSeeder` — student fixture now `firstOrCreate(['school_id' => 'STU001'])`
   with `'email' => null` (REM-037); admin/teacher unchanged (email-only, conforming).

### Service/controller/model changes (not fixture, logged for traceability)

- `BatchImportController::downloadErrorReport` — token lookup by sha256 hash;
  410 `{error:{message,code:'GONE'}}` for missing/expired/used; on success deletes
  the file and sets `error_report_used_at` + clears the token (REM-020).
- `BatchImportService` — `ERROR_REPORT_TTL_DAYS = 7`; stores sha256 token +
  `now()+7d` expiry on both xlsx paths; CSV wrapper writes a `status='failed'`
  `import_logs` row when `parseCsv` raises, then rethrows (REM-020).
- `ImportJob` model — `error_report_token/expires_at/used_at` fillable + datetime
  casts (REM-020). `LearningMaterial` model — `title` fillable (REM-035).
- `AuditLogService::EVENT_TYPES` — `'export'` → `'error_report_download'` (REM-025);
  grep confirmed no other `'export'` audit literal in `app/`, `database/`, `routes/`
  (only the two Phase 8 test comments explaining the rename).
- New `app/Console/Commands/BackfillImportErrorReportTokens.php` +
  `routes/console.php` registration — `import:backfill-error-report-tokens` derives
  the plain token from `error_report_path` filename, stores sha256 +
  `expires_at = created_at + 7d` (TTL anchored to creation, REM-020).

### Deviations (watch-items for WU-D/docs)

1. **`learning_materials.title` is NULLABLE, not NOT NULL.** DATA-DEC-009 states
   `title VARCHAR(255) NOT NULL`, but `LearningMaterialService` still maps the API
   `title` to `original_filename` (the as-built column gap REM-035 fixes) — a NOT
   NULL column would break every service-driven insert until the Phase 7 remediation
   rewires the service. Nullable matches the WU-C scope's "nullable unless DDD says
   otherwise" default; the migration comment documents this. WU-D/CODEMAP should
   record title as nullable-until-service-rewire.
2. **REM-038 is a BEFORE trigger, not a CHECK** (cross-table guard — a PG CHECK
   cannot read `assessments`/`subject_sections`). Deviation documented in the
   migration docblock and this log.
3. **410 replaces the old 404** for error-report download. The error envelope
   (`{error:{message,code}}`) is unchanged; only the code/status changed (NFR-11/15).
4. **Backfill TTL anchored to `created_at`** (not `now()`): legacy reports expire
   `created_at + 7d`, the same schedule new reports get at creation time.