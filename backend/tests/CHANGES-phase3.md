# Phase 3 test changes (R-11 log)

R-11: each adjusted existing test is logged here with the reason (fixture conforms
/ assertion superseded / behavior corrected).

## WU-6 — REM-072 + REM-027 (lazy AI: batch triggers removed, honest release payload)

Service-side scope: `app/Services/AssessmentService.php` — Path A trigger
(release-time batch per unmastered pair) and Path B trigger (submit-time batch
for objective-only Unrecorded) deleted; `triggerAIExplanations()` deleted (dead,
zero callers); `AIService` constructor dependency removed; `releaseResults()`
now returns the persisted `assessment_submissions.results_released_at` value as
the only release-time artifact (`ai_explanations_triggered` semantics deleted).
The eligibility read (`getUnmasteredStudentCompetencies`) is retained per
REM-072 ("keep eligibility triggers only"). Generation is lazy: the first
explanation view triggers exactly one call per (attempt, item) inside
`AIService::listExplanationsForAssessment` (WU-5).

### Adjusted tests

1. `Phase5Test::test_release_results_returns_unmastered_competencies`
   - **Reason: assertion superseded / behavior corrected (REM-027, REM-072).**
     The HTTP payload no longer carries `ai_explanations_triggered`; the
     rewritten test asserts the decided release payload (`message`,
     `results_released_at`), proves `results_released_at` equals the timestamp
     actually persisted on `assessment_submissions` (Carbon-equality against
     the DB value — no fabricated `now()`), asserts the service return array
     has no `ai_explanations_triggered` key, and keeps the reflection-based
     unmastered-eligibility check. The old guard comment ("re-calling
     releaseResults would re-trigger AI generation") is obsolete under lazy AI
     and was removed.
   - **Note:** the HTTP-level *absence* of `ai_explanations_triggered` cannot
     be asserted while `AssessmentController::teacherReleaseResults()`
     (AssessmentController.php:250) still fabricates `?? true` — blocking
     dependency, see report.

2. `Phase5AccessControlTest::test_65_release_results_sets_is_results_released_flag`
   - **Reason: assertion superseded (REM-027).** The `assertJsonStructure`
     list dropped `ai_explanations_triggered`; the decided release payload is
     `message` + `results_released_at`. The DB assertion
     (`is_results_released = true`) is unchanged. Same controller note as #1.

3. `Phase7AIServiceTest` (comments only)
   - **Reason: fixture docs conform (REM-072).** Class-level trigger-control
     comment, `studentStartAndSubmit` docblock, `releaseSubmission` docblock,
     and the lazy-view comment at the first-view test no longer describe a
     release-time Path A trigger; rewritten to the lazy-semantics wording.

4. `Phase7ModerationLogGapsTest::test_..._seed...`
   - **Reason: fixture docs conform (REM-072).** The "Idempotent — Path A may
     already have stored them at release-results time" comment is obsolete:
     release stores nothing; the explicit `generateSingleExplanation` calls
     are the only row source.

### New tests (appended to `tests/Feature/Phase7AIServiceTest.php`)

Written RED against the batch-trigger service (release stored 1 mock row per
unmastered pair; Unrecorded submit stored 1 row), GREEN after the trigger
removal:

| Test | REM-072 requirement verified |
|---|---|
| `test_release_does_not_call_ai` | release-results stores zero `ai_explanations` rows and `Http::assertNothingSent()` (mock gate on — even the canned path cannot hide a trigger) |
| `test_submit_does_not_call_ai` | Unrecorded objective-only submit (the old Path B shape) stores zero rows and sends nothing |

### Blocking dependency (not in WU-6 scope)

`app/Http/Controllers/Teacher/AssessmentController.php:249-250` fabricates
`results_released_at ?? now()->toJSON()` and `ai_explanations_triggered ?? true`
when the service omits keys. The service now always returns
`results_released_at` (persisted value), so line 249 is honest passthrough, but
line 250 still re-fabricates the deleted key into the HTTP payload. Removing
line 250 (and the now-dead `??` fallback on 249) is required to complete
REM-027 at the HTTP boundary. Phase 3 HTTP tests
(`Phase3Test::test_release_results...` :1156,
`Phase3PendingGradingTest` :547/:711) still assert
`data.ai_explanations_triggered = true` and will need the same follow-up pass.

## WU-6b — HTTP boundary gap closure (controller + Phase 3 HTTP tests)

Closes the WU-6 blocking dependency (REM-027 at the HTTP boundary). The service
always returns a persisted `results_released_at`, so
`AssessmentController::teacherReleaseResults()` now passes it through honestly
(`$result['results_released_at']`, no `?? now()->toJSON()` fallback) and the
`ai_explanations_triggered ?? true` fabrication line is deleted — the release
payload is exactly `message` + `results_released_at`.

### Adjusted tests

1. `Phase3Test::test_student_can_view_results_after_release`
   - **Reason: assertion superseded (REM-027).** Replaced
     `assertJsonPath('data.ai_explanations_triggered', true)` with top-level and
     `data`-level `assertArrayNotHasKey('ai_explanations_triggered', ...)` plus
     a Carbon-equality check that `data.results_released_at` equals the
     timestamp persisted on `assessment_submissions` (no fabricated `now()`).
2. `Phase3PendingGradingTest::test_teacher_releases_results_after_scoring_done`
   - **Reason: assertion superseded (REM-027).** Same replacement; persisted
     value read per submission id.
3. `Phase3PendingGradingTest::test_full_e2e_pending_grading_workflow`
   - **Reason: assertion superseded (REM-027).** Same replacement; persisted
     value read per submission id.

Written RED first (all three failed with "Failed asserting that an array does
not have the key 'ai_explanations_triggered'." while the controller still
fabricated `?? true`), GREEN after the controller change.

Consumer scan (findstr `ai_explanations_triggered` across `app/`, `tests/`,
`frontend/src/`): after the change only the REM-027 doc comment in
`app/Services/AssessmentService.php:757` mentions the term; no HTTP consumer
reads the key (frontend included).

## WU-7 — REM-058 (upload-time PDF extraction, stored-text RAG read)

PDF extraction moved to upload time (smalot/pdfparser, synchronous in-request)
and the generation-time RAG read now SELECTs the stored `extracted_text`
column instead of reading/parsing the stored file. The old regex PDF extractor
and the generation-time DOCX extractor were deleted from `AIService`.

### Adjusted tests

1. `Phase7AIServiceTest::test_retrieve_rag_materials_extracts_pdf_text`
   - **Reason: assertion superseded / behavior corrected (REM-058).** The RAG
     read no longer extracts from the file at generation time; rewritten as
     `test_retrieve_rag_materials_selects_stored_extracted_text`. The row now
     carries `extracted_text` and deliberately references a file NEVER written
     to disk — a grounded result proves the generation path touches only the
     column (no disk read, no parser).
2. `Phase7AIServiceTest::test_retrieve_rag_materials_extracts_docx_text`
   - **Reason: assertion superseded (REM-058).** Generation-time DOCX parsing
     is gone; rewritten as
     `test_retrieve_rag_materials_selects_stored_extracted_text_for_docx_mime`
     — the stored-text read is MIME-agnostic.
3. `Phase7AIServiceTest::test_retrieve_rag_materials_missing_file_returns_ungrounded`
   - **Reason: fixture semantics changed (REM-058).** "Missing file" is no
     longer the ungrounded trigger (the file is never read); rewritten as
     `test_retrieve_rag_materials_null_extracted_text_returns_ungrounded`
     (extracted_text NULL → ungrounded) and a new
     `test_retrieve_rag_materials_empty_extracted_text_returns_ungrounded`
     ('' → ungrounded).
4. `Phase7AIServiceTest::test_retrieve_rag_materials_extracts_multiple_parens_groups_per_line`
   - **Reason: deleted (REM-058).** It asserted behavior of the deleted regex
     extractor; superseded by the stored-text selection tests.
5. `Phase7AIServiceTest::test_generate_single_explanation_grounded_when_materials_exist`
   - **Reason: fixture updated (REM-058).** The old fake PDF
     (`"BT\n(...) Tj\nET"`) cannot be parsed by smalot/pdfparser at upload
     time, so the material would store NULL text and generation would be
     ungrounded. The fixture is now a real minimal single-page PDF (built at
     runtime with byte-accurate xref offsets) and the test asserts
     `extracted_text` is stored.
6. `Phase7StudentExplanationGapsTest::test_89_explain_further_stores_grounded_when_learning_material_exists`
   - **Reason: fixture updated (REM-058).** Same fake-PDF problem; uploads the
     runtime-built minimal PDF so upload-time extraction grounds the
     follow-up.

### New tests

| Test | REM-058 requirement verified |
|---|---|
| `Phase7LearningMaterialsTest::test_pdf_upload_extracts_text_at_upload_time` | upload-time extraction stores readable text in `extracted_text` |
| `Phase7LearningMaterialsTest::test_non_pdf_upload_no_extraction` | DOCX upload → `extracted_text` NULL |
| `Phase7LearningMaterialsTest::test_corrupt_pdf_upload_does_not_fail` | garbage PDF → upload still 201, `extracted_text` NULL |
| `Phase7LearningMaterialsTest::test_put_replacing_file_re_extracts_text` | PUT file replacement re-extracts and overwrites `extracted_text` |

Written RED first (5 failures: uploads stored NULL text / RAG read returned no
excerpts / grounded generation ungrounded), GREEN after the service changes.

## WU-7b — REM-058 gap closure (upload-time DOCX extraction)

WU-7 left DOCX uploads with `extracted_text` NULL and ungrounded. The DOCX
branch now runs at upload time in `LearningMaterialService::extractUploadedText`
via PHP's built-in ZipArchive + SimpleXMLElement (`word/document.xml` `<w:t>`
nodes, space-joined, whitespace-collapsed, 200,000-char cap, same
Throwable → Log::warning + NULL failure policy as the PDF branch).

### Adjusted tests

1. `Phase7LearningMaterialsTest::test_non_pdf_upload_no_extraction`
   - **Reason: behavior corrected (REM-058).** DOCX is now an extractable
     format, so "non-PDF upload → NULL" is no longer true for DOCX; the old
     fixture (garbage bytes + DOCX mime) collided with the new DOCX branch
     and became a duplicate of the corrupt-DOCX test. Rewritten as
     `test_docx_without_text_nodes_stores_null` — a real minimal DOCX whose
     body has no `<w:t>` text nodes → extraction yields '' → NULL (mirrors
     the PDF empty-text → NULL path). Written RED alongside WU-7b's new
     tests, GREEN after the service change.

### New tests

| Test | REM-058 requirement verified |
|---|---|
| `Phase7LearningMaterialsTest::test_docx_upload_extracts_text_at_upload_time` | real DOCX (zip with `word/document.xml`) → `extracted_text` contains the `<w:t>` run text |
| `Phase7LearningMaterialsTest::test_corrupt_docx_upload_does_not_fail` | garbage bytes + `.docx` → upload still 201, `extracted_text` NULL (malformed DOCX → clean rejection) |

Written RED first (`test_docx_upload_extracts_text_at_upload_time` failed
"Failed asserting that null is not null." while the service stored NULL for
DOCX), GREEN after the service change.

## WU-8 — REM-043 (foreign-item grade guards) + REM-024 (audit scope, R-15)

Service-side scope: `app/Services/GradingService.php` — `recordManualGrade`,
`saveGradeDraft`, and `regradeAttempt` now reject, BEFORE any `grade_entries`
write: (a) items whose `assessment_id` does not match the attempt's assessment
(409 `ITEM_NOT_IN_ASSESSMENT`); (b) non-essay items through the essay-only
grading path (409 `NOT_A_SUBJECTIVE_ITEM`, the same code the #64 endpoint
already uses in `AssessmentService::scoreSubjectiveItems`); (c) for the
manual/draft paths only, responses already carrying `earned_points` (409
`RESPONSE_ALREADY_SCORED`) — `regradeAttempt` keeps its overwrite semantics
(BR-56) and gets only guards (a)+(b). REM-024: result release lives in
`AssessmentService::releaseResults` (already logs `release_results`, R-15 scope
= result-release events only); no `AuditLogService` injection into
`GradingService` — grading ops are record-not-implement (write-budget).

### Adjusted tests

1. `Phase5AccessControlTest::test_100_draft_mode_does_not_trigger_mastery_recompute`
   - **Reason: fixture conforms (REM-043).** The fixture graded the helper's
     hard-coded `multiple_choice` item through the essay-only manual-grade
     path — the new guard now rejects it with 409 `NOT_A_SUBJECTIVE_ITEM`
     before the draft write. The test's intent is draft-mastery suppression,
     not item type, so the item is flipped to `essay`
     (`item_type` + `correct_answer = null`).
2. `Phase5AccessControlTest::test_100_validation_error_score_exceeds_maximum`
   - **Reason: fixture conforms (REM-043).** Same helper's `multiple_choice`
     item; the guard fires (409) before the score-bounds check (422). Item
     flipped to `essay` so the score-bounds validation is what the test
     exercises.

### New tests

| Test | REM requirement verified |
|---|---|
| `Phase4Test::test_100_grade_item_not_in_assessment_returns_409_and_writes_no_grade_entries` | REM-043: foreign essay item (second assessment, same teacher) → 409 `ITEM_NOT_IN_ASSESSMENT`, zero `grade_entries` |
| `Phase4Test::test_100_grade_objective_item_returns_409_not_a_subjective_item_and_writes_no_grade_entries` | REM-043: objective item through manual path → 409 `NOT_A_SUBJECTIVE_ITEM`, zero rows |
| `Phase4Test::test_100_regrade_same_item_in_pending_grading_returns_409_already_scored` | REM-043: re-grading an already-scored response (partial finalize, then same item again) → 409 `RESPONSE_ALREADY_SCORED`, ledger row count unchanged |
| `Phase4Test::test_100_draft_foreign_item_returns_409_and_writes_no_grade_entries` | REM-043: draft path foreign item → 409, zero rows |
| `Phase4Test::test_100_draft_objective_item_returns_409_and_writes_no_grade_entries` | REM-043: draft path objective item → 409, zero rows |
| `Phase4Test::test_100_draft_already_scored_response_returns_409` | REM-043: draft on already-scored response → 409, ledger row count unchanged |
| `Phase5IntegrationTest::test_regrade_foreign_item_returns_409_item_not_in_assessment` | REM-043: `regradeAttempt` rejects foreign item before any write; original grade untouched |
| `Phase8AuditLogTest::test_manual_grade_does_not_write_audit_row` | REM-024/R-15: successful manual grade writes ZERO `audit_logs` rows (grading ops record-not-implement) |

RED evidence: 6 Phase4Test failures (expected 200, got 409) + 1
Phase5IntegrationTest error (regrade reached the UNIQUE derivation guard
instead of `ITEM_NOT_IN_ASSESSMENT`); GREEN after the service guards. The
release-side audit assertion already existed
(`Phase8AuditLogTest::test_release_results_writes_release_results_audit_row`).

## WU-9 — REM-054 (ratios ≤ 100) + REM-047 (tiebreak) + REM-026 (multi-section) + REM-074 (derived view)

Service-side scope: `app/Services/CompetencyMappingService.php`,
`app/Services/AnalyticsService.php`, `app/Services/AssessmentService.php`,
`app/Http/Controllers/CompetencyMappingController.php`, the migration
`database/migrations/2026_08_16_000004_derived_not_competent_view.php`, and
deletion of `app/Models/NotCompetentFlag.php` + the `notCompetentFlags()`
HasMany on `MasteryRecord`.

Decided design (BASELINE v1.2 §15.4, ARCH-DEC-012):
- Not-Competent is a derived view `v_not_competent_flags` over the LATEST
  Recorded mastery_records row per (student, competency) with
  `mastery_status = 'Not_Mastered'` (ROW_NUMBER window, ORDER BY
  `created_at DESC, id DESC`, latest resolved FIRST then status-filtered —
  a newer `Mastered` row must clear a stale older `Not_Mastered`).
- One shared subquery `MasteryRecord::scopeLatestPerPair(Builder, bool
  $partitionByAssessment = false)` feeds every dashboard aggregate
  (mastery_rate_percent / remediation_frequency / average_mastery_percent
  computed over the latest set only → ratios can never exceed 100%, REM-054).
  `$partitionByAssessment = true` gives the per-assessment snapshots the
  performance-trends endpoint needs.
- `getAggregateSummaries` and `AnalyticsService::getAggregateCompetencySummary`
  now take `?array $sectionIds` (REM-026) — the controller's unfiltered
  `/api/teacher/competency-summary` aggregates across ALL assigned sections
  instead of silently first-section-only; the filtered branch and the admin
  branch pass `[$sectionId]`.
- AI eligibility read (`AssessmentService::getUnmasteredCompetenciesForAssessment`)
  queries the view scoped to the assessment's scored submissions; the flag
  writer inside `persistMasteryRecords` is deleted.

### Adjusted tests

1. `Phase5ServiceTest` — `getAggregateSummaries($this->section->id)` → `[$this->section->id]`
   in `test_get_aggregate_summaries_empty_when_no_records`,
   `test_get_aggregate_summaries_section_level_filter`,
   `test_get_aggregate_summaries_mixed_mastered_not_mastered`
   - **Reason: signature changed (REM-026).** Service now scopes by section id array.
2. `Phase5ServiceTest::test_compute_mastery_unrecorded_does_not_persist` (or the
   Unrecorded non-persistence test containing `NotCompetentFlag::count()`)
   - **Reason: model deleted (REM-074).** `NotCompetentFlag::count()` → `DB::table('v_not_competent_flags')->count()`.
3. `Phase5Test::test_compute_mastery_not_mastered_below_threshold_creates_flag`
   - **Reason: assertion superseded (REM-074).** Stored-table read → derived-view
     read (`DB::table('v_not_competent_flags')`), same expected count.
4. `Phase5Test::test_compute_mastery_mixed_mastered_not_mastered_...` (per-competency
   flag counts)
   - **Reason: assertion superseded (REM-074).** Same view-based read.
5. `Phase5Test` Unrecorded-submission test asserting zero flags
   - **Reason: assertion superseded (REM-074).** `NotCompetentFlag` count → view count.
6. `Phase6IntegrationTest::test_drill_down_flags_match_gap_report`
   - **Reason: assertion superseded (REM-074).** Stored-model count → `DB::table('v_not_competent_flags')->count()`.
7. `Phase6EdgeCaseTest::test_77_heatmap_aggregation_without_assessment_id_combines_all`
   - **Reason: behavior corrected (REM-054).** `average_mastery_percent` now
     aggregates over the latest-per-(student, competency) set only; expected
     average updated (50.0 → 0.0 for the latest-still-Not_Mastered case).
8. `Phase6AccessControlTest` — `getAggregateCompetencySummary($this->section->id)` →
   `[$this->section->id]` in both service tests
   - **Reason: signature changed (REM-026).**

### New tests

| Test | REM requirement verified |
|---|---|
| `Phase5ServiceTest::test_mastery_rate_percent_never_exceeds_100` | REM-054: two Recorded assessments on one competency, both Mastered → mastered_count/total_students_assessed both 1, rate 100.0 (raw counting gave 2 ≠ 1) |
| `Phase5ServiceTest::test_remediation_frequency_never_exceeds_100` | REM-054: both Not_Mastered → remediation_frequency 100.0, not 200.0 |
| `Phase5ServiceTest::test_tiebreak_identical_created_at_higher_id_wins` | REM-047: four records, forced identical `created_at` → every derivation (aggregate summaries, analytics summary, derived view, class-level report) resolves to the higher id per pair |
| `Phase5ServiceTest::test_parity_derived_view_matches_stored_flags_before_drop` | REM-074: stored-snapshot parity proof — the derived view must equal the old stored-flag table's contents |
| `Phase5ServiceTest::test_not_competent_flag_view_latest_record_only` | REM-074: only the LATEST Not_Mastered row per pair surfaces; superseded rows do not |
| `Phase6EdgeCaseTest::test_competency_summary_unfiltered_aggregates_all_sections` | REM-026: one call across two sections yields both competencies |
| `Phase6EdgeCaseTest::test_competency_summary_section_filter_scopes` | REM-026: filtered call yields only that section's competency |

RED evidence (view absent / int-vs-array signature): 5 Phase5ServiceTest
failures (4 wrong counts, 1 missing-view error) + Phase6EdgeCaseTest heatmap
(50 ≠ 0.0) + unfiltered-summary (size 1 ≠ 2) + Phase5Test flag-test errors
(`v_not_competent_flags` undefined) + Phase6IntegrationTest view error;
`test_competency_summary_section_filter_scopes` passed unchanged (RED-half
excluded). GREEN after the migration + service/controller changes + signature
flips.

## WU-10 — REM-012 (BR-46 Pending-Grading deactivation guard is real)

Service-side scope: `app/Services/UserService.php` —
`blockingPendingGradingAssessments()` swapped from the hardcoded `return [];`
stub to the real query: distinct `assessment_id`s of every
`assessment_submissions` row in `pending_grading` status whose attempt's
assessment is owned by the teacher (`whereHas('attempt.assessment')` with
`assessments.teacher_id`). Verified FK chain (migrations): submission
`attempt_id` → `assessment_attempts` (RESTRICT, UNIQUE attempt_id) →
`assessment_attempts.assessment_id` → `assessments` (RESTRICT);
`assessments.teacher_id` → `users` (RESTRICT) is the ownership link used by the
grading services (BR-11) — `subject_sections` has NO teacher column, so the
task-suggested subject_section hop does not exist; the submission's own
denormalized `assessment_id` is set from the attempt's assessment at submit
time (AssessmentService::performSubmit), so both hops agree.

Decided (documented in the method docblock): an in-progress resubmission
attempt (REM-064) does NOT count as pending grading — a submission row exists
only after the student submits, so the join against `assessment_submissions`
naturally excludes unsubmitted attempts. The 409 envelope
(`PENDING_GRADING_BLOCKS_DEACTIVATION`, `blocking_assessments`) and the
`['success' => ..., 'blocking_assessments' => ...]` return contract were
already wired in `Admin/UserController::deactivate()` — no controller change.

### New tests (`tests/Feature/Phase3DeactivationGuardTest.php`, `#[Group('phase3-deactivation-guard')]`)

| Test | REM-012 requirement verified |
|---|---|
| `test_deactivation_teacher_with_pending_grading_returns_409` | teacher with a `pending_grading` submission → deactivate returns 409 `PENDING_GRADING_BLOCKS_DEACTIVATION`, `blocking_assessments` lists the assessment, user stays `is_active = true` |
| `test_deactivation_teacher_without_pending_grading_succeeds` | teacher with no pending grading (while ANOTHER teacher has some — ownership scoping) → 200, user `is_active = false` |
| `test_deactivation_teacher_with_scored_submission_not_blocked` | fully-scored attempt+submission → 200, deactivated |
| `test_deactivation_teacher_with_in_progress_resubmission_attempt_not_blocked` | REM-064 mid-state (scored original + `in_progress` resubmission attempt, no submission row) → 200, deactivated — pins the in-progress-resubmission decision |

RED evidence: 4 tests run against the stub — 3 passed (unaffected by the
guard), `test_deactivation_teacher_with_pending_grading_returns_409` failed
("Expected response status code [409] but received 200"); GREEN (4/4, 14
assertions) after the service change.

## WU-11 — REM-046 (MAX_FILE_SIZE wired + unified blank-row semantics) + REM-034 (stable Excel pin)

Service-side scope: `app/Services/BatchImportService.php` — the previously
unused `MAX_FILE_SIZE` (15 MB) const is now wired into the import path two ways:
(1) the HTTP gate already existed as `max:15360` KB in `ImportXlsxRequest` /
`ImportStudentsCsvRequest` (422 `VALIDATION_ERROR`; kept, matches repo
convention of literal `max:15360` in FormRequests); (2) a new
`assertWithinSizeLimit()` guard at the top of all three public import entry
points (`importStudentEnrollments`, `importCompetencyTags`,
`importStudentEnrollmentsCsv`) throws `BusinessRuleConflictException`
`FILE_TOO_LARGE` on direct service calls, mirroring AnnouncementService /
AssignmentService / LearningMaterialService. No row-count ceiling was added
(BR-47: cap is file size only, REM-046).

Decided blank-row semantics (documented in the service class docblock): a row
whose every cell is empty is skipped entirely — never counted in `total_rows`,
never reported as imported or failed — in BOTH formats. Evidence:
`parseCsv()` already filtered all-empty lines before counting; the XLSX paths
counted interior blank rows into `ImportJob.total_rows` (the RED run showed
`total_rows: 4` for 3 real rows) and the CSV loop additionally blank-skipped
rows whose first two cells were empty but that carried a stray cell in a later
column (e.g. `['', '', 'stray']`) — the exact XLSX/CSV divergence REM-046
flags. Now both formats use the same all-cells-empty predicate; the stray-cell
row fails with "Missing school_id." in both. `skipped_rows` (CSV response)
stays 0 — blank lines are filtered at parse time, never counted.

Composer: `maatwebsite/excel` `^4.0@dev` (locked `4.x-dev a9a16f1`) →
`^4.0` (locked `4.0.0`, stable, released 2026-08-13). `composer update
maatwebsite/excel --with-dependencies` also moved `symfony/console` v8.1.2 →
v8.1.4 (transitive). `composer validate` passes.

### Adjusted tests (`tests/Feature/BatchImportTest.php`)

1. `test_xlsx_import_rejects_file_over_15mb` /
   `test_csv_import_rejects_file_over_15mb`
   - **Reason: assertion extended (REM-046 verification "zero imports").**
     Both now additionally assert `ImportJob::count() === 0` / `ImportLog::count() === 0`
     after the 422 — the rejection must happen at the validation gate, before
     any import bookkeeping.
2. `test_csv_import_ignores_blank_lines`
   - **Reason: behavior unchanged (no edit).** Blank lines are filtered at
     parse time; the test pins `total_rows: 1`, `skipped_rows: 0` and still
     passes — kept as the CSV-side blank-row contract pin.
3. Existing xlsx count tests (`test_import_student_enrollments_partial_success`,
   `test_import_job_recorded_with_correct_counts`, etc.)
   - **Reason: unaffected (no edit).** No fixture contains blank rows, so
     `total_rows` is unchanged; verified green on the final run.

### New tests (`tests/Feature/BatchImportTest.php`, `#[Group('batch-import')]`)

| Test | REM-046 requirement verified |
|---|---|
| `test_oversized_upload_rejected_422` | 15361-KB fake .xlsx (byte size asserted `> MAX_FILE_SIZE`), upload → 422 `VALIDATION_ERROR`, zero `ImportJob`/`ImportLog` rows |
| `test_service_rejects_oversized_file_with_file_too_large` | direct `importStudentEnrollmentsCsv` call with a >15-MB file → `BusinessRuleConflictException` `FILE_TOO_LARGE`, zero import rows (const is wired at service level) |
| `test_xlsx_csv_row_semantics_consistent` | identical data (2 valid + 1 duplicate + 2 blank rows) via .xlsx and .csv → same imported count (2), same blank handling (`ImportJob.total_rows === ImportLog.total_rows === 3`); stray-cell row `['', '', 'stray']` fails "Missing school_id." in both formats |

RED evidence: `test_xlsx_csv_row_semantics_consistent` run against the
pre-change service failed — "Failed asserting that a row in the table
[import_jobs] matches ... total_rows: 3. Found similar results: [total_rows:
4]" (interior blank row counted by XLSX). GREEN after the service change.
The 422 oversize tests were already green pre-change (FormRequest gate
predates WU-11) — the RED evidence for the changed behavior is the
row-semantics test.

Baseline → final for the host file (`tests/Feature/BatchImportTest.php`):
66 tests / 398 assertions → 69 tests / 428 assertions, all green.
`AuthFlowTest` + `RoleAuthorizationTest` (83 tests, endpoints touched by the
import routes but no upload semantics) re-run green as a sanity check.

## WU-9 follow-up — RETIRED `NotCompetentFlag` model (REM-074, final gate)

### Adjusted tests

1. `Phase9RetentionAndPurgeTest::test_purge_preserves_unrelated_data`
   - **Reason: model retired / table dropped (REM-074).** The test created a
     `NotCompetentFlag` row and counted `NotCompetentFlag::class` in the
     purge-protected snapshot. The stored `not_competent_flags` table no longer
     exists (derived `v_not_competent_flags` view replaces it; model deleted).
     Removed the `use` import, the `NotCompetentFlag::create([...])` block, and
     the class from the `$counts` snapshot. BR-32 intent is preserved — the
     mastery record, AI explanation, materials, and assessments are still
     protected-model counted. (The view is empty here anyway: the seeded
     mastery record is `Mastered`, which never yields a flag row.)
2. `Phase9Test::test_purge_preserves_br32_protected_data`
   - **Reason: same as #1.** Removed the import, the `NotCompetentFlag::create`
     block, and the class from `$protectedModels`.

RED evidence: full-suite run failed both tests with
`Class "App\Models\NotCompetentFlag" not found`. GREEN: `--filter=Phase9` 68/68
(394 assertions) after the edits.

# Phase 4 test changes (R-11 log — appended per phase-03 recommendation #2)

## Phase 4 — Security Corrections (REM-009/013/015/016/017/018/019/033/042/045/049/050/055/069)

Phase 4 (WU-1..WU-10, plan `phase-04/execution-plan.md`) was executed test-first
(RED → GREEN) throughout: every new behavior was pinned by a new test BEFORE the
source change, and the full suite was re-run green after each WU. Only two
pre-Phase-4 tests needed adjusting (the rate-limit key assertions below); all
other Phase-4 verification is carried by the two new test classes. The shared
fixture got the SPA-CSRF helpers (`TestCase.php` `csrfBootstrap()`/`csrfHeaders()`,
REM-069) — additive, no existing test behavior changed.

### Adjusted tests (re-anchored login rate-limit keys)

1. `Phase9RetentionAndPurgeTest::test_validation_errors_do_not_consume_rate_limit_attempts`
   - **Reason: assertion updated (REM-018, SEC-DEC-006).** `AuthService::authenticate`
     now keys the login rate-limit counter on the CANONICALIZED identifier
     (`strtolower(trim($identifier))` inside the `sha1('ip|' . ...)` key), so
     case/spacing variants share one per-IP+account counter. The test's expected
     key was re-computed identically, preserving what it pins: validation errors
     consume no attempts and the 5th bad credential blocks.
2. `Phase9RetentionAndPurgeTest::test_successful_login_resets_rate_limit`
   - **Reason: same as #1.** The success-path counter-reset (`RateLimiter::attempts
     ($key) === 0` and the `4..0` remaining ladder) now reads the canonicalized key.

RED evidence: both failed against the pre-change service only in the case where
the test fixture itself used a non-canonical identifier (key mismatch); with
lowercase identifiers they were green pre-change — the canonicalization RED probe
lives in `Phase4SecurityTest::test_rate_limit_key_is_canonicalized`, which failed
("Failed asserting that two strings are equal" on the key) until the AuthService
change landed.

### New tests — `Phase4SecurityTest` (`tests/Feature/Phase4SecurityTest.php`, `#[Group('phase4-security')]`, 18 tests)

| Group | Tests | REM-IDs verified |
|---|---|---|
| Prod-gating ×3 | `test_test_routes_registered_under_testing_environment`, `test_production_route_list_excludes_test_routes`, `test_testing_route_list_includes_test_routes` | REM-009 (SEC-DEC-017): `/api/test/*` registered in testing/dev, ZERO in the production route table |
| CSRF ×5 | `test_csrf_cookie_endpoint_reachable`, `test_state_changing_request_without_token_returns_419`, `test_admin_state_changing_request_without_token_returns_419`, `test_state_changing_request_with_valid_token_succeeds`, `test_test_routes_remain_csrf_exempt` | REM-069: exemptions narrowed to `api/test/*` + `sanctum/csrf-cookie`; state-changing call without a valid `X-XSRF-TOKEN` → 419 (force-enforced CSRF via container-bound middleware subclass, `runningUnitTests()` overridden) |
| Canonicalization ×2 | `test_rate_limit_key_is_canonicalized`, `test_success_resets_rate_limit_after_canonicalized_failures` | REM-018: case/spacing variants share one counter → 429 `RATE_LIMIT_EXCEEDED` at 5; success clears it |
| Timing parity ×1 | `test_login_timing_parity_user_miss_vs_hit` | REM-019 (SEC-DEC-016): user-miss vs user-hit response time within tolerance (BCRYPT_ROUNDS=4 in phpunit.xml; warm-up + measured phases) |
| Reset revocation ×2 | `test_password_reset_revokes_target_users_other_sessions`, `test_revoked_session_request_returns_401` | REM-016: reset deletes the target's `sessions` rows (admin's own row untouched); the revoked session's next request → 401 |
| Mass assignment ×1 | `test_mass_assignment_cannot_set_sensitive_user_fields` | REM-033: mass-assign via `create()`/`fill()` cannot set `role`/`is_active`/`must_change_password`/`password_hash` (file comment: every assertion below fails RED pre-REM-033) |
| AI-status gate exemption ×1 | `test_ai_status_exempt_from_forced_password_gate` | REM-017: must-change-password user reaches `/api/ai/status` 200 while gated endpoints stay 403 `PASSWORD_CHANGE_REQUIRED` |
| Throttles ×3 | `test_ai_chat_explain_further_is_throttled_per_ip`, `test_import_upload_is_throttled_per_ip`, `test_throttles_do_not_affect_login_contract` | REM-049: 31st explain-further call / 11th import upload → 429 `RATE_LIMIT_EXCEEDED` with `X-RateLimit-*` headers; login limiter (BR-64) independent |

### New tests — `Phase4OwnershipTest` (`tests/Feature/Phase4OwnershipTest.php`, `#[Group('phase4-ownership')]`, 14 tests)

| Test | REM-055 requirement verified |
|---|---|
| `test_101_teacher_can_view_student_from_own_section_returns_200` | regression: own-section read still 200 |
| `test_101_teacher_cannot_view_student_from_other_section_returns_403` | #101 cross-section read blocked |
| `test_101_teacher_with_foreign_subject_section_filter_returns_403` | #101 filter on a section the teacher does not own blocked |
| `test_101_unassigned_teacher_returns_403` | teacher with zero assignments blocked |
| `test_102_teacher_can_view_student_from_own_section_returns_200` | regression: own-section summary still 200 |
| `test_102_teacher_cannot_view_student_from_other_section_returns_403` | #102 cross-section read blocked |
| `test_102_teacher_from_other_section_cannot_view_student_summary_returns_403` | #102 foreign-section variant blocked |
| `test_41_teacher_creates_assignment_for_foreign_subject_section_returns_409` | #41 create for foreign section → 409 `NOT_ASSIGNED_TO_SECTION` (was untested) |
| `test_55_teacher_creates_assessment_for_foreign_subject_section_returns_409` | #55 same guard (was untested) |
| `test_84_teacher_stores_material_for_foreign_subject_section_returns_403` | #84 foreign-section material store → 403 (was untested) |
| `test_73_teacher_not_assigned_to_subject_section_returns_403` | #73 foreign-section filter → 403 (was untested) |
| `test_74_teacher_not_assigned_to_subject_section_returns_403` | #74 same (was untested) |
| `test_78_teacher_not_assigned_to_subject_section_returns_403` | #78 gap-report teacher-not-assigned case (was untested) |
| `test_79_teacher_not_assigned_to_subject_section_returns_403` | #79 trends same (was untested) |

RED evidence: the 5 × #101/#102 cross-section tests failed against the
pre-guard `GradingService` ("Expected response status code [403] but received
200") — the GAP the WU-3 audit found. GREEN (14/14) after
`ensureCanViewStudentMastery()` landed; audit sheet `docs/ownership-audit-sheet.md`
§11 records the WU-3 checkpoint suite at 1119/1119 (4664 assertions).

Suite progression across Phase 4 checkpoints: 1108 → 1128 → 1129 → 1132
(final full-suite gate: 1132 tests / 4830 assertions, all green).

# Phase 5 test changes (R-11 log)

## Phase 5 — API Alignment (REM-003/036/041/059/060/061)

WU-1 (API Alignment, list-shape unification): new `app/Support/Pagination.php`
helper (`perPage()` clamp, `meta()`, `response()`). 10 raw-paginator endpoints
converted to `{data, meta}` via `Pagination::response` (map-then-wrap keeps the
existing `->through` transforms), 6 lean endpoints standardized onto the same
helper, and the audit-log `per_page` 422-on-range rules removed so `per_page`
clamps (REM-036: `>100 → 100`, `<1`/non-numeric → default 15) instead of 422ing.

### Adjusted tests

1. `Phase7LearningMaterialsGapsTest::test_83_get_learning_materials_clamps_per_page_lower_bound`
   - **Reason: assertion superseded (REM-036).** `per_page=0 → meta.per_page 1`
     becomes `per_page=0 → meta.per_page 15`. The endpoint's old inline clamp
     (`max(1, ...)` → 1) is replaced by the shared clamp whose out-of-range rule
     is "→ default (15)". The `999999 → 100` upper-bound test is unchanged.
2. `Phase7ModerationLogGapsTest::test_90_moderation_log_list_clamps_per_page_lower_bound`
   - **Reason: same as #1.** `per_page=0 → 1` becomes `per_page=0 → 15`; the
     inline `max(1, ...)` clamp in `AIController::moderationLog` was replaced by
     `Pagination::perPage()`.
3. `Phase8AuditLogTest::test_96_page_and_per_page_out_of_range_return_422`
   - **Reason: behavior corrected (REM-036).** Renamed
     `test_96_page_out_of_range_returns_422_and_per_page_is_clamped`. `page=0`
     still 422s (the `page min:1` rule is untouched); `per_page=0 → 422` becomes
     `per_page=0 → 200, meta.per_page 15` and `per_page=101 → 422` becomes
     `per_page=101 → 200, meta.per_page 100` (`min:1/max:100` rules removed from
     `AuditLogQueryRequest`, controller clamps via `Pagination::perPage`).
4. `Phase8AuditReadMatrixTest::test_96_per_page_max_allowed_ok_and_over_limit_422`
   - **Reason: same as #3.** Renamed
     `test_96_per_page_max_allowed_ok_and_over_limit_clamped`; `per_page=101 →
     422 VALIDATION_ERROR` becomes `per_page=101 → 200, meta.per_page 100` (and
     still returns all 3 rows). The `per_page=100 OK` asserts stay.
5. `Phase8AuditReadMatrixTest::test_97_pagination_pages_meta_and_out_of_range_422`
   - **Reason: same as #3.** `per_page=101 → 422` becomes `per_page=101 → 200,
     meta.per_page 100`; `page=0 → 422` stays (page rule untouched).
6. `Phase8AuditReadMatrixTest::test_98_pagination_pages_meta_and_out_of_range_422`
   - **Reason: same as #3.** Same `per_page=101 → clamp 100` re-anchor on #98.

### Verified green unchanged (no edit needed)

- `Phase3Test::test_teacher_can_list_assessments` (:585-586) — already asserts
  `data.0.title` + `meta.current_page`; the helper emits the same 6 meta keys.
- `Phase7LearningMaterialsTest::test_83_get_learning_materials_returns_pagination_meta_structure`
  (:334-338) — asserts the exact 6-key `meta` structure the helper produces.
- `Phase8AuditLogTest::test_96_empty_table_returns_empty_data_and_zero_meta`
  (:240-254) and `test_96_pagination_per_page_and_page` (:429-455) — already
  assert `{data, meta}` keys; unchanged.
- `Phase8AuditReadMatrixTest::test_97_and_98_meta_keys_and_item_shape_match_96`
  (:473-505) — asserts `array_keys(meta)` order; `Pagination::meta` emits the
  same key order (`current_page, from, last_page, per_page, to, total`).

### New tests — `Phase5ContractSweepTest` (`tests/Feature/Phase5ContractSweepTest.php`, `#[Group('phase5')]`, 2 tests)

| Test | REM requirement verified |
|---|---|
| `testAll16ListEndpointsReturnOnlyDataAndMeta` | REM-059/REM-036: all 16 list endpoints (admin #5/#12/#14/#16/#18/#20/#96/#97/#98, teacher #34/#40/#46/#53/#62/#83/#90) return 200 with top-level keys EXACTLY `data` + `meta`; `meta` keys exactly the 6 helper keys; no raw-paginator key (`next_page_url`/`first_page_url`/`last_page_url`/`prev_page_url`/`path`/`links`/top-level `current_page`/`per_page`/`total`/`from`/`to`) leaks |
| `testPerPageClampsOnRepresentativeEndpoints` | REM-036: on one endpoint per role surface (admin users #5, teacher assessments #53, teacher learning-materials #83): `per_page=1000000 → 100`, `per_page=0`/`-3`/`abc` → 15 (default) |

RED evidence: written against the decided contract, then run with one endpoint
temporarily reverted to its pre-change raw-paginator response
(`Admin\UserController::index` returning the serialized paginator with the
unclamped `per_page`) — `testAll16ListEndpointsReturnOnlyDataAndMeta` failed
on the `#5 admin users` entry (`array_keys` mismatch: raw paginator keys
present) and `testPerPageClampsOnRepresentativeEndpoints` failed on the same
endpoint (`meta.per_page` = 1000000, not 100). GREEN after restoring the
converted controller (2 tests / 281 assertions).

Auth note: the sweep test performs GET-only requests, so the SPA-CSRF relay
helpers (`csrfBootstrap()`/`csrfHeaders()`/`withSessionCookie()`/
`statefulPost()`) are not exercised — role auth uses `Sanctum::actingAs`
exactly as the existing GET-heavy feature tests (Phase5Test, Phase7*,
Phase8Audit*) do.

### WU-2 — REM-060 (route deviant error paths through the canonical renderer)

Every deviant path now renders through the canonical `{error:{message, code,
fields?}}` envelope (bootstrap/app.php) while the 401/403/429 login contract
stays untouched (`AuthController::login` renders those directly and was not
modified). Changes: (1) the HttpException-branch codeMap adds
`419 => CSRF_TOKEN_MISMATCH` and renames `429 => TOO_MANY_REQUESTS` →
`RATE_LIMIT_EXCEEDED`, passes `$e->getHeaders()` into the rendered response
so throttled 429s keep `X-RateLimit-*`, and uses the exception's own message
for 400s only (default codeMap message everywhere else); (2)
`AnalyticsController` 400s → `HttpException(400, '<same message>')` and the
hand-rolled 422 → `ValidationException::withMessages(['group_by' => ...])`
(renders `fields`); `error()` helper deleted (zero call sites); (3)
`AIController` 400 → `HttpException(400, ...)`, `aiStatus` unavailable →
`AIServiceException(AIService::AI_UNAVAILABLE_MESSAGE, AI_SERVICE_UNAVAILABLE,
503)`; `error()` helper deleted (zero call sites); (4) `UserService::
deactivateAccount` blocked path throws `BusinessRuleConflictException(
PENDING_GRADING_BLOCKS_DEACTIVATION)` instead of returning the blocked result
array, and `Admin/UserController::deactivate` drops the hand-rolled 409 branch
and `blocking_assessments` from the success payload (single caller, verified).

### Adjusted tests

1. `Phase4SecurityTest::test_state_changing_request_without_token_returns_419`
   - **Reason: assertion strengthened (REM-060).** Added
     `assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH')` — 419 now maps in
     the HttpException codeMap (previously fell through to the INTERNAL_ERROR
     fallback).
2. `Phase4SecurityTest::test_admin_state_changing_request_without_token_returns_419`
   - **Reason: same as #1.**
3. `Phase4SecurityTest::test_ai_chat_explain_further_is_throttled_per_ip`
   - **Reason: assertion re-anchored + strengthened (REM-060).** The renderer
     now passes the throttle exception's transport headers through, so the 31st
     request asserts `RATE_LIMIT_EXCEEDED` + `X-RateLimit-Limit` 30 /
     `X-RateLimit-Remaining` 0 / `X-RateLimit-Reset` present ON the 429
     response itself. Removed the obsolete request-30 header assertions and the
     "429 code is P5-scope" comment; docblock rewritten.
4. `Phase4SecurityTest::test_import_upload_is_throttled_per_ip`
   - **Reason: same as #3** (limit 10, 11th request).
5. `Phase6AccessControlTest::test_78_returns_422_when_group_by_invalid`
   - **Reason: assertion strengthened (REM-060).** gap-report's hand-rolled 422
     is now a real `ValidationException`, so the envelope carries
     `error.fields.group_by`; the test asserts it.
6. `Phase3DeactivationGuardTest::test_deactivation_teacher_with_pending_grading_returns_409`
   - **Reason: assertion re-anchored (REM-060).** The 409 renders via
     `BusinessRuleConflictException` (canonical `{error:{message, code}}`
     envelope, no `blocking_assessments` payload — that key was only carried by
     the deleted controller branch). `assertContains(..., error.blocking_
     assessments)` → `assertJsonMissingPath('error.blocking_assessments')`.
7. `Phase7AIServiceTest::test_95_ai_status_endpoint_returns_503_when_unavailable`
   - **Reason: assertion re-anchored (REM-060).** aiStatus unavailable now
     throws `AIServiceException` 503 `AI_SERVICE_UNAVAILABLE`, rendered as
     `{error:{message, code}}`; was `data.status = "unavailable"` +
     `data.checked_at`.

Verified green unchanged (no edit): `Phase7Test::test_95_ai_status_returns_
200_when_available` and `Phase7AIServiceTest::test_95_ai_status_endpoint_role_
matrix_returns_available` / `test_ai_status_exempt_from_forced_password_gate`
(200/available keeps the `data` shape); the login-contract suites
(`AuthFlowTest`, `Phase9Test`, `Phase9RetentionAndPurgeTest` — 401/403/429
rendered by the untouched `AuthController`); `TestRouteTest`;
`Phase6EdgeCaseTest::test_error_envelope_structure_for_all_endpoints`; every
other 400 `BAD_REQUEST` pin (messages preserved through the renderer);
`Phase7AIServiceTest:1516`/`:1668` + `Phase7Test:870` 503s (already canonical).

RED evidence (7): the two 419s failed with code `INTERNAL_ERROR` ≠
`CSRF_TOKEN_MISMATCH`; both throttles failed with `TOO_MANY_REQUESTS` ≠
`RATE_LIMIT_EXCEEDED`; `test_78` errored (`error.fields` was null — the old
hand-rolled 422 has no `fields` key); the deactivation test failed
(`assertJsonMissingPath` — `blocking_assessments` still present); the aiStatus
503 failed (`error.code` null — `data.status` envelope still in place). GREEN
after the bootstrap/controller/service changes: targeted filter 145/145
(574 assertions), login contract 85/85 (469 assertions), full suite 1134/1134
(5117 assertions).

Question resolved: the aiStatus 503 message uses `AIService::
AI_UNAVAILABLE_MESSAGE` (public const, single source of truth for AI-outage
wording) instead of the orchestrator's literal "AI service is temporarily
unavailable." text — the WU brief says "reuse it if publicly accessible".

### WU-3 — REM-061 (every teacher-not-assigned path → 403 SUBJECT_SECTION_NOT_ASSIGNED)

Orchestrator decision (spec §16: "teacher endpoints additionally 403
`SUBJECT_SECTION_NOT_ASSIGNED`"): `BusinessRuleConflictException` gains an
optional status override (default 409, so all ~89 existing throw sites are
untouched) and the bootstrap render branch uses `$e->getStatusCode()`. All 8
listed teacher-not-assigned sites converted to
`BusinessRuleConflictException('<message>', 'SUBJECT_SECTION_NOT_ASSIGNED', 403)`:
`CompetencyMappingController::classLevelReport` (hand-rolled
`{error:{...NOT_ASSIGNED_TO_SECTION}} 409` → throw; message unchanged),
`CompetencyMappingController::ensureSubjectSectionBelongsToTeacher`,
`AnnouncementService`/`AssessmentService`/`AssignmentService::
ensureSubjectSectionBelongsToTeacher` (code change only),
`AIController::ensureSubjectSectionBelongsToTeacher` and
`LearningMaterialService::ensureSubjectSectionBelongsToTeacher` (both were
`AuthorizationException` → FORBIDDEN), `AnalyticsService::
ensureTeacherOwnsSubjectSection`.

### Adjusted tests (re-anchored / strengthened, REM-061)

1. `Phase3Test::test_teacher_cannot_create_announcement_for_unassigned_section`
   - **Old:** 409 + `error.code NOT_ASSIGNED_TO_SECTION` → **New:** 403 +
     `SUBJECT_SECTION_NOT_ASSIGNED`. **Reason:** announcement create now throws
     the converted business-rule exception (403 override).
2. `Phase4OwnershipTest::test_41_teacher_creates_assignment_for_foreign_subject_section_returns_409`
   - **Old:** 409 + `NOT_ASSIGNED_TO_SECTION` → **New:** 403 +
     `SUBJECT_SECTION_NOT_ASSIGNED` (renamed `..._returns_403`). **Reason:**
     `AssignmentService::ensureSubjectSectionBelongsToTeacher` converted
     (REM-061).
3. `Phase4OwnershipTest::test_55_teacher_creates_assessment_for_foreign_subject_section_returns_409`
   - **Old:** 409 + `NOT_ASSIGNED_TO_SECTION` → **New:** 403 +
     `SUBJECT_SECTION_NOT_ASSIGNED` (renamed `..._returns_403`). **Reason:**
     `AssessmentService::ensureSubjectSectionBelongsToTeacher` converted
     (REM-061).
4. `Phase4OwnershipTest::test_84_teacher_stores_material_for_foreign_subject_section_returns_403`
   - **Old:** 403 + `FORBIDDEN` → **New:** 403 + `SUBJECT_SECTION_NOT_ASSIGNED`.
     **Reason:** both `AIController::ensureSubjectSectionBelongsToTeacher` and
     `LearningMaterialService::ensureSubjectSectionBelongsToTeacher` converted
     (REM-061).
5. `Phase4OwnershipTest::test_73_teacher_not_assigned_to_subject_section_returns_403`
   - **Old:** 403 + `FORBIDDEN` → **New:** 403 + `SUBJECT_SECTION_NOT_ASSIGNED`.
     **Reason:** not-competent-flags scoping goes through the converted
     `CompetencyMappingController::ensureSubjectSectionBelongsToTeacher`
     (REM-061).
6. `Phase4OwnershipTest::test_74_teacher_not_assigned_to_subject_section_returns_403`
   - **Old:** 403 + `FORBIDDEN` → **New:** 403 + `SUBJECT_SECTION_NOT_ASSIGNED`.
     **Reason:** competency-summary scoping, same converted helper (REM-061).
7. `Phase4OwnershipTest::test_78_teacher_not_assigned_to_subject_section_returns_403`
   - **Old:** 403 + `FORBIDDEN` → **New:** 403 + `SUBJECT_SECTION_NOT_ASSIGNED`.
     **Reason:** gap-report goes through the converted
     `AnalyticsService::ensureTeacherOwnsSubjectSection` (REM-061).
8. `Phase4OwnershipTest::test_79_teacher_not_assigned_to_subject_section_returns_403`
   - **Old:** 403 + `FORBIDDEN` → **New:** 403 + `SUBJECT_SECTION_NOT_ASSIGNED`.
     **Reason:** trends, same converted helper (REM-061). (Class docblock
     reference to the old #75 409 code updated too.)
9. `Phase5Test::test_75_teacher_not_assigned_to_section_returns_409`
   - **Old:** 409, status-only → **New:** renamed
     `test_75_teacher_not_assigned_to_section_returns_403`, asserts 403 +
     `error.code SUBJECT_SECTION_NOT_ASSIGNED`. **Reason:** class-level-report
     hand-rolled 409 response replaced by the exception throw (REM-061).
10. `Phase6AccessControlTest::test_77_returns_403_when_teacher_not_assigned`
    - **Old:** status-403 only, code `FORBIDDEN` → **New:** asserts
      `SUBJECT_SECTION_NOT_ASSIGNED`. **Reason:** heatmap ownership check is the
      converted `AnalyticsService::ensureTeacherOwnsSubjectSection` (REM-061).
11. `Phase6AccessControlTest::test_80_returns_403_when_teacher_not_assigned`
    - **Old:** status-403 only, code `FORBIDDEN` → **New:** asserts
      `SUBJECT_SECTION_NOT_ASSIGNED`. **Reason:** student-drill-down ownership
      check is the same converted helper (REM-061).
12. `Phase7LearningMaterialsTest::test_83_get_learning_materials_returns_403_for_unassigned_teacher`
    - **Old:** 403 + `FORBIDDEN` → **New:** 403 + `SUBJECT_SECTION_NOT_ASSIGNED`.
      **Reason:** learning-materials index scoping is the converted
      `AIController::ensureSubjectSectionBelongsToTeacher` (REM-061).

Stays FORBIDDEN (verified semantics, not converted): `AnalyticsService::
ensureStudentEnrolledInSection` (student-enrollment check — `Phase6Access
ControlTest::test_80_returns_403_when_student_not_enrolled_in_section`
unchanged), `AnalyticsService::getAdminSchoolWideOverview` `isAdmin` guard
(generic role-mismatch), `GradingController` student self-scope guards
(BR-30), `GradingService::ensureCanViewStudentMastery` (mixed role/enrollment
scoping), and `AIService` moderation ownership guards (`getModerationLog` /
`ensureExplanationAccessibleToTeacher` / `findExplanationForTeacher`) —
**out of WU-3 scope (AIService.php not in the permitted file list)**, so
moderation-log #90 stays `FORBIDDEN`; reported as an open item.

### New tests — `tests/Feature/Phase5NotAssignedContractTest.php` (`#[Group('phase5')]`, 13 tests)

A teacher owning only subject-section A hitting section B's endpoints gets
403 + `error.code === 'SUBJECT_SECTION_NOT_ASSIGNED'` (asserted via a shared
`assertNotAssigned()` helper): #35 announcement create, #41 assignment
create, #55 assessment create, #84 learning-material create/upload, #83
learning-material index (AI teacher action §3.9), #77 heatmap, #78 gap-report,
#79 trends, #80 student-drill-down, #75 class-level-report, #73
not-competent-flags, #74 competency-summary, plus a control test that the
assigned section still returns 200/201 on the same endpoints.

RED evidence (24 failed / 123 in the targeted filter): every re-anchored and
new test failed against the pre-change code — 409 ≠ 403 on the old
`NOT_ASSIGNED_TO_SECTION` sites, `FORBIDDEN` ≠ `SUBJECT_SECTION_NOT_ASSIGNED`
on the converted `AuthorizationException` sites. GREEN after the code pass:
targeted 207/207 (654 assertions), full suite 1147/1147 (5146 assertions),
phpcs 0 errors. Frontend grep (`NOT_ASSIGNED_TO_SECTION` /
`SUBJECT_SECTION_NOT_ASSIGNED` in `frontend/src`): no references — nothing to
report.

### WU-4 — REM-003 (INVALID_FILE_TYPE → 422-only; login payload + 401 wording)

REM-003 (API Interface Spec §16, BASELINE v1.2): (a) `INVALID_FILE_TYPE` is a
**422-only** error code — the duplicate 409 is dropped; (b) the login success
payload and 401 message must match the §16 contract examples exactly
(`data.{id,name,email,school_id,role,must_change_password}`, no `user`
nesting, no `message` key; 401 message `'Invalid credentials.'`).

Code changes (the `BusinessRuleConflictException` 3rd `?int $statusCode` arg
and its `bootstrap/app.php` renderer landed in WU-3 and were NOT touched):

- `AnnouncementService.php`, `AssessmentService.php`, `AssignmentService.php`,
  `LearningMaterialService.php` — each `assertAllowedFileType()` guard now
  throws `BusinessRuleConflictException(message, 'INVALID_FILE_TYPE', 422)`
  (was default 409). Messages kept verbatim. Sibling codes `FILE_TOO_LARGE` /
  `TOO_MANY_ATTACHMENTS` remain 409 — not touched.
- `AuthController.php::login` — success payload flattened
  (`data.user.{...}` + `data.message` → `data.{id,name,email,school_id,role,
  must_change_password}`); X-RateLimit-* headers on success unchanged; 401
  message `'The provided credentials are incorrect.'` → `'Invalid
  credentials.'` (code `INVALID_CREDENTIALS`, status 401 unchanged). 403
  `ACCOUNT_DEACTIVATED` and 429 `RATE_LIMIT_EXCEEDED` messages/codes/statuses/
  headers unchanged (SEC-DEC-016 / REM-060).

#### Adjusted tests — file-type guard (service-level 409 → 422)

`assertBusinessRuleConflict()` helper (in `Phase9Test` and
`Phase9RetentionAndPurgeTest`) gained an optional `int $status = 409` param and
now also asserts `$e->getStatusCode()` — every `INVALID_FILE_TYPE` call site
passes `422`; `FILE_TOO_LARGE` / `TOO_MANY_ATTACHMENTS` call sites keep the
default and are now pinned at 409 (strengthened, behavior unchanged).

1. `Phase9Test::test_announcement_txt_attachment_rejected_409` →
   `test_announcement_txt_attachment_rejected_422`
   - **Reason: status corrected (REM-003).** Direct `AnnouncementService`
     call with `.txt` now asserts `INVALID_FILE_TYPE` **and**
     `getStatusCode() === 422` (was code-only, 409 implied). Method renamed to
     match the asserted status.
2. `Phase9Test::test_assignment_teacher_attachment_rejected_409` →
   `test_assignment_teacher_attachment_rejected_422` — **Reason: same as #1**
   (`AssignmentService::createAssignment`, `.exe`).
3. `Phase9Test::test_assignment_student_submission_txt_rejected_409` →
   `test_assignment_student_submission_txt_rejected_422` — **Reason: same as
   #1** (`AssignmentService::submitAssignment`, `.txt`).
4. `Phase9Test::test_assessment_item_txt_attachment_rejected_409` →
   `test_assessment_item_txt_attachment_rejected_422` — **Reason: same as #1**
   (`AssessmentService::addItem`, `.txt`).
5. `Phase9Test::test_learning_material_pptx_rejected_409` →
   `test_learning_material_pptx_rejected_422` — **Reason: same as #1**
   (`LearningMaterialService::storeLearningMaterial`, `.pptx`).
6. `Phase9Test::test_renamed_file_with_allowed_extension_rejected_409` →
   `test_renamed_file_with_allowed_extension_rejected_422` — **Reason: same
   as #1**; both MIME-mismatch asserts (announcement + learning material) now
   pin 422.
7. `Phase9RetentionAndPurgeTest::test_announcement_rejects_disallowed_extension`
   — **Reason: status corrected (REM-003).** Same assert shape as #1; now pins
   `INVALID_FILE_TYPE` + 422.
8. `Phase9RetentionAndPurgeTest::test_student_submission_rejects_disallowed_extension`
   — **Reason: same as #7** (`submitAssignment`, `.txt`).
9. `Phase9RetentionAndPurgeTest::test_learning_material_rejects_disallowed_extension`
   — **Reason: same as #7** (`storeLearningMaterial`, `.txt`).
10. `Phase9RetentionAndPurgeTest::test_assessment_item_rejects_disallowed_extension`
    — **Reason: same as #7** (`addItem`, `.txt`).
11. `Phase9RetentionAndPurgeTest::test_renamed_extension_files_are_rejected`
    — **Reason: same as #7**; both MIME-mismatch asserts now pin 422.

Untouched (verified green, no edit): the HTTP FormRequest-level tests that
already return 422 `VALIDATION_ERROR` for the same input — `Phase9Test::
test_announcement_txt_attachment_rejected_422_at_http` /
`test_assignment_teacher_attachment_rejected_422_at_http`,
`Phase9RetentionAndPurgeTest::test_assignment_rejects_disallowed_extension_at_http`,
`Phase7LearningMaterialsGapsTest::test_86_put_learning_material_rejects_invalid_file_type_and_leaves_row_unchanged`.
The service-level guard is the second line; both layers now agree on 422.
`Phase8AuditLogTest::test_announcement_update_too_many_attachments_is_atomic`
(409 `TOO_MANY_ATTACHMENTS`) and `test_announcement_update_oversized_file_is_atomic`
(`FILE_TOO_LARGE`) unchanged — sibling codes stay 409.

#### Adjusted tests — login payload flattening + 401 wording

12. `AuthFlowTest::test_admin_can_login` — `data.user.school_id` /
    `data.user.role` / `data.user.must_change_password` → `data.school_id` /
    `data.role` / `data.must_change_password`; `data.message 'Authenticated.'`
    → `data.id` + `assertJsonMissingPath('data.user')` /
    `assertJsonMissingPath('data.message')`.
    - **Reason: payload flattened (REM-003 §16).** Assertions re-anchored to
      the contract example shape; negative asserts pin that the `user`
      nesting and `message` key are gone.
13. `AuthFlowTest::test_student_can_login_with_school_id` —
    `data.user.role` → `data.role`. **Reason: same as #12.**
14. `AuthFlowTest::test_login_fails_on_invalid_credentials` — strengthened:
    added `assertJsonPath('error.message', 'Invalid credentials.')` (code
    `INVALID_CREDENTIALS` / 401 already asserted).
    - **Reason: 401 wording aligned (REM-003).** No existing test pinned the
      old message; the new wording is now pinned so it cannot regress.
15. `AuthFlowTest::test_successful_login_resets_rate_limit_counter` —
    `data.message 'Authenticated.'` → `data.role 'Admin'` (user under test is
    Admin). **Reason: same as #12**; rate-limit reset/headers asserts stay.
16. `AuthFlowTest::test_teacher_can_login` — same flattening as #12 (`data.id`
    = teacher id, negative `data.user`/`data.message` asserts added).
    **Reason: same as #12.**
17. `Phase4SecurityTest::test_success_resets_rate_limit_after_canonicalized_failures`
    — `data.message 'Authenticated.'` → `data.role 'Admin'`.
    **Reason: same as #12**; canonicalization reset asserts stay.
18. `Phase9RetentionAndPurgeTest::test_successful_login_resets_rate_limit` —
    `data.user.role` → `data.role`. **Reason: same as #12.**

Verified green unchanged (no edit): the login CONTRACT tests —
`AuthFlowTest` 401/403/429/rate-limit/success-reset tests, `Phase9Test`
login-rate-limit section (:706-837), `Phase9RetentionAndPurgeTest` login
section (:613-735), `Phase4SecurityTest` canonicalization tests,
`TestRouteTest` (uses `/api/test/login`'s own controller, `user.role`
unaffected by `AuthController` changes).

Frontend grep (`frontend/src` for `data.user` / `Authenticated.` /
`The provided credentials`): zero references — no consumers to update
(orchestrator's expectation re-confirmed).

RED evidence (18 failed / 103 in the targeted filter): 7 login re-anchors
(flattened paths null, message wording mismatch) + 11 file-type status
asserts (`409` ≠ `422`). GREEN after the code pass: targeted 103/103
(673 assertions), upload-adjacent filter 183/183 (966 assertions), full suite
1147/1147 (5171 assertions), phpcs 0 errors, pint 0 diffs.

### WU-5 — REM-041 (app timezone Asia/Manila; sweep + flip in ONE commit, R-9)

`config/app.php` `'timezone' => 'UTC'` → `'timezone' =>
env('APP_TIMEZONE', 'Asia/Manila')` (comment block updated to state the
Asia/Manila default); `phpunit.xml` pins `<env name="APP_TIMEZONE"
value="Asia/Manila"/>` (deterministic test env = the plan's rollback lever);
`.env.production.example` gains a REM-041 comment documenting the server-side
`php.ini` `date.timezone=Asia/Manila` requirement (no php.ini exists in-repo —
deployment setting; phase-06 recommendations doc covers the runbook side).
The full sweep and the flip were written in the same pass with no test run or
artifact generation in between (R-9).

#### Sweep inventory — every timezone construct in `backend/tests/`, classified

**RE-ANCHORED (UTC-absolute `Z`-suffixed deadline fixtures → naive app-tz
strings).** Intent per fixture: "end-of-day deadline a teacher sets in
school-local time". Under UTC those instants displayed as `T23:59:00Z` =
07:59+08 the NEXT school day (a mid-morning deadline in Manila); after the
flip the same string would keep that wrong wall-clock. Naive strings parse in
the app timezone, so the fixture now means 23:59 school-local exactly as the
original author intended. No test asserts the stored instant's value, so the
8-hour instant shift is behavior-preserving for every one of these (verified
green below):

1. `Phase3Test` — 12 sites (`test_teacher_can_create_assignment`,
   `test_teacher_can_list_assignments_with_submission_count`,
   `test_teacher_can_update_assignment_metadata`,
   `test_teacher_can_delete_draft_assignment`,
   `test_teacher_delete_with_submissions_requires_confirmation`,
   `test_teacher_confirm_delete_hard_deletes`,
   `test_teacher_can_list_submissions`, `test_teacher_can_provide_feedback`,
   `test_student_can_list_assignments`, `test_student_can_submit_assignment`,
   `test_student_submission_blocked_if_already_submitted`,
   `test_student_not_enrolled_cannot_submit`):
   `'2026-10-15T23:59:00Z'` → `'2026-10-15 23:59:00'`
2. `Phase3NegativePathTest` — 2 sites (teacher-404 assignment tests):
   `'2026-10-15T23:59:00Z'` → `'2026-10-15 23:59:00'`
3. `Phase4OwnershipTest::test_41_teacher_creates_assignment_for_foreign_subject_section_returns_403`:
   `'2026-10-15T23:59:00Z'` → `'2026-10-15 23:59:00'`
4. `Phase5NotAssignedContractTest::test_41_assignment_create_for_unassigned_section_returns_403`:
   `'2026-10-15T23:59:00Z'` → `'2026-10-15 23:59:00'`
5. `Phase8AuditInstrumentationUserTest` — `private const DUE_DATE`
   (used by `createAssignmentViaApi`,
   `test_teacher_create_assignment_writes_create_row_with_title_metadata`):
   `'2026-10-15T23:59:00Z'` → `'2026-10-15 23:59:00'`
6. `Phase3EdgeCaseTest::createAssignment` default:
   `'2026-12-31T23:59:00Z'` → `'2026-12-31 23:59:00'`
   (default future deadline for the "too many files / file too large" helpers)
7. `Phase3EdgeCaseTest::test_late_submission_marks_is_late_and_status_late`:
   `'2020-01-01T00:00:00Z'` → `'2020-01-01 00:00:00'` (past-deadline intent,
   still far past either way)

**SAFE — relative math / internally consistent (no edit).** All
`Carbon::setTestNow` sites (18 call sites across 6 classes) compute rows and
boundaries with `Carbon::create` / `now()->subDays|subHours|addSeconds`
relative math in the app timezone, so the whole test shifts uniformly under
UTC+8: `AuthFlowTest:288,298` (rate-limit decay, addSeconds),
`AuditLogServiceTest:134,160,165,182,230,253,274,295` (retention/anonymize,
subDays), `Phase8AuditLogTest:356,397,403,425,666,699,705,730` (from/to
date-window semantics — fixture instants and query-string day boundaries are
BOTH app-tz, so "end-of-day inclusive" behaves identically),
`Phase8AuditReadMatrixTest:256,301,376,410` (same, incl. the "late-evening
18:00 boundary row"), `Phase9RetentionAndPurgeTest:63,226,246,259,276` and
`Phase9Test:78,602,618,630,643,666,680,832` (purge/anonymize, subDays).
SAFE also: BR-13 on-time/late relative tests (`Phase3Test:473` is_late=false
vs a far-future deadline; `Phase3EdgeCaseTest:175` due 2020 → late),
BR-53 availability-window relative tests (`Phase3EdgeCaseTest:794,827` use
`now()->addDay()/subDays(2)/subHour()`; `Phase3ValidationTest:385` is
order-only), session/`last_activity` tests (`Phase4SecurityTest:378-382` use
`now()->timestamp`), rate-limit window (`AuthFlowTest:288`), audit-stamp
tests (all `now()->subDays`-relative), `BatchImportTest:1635`
(`now()->subDay()` error-report TTL), `Phase3StudentAssessmentFlowTest:1090,
1147,1188,1218` (`now()->subHours(2)` deadline backdating),
`Phase3PendingGradingTest:556,731` + `Phase5Test:1215` +
`Phase5ServiceTest:1153-1154` (Carbon::parse equality between API value and
persisted value — both sides parse in app tz), `Phase5ServiceTest:955`
(tie-break fixture, ordering-only), `Phase6EdgeCaseTest:1151`
(generated_at not-null only), `Phase7AIServiceTest:1125` (copied timestamp),
`Phase3StudentAssessmentFlowTest:700,706,731` + `:706` format round-trip
(naive parse → store → re-read → format is self-consistent in any tz).
Already-naive fixtures with no wall-clock assert (`Phase9*` due/submitted_at
strings, school-year `start_date`/`end_date` DATE-only values,
`Phase5ContractSweepTest:155`, `Phase3ValidationTest` due/availability
strings) are app-tz-relative by construction and were left as-is. No test in
the suite asserted a `Z`/`+00:00` serialization value — the only
serialization assert is `Phase8AuditLogTest:290` (`assertIsString`), SAFE.

#### Code change: API timestamp serialization `toJSON()` → `toIso8601String()`

**Question resolved (documented per WU-5 rules).** After the flip the new
+08:00 payload test was still RED: `Carbon::toJSON()` is hardcoded Zulu — it
delegates to `toISOString()` which `utc()`s the instance before formatting
(`vendor/nesbot/carbon/src/Carbon/Traits/Converter.php:463-466`, and
`:264-269` `toIso8601ZuluString`), so `config/app.php` alone can NEVER produce
a `+08:00` payload: every `X->toJSON()` timestamp renders as `...Z`
regardless of the app timezone. Laravel's model-level alternative
(`serializeDate`, `Y-m-d H:i:s` app-tz, no offset) emits no offset either.
REM-041's "serialized `+08:00` offsets" acceptance therefore REQUIRES the app
to format timestamps in the instance timezone. Fix: every timestamp
serializer in the API layer swaps `->toJSON()` → `->toIso8601String()` (Atom
`Y-m-d\TH:i:sP` — emits the instance's own offset, Asia/Manila after the
flip): `Admin/UserController`, `AIController`,
`Teacher/AnnouncementController`, `Teacher/AssessmentController`,
`Teacher/AssignmentController`, `AIService`, `AnalyticsService`,
`AnnouncementService`, `AssessmentService`, `AssignmentService`,
`CompetencyMappingService`, `GradingService` (~55 sites). Instants are
unchanged — Zulu and `+08:00` strings parse to the same moment — so the
parse-equality tests (`Phase3PendingGradingTest:556,731`, `Phase5Test:1215`,
`Phase3Test:1165`) stay true, and every presence-only assert (`checked_at`,
`generated_at`, `graded_at`, audit `created_at` string) is untouched.
Ripple verified by the full-suite run below.

#### New tests — `Phase5TimezoneTest` (`tests/Feature/Phase5TimezoneTest.php`, `#[Group('phase5')]`, 4 tests)

1. `test_timestamped_payload_serializes_plus_0800_offset` — NEW. Teacher
   creates an announcement via POST `/api/teacher/announcements`; asserts
   `data.created_at` is ISO-8601 with `+08:00` suffix — NOT `Z`, NOT
   `+00:00` (REM-041 serialization probe).
2. `test_app_timezone_is_asia_manila` — NEW. `config('app.timezone') ===
   'Asia/Manila'`, `date_default_timezone_get() === 'Asia/Manila'`, and
   `now()->format('P') === '+08:00'`.
3. `test_br13_assignment_deadline_boundary_in_manila_local` — NEW. BR-13
   boundary exercised in Manila-local: assignment due `'2026-08-16 16:00:00'`
   (naive → app tz); submit at `Carbon::parse('2026-08-16 15:59:59',
   'Asia/Manila')` → `is_late=false`, status `on_time`; submit at
   `'2026-08-16 16:00:01'` → `is_late=true`, status `late`.
4. `test_br53_availability_window_boundary_in_manila_local` — NEW. BR-53
   window boundary in Manila-local (window `'2026-08-16 13:00:00'`–`'16:00:00'`):
   at 12:59 start → 409 `ASSESSMENT_NOT_AVAILABLE`; window `12:00–16:00` at
   13:01 → start OK; window `12:00–16:00` at 16:01 → 409
   `ASSESSMENT_NOT_AVAILABLE`.

RED evidence (two phases, both captured): **(1) new tests vs the pre-flip
UTC config** — 4/4 failed (21 assertions): serialization actual
`'2026-08-17T02:03:25.000000Z'` ≠ `+08:00` suffix; config actual `'UTC'` ≠
`'Asia/Manila'`; BR-13 late-branch `is_late=false` ≠ `true` (16:00:01+08 is
08:01 UTC, before a 16:00-UTC due — the exact 8-hour boundary shift); BR-53
open-branch start 409 `ASSESSMENT_NOT_AVAILABLE` (13:01+08 = 05:01 UTC,
before the 13:00-UTC window). **(2) post-flip** — 3/4 green; the
serialization test still RED (`'...Z'`, proving the toJSON() finding above).
GREEN only after the serialization swap; final: targeted 4/4 (32 assertions),
full suite 1151/1151 (5203 assertions), phpcs 0 errors, tinker probe
`Asia/Manila +08:00`.

