# Data Architecture

| Field | Value |
|---|---|
| **Document ID** | ARCH-004 |
| **System / Project Name** | CompAss |
| **Version** | 1.9 |
| **Status** | Approved |
| **Owner(s)** | Platform team |
| **Last Updated** | 2026-09-21 |
| **Reviewers** | Platform team |
| **Related Docs** | ARCH-001 ArchitectureOverview, ARCH-002 RequirementsQualityAttributes, ARCH-003 ArchitectureDecisionRecords, ARCH-005 APIEventContracts, ARCH-006 DeploymentSecurityArchitecture — assumptions in ARCH-002 section 6 (ASSUMED-1/7/8/9 apply throughout) |

---

## 1. Purpose & Scope

How we model, own, store, move, and govern data — so classroom activity, grading, mastery, and audit stay consistent and traceable.

- **In scope:** The domains below plus the relational DB, session/rate-limit backing, local files, and the pipelines between them.
- **Out of scope:** Endpoint shapes and versioning, infra provisioning and topology, security controls, and runbooks.

---

## 2. Data Domains & Ownership

| Domain | Description | Owning Service/Team | Consumers |
|---|---|---|---|
| Identity & Access | Users, roles, server-generated CompAss IDs (school_id, all roles), photo opt-in flags, auto-assigned hidden record numbers, credential state, password-change flags, activation state, and session state. | Authentication and user management / Platform team | External admin client via API, Role-Grouped API Handling, Audit Recorder |
| Organization Structure | School years, semesters (trimesters 1–3), grade levels (7–12), sections, and scoped subjects that scope classrooms and reporting. Teacher scope is derived from classrooms, not a join table. | Organization administration / Platform team | External admin and teacher clients via API, Domain Service Layer, Analytics and reporting |
| Classroom Management | Classrooms, ownership by teachers, join keys, and join-key history. | Classroom management / Platform team | External admin, teacher, and student clients via API, Domain Service Layer, Audit Recorder |
| Enrollment | Membership of students in classrooms through file onboarding, hand placing and moving, joining and leaving, with per-row outcomes and placement history. | Classroom management / Platform team | External teacher and student clients via API, Domain Service Layer, Analytics and reporting |
| Classroom Content | Announcements with attachments, assignments with attachments, assessments with items and attachments, and learning materials. | Content management / Platform team | External teacher and student clients via API, Domain Service Layer, File Storage, Audit Recorder |
| Submission & Grading | Assignment submissions with files and feedback, assessment attempts with responses and auto-saves, scores, grades, and release state. | Submission and grading / Platform team | External teacher and student clients via API, Domain Service Layer, Analytics and reporting, Audit Recorder |
| Competency & Mastery | Competency definitions and tags, mastery records per student and competency, and not-competent flags. | Competency and mastery / Platform team | External teacher, student, and admin clients via API, Domain Service Layer, Batch Import Handling |
| AI Explanations | Stored explanations, follow-up turns, moderation flags, teacher notes, and generation status. | AI mediation / Platform team | External student and teacher clients via API, AI Mediation Proxy, Audit Recorder |
| Batch Import | Import jobs for competency and full_name-only learner/teacher sheets, staged rows with per-row outcomes, single-column templates, and generated error sheets (Row/full_name/Reason + Help). | Batch import handling / Platform team | External admin client via API, Batch Import Handling, File Storage, Audit Recorder |
| Audit Logging | Append-only records of significant actions with actor identity and network context. | Audit recording / Platform team | External admin client via API, Audit Recorder, Domain Service Layer |
| Analytics & Reporting | Derived read-only views such as heatmaps, gap reports, trends, drill-downs, school-wide overviews, and mastery history. | Analytics and reporting / Platform team | External admin, teacher, and student clients via API, Domain Service Layer |

**Ownership principle:** One domain, one owner. Everything else goes through the owning service layer — no reaching into someone else's store directly.

---

## 3. Conceptual Data Model

```
[User] -- manages --> [Classroom] -- enrolls --> [Classroom Enrollment] --> [User (student)]
  |                      |
  |                      +-- publishes --> [Announcement]
  |                      +-- assigns ---> [Assignment] -- receives --> [Submission] -- scored as --> [Grade Entry]
  |                      +-- releases --> [Assessment] -- contains --> [Assessment Item] -- answered in --> [Submission]
  |
[School Year] -- scopes --> [Semester (1/2/3)] -- scopes --> [Grade Level (7-12) and Section] -- owns --> [Subject]
  |                                                                                        |
  +-- scopes ------------------------------------------------------------ [Classroom] -----+
  |                      (classroom: subject_id + section_id)                              |
[Competency (subject_id, grade_level, semester)] -- tagged on --> [Assessment Item] -- evidenced by --> [Submission] -- summarized as --> [Mastery Record]
  |
  +-- supported by --> [Learning Material]
  |
[Submission] -- explained by --> [AI Explanation]
[Batch Import] -- loads --> [Competency]
[Batch Import] -- onboards --> [User (student)] -- placed by --> [Classroom Enrollment] -- tracked in --> [Placement History]
[Classroom Enrollment] -- left via --> [Classroom Leave History]
[User action] -- recorded as --> [Audit Record]
```

| Entity | Description | Key Relationships |
|---|---|---|
| User | A person using the system as administrator, teacher, or student, with credential and activation state, server-generated CompAss ID (school_id, all roles), and photo opt-in flag. Internal numbers are auto-assigned behind the scenes and never entered, searched, or shown. | Manages classrooms as teacher; enrolls in classrooms as student through file onboarding or hand placing; owns submissions; recorded as actor in audit records and placement history. |
| School Year | The top-level time boundary organizing semesters and classroom activity. | Divides into semesters (trimesters 1–3); scopes grade levels, sections, and classrooms. |
| Grade Level and Section | The grade (7–12) and class grouping within a semester that organizes students and owns subjects. | Belongs to a semester (`semester_id`) within a school year; owns subjects via `subjects.grade_level_id`; scopes classrooms via sections. |
| Subject | A taught discipline scoped under one grade level (`grade_level_id`), with `code` unique per grade level. Ref: `App\Models\Subject`. | Owned by one grade level (which implies one semester); linked to classrooms and Competencies; blocked from edit/delete while classroom or Competency references exist. |
| Classroom | A teacher-managed group where announcements, assignments, assessments, and materials are shared. | Owned by a teacher; linked to one subject (`subject_id`) and one section (`section_id`); unique (teacher_id, subject_id, section_id, school_year) with 409 `DUPLICATE_CLASSROOM` on conflict; has many enrollments, announcements, assignments, and assessments. Teacher scope is the Semester + Grade Level + Subject package derived from owned classrooms. |
| Classroom Enrollment | The membership of a student in a classroom. | Belongs to one classroom and one student; governs visibility of classwork and results; hand placing and moving changes are tracked in placement history. |
| Announcement | A classroom message from a teacher, optionally with attachments. | Belongs to one classroom; visible to enrolled students. |
| Assignment | Teacher-created classwork that students submit for feedback and grading. | Belongs to one classroom; has many submissions; may link to competencies. |
| Assessment | A structured set of items released to a classroom with attempt, scoring, and release flows. | Belongs to one classroom; contains many assessment items; has many submissions. |
| Assessment Item | A single question or task within an assessment tied to a competency. | Belongs to one assessment; tagged with one competency; answered by many submissions. |
| Submission | Student-provided work for an assignment or assessment, including attempts, responses, auto-saves, and files. | Belongs to one assignment or assessment and one student; produces grade entries; may prompt AI explanations. |
| Grade Entry | A recorded score for submitted work, held until release. | Derived from a submission; released to the student by the teacher; feeds mastery records. |
| Competency | A defined knowledge unit in `competency_reference` (`subject_id`, `grade_level`, `semester` '1'/'2'/'3') tracked across items, work, and mastery. | Tagged on assessment items and assignments only when the (subject_id, grade_level, semester) triple matches the classroom scope (else 422 `COMPETENCY_MISMATCH`); loaded by batch import with `code, descriptor, subject_id, grade_level, semester` headers; summarized in mastery records; read filtered via `GET /api/admin/competency-tags?subject_id&grade_level&semester`. |
| Mastery Record | The recorded attainment level of a student for a competency. | Belongs to one student and one competency; derived from grades and assessment outcomes. |
| Learning Material | Teacher-shared content supporting classroom study outside graded work. | Linked to a classroom and optionally a competency; supports submissions. |
| AI Explanation | A generated explanation of a released result, with follow-up turns and moderation state. | Explains one submission; moderated by teachers through flags, notes, and generation control. |
| Import Job | A batch onboarding run for competency or full_name-only learner/teacher-sheet data (import_type competency, student_enrollment, teacher_application; TYPE_STUDENT_ENROLLMENT = student_enrollment, TYPE_TEACHER_APPLICATION = teacher_application) with validation and correction. | Loads competencies or creates learner/teacher accounts (User rows with server-generated CompAss IDs, always fresh, never matched). Classroom membership comes only via hand place/move or join, never from the sheet. Records staged full_name-only rows with per-row outcomes and row-level import logs, plus error sheets for retry (one download, 7-day expiry). |
| File-Enrollment Row | A staged row from a full_name-only sheet carrying full_name plus per-row outcome. Preview reports total, valid, invalid, and in-file duplicate full_name repeats (informational only); confirm reports imported, updated (always 0), and failed, and the counts reconcile. | Belongs to one import job; creates a fresh user with a server-generated CompAss ID (STU- for students, TEA- for teachers; learner_code holds the generated school_id for audit continuity, '' when the row failed before generation; parent import_job.import_type distinguishes student vs teacher bulk) on success, or carries a reject reason on skip. No group/year columns exist anywhere and no group/year input is accepted. Ref: migration 2026_09_21_000001_fullname_only_bulk. |
| Placement History | The append-only record of hand placements and moves (classroom_enrollment_moves) plus learner leaves (classroom_leave_history). | Records actor, learner, destination classroom, and time for each hand placing or move; records classroom, learner, and time for each leave. |
| Audit Record | An append-only record of a significant action with actor and network context. | Records actions across identity, content, grading, import, moderation, and structural changes. |

---

## 4. Logical / Physical Data Models

### 4.1 Relational store

- **Type:** Relational
- **Purpose:** System of record for all persistent domain state: identity, organization structure, classrooms and enrollment, content, submissions and grading, competency and mastery, AI artifacts, imports, and audit records.
- **Owning service:** Platform team

**Identity and access**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| users | id (PK, auto-assigned, never entered or shown), name, school_id (NOT NULL UNIQUE; CompAss ID ^(ADM\|TEA\|STU)-[0-9]{4}-[0-9]{5}$ with role-prefix check Admin→ADM/Teacher→TEA/Student→STU; server-generated only with collision retry; fillable name/school_id only), photo_opt_in (explicit opt-in flag gating photo display, default off), password_hash, role (Admin, Teacher, Student), must_change_password, is_active. Ref: chk_users_school_id_format, chk_users_school_id_role_prefix | One row per person using the system. Internal numbers are never entered, searched, or shown. No group/year columns exist on this table. Login/bulk rules — see ARCH-005 §4.1/§4.16. Ref: migration 2026_09_20_000001_compass_id_only (users.email dropped, no back-compat). | Yes |
| No password_reset_tokens table (removed per ADR-018) | n/a | No self-service token store exists. Manager-issued temps follow users.must_change_password plus session revoke plus audit with no new tables. Ref: ADR-018 (replaces ADR-016 hash-only half; code and store deleted). | No |

**Organization structure**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| school_years | id (PK), name (unique) | Top-level time boundary scoping semesters and classroom activity. | No |
| semesters | id (PK), school_year_id (FK), semester ('1'/'2'/'3', CHECK), name (display label), start_date, end_date; end_date on or after start_date; unique (school_year_id, semester); duplicate create rejected 409 `SEMESTER_ALREADY_EXISTS` | Trimester division within a school year. Legacy `terms` table renamed; `App\Models\Term` is a deprecated alias only. Ref: table `semesters`, `App\Models\Semester`, migration 2026_09_19_000001_restructure_semester_hierarchy. | No |
| grade_levels | id (PK), semester_id (FK → semesters, CASCADE), grade_level (grades 7-12); unique (semester_id, grade_level) | One row per configured grade within a semester. Ref: `App\Models\GradeLevel`. | No |
| sections | id (PK), grade_level_id (FK), name | Class grouping within a grade level. | No |
| subjects | id (PK), grade_level_id (FK → grade_levels, CASCADE, nullable transitional), name, code, description; unique (grade_level_id, code) | Taught discipline scoped under one grade level; same code may recur once per grade level. Ref: `App\Models\Subject`. | No |
| subject_sections — DELETED | n/a | Dropped in the Semester restructure; holders migrated to direct `subject_id` (classrooms additionally `section_id`). `GET /api/admin/subject-sections` answers 410 `GONE`. Ref: `App\Http\Controllers\Admin\SubjectSectionController`. | No |
| teacher_subject_section_assignments — DELETED | n/a | Dropped in the Semester restructure; teacher scope is derived from classrooms (teacher_id + subject_id + section_id + school_year). Assignment writes answer 410 `GONE`; reads are classroom-derived. | No |

**Classroom management and enrollment**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| classrooms | id (PK), teacher_id (FK), subject_id (FK, required), section_id (FK, required), school_year, name, suffix (nullable), join_key (unique, 6 characters), is_join_enabled, archived_at (nullable); unique (teacher_id, subject_id, section_id, school_year) | Teacher-managed group where content is shared; teacher package is Semester + Grade Level + Subject validated by shared `grade_level_id` (else 422), duplicate rejected 409 `DUPLICATE_CLASSROOM`; join key grants membership while joining is enabled. Ref: `App\Models\Classroom`. | No |
| classroom_join_key_history | id (PK), classroom_id (FK), old_join_key (unique, 6 characters), revoked_at (nullable) | Retired join keys retained so old keys can never be reissued. | No |
| classroom_enrollments | id (PK, auto-assigned, never entered or shown), classroom_id (FK), student_id (FK); unique (classroom_id, student_id), joined_at | Membership of a student in a classroom; the sole roster source governing visibility of classwork and results; a second placing of an already placed learner is blocked in favor of a move. No group/year columns exist on this table; no group/year input is accepted anywhere. | No |
| classroom_enrollment_moves | id (PK, auto-assigned, never entered or shown), classroom_id (FK, destination), student_id (FK), actor_id (FK), action (placed, moved), created_at | Append-only history of single-learner hand placements and moves recording actor, learner, destination, and time for each change. | No |
| classroom_leave_history | id (PK, auto-assigned, never entered or shown), classroom_id (FK), student_id (FK), left_at | Append-only history of learner leaves recording classroom, learner, and time for each leave (first leave writes, repeats converge with no second write). | No |

**Classroom content**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| announcements | id (PK), classroom_id (FK, required), teacher_id (FK), subject_id (FK, required), title, body | Classroom message from a teacher to enrolled students. | No |
| announcement_attachments | id (PK), announcement_id (FK), filename, original_filename, mime_type, file_size (capped) | File attached to an announcement; bytes held in file storage. | No |
| assignments | id (PK), classroom_id (FK, required), teacher_id (FK), subject_id (FK, required), semester_id (FK → semesters, required), title, description (nullable), due_date | Classwork that students submit for feedback and grading. Ref: `App\Models\Assignment`. | No |
| assignment_attachments | id (PK), assignment_id (FK), filename, original_filename, mime_type, file_size (capped) | File attached to an assignment; bytes held in file storage. | No |
| assessments | id (PK), classroom_id (FK, required), teacher_id (FK), subject_id (FK, required), semester_id (FK → semesters, required), title, description (nullable), type (Recorded, Unrecorded; immutable after creation), status (draft, released), time_limit (nullable), availability window (nullable) | Structured set of items released to a classroom; only recorded assessments feed mastery. Ref: `App\Models\Assessment`. | No |
| assessment_items | id (PK), assessment_id (FK), item_type (multiple_choice, true_false, essay), prompt, max_points, competency_tag_id (FK), sort_order | Single question or task within an assessment tied to one competency. | No |
| assessment_item_attachments | id (PK), assessment_item_id (FK), filename, original_filename, mime_type, file_size (capped) | File attached to an assessment item; bytes held in file storage. | No |
| learning_materials | id (PK), teacher_id (FK), subject_id (FK, required), competency_id (FK), title (nullable), filename, original_filename, mime_type, file_size (capped), extracted_text (nullable) | Teacher-shared reference content supporting study; competency must match the subject triple (else 422 `COMPETENCY_MISMATCH`); extracted plain text is captured at upload time for explanation support and is absent when the file is not extractable. | No |

**Submission and grading**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| assignment_submissions | id (PK), assignment_id (FK), student_id (FK); unique (assignment_id, student_id), submitted_at, status (on_time, late) | One submission per student per assignment. | No |
| submission_files | id (PK), submission_id (FK), filename, original_filename, mime_type, file_size (capped) | Uploaded file belonging to an assignment submission; bytes held in file storage. | No |
| assignment_feedback | id (PK), submission_id (FK, unique), teacher_id (FK), feedback_text | Single teacher feedback record per assignment submission. | No |
| assessment_attempts | id (PK), assessment_id (FK), student_id (FK), attempt_number; unique (assessment_id, student_id, attempt_number), status (in_progress, submitted, pending_grading, scored), grader_id (nullable), graded_at (nullable), started_at (nullable), is_resubmission, resubmission_of_attempt_id (self-FK, nullable), resubmission_reason (nullable), resubmission_requested_at (nullable), response_history | One lifecycle-tracked attempt per taking of an assessment, including resubmission linkage. | No |
| assessment_auto_saves | id (PK), student_id (FK), assessment_id (FK), attempt_id (FK, nullable); unique (student_id, assessment_id, attempt_id), responses | Per-attempt draft responses captured during taking. | No |
| assessment_submissions | id (PK), attempt_id (FK, unique), assessment_id (FK), student_id (FK), section_id (FK), semester_id (FK → semesters, matching the parent assessment), submitted_at, status (pending_grading, scored), is_results_released, results_released_at (nullable) | Exactly one submitted record per attempt; semester and section always match the parent assessment. Ref: `trg_assess_sub_snapshot`. | No |
| assessment_responses | id (PK), submission_id (FK), item_id (FK); unique (submission_id, item_id), response_text (nullable), earned_points (nullable), is_auto_scored, scored_by_teacher_id (nullable) | One scored or scoreable answer per item per submission. | No |
| grade_entries | id (PK), assessment_submission_id (FK), assessment_item_id (FK); unique (assessment_submission_id, assessment_item_id), score, max_score (write-time snapshot of the item's points), feedback (nullable), graded_by (nullable), graded_at (nullable), is_draft | Authoritative per-question grading ledger; score is never negative and never exceeds the snapshotted maximum. | No |

**Competency and mastery**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| competency_reference | id (PK), code (unique), descriptor, subject_id (FK), grade_level (grades 7-12), semester ('1'/'2'/'3', CHECK, default '1'; index on (subject_id, semester)) | Defined knowledge unit tracked across items, work, and mastery. Ref: `App\Models\CompetencyReference`. | No |
| mastery_records | id (PK), student_id (FK), classroom_id (FK, required), subject_id (FK, co-carried scope), assessment_id (FK), assessment_submission_id (FK), competency_id (FK), mastery_percent (0-100), mastery_status (Mastered at 80 percent and above, otherwise Not_Mastered), created_at; unique (student_id, competency_id, assessment_id, assessment_submission_id) | Append-only history of per-student per-competency outcomes; rows are written only for recorded assessments and never updated in place, so current standing is always derived from the latest row. | No |

**AI explanations**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| ai_explanations | id (PK), student_id (FK), assessment_id (FK), assessment_submission_id (FK), assessment_attempt_id (FK, nullable), item_id (FK), explanation_text, is_ungrounded, is_follow_up, parent_explanation_id (self-FK, nullable), turn_number, flagged, flagged_by_teacher_id (nullable), teacher_note (nullable), teacher_note_updated_at (nullable), explain_further_disabled, disabled_by_teacher_id (nullable), moderation_status (none, flagged, manual_review); unique (student_id, assessment_id, item_id, is_follow_up, turn_number, assessment_attempt_id) | Stored explanation of a result with follow-up turns and teacher moderation state; explanations for different attempts of the same item coexist. | No |

**Batch import**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| import_jobs | id (PK, auto-assigned, never entered or shown), import_type (competency, learner_sheet; TYPE_STUDENT_ENROLLMENT = student_enrollment), admin_id (FK), total_rows, imported_rows, failed_rows, error_report_path (nullable), error_report_token (nullable), error_report_expires_at (nullable; 7-day expiry), error_report_used_at (nullable) | One row per competency or learner-sheet upload run limited to managers (15 MB, 5000-row ceiling; 60-minute preview token held separately). Each row commits atomically so bad rows skip without blocking good rows, and preview/confirm counts reconcile. Error reports allow one download within a 7-day expiry (token plus use marker are mutually exclusive). | No |
| import_logs | id (PK, auto-assigned, never entered or shown), admin_id (FK), filename, total_rows, valid_rows, invalid_rows, imported_rows, skipped_rows, status (completed, failed), error_details (nullable), started_at (nullable), completed_at (nullable) | Row-level outcome log for a completed import run carrying per-row outcome and reject reason. | No |
| enrollment_import_rows | id (PK, auto-assigned, never entered or shown), import_job_id (FK), row_number, learner_code (generated school_id STU-/TEA- for audit continuity, '' when the row failed before generation; the parent import_job.import_type distinguishes student vs teacher bulk), full_name, error (nullable, full reason stored unstripped; staged display value truncated to 255 chars) | Staged full_name-only bulk row. Every valid row creates a fresh account with a new CompAss ID, never a match or update, and the updated count stays 0. Bad rows (blank or >255-char names, save failures) skip into the error sheet while good rows save. No group/year columns exist anywhere and none are accepted. Ref: migration 2026_09_21_000001_fullname_only_bulk (drops group_assignment/year_level). | Yes |

**Audit logging**

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| audit_logs | id (PK), user_id (FK, nullable, preserved when the user row changes), event_type (login, logout, create, update, delete, release_results, flag_explanation, disable_explain_further, purge_semesters, error_report_download, other), auditable_type (nullable), auditable_id (nullable), description, ip_address (nullable), user_agent (nullable), metadata (nullable), created_at | Append-only record of significant actions with actor identity and network context; rows are inserted once and never updated. | Yes |

### 4.2 Session and rate-limit backing

- **Type:** Relational
- **Purpose:** Server-side session state for cookie authentication and rate-limit counters for repeated and sensitive operations. There is no domain read-through caching; all domain reads come from the relational store or file storage. There are no queued background jobs; all processing is synchronous and scheduled maintenance runs in-process.

| Table/Collection | Key Fields | Description | PII? |
|---|---|---|---|
| sessions | id (PK), user_id (FK, nullable, preserved when the user row changes), ip_address (nullable), user_agent (nullable), payload, last_activity | Server-side session backing cookie authentication, password-change gating, and request forgery handling; short inactivity lifetime with probabilistic sweeping of expired rows. | Yes |
| cache | key (PK), value, expiration | Rate-limit counters only (authentication attempts, AI requests, imports, classroom joins); no domain objects are cached here. | No |
| cache_locks | key (PK), owner, expiration | Mutual-exclusion rows supporting atomic cache operations for the counters above. | No |
| jobs | id (PK), queue metadata, payload, timing fields | Structurally present but unused; no work is enqueued and all processing is synchronous. | No |
| job_batches | id (PK), batch metadata, counts, timing fields | Structurally present but unused; no work is enqueued and all processing is synchronous. | No |
| failed_jobs | id (PK), queue metadata, payload, failure detail, timing fields | Structurally present but unused; no work is enqueued and all processing is synchronous. | No |

The queue backing tables exist structurally but remain empty in operation: no work is ever enqueued, imports and explanation generation run synchronously in-request, and cleanup duties run on the in-process scheduler.

### 4.3 File storage

- **Type:** Local file storage
- **Purpose:** Durable bytes for everything too large or unstructured for the relational store, with retrieval always gated by the same role and membership checks that guard the owning record.
- **Owning service:** Platform team

| Content | What Is Stored | Relational Pointer | PII? |
|---|---|---|---|
| Announcement attachments | Teacher-uploaded files attached to announcements | announcement_attachments row holding filename, original name, media type, and size | No |
| Assignment attachments and submission files | Teacher-provided classwork files and student-uploaded submission files | assignment_attachments and submission_files rows holding filename, original name, media type, and size | No |
| Assessment item attachments | Supporting files attached to individual assessment items | assessment_item_attachments row holding filename, original name, media type, and size | No |
| Learning material files | Teacher-uploaded reference documents for explanation support | learning_materials row holding filename, original name, media type, size, and extracted text | No |
| Import templates | Downloadable spreadsheet templates generated on demand for competency and full_name-only learner/teacher onboarding; enrollment templates carry a single `full_name` column (case-insensitive, order-insignificant, extras ignored; missing full_name header yields 422); the server auto-generates CompAss IDs (STU- for students, TEA- for teachers) | No persistent pointer; produced fresh per request | No |
| Import error reports | Generated spreadsheets describing rejected rows with reasons for correction and retry: competency sheets carry Row/code/descriptor/subject_id/grade_level/semester/Reason; enrollment sheets carry Row/full_name/Reason plus a Help sheet | import_jobs row holding the report path with single-use token and expiry; staged enrollment rows hold the same per-row outcome | Yes when enrollment sheets carry names |

Uploads are size-capped; error reports expire after 7 days and stop working after the first download.

### 4.4 Derived views

- **Type:** Relational read-only derivations
- **Purpose:** Read-only summaries computed from the tables above at query time; they own no primary data and are never written to directly.
- **Owning service:** Platform team

| View/Summary | Key Fields | Description | PII? |
|---|---|---|---|
| Not-competent flags | id, student_id, competency_id, assessment_submission_id, mastery_record_id, created_at | The latest recorded mastery record per student and competency, shown only where that latest standing is below the mastery threshold; resolves ties by recency and identifier order so regrades immediately correct the flag set with no stored rows to go stale. | No |
| Classroom heatmaps and gap summaries | student, competency, mastery standing | Per-classroom aggregates over the latest mastery standing per student and competency, used to spot weak competencies and students needing support. | No |
| Performance trends and drill-downs | assessment, student, competency, outcome over time | Per-assessment and per-student breakdowns of grades and past scores history, including school-wide overviews that pool classrooms while retaining classroom scope on each underlying row. | No |
| Student mastery history (past scores history) | student_id, competency_id, standings over time | Chronological list of a student's mastery records across recorded assessments (past scores history); viewing never creates new AI explanations. | No |
| People list | name only, photo only with opt-in, paged and scoped | Read-only names-only listing in small alphabetical pages (15/100) scoped to the viewer's own group; any ?search= 422, no write actions. Photos appear only with explicit opt-in (photo_opt_in, default off); internal numbers are excluded. | Yes |

Search across lists matches human names (learner name, group name, subject name, room name) plus CompAss IDs (school_id) on staff reads through dedicated indexes (HumanSearch; digits-only search refused 422; learner reads reject any ?search= 422); internal numbers are never searchable or shown and the CompAss ID plus join key remain the visible and matchable identifiers. Reports return in small pages scoped to the right group or classroom.

---

## 5. Data Flow & Lineage

Where data enters, where it is stored, and what is derived from it.

```
[Client input / upload]
        |
        v
[Backend API + Service Layer] -- validate, authorize, throttle --> [Relational Store]
        |                                                                    |
        +--> [File Storage] <--> metadata rows in [Relational Store]         |
        |                                                                    v
        +--> [External Text Service] --> stored [Explanations]       [Derived Views]
        |                                     (moderated)            (heatmaps, gaps,
        |                                                              trends, flags)
        v                                                                    ^
[Audit Records] <-- every significant action --------------------------------+
        ^
[Scheduled Cleanup] -- anonymize + purge --> [Audit Records]
                                     +-----> expired [Error Reports]

Everything runs synchronously in the request; scheduled cleanup runs in process. Each arrow is a direct read or write.
```

| Flow | Source | Destination | Trigger | Transformation | Latency |
|---|---|---|---|---|---|
| Classroom creation and enrollment | Teacher and student requests (teacher package Semester + Grade Level + Subject; `POST /api/teacher/classrooms` with `subject_id` + `section_id`) | Classrooms, join-key history, enrollments | Teacher creates room; student joins by key, leaves, or is removed | Validate shared `grade_level_id` package scope, reject duplicate teacher + subject + section + school year 409 `DUPLICATE_CLASSROOM`, generate unique join key, check key state and membership, record roster change and audit row; competency picker triple served via `GET /api/teacher/classrooms/{id}/competency-context` | Synchronous |
| Content publishing with attachments | Teacher requests with uploaded files | Announcements, assignments, assessment items plus attachment metadata; file bytes in file storage | Teacher creates content via API with classroom scope | Validate classroom scope, store file bytes, write metadata rows with size and type caps, record audit row | Synchronous |
| Assignment submission and feedback | Student requests with uploaded files | Submissions, submission files, feedback records | Student submits; teacher gives feedback | Enforce single submission for each student and assignment, store file bytes with metadata rows, write single feedback row for each submission, record audit row | Synchronous |
| Assessment authoring and release | Teacher requests | Assessments, assessment items | Teacher authors draft, adds tagged items, releases | Require at least one competency-tagged item before release, freeze structure after release, record audit row | Synchronous |
| Assessment taking with auto-save and submit | Student requests | Attempts, auto-saves, submissions, responses | Student starts attempt, auto-saves drafts, submits once | Validate enrollment and availability, keep drafts separate from scoring, auto-score objective items on submit while holding subjective items pending, refuse resubmission of submitted work, record audit row | Synchronous |
| Grading, mastery recomputation, and release | Teacher grading actions | Grade entries, responses, attempts, mastery records | Teacher scores manually or in bulk, then releases results | Bound scores to item maxima, write response scores and grade ledger rows in one transaction, append mastery rows only for recorded assessments at the 80 percent threshold, block release while grading is pending | Synchronous |
| Competency and learner import with correction cycle | Manager upload | Competency definitions or learner/teacher accounts, import jobs, staged full_name-only rows, import logs, error sheets | Manager uploads spreadsheet | Create fresh accounts with server-generated CompAss IDs (STU-/TEA-; per-row 3x retry on collision; never matched by name). Validate full_name-only rows with atomic per-row commits, keeping valid rows and collecting failures with reasons. Preview/confirm counts reconcile; error sheet carries Row/full_name/Reason + Help (one download, 7-day expiry); record audit row. See ARCH-005 §4.16. | Synchronous |
| Hand placing and moving | Manager action | Enrollments, placement history (classroom_enrollment_moves) | Manager places (201) or moves (200; identical 409; archived 410) a single learner | Create a fresh Student account with a server-generated STU- CompAss ID (3x retry), then enroll; no name matching, no manual IDs, no group/year input. A second placing of a placed learner returns 409 in favor of a move; write actor, learner, destination, and time to placement history plus audit row. See ARCH-005 §4.16. | Synchronous |
| Learner leaving | Learner action | Enrollments, leave history (classroom_leave_history) | Learner leaves via API | First leave already_left false removes enrollment; repeats already_left true with no second write; append classroom_leave_history plus audit row | Synchronous |
| AI explanation generation and moderation | Student explanation request; teacher moderation actions | Stored explanations with follow-up turns and moderation state | Explicit student request after results release; teacher flags, notes, or disables follow-ups | Withhold direct identifiers from outbound prompts, delimit embedded content as data, persist each explanation immediately with attempt-plus-item dedupe, mark ungrounded output, apply moderation fields without deleting history | Synchronous |
| Analytics and reporting derivations | Stored grades and mastery records | Heatmaps, gap summaries, trends, drill-downs, past scores history, not-competent flags | Teacher, student, or administrator opens a dashboard or report | Aggregate latest mastery standing for each student and competency at query time; match by human names (digits-only refused 422) with internal numbers excluded, return in small pages scoped to the right group or classroom; no separate persisted copies | On-demand |
| People-list reading | Learner request | Names-only read model | Learner reads the people list | Return names only in small pages (15/100, alphabetical) scoped to the learner's own group with no school-wide search (any ?search= 422), include photos only where opt-in is recorded (photo_opt_in default off), expose no edit actions | On-demand |
| Audit capture | Every significant action | Append-only audit records | Authentication, content, grading, import, moderation, and structural changes | Capture actor, event type, message, affected entity, and network context in one centralized write; write failure never blocks the triggering action | Synchronous |
| Retention and report cleanup | Stored audit records and error reports | Anonymized audit records; purged rows; expired reports | Scheduled daily run | Irreversibly anonymize network addresses after 90 days, hard-delete audit rows older than 365 days, expire consumed or aged error reports | Scheduled daily |

---

## 6. Data Storage Strategy

Why each kind of data lives where it does.

| Data Type | Storage Choice | Rationale |
|---|---|---|
| Transactional / operational | Relational database | Single system of record for identity, organization structure, classrooms and enrollment, content, submissions and grading, competency and mastery, AI artifacts, imports, and audit records; integrity constraints and single-store transactions keep classroom, grading, and mastery rules consistent. |
| Analytical / reporting | Read-only views on the same relational store, computed at query time | Heatmaps, gap summaries, trends, drill-downs, past scores history, and not-competent flags derive directly from grades and mastery records; no separate copies exist to go stale and regrades correct derived output immediately. |
| Caching | Relational cache tables holding rate-limit counters only | Counters throttle authentication attempts, AI requests, imports, and classroom joins; no domain objects are cached so every domain read comes from the system of record or file storage. |
| Unstructured / files | Local file storage with relational metadata pointers | Holds announcement and assignment attachments, submission files, learning material files, import templates, and generated error reports; metadata rows carry filename, type, and size while retrieval stays gated by the same role and membership checks that guard the owning record. |
| Session | Relational session table backing cookie authentication | Holds server-side session state supporting password-change gating and request forgery handling with a short inactivity lifetime; loss of backing state forces sign-out so deactivation and gating stay enforceable. |

---

## 7. Data Consistency & Integrity

How the data stays correct when services and stores meet.

- **Consistency model:** Strong consistency within the single relational store; every domain write completes synchronously in-request so reads immediately reflect classroom changes, grades, mastery records, explanations, imports, and audit rows. Derived views are computed at query time from the same store so they never diverge into stale copies.
- **Transaction boundaries:** Single-store transactions only with no distributed transactions and no background workers; multi-row writes such as grading write-through, mastery appends, content creation with attachments, and enrollment changes commit atomically inside one store transaction, while bulk grading reports partial success for each student instead of rolling back completed students. File-enrollment sheets commit atomically for each row so bad rows skip without blocking good rows and counts stay exact.
- **Idempotency strategy:** Classroom joins, leaves, archive transitions, and semester purges are idempotent. Assignment and assessment submissions refuse duplicates, so each student holds one submission per assignment and one submitted record per attempt. Learner sheets always create fresh accounts (updated stays 0), and a second hand placing of a placed learner returns 409 in favor of a move with actor, learner, destination, and time written to placement history. Competency and learner imports report imported and failed counts with valid rows never blocked by bad rows and error sheets limited to one download. AI explanations dedupe on attempt-plus-item keys so concurrent retries serve the surviving row without duplicates.
- **Referential integrity across boundaries:** Foreign keys enforce integrity inside the relational store. These cover unique CompAss IDs (users.school_id NOT NULL UNIQUE with format plus role-prefix CHECKs), unique join keys with revoked keys retained in history, unique enrollment and submission keys, and append-only mastery, placement-history, and audit rows that are never updated in place. Record numbers stay auto-assigned behind the scenes. Human names (learner, group, subject, room names) carry dedicated indexes with no index on internal numbers, so school_id and join key remain the visible match keys. No group/year columns exist on users, classroom_enrollments, or staged rows, and no group/year input is accepted or retained. Cross-boundary rules such as classroom scope, enrollment membership, competency tagging, grading gates, and release gates are enforced by service-layer checks before any write. File bytes in file storage always pair with owning metadata rows. Ref: generateUniqueCompassId (3x single create/place, per-row 3x bulk confirm, 50-candidate budget); migration 2026_09_21_000001_fullname_only_bulk.

---

## 8. Data Lifecycle Management

### 8.1 Retention & Archival

| Data Category | Active Retention | Archive Policy | Deletion Policy | Legal/Regulatory Basis |
|---|---|---|---|---|
| Classroom records, enrollments, and published content | Retained while the classroom is active | Archived classrooms retained read-only with history preserved | Removed only through closed-semester purge | Internal policy |
| Assignment submissions, assessment attempts, responses, scores, and feedback | Retained while the classroom is active | Retained with the classroom on archive | Removed only through closed-semester purge | Internal policy |
| Mastery records and competency standing | Retained while the student record is active; history is append-only and never updated in place | Retained with the classroom on archive; current standing derived from the latest row | Removed only through closed-semester purge | Internal policy |
| AI explanations, follow-up turns, and moderation state | Retained while the related submission is retained | Retained with the classroom on archive | Removed with the owning submission data | Internal policy |
| Competency and learner import jobs, staged file-enrollment rows, and row-level logs | Retained while the competency set or learner record is active | None; retained in place | Retained as operational history; removed with the owning administrative scope when purged | Internal policy |
| Placement, move, and leave history | Retained while the classroom is active | Retained with the classroom on archive with history preserved | Removed only through closed-semester purge | Internal policy |
| Import error reports | Available for 7 days and unavailable after first download | None | Deleted automatically on expiry or first download | Internal policy |
| Learner preview tokens | Single-use, expire after 60 minutes | None | Consumed on confirm or expired by time | Internal policy |
| Audit records including actor and network context | Retained for 365 days; network addresses anonymized after 90 days | None | Hard-deleted after 365 days by daily scheduled run | Internal policy |
| Sessions and rate-limit counters | Retained for the session lifetime with a short inactivity lifetime | None | Expired rows swept automatically | Internal policy |

### 8.2 Backup & Recovery

There is one relational store and one file volume. Backups follow host and database procedures. The app keeps no backup schedule of its own.

| Store | Backup Frequency | Retention | RPO | RTO | Tested? |
|---|---|---|---|---|---|
| Relational store holding all domain, session, and audit state | Per host procedure | Per host procedure | Per host procedure | Per host procedure | Per host procedure |
| File volume holding attachments, submission files, materials, and generated reports | Per host procedure | Per host procedure | Per host procedure | Per host procedure | Per host procedure |

---

## 9. Data Privacy & Classification

This section defines data classification and handling; enforcement controls live with deployment and security.

### 9.1 Data Classification Levels

| Level | Description | Examples |
|---|---|---|
| Public | Intended for open distribution | Import template column structure |
| Internal | Limited to authenticated classroom use | Announcements, assignments, assessments, learning materials |
| Confidential | Limited to the involved parties | Scores, grades, feedback, mastery standing, classroom analytics |
| Restricted / PII | Identifiable personal data with handling rules | Names, CompAss IDs (school_id), credential state, network addresses, response content containing identifiers |

### 9.2 PII & Sensitive Data Inventory

| Field | Domain/Store | Classification | Regulation | Handling Requirement |
|---|---|---|---|---|
| User name, CompAss ID (school_id) | Identity | Restricted / PII | Internal policy | Shown only to authorized roles; internal numbers are never searched or shown. Login identifiers (CompAss ID, all roles; trimmed, case-sensitive) are stored masked in logs. Login/bulk rules — see ARCH-005 §4.1/§4.16. Ref: migration 2026_09_20_000001_compass_id_only (no email column). |
| Photo opt-in flag | Identity | Restricted / PII | Internal policy | Photos appear only where explicit opt-in is recorded; names-only display is the default in the people list |
| Staged full_name-only bulk rows (full_name + generated CompAss ID) | Batch import | Restricted / PII | Internal policy | Held with per-row outcome and reject reason; learner_code holds the generated school_id (STU-/TEA-) for audit continuity ('' when the row failed before generation). No group/year columns exist anywhere and no group/year input is accepted. Rejected rows carry into the error sheet (Row/full_name/Reason + Help; one download, 7-day expiry). Ref: migration 2026_09_21_000001_fullname_only_bulk. |
| Password hash and manager-issued temp state | Identity | Restricted / PII | Internal policy | Stored hashed; no self-service reset tokens exist. A manager reset temp goes to the calling admin only over the authenticated session with must_change_password plus session revoke plus audit, and the recipient follows first-login parity per ASSUMED-9. Ref: ADR-018. |
| Session network address and agent string | Session state | Restricted / PII | Internal policy | Used for authentication and abuse handling only; expired rows swept automatically |
| Audit network address, agent string, and masked identifiers | Audit trail | Restricted / PII | Internal policy | Network addresses anonymized after 90 days and rows hard-deleted after 365 days; identifiers stored masked |
| Assessment responses, submission text, and uploaded content as written | Submissions and grading | Restricted / PII | Internal policy | Stored as-is; sent only as delimited data for explanation support with direct identifiers withheld |

---

## 10. Master Data & Reference Data

Reference data the rest of the system depends on.

| Reference Data Set | System of Record | Distribution Method | Update Frequency |
|---|---|---|---|
| Grade bands 7 through 12 | Platform team | Read directly from the system of record at request time | Rare; changes with school structure |
| CompAss ID format ^(ADM\|TEA\|STU)-[0-9]{4}-[0-9]{5}$ with role-prefix check (Admin→ADM, Teacher→TEA, Student→STU) | Platform team | Generated server-side only with collision retry. Ref: generateUniqueCompassId; chk_users_school_id_format; chk_users_school_id_role_prefix. | On each account creation |
| Subjects with codes and descriptions | Platform team | Read directly from the system of record at request time | Rare; changes with school structure |
| Competency definitions with code, descriptor, subject_id, grade_level, and semester ('1'/'2'/'3') | Platform team | Read directly from the system of record at request time | On each approved import |
| Mastery threshold at 80 percent | Platform team | Applied uniformly at write time for recorded assessments | Rare; change applies forward |
| Join-key format of 6 unique characters with revoked-key history | Platform team | Generated and validated at request time against the system of record | On each classroom creation and rotation |
| Semester structure with `semester` '1'/'2'/'3' plus start and end dates bounding activity | Platform team | Read directly from the system of record at request time | Per school year |

---

## 11. Data Quality

| Dimension | Requirement | Monitoring Approach |
|---|---|---|---|
| Completeness | Required fields present on every write; classroom scope complete before release | Validation at request time with rejection of incomplete writes |
| Accuracy | Scores bounded by item maxima; mastery derived deterministically at the fixed threshold; derived views computed from current records | Transactional writes with recomputation checks and query-time derivation |
| Timeliness | Classroom, grading, and mastery writes visible immediately; derived views computed on demand; retention jobs run daily | Synchronous write confirmation with scheduled-run health checks |
| Uniqueness | One enrollment for each student and classroom; one submission for each student and assignment; unique join keys never reissued; single-use error reports | Integrity constraints with duplicate rejection |

---

## 12. Migration Strategy

How the current shape came about.

- **Current state:** The Semester-hierarchy model is live: School Year > Semester > Grade Level > Subject > Competencies. Content, enrollment, grading, mastery, and reporting are scoped by classroom with `subject_id` + `semester_id`. The CompAss rework is live: users.email is dropped with users.school_id NOT NULL UNIQUE plus format and role-prefix CHECKs, and bulk onboarding is full_name-only with fresh IDs. Ref: migrations 2026_09_19_000001_restructure_semester_hierarchy; 2026_09_20_000001_compass_id_only; 2026_09_21_000001_fullname_only_bulk (teacher_application enum value is irreversible; group/year re-add on down() is partial).
- **Migration approach:** The move from the `terms` / `subject_sections` shape to the Semester hierarchy is complete (terms renamed to semesters, `term_id` renamed to `semester_id`, subjects scoped, `subject_sections` plus teacher-assignment tables dropped). No dual-write remains.
- **Rollback plan:** Not applicable; issues are addressed by forward fix.
- **Cutover criteria:** Met; semester creation, grade/subject scoping, classroom creation, joining, content, grading, mastery, and reporting operate under the Semester-hierarchy model.

---

## 13. Open Risks & Questions

| Risk / Question | Impact | Owner | Status |
|---|---|---|---|
| Audit records grow with every significant action and depend on the daily scheduler for anonymization and purge | Medium | Platform team | Open |
| File bytes live on a local disk volume, concentrating availability and limiting independent scaling | Medium | Platform team | Open |
| Synchronous grading, import, and explanation work holds request slots under burst load | Medium | Platform team | Open |
| Explanation generation depends on an external text service, so outages and ungrounded output must be surfaced | Medium | Platform team | Open |
| Student-written responses may contain personal data as written and flow into stored explanations and moderation views | Medium | Platform team | Open |

---

## Revision History

| Version | Date | Author | Summary of Changes |
|---|---|---|---|
| 1.0 | 2026-09-07 | Platform team | Initial v1 established from implementation |
| 1.1 | 2026-09-09 | Platform team | Docs refresh: leave flow and history, reset-token store details, import ceilings and counts, people-list paging and photo opt-in, name search rules; cross-refs aligned |
| 1.2 | 2026-09-10 | Platform team | Rework cycle 1 (ADR-016 + ADR-017): staged import boundary, token store rules, template requirements; header assumptions updated |
| 1.3 | 2026-09-11 | Platform team | Follow-up (ADR-018 admin-only): token store replaced with absence note; manager temps via users.must_change_password plus revoke plus audit; PII row updated; header assumptions updated |
| 1.4–1.6 | 2026-09-12–2026-09-18 | Platform team | Presentational and wording passes only; no schema, API, auth, or audit change |
| 1.8 | 2026-09-20 | Platform team | Semester hierarchy restructure (migration 2026_09_19_000001_restructure_semester_hierarchy): semesters table + Semester model, semester_id renames, subjects scoped grade_level_id code-unique-per-grade, competency_reference semester 1–3, subject_sections + teacher assignments deleted, classrooms subject_id+section_id unique teacher+subject+section+school_year, content subject_id+semester_id, purge_semesters audit, closed-semester purge; Semester-only wording |
| 1.9 | 2026-09-21 | Platform team | CompAss rework (ADR-025): §4.1 users school_id NOT NULL UNIQUE ^(ADM\|TEA\|STU)-[0-9]{4}-[0-9]{5}$ + role-prefix (email dropped via 2026_09_20_000001); enrollment_import_rows full_name-only with learner_code as generated-ID audit store (group/year dropped via 2026_09_21_000001); templates single full_name column; error sheets Row/full_name/Reason + Help; import/hand flows always fresh IDs (updated=0, in-file repeats); PII no email; login identifier school_id trim-only case-sensitive |
