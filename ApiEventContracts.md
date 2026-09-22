# API / Event Contracts

| Field | Value |
|---|---|
| **Document ID** | ARCH-005 |
| **System / Project Name** | CompAss |
| **Version** | 2.0 |
| **Status** | Approved |
| **Owner(s)** | Platform team |
| **Last Updated** | 2026-09-21 |
| **Reviewers** | Platform team |
| **Related Docs** | ARCH-001 ArchitectureOverview, ARCH-002 RequirementsQualityAttributes, ARCH-003 ArchitectureDecisionRecords, ARCH-004 DataArchitecture, ARCH-006 DeploymentSecurityArchitecture — assumptions in ARCH-002 section 6 (ASSUMED-1/7/8/9 apply throughout) |

---

## 1. Purpose & Scope

What callers and the server agree on — shapes, validation, auth and roles, versioning, and errors.

- **In scope:** The one backend REST API for admin, teacher, and student (any client), plus the single outbound call to the AI service for post-assessment explanations. We publish no domain events.
- **Out of scope:** Internal function signatures, service-layer interfaces, DB internals, and UI props.
- **Contract source of truth:** This doc. No external spec files — everything lives here.

---

## 2. Conventions & Standards

Ground rules for every endpoint, written once here so we don't repeat them below.

| Convention | Rule |
|---|---|
| API style | REST over HTTPS with JSON request and response bodies; multipart form data only for file-upload endpoints |
| Naming | Kebab-case URLs; snake_case JSON fields; numeric IDs constrained to digits |
| Versioning scheme | No URI versioning; the API is served unversioned and evolves through backward-compatible changes |
| Auth | Session-cookie authentication with CSRF protection — clients fetch a CSRF cookie, send credentials on every request, and attach the CSRF token header on all non-GET requests; role checks (admin, teacher, student) and a forced-password-change gate apply per route except sign-out, password change, profile, and service-status reads (all “forced-password-change gate applies” rows below inherit this exception) |
| Pagination | Most list endpoints paginate via `?page=` and `?per_page=` with `{data, meta}`; page size defaults to 15 items with a ceiling of 100 per page; a few classroom and student index reads return unpaginated `{data}` arrays |
| Date/time format | ISO 8601 UTC timestamps |
| Idempotency | No global idempotency header; retry safety is documented per endpoint, with explicit confirmation or single-use semantics where a repeat call would be unsafe |
| Error format | Uniform envelope of the form `{ "error": { "message": "...", "code": "...", "fields?": { } } }`; validation failures return 422 with per-field details |
| Event envelope | None — the system publishes no domain events |

---

## 3. API Inventory

Every API in one list. Detail for each lives in §4.

| API | Owning Service | Type | Consumers | Spec Link | Status |
|---|---|---|---|---|---|
| Auth API | Platform team | REST | Admin, teacher, and student roles (via API, any client) | Covered here | Stable |
| Admin Users API | Platform team | REST | Admin role (via API, any client) | Covered here | Stable |
| Org Structure API | Platform team | REST | Admin role (via API, any client) | Covered here | Stable |
| Admin Teacher Assignments API | Platform team | REST | Admin role (via API, any client) | Covered here | Stable |
| Classroom API | Platform team | REST | Admin, teacher, and student roles (via API, any client) | Covered here | Stable |
| Classroom Content API | Platform team | REST | Teacher and student roles (via API, any client) | Covered here | Stable |
| Announcements API | Platform team | REST | Teacher and student roles (via API, any client) | Covered here | Stable |
| Assignments API | Platform team | REST | Teacher and student roles (via API, any client) | Covered here | Stable |
| Assessments API | Platform team | REST | Teacher and student roles (via API, any client) | Covered here | Stable |
| Grading API | Platform team | REST | Teacher, student, and admin roles (via API, any client) | Covered here | Stable |
| Competency Mapping API | Platform team | REST | Teacher and admin roles (via API, any client) | Covered here | Stable |
| Analytics API | Platform team | REST | Teacher, student, and admin roles (via API, any client) | Covered here | Stable |
| AI Explanations and Moderation API | Platform team | REST | Teacher and student roles (via API, any client) | Covered here | Stable |
| Batch Import API | Platform team | REST | Admin role (via API, any client) | Covered here | Stable |
| Audit Log API | Platform team | REST | Admin role (via API, any client) | Covered here | Stable |

---

## 4. API Contracts

One block per endpoint group. JSON everywhere except file uploads (multipart). Every error uses `{ "error": { "message", "code", "fields?" } }`, with `fields` on 422s.

### 4.1 Auth session — login, logout, change password, current user

| Field | Value |
|---|---|
| **Endpoints** | POST /auth/login · POST /auth/logout · POST /auth/change-password · GET /me |
| **Owning Service** | Platform team |
| **Auth Required** | Login: none. Logout, change-password, me: session cookie; any role; exempt from the forced-password-change gate |
| **Idempotent** | No, except GET /me (Yes) |
| **Rate Limit** | Login: 5 attempts per 15-minute window per account and client address (bucket keyed on the trimmed identifier, case-sensitive — trim-only, no case folding; spacing variants share one bucket, distinct case variants hold distinct buckets), with X-RateLimit-Limit, X-RateLimit-Remaining, and X-RateLimit-Reset headers; else None stated |

**Request**

```json
{
  "identifier": "string — required, login only; the caller's CompAss ID (school_id, all roles; format ^(ADM|TEA|STU)-[0-9]{4}-[0-9]{5}$); trimmed then matched case-sensitively (no email login exists)",
  "password": "string — required, login only",
  "current_password": "string — required, change-password only",
  "new_password": "string — required, change-password only; minimum 8 characters with mixed case plus numbers, must differ from current"
}
```

Logout and me send no body. All non-GET requests attach the CSRF token header.

**Response — 200**

```json
{
  "data": {
    "id": "integer — login and me only",
    "name": "string — login and me only",
    "school_id": "string — login and me only; CompAss ID (no email field exists)",
    "role": "string — login and me only",
    "must_change_password": "boolean — login and me only",
    "assignments": "array — me only, teacher",
    "enrollments": "array of { classroom_id, classroom_name, subject_id, section_id, school_year, joined_at } — me only, student (no subject_section_id)",
    "message": "string — logout and change-password only"
  }
}
```

Changing the password revokes every other session and keeps only the performing one.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Invalid credentials; missing or expired session | error envelope, code INVALID_CREDENTIALS / UNAUTHENTICATED |
| 403 | Account deactivated (login) | error envelope, code ACCOUNT_DEACTIVATED |
| 419 | Missing or stale CSRF token on non-GET requests | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing fields, weak new password, wrong current password (under fields.current_password) | error envelope, code VALIDATION_ERROR, with fields |
| 429 | Login attempt budget exhausted | error envelope, code RATE_LIMIT_EXCEEDED |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/auth/login with {"identifier": "TEA-7100-00001", "password": "..."} → 200 {"data": {"id": 7, "name": "...", "school_id": "TEA-7100-00001", "role": "Teacher", "must_change_password": false}}
```

Ref: backend/app/Http/Controllers/Auth/ProfileController.php.

---

### 4.2 Classroom join and lifecycle

| Field | Value |
|---|---|
| **Endpoints** | POST /classrooms/join · POST /teacher/classrooms · GET /teacher/classrooms (?school_year, ?semester_id canonical with ?term_id transition alias) · GET /teacher/classrooms/{id} · POST /teacher/classrooms/{id}/reset-key · POST /teacher/classrooms/{id}/toggle-join · POST /teacher/classrooms/{id}/archive · POST /teacher/classrooms/{id}/unarchive · GET /teacher/classrooms/{id}/people · GET /teacher/classrooms/{id}/competency-context · DELETE /teacher/classrooms/{id}/people/{studentId} · GET /admin/classrooms · GET /admin/classrooms/{id} · PATCH /admin/classrooms/{id} · POST /admin/classrooms/{id}/reset-key · POST /admin/classrooms/{id}/archive · POST /admin/classrooms/{id}/unarchive |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for teacher paths, role student for join, role admin for admin paths; forced-password-change gate applies |
| **Idempotent** | Join Yes (repeat returns already_joined true with 200); create and reset-key No; toggle-join, archive, and unarchive converge on the requested state |
| **Rate Limit** | Join: 20 per minute per student. Create and reset-key: 10 per minute per teacher or admin. Else None stated. Frequent joins or key rotations hit the cap, so wait briefly and retry. |

**Request**

```json
{
  "key": "string — required, max 20 characters (join only)",
  "subject_id": "integer — required (teacher create; must share grade_level_id with section_id, else 422)",
  "section_id": "integer — required (teacher create; must share grade_level_id with subject_id, else 422)",
  "school_year": "string — optional, YYYY-YYYY (create; defaults to current academic year)",
  "suffix": "string — optional, max 50 characters (create)",
  "is_join_enabled": "boolean — optional (toggle-join; also accepts enabled; omitted flips the current value)"
}
```

No `subject_section_id` exists on any request. Teacher scope is the Semester plus Grade Level plus Subject package derived from owned classrooms.

Reads, archive, unarchive, reset-key, and remove-student send no body. Student rooms reads and leave live alongside this group and send no body there.

**Response — 200 / 201**

```json
{
  "data": {
    "classroom": {
      "id": "integer",
      "teacher_id": "integer",
      "subject_id": "integer",
      "section_id": "integer",
      "school_year": "string",
      "name": "string",
      "suffix": "string or null",
      "join_key": "string",
      "join_key_display": "string",
      "is_join_enabled": "boolean",
      "archived_at": "ISO 8601 timestamp or null",
      "created_at": "ISO 8601 timestamp",
      "updated_at": "ISO 8601 timestamp"
    },
    "already_joined": "boolean — join only"
  }
}
```

Join returns 201 on first join and 200 with already_joined true on repeat. Create returns 201 with the classroom object directly in data. Every classroom shape carries teacher_name plus teacher_school_id on list and detail.

The people list here is the teacher read. It returns a data array of { id, student_id, student_name, school_id, joined_at } with no email, no learner_code, and no internal numbers beyond route keys. The learner names-only read lives alongside this group. Archive and unarchive return the full classroom object. Remove-student returns data.message, and student leave lives alongside this group.

`GET /teacher/classrooms/{id}/competency-context` returns `{ data: { classroom_id, subject_id, section_id, grade_level_id, grade_level, semester_id, semester } }` for the filtered picker (`GET /api/admin/competency-tags?subject_id&grade_level&semester`). Non-owners receive 403.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Not the classroom owner; student not enrolled where membership is required | error envelope, code FORBIDDEN / NOT_ENROLLED |
| 404 | Unknown classroom id; invalid join key | error envelope, code NOT_FOUND / KEY_INVALID |
| 409 | Duplicate classroom (same teacher + subject + section + school year) | error envelope, code DUPLICATE_CLASSROOM |
| 410 | Join key revoked; classroom archived; joining disabled | error envelope, code GONE / CLASSROOM_ARCHIVED / CLASSROOM_JOIN_DISABLED |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed key, subject_id, section_id, school_year, mismatched Semester + Grade Level + Subject package, or toggle flags | error envelope, code VALIDATION_ERROR, with fields |
| 429 | Join, create, or reset-key budget exhausted | error envelope, code RATE_LIMIT_EXCEEDED |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/classrooms/join with {"key": "K7Q2M9XD"} → 201 {"data": {"classroom": {"id": 3, "name": "...", "is_join_enabled": true}, "already_joined": false}}
```

Ref: backend/app/Services/ClassroomService.php; backend/app/Http/Controllers/Teacher/ClassroomController.php competencyContext; migration 2026_09_19_000001_restructure_semester_hierarchy.

---

### 4.3 Assignment submit and feedback

| Field | Value |
|---|---|
| **Endpoints** | POST /student/assignments/{id}/submit · GET /student/assignments/{id}/feedback · POST /teacher/submissions/{submissionId}/feedback |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role student for submit and feedback read, role teacher for feedback write; forced-password-change gate applies |
| **Idempotent** | Submit No; feedback write Yes (repeat overwrites the same text) |
| **Rate Limit** | None stated |

**Request**

Submit is multipart form data; feedback write is JSON:

```json
{
  "files": "file array — submit only; pdf, docx, pptx, xlsx, jpg, jpeg, png, zip; 15 MB max each",
  "feedback": "string — required, feedback write only"
}
```

The student feedback read sends no body.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — submission id (submit only, 201)",
    "submitted_at": "ISO 8601 timestamp — submit only",
    "is_late": "boolean — submit only",
    "file_count": "integer — submit only",
    "message": "string — feedback write only"
  }
}
```

The student feedback read returns the stored feedback detail; late hand-in still returns 201 with is_late true.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Assignment or submission outside the caller's classes | error envelope, code FORBIDDEN |
| 404 | Unknown assignment or submission; feedback read with no submission yet | error envelope, code NOT_FOUND |
| 409 | File too large or too many attachments | error envelope, code FILE_TOO_LARGE / TOO_MANY_ATTACHMENTS |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Disallowed file type or size; missing feedback text | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/student/assignments/12/submit with multipart files → 201 {"data": {"id": 44, "is_late": false, "file_count": 2}}
```

---

### 4.4 Assessment release, attempt, auto-save, and submit

| Field | Value |
|---|---|
| **Endpoints** | POST /teacher/assessments/{id}/release · POST /student/assessments/{id}/start · PUT /student/assessments/{id}/auto-save · PATCH /student/assessments/{id}/attempts/{attemptId}/response · POST /student/assessments/{id}/submit · GET /student/assessments/{id}/results · POST /teacher/submissions/{id}/score |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for release and scoring, role student for start, auto-save, patch, submit, and results; forced-password-change gate applies |
| **Idempotent** | Auto-save and per-question patch Yes (repeat overwrites); start, submit, release, and scoring No |
| **Rate Limit** | None stated |

**Request**

```json
{
  "responses": "map of item id to answer text — required on auto-save, optional on submit",
  "questionId": "integer — required, minimum 1 (patch only)",
  "response": "string — required, max 10000 characters (patch only)",
  "scores": "map of item id to numeric score, minimum 0 (teacher scoring only)"
}
```

Release, start, submit without answers, and results send no body.

**Response — 200**

```json
{
  "data": {
    "message": "string — release, auto-save, patch, and scoring",
    "status": "string — release returns released",
    "assessment_id": "integer — start only",
    "title": "string — start only",
    "time_limit": "integer or null — start only",
    "attempt_id": "integer — start only",
    "attempt_number": "integer — start only",
    "items": "array of { id, prompt, item_type, max_points, sort_order } — start only",
    "responses": "map of pre-filled answers — start only"
  }
}
```

Submit returns the scored submission result; results returns the student's released results.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Not the assessment owner; student not enrolled | error envelope, code FORBIDDEN / NOT_ENROLLED |
| 404 | Unknown assessment, attempt, or submission | error envelope, code NOT_FOUND |
| 409 | Assessment already released; attempt already in progress; already submitted; time limit expired; results not released; release with no items; scoring a non-pending submission, a non-subjective item, or exceeding an item maximum | error envelope, code ASSESSMENT_ALREADY_RELEASED / STUDENT_HAS_ACTIVE_ATTEMPT / ALREADY_SUBMITTED / TIME_LIMIT_EXPIRED / RESULTS_NOT_RELEASED / ASSESSMENT_HAS_NO_ITEMS / NOT_PENDING_GRADING / NOT_A_SUBJECTIVE_ITEM / SCORE_EXCEEDS_MAXIMUM |
| 410 | Classroom archived | error envelope, code CLASSROOM_ARCHIVED |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Malformed responses, questionId, response text, or scores | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
PUT /api/student/assessments/9/auto-save with {"responses": {"31": "B", "32": "True"}} → 200 {"data": {"message": "Auto-save successful."}}
```

---

### 4.5 Grading — manual, bulk, resubmit, result release, mastery reads

| Field | Value |
|---|---|
| **Endpoints** | POST /grades/manual · POST /grades/bulk · POST /assessments/{assessmentId}/attempts/{attemptId}/resubmit · POST /teacher/assessments/{id}/release-results · GET /mastery/records · GET /mastery/records/{studentId}/summary |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for manual, bulk, resubmit, and result release; roles teacher, student, and admin for mastery reads with students restricted to their own rows; forced-password-change gate applies |
| **Idempotent** | Writes No; mastery reads Yes |
| **Rate Limit** | None stated |

**Request**

```json
{
  "attempt_id": "integer — required (manual only)",
  "grade_entries": "array, minimum 1 — required (manual only); each entry { assessment_item_id required, score required minimum 0, max_score required minimum 0, feedback optional }",
  "is_draft": "boolean — optional (manual only; true saves a draft instead of a final grade)",
  "assessment_id": "integer — required (bulk only)",
  "grades": "array, 1 to 100 students — required (bulk only); each entry { student_id required, grade_entries array of 1 to 100 entries shaped as above }",
  "reason": "string — required, 1 to 1000 characters (resubmit only)"
}
```

Result release and mastery reads send no body; mastery reads accept optional student_id, competency_id, and subject_id query filters (no subject_section_id).

**Response — 200**

```json
{
  "data": {
    "attempt_id": "integer — manual only",
    "grader_id": "integer — manual only",
    "graded_at": "ISO 8601 timestamp or null — manual only",
    "status": "string — manual only, e.g. scored or pending_grading",
    "message": "string — result release only",
    "results_released_at": "ISO 8601 timestamp — result release only"
  }
}
```

Bulk grading returns per-student grading results; resubmit returns the resubmission record (reason 1–1000 chars via API); mastery reads return mastery rows or the student summary (available via API to the authorized role; per-group class reports available via API).

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Student requesting another student's mastery rows | error envelope, code FORBIDDEN |
| 404 | Unknown attempt, assessment, or student | error envelope, code NOT_FOUND |
| 409 | Grading-state conflict, e.g. assessment not released or submissions still pending grading at result release | error envelope, code ASSESSMENT_NOT_RELEASED / PENDING_GRADING_BLOCK |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed grade entries, grades array over 100 entries, or reason | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/grades/manual with {"attempt_id": 55, "grade_entries": [{"assessment_item_id": 31, "score": 8, "max_score": 10}]} → 200 {"data": {"attempt_id": 55, "status": "scored"}}
```

---

### 4.6 Competency import and error reports

| Field | Value |
|---|---|
| **Endpoints** | GET /admin/import/templates/{type} · POST /admin/import/competency-tags · GET /admin/import/error-reports/{token} · GET /admin/competency-tags (catalog read per ADR-023) |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on the upload; role admin on all four; forced-password-change gate applies |
| **Idempotent** | No (import writes rows; each error report downloads exactly once); catalog read Yes |
| **Rate Limit** | Import upload: 10 per minute per user; catalog read: None stated; else None stated |

**Request**

Template and error-report downloads send no body. The import is multipart form data:

```json
{
  "type": "string path parameter — competency_tags or student_enrollments (workbook selector; enrollment columns live alongside this group)",
  "file": "file — required, .xlsx or .xls, 15 MB max (import only)",
  "token": "string path parameter — single-use, expires after 7 days (error report only)"
}
```

**Response — 200**

The template and the error report return the .xlsx file bytes directly. The import returns JSON:

```json
{
  "data": {
    "imported_rows": "integer",
    "failed_rows": "integer",
    "has_error_report": "boolean",
    "error_report_url": "string or null"
  }
}
```

Failed rows never block valid rows; the error report lists each failed row number with its reason. Rules as in §4.16; this block only adds the shared template and error-report paths, the type value selecting the workbook, and the size-layer split. Preview and confirm for those flows live in §4.16. Oversize rejected by request validation returns 422, while service-level too-large returns 409.

**Catalog read — GET /admin/competency-tags (ADR-023)**

No body. Query parameters only: `page` and `per_page` (default 15, ceiling 100) plus optional `search`, `subject_id`, `grade_level` (7-12), and `semester` ('1'/'2'/'3'). Search matches human code and descriptor text with LIKE wildcard escaping. It needs at least 2 characters, and digits-only search is rejected 422.

Response is the paged envelope `{data: [...tag rows...], meta: {page, per_page, total}}` with server counts in `meta.total`. Each row carries `{ id, code, descriptor, subject_id, subject_name, subject_code, grade_level, semester }`. Non-admin callers receive 403 FORBIDDEN. Bad paging, short or digits-only search, and out-of-range filters receive 422 VALIDATION_ERROR with fields.

Import template headers are `code, descriptor, subject_id, grade_level, semester`. Rows whose triple mismatches the scoped subject are rejected per-row.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Non-admin caller | error envelope, code FORBIDDEN |
| 409 | Unknown template type; service-level file too large | error envelope, code INVALID_TEMPLATE_TYPE / FILE_TOO_LARGE |
| 410 | Error report unknown, already consumed, or past its expiry | error envelope, code GONE |
| 419 | Missing or stale CSRF token on the upload | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Wrong file type; oversize rejected at validation; row-count ceiling exceeded; malformed rows | error envelope, code VALIDATION_ERROR / TOO_MANY_ROWS, with fields |
| 429 | Import upload budget exhausted | error envelope, code RATE_LIMIT_EXCEEDED |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/admin/import/competency-tags with multipart file → 200 {"data": {"imported_rows": 120, "failed_rows": 3, "has_error_report": true}}
```

Ref: backend/app/Http/Requests/Admin/ListCompetencyTagsRequest.php; backend/app/Http/Controllers/Admin/BatchImportController.php indexCompetencyTags; backend/app/Services/BatchImportService.php.

---

### 4.7 AI explanations, learning materials, moderation, and status

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/learning-materials · POST /teacher/learning-materials · PUT /teacher/learning-materials/{id} · DELETE /teacher/learning-materials/{id} · GET /student/assessments/{id}/explanations · POST /student/assessments/{id}/explanations/generate · GET /student/explanations/{id} · POST /student/explanations/{id}/explain-further · GET /teacher/moderation-log · GET /teacher/moderation-log/{id} · POST /teacher/moderation-log/{id}/flag · POST /teacher/moderation-log/{id}/note · POST /teacher/moderation-log/{id}/disable-explain-further · GET /ai/status |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for materials and moderation, role student for explanations, roles teacher, student, and admin for status; forced-password-change gate applies except on status |
| **Idempotent** | Reads Yes; generation, follow-ups, and moderation writes No |
| **Rate Limit** | Explanation generation: 10 per minute per student. Explain-further: 30 per minute per client address. Moderation writes: 60 per minute per teacher. Else None stated. These caps fill fast, so wait briefly and retry. |

**Request**

Material upload is multipart form data; all else is JSON:

```json
{
  "subject_id": "integer — required (material create and material list query; no subject_section_id exists)",
  "competency_id": "integer — required (material create; must match the subject triple, else 422 COMPETENCY_MISMATCH), optional query (material list)",
  "title": "string — required max 255 chars on create, optional on update",
  "file": "file — required pdf or docx max 15 MB on create, optional on update",
  "item_id": "integer — required (explain-further only)",
  "note": "string — optional on flag, required on note"
}
```

Explanation list, explanation detail, generation, moderation-log reads, disable-explain-further, deletes, and status send no body. Moderation-log list accepts optional assessment_id, section_id, page, and per_page query parameters.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — material, explanation, and moderation rows",
    "subject_id": "integer — material only",
    "competency_id": "integer — material only",
    "original_filename": "string — material only",
    "mime_type": "string — material only",
    "file_size": "integer — material only",
    "created_at": "ISO 8601 timestamp — material only",
    "message": "string — deletes, flag, note, and disable actions",
    "status": "string — status returns available",
    "checked_at": "ISO 8601 timestamp — status only"
  }
}
```

Material create returns 201 with the material object. The explanation list returns stored explanations and is empty until the student explicitly generates them; reading never generates. Explanation detail carries follow-up eligibility, the teacher note, and remaining turns. Generation returns the explanation list in the same shape as the list read. When the AI service is down, status reports unavailable through the service-unavailable error.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 400 | Material list without subject_id | error envelope, code BAD_REQUEST |
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Teacher does not own a classroom for the subject; explanation outside the caller's scope | error envelope, code SUBJECT_NOT_ASSIGNED / FORBIDDEN |
| 404 | Unknown material, explanation, assessment, or moderation row; cross-scope access reported as not found | error envelope, code NOT_FOUND |
| 409 | Business-rule conflict on write | error envelope with the stable rule code |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed fields; competency triple mismatch (422 COMPETENCY_MISMATCH); explain-further rejected for subjective items, exhausted turns, or teacher-disabled follow-ups | error envelope, code VALIDATION_ERROR / COMPETENCY_MISMATCH / EXPLAIN_FURTHER_SUBJECTIVE_ITEM / EXPLAIN_FURTHER_TURN_LIMIT / EXPLAIN_FURTHER_DISABLED, with fields where applicable |
| 429 | Generation, chat, or moderation-write budget exhausted | error envelope, code RATE_LIMIT_EXCEEDED |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/student/assessments/9/explanations/generate → 200 {"data": [{...stored explanations...}]}
```

---

### 4.8 Audit log reads

| Field | Value |
|---|---|
| **Endpoints** | GET /admin/audit-logs · GET /admin/audit-logs/entity · GET /admin/audit-logs/user/{userId} |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie; role admin; forced-password-change gate applies |
| **Idempotent** | Yes |
| **Rate Limit** | None stated |

**Request**

No body. Query parameters only:

```json
{
  "event_type": "string — optional",
  "user_id": "integer — optional (index only)",
  "auditable_type": "string — required on entity, optional on index",
  "auditable_id": "integer — required on entity, optional on index",
  "from": "date — optional",
  "to": "date — optional, must not precede from",
  "page": "integer — optional, minimum 1",
  "per_page": "integer — optional"
}
```

**Response — 200**

```json
{
  "data": [
    {
      "id": "integer",
      "user_id": "integer or null",
      "user_name": "string or null",
      "event_type": "string",
      "description": "string",
      "auditable_type": "string or null",
      "auditable_id": "integer or null",
      "ip_address": "string or null — masked for rows past the anonymization window",
      "created_at": "ISO 8601 timestamp"
    }
  ],
  "meta": "object — page and total counts"
}
```

The trail is append-only; these endpoints never write.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Non-admin caller | error envelope, code FORBIDDEN |
| 404 | Unknown user id | error envelope, code NOT_FOUND |
| 422 | Missing entity keys; malformed filters; to before from | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/admin/audit-logs?event_type=login&page=1 → 200 {"data": [{...audit rows...}], "meta": {...}}
```

---

### 4.9 Admin users, org structure, and teacher assignments

| Field | Value |
|---|---|
| **Endpoints** | GET /admin/users · POST /admin/users · GET /admin/users/{id} · PUT /admin/users/{id} · POST /admin/users/{id}/deactivate · POST /admin/users/{id}/reactivate · POST /admin/users/{id}/reset-password · GET /admin/school-years · POST /admin/school-years · GET /admin/school-years/{schoolYearId}/semesters (canonical; legacy GET .../terms is a deprecated alias) · POST /admin/school-years/{schoolYearId}/semesters (canonical; legacy POST .../terms is a deprecated alias) · GET /admin/semesters/{semesterId}/grade-levels (legacy GET /admin/terms/{termId}/grade-levels is a deprecated alias) · POST /admin/semesters/{semesterId}/grade-levels (legacy POST /admin/terms/{termId}/grade-levels is a deprecated alias) · POST /admin/semesters/{semesterId}/purge (legacy POST /admin/terms/{termId}/purge is a deprecated alias) · GET /admin/grade-levels/{gradeLevelId}/sections · POST /admin/grade-levels/{gradeLevelId}/sections · GET /admin/subjects (?grade_level_id) · POST /admin/subjects (grade_level_id required) · PUT /admin/subjects/{id} · DELETE /admin/subjects/{id} · GET /admin/sections/{sectionId}/assignments (classroom-derived read) · POST /admin/sections/{sectionId}/assignments (410 GONE stub) · DELETE /admin/sections/{sectionId}/assignments/{assignmentId} (410 GONE stub) · PATCH /admin/teacher-assignments/{id} (410 GONE stub) · GET /admin/teacher-assignments (classroom-derived filtered read) · POST /admin/teacher-assignments (410 GONE stub) · DELETE /admin/teacher-assignments/{id} (410 GONE stub) · GET /admin/subjects (410 GONE stub) |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role admin; forced-password-change gate applies |
| **Idempotent** | Reads Yes; creates No; updates Yes (repeat overwrites); deletes converge; purge honors a dry-run flag |
| **Rate Limit** | None stated |

**Request**

```json
{
  "name": "string — required (user create and update; update is name-only, school_id immutable)",
  "role": "string — required (user create only; Admin, Teacher, or Student; prohibited on update)",
  "semester": "string — required ('1'/'2'/'3'; semester create)",
  "start_date": "date — required (semester create)",
  "end_date": "date — required (semester create; on or after start_date)",
  "grade_level": "integer — required 7–12 (grade-level create)",
  "grade_level_id": "integer — required (subject create; scopes the subject; optional on subject update)",
  "teacher_id": "integer — required (teacher-assignment list filter only; writes are 410 GONE stubs)",
  "subject_id": "integer — optional (teacher-assignment list filter)",
  "section_id": "integer — optional (teacher-assignment list filter)",
  "school_year": "string — optional YYYY-YYYY (teacher-assignment list filter)",
  "code": "string — optional (subject create and update; unique per grade_level_id)",
  "description": "string — optional (subject create and update)",
  "dry_run": "string — optional query 1 (purge only)"
}
```

No `subject_section_id` exists on any request. User create takes {name, role} only and user update takes {name} only, with school_id immutable and role prohibited on update. Manual school_id plus legacy keys (email, identifier, identifierType, learner_code, group/group_assignment, year_level, password/password_hash, is_active/status, must_change_password, full_name, classroom_id, id) and any other unknown key are refused 422 and never silently ignored. Semester vocabulary only: `semester` and `semester_id` are canonical, while `term_id` is accepted only as a transition alias on teacher classroom index, assignment and assessment creates, and the deprecated `/terms` routes.

Reads send no body. List reads accept page and per_page plus teacher_id, subject_id, section_id, school_year, grade_level_id, and search filters where applicable; search matches human names and CompAss IDs only and never matches internal numbers. Semester creates carry `semester` + `name` + dates; grade-level creates carry `grade_level` 7–12 under one `semester_id`; subject creates carry `grade_level_id` with per-grade code uniqueness.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — user and assignment rows",
    "name": "string — user and org rows",
    "school_id": "string — user rows; CompAss ID (no email field exists)",
    "role": "string — user rows",
    "status": "string — user rows",
    "must_change_password": "boolean — user rows",
    "temporary_password": "string — user store and reset-password only",
    "semester": "string '1'/'2'/'3' — semester rows",
    "semester_id": "integer — semester/grade rows",
    "grade_level_id": "integer — subject rows",
    "teacher_id": "integer — classroom-derived teacher-assignment rows",
    "subject_id": "integer — classroom-derived teacher-assignment rows",
    "section_id": "integer — classroom-derived teacher-assignment rows",
    "school_year": "string — classroom-derived teacher-assignment rows",
    "message": "string — deactivate, reactivate, subject delete"
  },
  "meta": "object — page and total counts on paginated lists"
}
```

User store returns 201 with the user object plus the temporary password. The server auto-generates the CompAss ID with collision retry. Reset-password is the sole recovery channel. It returns the message plus a new temporary password to the calling admin only, sets must_change_password=true on the target, revokes the target's other sessions, preserves deactivation per FR-004, writes an audit row, and refuses number-only or weak input with 422. The recipient follows first-login parity, so the gate blocks every gated action except sign-out, change, me, and status until POST /auth/change-password succeeds.

Org and subject stores return 201 with the created node. The section-assignments index (`GET /api/admin/sections/{sectionId}/assignments`) returns an unpaginated classroom-derived data array. The teacher-assignments index (`GET /api/admin/teacher-assignments`) returns paginated classroom-derived rows filtered by teacher_id, subject_id, section_id, school_year, and search, with the Semester plus Grade Level plus Subject package. `GET /api/admin/subjects` always answers 410 `GONE`. Section and teacher-assignment writes plus PATCH always answer 410 `GONE`. All other lists return data with meta.

Purge (`POST /api/admin/semesters/{semesterId}/purge` with legacy `/terms` alias) returns the message plus purged counts and the dry-run flag when requested, with `semester_id` plus a `term_id` transition alias.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Non-admin caller | error envelope, code FORBIDDEN |
| 404 | Unknown user, org node, subject, section, or assignment id | error envelope, code NOT_FOUND |
| 409 | Duplicate semester under the school year; duplicate grade level under the semester; duplicate subject code under the grade level; delete blocked by an existing classroom or Competency reference | error envelope, code SEMESTER_ALREADY_EXISTS / GRADE_LEVEL_ALREADY_EXISTS / VALIDATION_ERROR (code) / SUBJECT_HAS_ACTIVE_ASSIGNMENTS |
| 410 | Removed assignment/subject surface (section-assignment writes, teacher-assignment writes/PATCH, subjects index) | error envelope, code GONE |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed names, roles, prohibited legacy/unknown keys on user create/update, dates, semester ('1'/'2'/'3'), grade levels (7–12), school years, or grade_level_id | error envelope, code VALIDATION_ERROR / INVALID_SEMESTER / INVALID_GRADE_LEVEL, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/admin/users with {"name": "A. Teacher", "role": "Teacher"} → 201 {"data": {"id": 9, "name": "A. Teacher", "school_id": "TEA-7100-00009", "role": "Teacher", "temporary_password": "..."}}
```

Ref: backend/app/Http/Requests/Admin/StoreUserRequest.php; UpdateUserRequest.php; UserService::createAccount; backend/app/Services/OrgStructureService.php; backend/routes/api.php; backend/app/Models/GradeLevel.php; backend/app/Http/Controllers/Admin/AssignmentController.php; backend/app/Http/Controllers/Admin/SubjectSectionController.php.

---

### 4.10 Announcements

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/announcements · POST /teacher/announcements · PUT /teacher/announcements/{id} · DELETE /teacher/announcements/{id} · GET /student/announcements · GET /student/announcements/{id}/download/{attachmentId} |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for teacher paths, role student for student paths; forced-password-change gate applies |
| **Idempotent** | Reads and downloads Yes; creates No; updates Yes (repeat overwrites); deletes converge |
| **Rate Limit** | None stated |

**Request**

Create is multipart form data; update is multipart only when replacing attachments, else JSON:

```json
{
  "subject_id": "integer — required on create (no subject_section_id exists)",
  "semester_id": "integer — optional (canonical Semester scope; term_id accepted as transition alias)",
  "title": "string — required max 255 characters on create",
  "body": "string — required on create",
  "attachments": "file array — optional; pdf, docx, pptx, xlsx, jpg, jpeg, png, zip; 15 MB max each"
}
```

Reads, deletes, and the attachment download send no body. Lists accept page and per_page.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — announcement rows",
    "subject_id": "integer — announcement rows",
    "title": "string — announcement rows",
    "body": "string — announcement rows",
    "has_attachments": "boolean — announcement rows",
    "created_at": "ISO 8601 timestamp — announcement rows",
    "message": "string — update and delete only"
  },
  "meta": "object — page and total counts on lists"
}
```

Create returns 201 with the announcement object. Lists return data with meta. The attachment download returns the file bytes directly.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Announcement outside the caller's scope | error envelope, code FORBIDDEN |
| 404 | Unknown announcement or attachment id | error envelope, code NOT_FOUND |
| 409 | Archived-classroom conflict; file too large or too many attachments | error envelope, code CLASSROOM_ARCHIVED / FILE_TOO_LARGE / TOO_MANY_ATTACHMENTS |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed subject_id, semester scope, title, body, or attachments | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/teacher/announcements with multipart title, body, and attachments → 201 {"data": {"id": 11, "title": "...", "has_attachments": true}}
```

---

### 4.11 Assignments — full CRUD, submissions, and file downloads

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/assignments · POST /teacher/assignments · GET /teacher/assignments/{id} · PUT /teacher/assignments/{id} · DELETE /teacher/assignments/{id} · POST /teacher/assignments/{id}/confirm-delete · GET /teacher/assignments/{id}/submissions · GET /teacher/submissions/{submissionId} · GET /student/assignments · GET /student/assignments/{id} · GET /teacher/assignments/{id}/attachments/{attachmentId}/download · GET /student/assignments/{id}/attachments/{attachmentId}/download · GET /teacher/submissions/{submissionId}/files/{fileId}/download · GET /student/submissions/{submissionId}/files/{fileId}/download |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for teacher paths, role student for student paths; forced-password-change gate applies |
| **Idempotent** | Reads and downloads Yes; creates No; updates Yes (repeat overwrites); deletes converge with confirmation when submissions exist |
| **Rate Limit** | None stated |

Student submit and feedback use the same student and teacher auth respectively, with multipart files on submit and JSON feedback on write.

**Request**

Create is multipart form data; update and confirms are JSON:

```json
{
  "subject_id": "integer — required (teacher create; no subject_section_id exists)",
  "title": "string — required on create",
  "description": "string — optional",
  "due_date": "ISO 8601 timestamp — optional",
  "attachments": "file array — optional on create"
}
```

Reads, deletes, confirms, and downloads send no body. Lists accept page and per_page, with an optional section filter on the teacher index. Assignment creates accept semester_id for canonical Semester scope, with term_id accepted as transition alias.

Ref: backend/app/Http/Requests/Teacher/StoreAssignmentRequest.php.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — assignment and submission rows",
    "classroom_id": "integer — classroom-scoped rows",
    "title": "string — assignment rows",
    "description": "string or null — assignment rows",
    "due_date": "ISO 8601 timestamp or null — assignment rows",
    "submission_count": "integer — list and delete-confirm rows",
    "confirmation_required": "boolean — delete with submissions only",
    "message": "string — update, delete, and confirm-delete"
  },
  "meta": "object — page and total counts on lists"
}
```

Create returns 201 with the assignment object. Detail reads return the assignment or submission object. The submissions list returns paginated rows carrying the student identity, submit time, lateness, and feedback presence. Deletes without submissions return the message; deletes with submissions return the confirmation flag plus the count until confirmed. Downloads return the file bytes directly.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Assignment or submission outside the caller's classes; student not enrolled | error envelope, code FORBIDDEN / NOT_ENROLLED |
| 404 | Unknown assignment, submission, attachment, or file id | error envelope, code NOT_FOUND |
| 409 | Business-rule conflict on write, including duplicate submit and file-count or size guards | error envelope, code ALREADY_SUBMITTED / FILE_TOO_LARGE / TOO_MANY_FILES / TOO_MANY_ATTACHMENTS |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed title, description, due date, or files | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/teacher/assignments/12/submissions?page=1 → 200 {"data": [{...submission rows...}], "meta": {...}}
```

---

### 4.12 Assessments — full CRUD, items, pending grading, and file downloads

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/assessments · POST /teacher/assessments · GET /teacher/assessments/{id} · PUT /teacher/assessments/{id} · DELETE /teacher/assessments/{id} · POST /teacher/assessments/{id}/items · PUT /teacher/items/{id} · DELETE /teacher/items/{id} · GET /teacher/pending-grading · GET /teacher/submissions/{id}/pending-items · GET /student/assessments · GET /student/assessments/{id} · GET /teacher/items/{itemId}/attachments/{attachmentId}/download · GET /student/items/{itemId}/attachments/{attachmentId}/download |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for teacher paths, role student for student paths; forced-password-change gate applies |
| **Idempotent** | Reads and downloads Yes; creates No; updates Yes (repeat overwrites); deletes converge |
| **Rate Limit** | None stated |

Release, attempt, auto-save, submit, results, and scoring use the same teacher and student auth and are covered alongside this group.

**Request**

Item create is multipart form data; all else is JSON:

```json
{
  "subject_id": "integer — required (assessment create; no subject_section_id exists)",
  "title": "string — required on create",
  "description": "string — optional",
  "type": "string — optional on create",
  "time_limit": "integer or null — optional",
  "availability_starts_at": "ISO 8601 timestamp — optional",
  "availability_ends_at": "ISO 8601 timestamp — optional",
  "item_type": "string — required (item create)",
  "prompt": "string — required (item create)",
  "max_points": "number — required (item create)",
  "correct_answer": "string — optional (item create and update)",
  "competency_tag_id": "integer — required (item create)"
}
```

Reads, deletes, and downloads send no body. The teacher index accepts section, status, page, and per_page filters, and pending grading accepts an optional assessment filter with page and per_page. Assessment creates accept semester_id for canonical Semester scope, with term_id accepted as transition alias. Every item competency_tag_id must match the classroom (subject_id, grade_level, semester) triple. Mismatches are rejected 422 COMPETENCY_MISMATCH.

Ref: backend/app/Http/Requests/Teacher/StoreAssessmentRequest.php; backend/app/Services/AssessmentService.php assertCompetencyMatchesAssessment.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — assessment, item, and submission rows",
    "title": "string — assessment rows",
    "type": "string — assessment rows",
    "item_count": "integer — assessment create only",
    "item_type": "string — item rows",
    "prompt": "string — item rows",
    "max_points": "number — item rows",
    "sort_order": "integer — item rows",
    "has_attachments": "boolean — item rows",
    "message": "string — update and delete only"
  },
  "meta": "object — page and total counts on paginated lists"
}
```

Assessment and item creates return 201 with the created object. Teacher detail returns the full assessment with its items. Pending grading returns paginated submission rows carrying assessment, student, attempt, and submit-time keys. Pending-items returns the submission with its pending items. The student index returns an unpaginated data array; student detail returns the assessment. Downloads return the file bytes directly.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Assessment outside the caller's scope; student not enrolled | error envelope, code FORBIDDEN / NOT_ENROLLED |
| 404 | Unknown assessment, item, submission, or attachment id | error envelope, code NOT_FOUND |
| 409 | Business-rule conflict on write | error envelope with the stable rule code |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed assessment or item fields; competency (subject_id, grade_level, semester) triple mismatch vs the classroom scope | error envelope, code VALIDATION_ERROR / COMPETENCY_MISMATCH, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/teacher/pending-grading?assessment_id=9 → 200 {"data": [{...pending rows...}], "meta": {...}}
```

---

### 4.13 Competency mapping

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/not-competent-flags · GET /teacher/competency-summary · GET /teacher/sections/{sectionId}/class-level-report · GET /admin/competency-summary |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie; role teacher for teacher paths, role admin for the admin path; forced-password-change gate applies |
| **Idempotent** | Yes |
| **Rate Limit** | None stated |

**Request**

No body. Query parameters only:

```json
{
  "subject_id": "integer — optional (teacher flags and summary scope; no subject_section_id exists)",
  "grade_level_id": "integer — optional (admin scope)",
  "semester_id": "integer (canonical; legacy term_id accepted as transition alias) — optional (admin scope)"
}
```

**Response — 200**

```json
{
  "data": "array — flag, summary, or class-level report rows"
}
```

Flags list students below mastery on recorded assessments (optional ?classroom_id or ?subject_id teacher scope via classroom ownership). Summaries aggregate per-competency mastery and remediation frequency across the teacher's classrooms (optional ?classroom_id / ?subject_id), or across the admin scope (?subject_id, ?grade_level_id, ?semester_id with legacy ?term_id alias). The class-level report carries per-competency mastered versus not-mastered distribution for the section.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Teacher without classroom ownership in the requested subject or section | error envelope, code SUBJECT_NOT_ASSIGNED |
| 404 | Unknown subject or section id | error envelope, code NOT_FOUND |
| 422 | Malformed scope filters | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/teacher/not-competent-flags → 200 {"data": [{...flag rows...}]}
```

---

### 4.14 Analytics

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/dashboard/heatmap · GET /teacher/dashboard/gap-report · GET /teacher/dashboard/trends · GET /teacher/dashboard/student-drill-down · GET /admin/dashboard/school-wide-overview · GET /student/dashboard/mastery-history |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie; role teacher for teacher paths, role admin for the admin path, role student for mastery history; forced-password-change gate applies |
| **Idempotent** | Yes |
| **Rate Limit** | None stated |

**Request**

No body. Query parameters only:

```json
{
  "subject_id": "integer — required (teacher reads; no subject_section_id exists)",
  "assessment_id": "integer — optional (heatmap scope)",
  "group_by": "string — optional student or section (gap report)",
  "competency_code": "string — optional (trends filter)",
  "student_id": "integer — required (drill-down)",
  "competency_id": "integer — optional (mastery history filter)"
}
```

**Response — 200**

```json
{
  "data": "object or array — heatmap, gap, trend, drill-down, overview, or history payload"
}
```

Heatmap carries per-competency mastery rates for the subject. Gap report carries competencies below mastery grouped by student or section. Trends carry mastery rates over released recorded assessments. Drill-down carries per-competency history plus flags for one student. The admin overview carries school-wide mastery across grade levels and subjects. Past scores history is self-scoped to the student. It shows what was scored before, and reading it never creates new AI explanations.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 400 | Missing required subject, student, or scope keys | error envelope, code BAD_REQUEST |
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Teacher without classroom ownership for the requested subject | error envelope, code SUBJECT_NOT_ASSIGNED |
| 404 | Unknown subject, student, or assessment scope | error envelope, code NOT_FOUND |
| 422 | Malformed group_by, competency, or scope filters | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/teacher/dashboard/heatmap?subject_id=4 → 200 {"data": {...heatmap rows...}}
```

---

### 4.15 Classroom-scoped content, stream, classwork, and heatmap

| Field | Value |
|---|---|
| **Endpoints** | GET /teacher/classrooms/{classroomId}/announcements · POST /teacher/classrooms/{classroomId}/announcements · GET /teacher/classrooms/{classroomId}/assignments · POST /teacher/classrooms/{classroomId}/assignments · GET /teacher/classrooms/{classroomId}/assessments · POST /teacher/classrooms/{classroomId}/assessments · GET /teacher/classrooms/{classroomId}/competency-summary · GET /teacher/classrooms/{classroomId}/heatmap · GET /student/classrooms/{id}/stream · GET /student/classrooms/{id}/classwork |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role teacher for teacher paths, role student for student paths; forced-password-change gate applies |
| **Idempotent** | Reads Yes; creates No |
| **Rate Limit** | None stated |

**Request**

Creates are multipart where attachments apply, else JSON:

```json
{
  "title": "string — required on create",
  "body": "string — required (announcement create)",
  "description": "string — optional (assignment and assessment creates)",
  "due_date": "ISO 8601 timestamp — optional (assignment create)",
  "type": "string — optional (assessment create)",
  "time_limit": "integer or null — optional (assessment create)",
  "subject_id": "integer — optional; must match the classroom when given (no subject_section_id exists)",
  "semester_id": "integer — optional; must match the classroom Semester when given (term_id accepted as transition alias)",
  "assessment_id": "integer — optional query (heatmap scope)"
}
```

Reads send no body. Lists accept page and per_page.

**Response — 200 / 201**

```json
{
  "data": "object or array — announcement, assignment, assessment, stream, classwork, or heatmap payload",
  "meta": "object — page and total counts on paginated lists"
}
```

Teacher creates return 201 with the created object. Classroom-scoped creation: announcement, assignment, assessment, and material creation requires classroom scope. Teacher lists and the student stream and classwork return data with meta. Stream carries the classroom announcements. Classwork carries combined assignment and assessment items with kind markers ordered by due and creation times. The classroom heatmap and competency-summary carry per-competency mastery rates scoped strictly to the classroom.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Not the classroom owner; student not enrolled | error envelope, code FORBIDDEN / NOT_ENROLLED |
| 404 | Unknown classroom id | error envelope, code NOT_FOUND |
| 409 | Write into an archived classroom | error envelope, code CLASSROOM_ARCHIVED |
| 410 | Read against an archived classroom where reads are blocked | error envelope, code CLASSROOM_ARCHIVED |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed title, body, description, dates, or mismatched subject/semester scope | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/student/classrooms/3/classwork?page=1 → 200 {"data": [{...assignment and assessment items...}], "meta": {...}}
```

---

### 4.16 Bulk student enrollment + bulk teacher creation — full_name-only sheets with preview, confirm, per-row outcome, and error sheet

> Note: the #31 numbering skip is doc-only — preview/confirm covers it.

| Field | Value |
|---|---|
| **Endpoints** | POST /admin/import/student-enrollments/preview · POST /admin/import/student-enrollments/confirm · POST /admin/import/teacher-applications/preview · POST /admin/import/teacher-applications/confirm |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on the upload and confirm; role admin; forced-password-change gate applies |
| **Idempotent** | No (preview mints a single-use token; confirm writes rows) |
| **Rate Limit** | Import upload: 10 per minute per user; else None stated |

**Request**

Preview is multipart form data; confirm is JSON. The workbook template and the error-report download use the template and error-report paths owned in §4.6 and send no body there:

```json
{
  "file": "file — required, .xlsx or .xls, 15 MB max, 5000-row ceiling (preview only)",
  "preview_token": "string — required, single-use, expires after 60 minutes, length-bounded min:32 max:128 (confirm only)"
}
```

The workbook template branch selects columns by slug: `competency_tags` serves the competency-tag headers (`code, descriptor, subject_id, grade_level, semester`) while `student_enrollment`, `student_enrollments`, `student-enrollments`, `teacher_application`, `teacher_applications`, and `teacher-applications` all serve the single enrollment header `full_name` (case-insensitive, order-insignificant, extras ignored so legacy multi-column sheets still parse — extra columns are ignored and IDs are never read from the file; missing `full_name` header yields 422).

Each workbook row carries full_name only. Every non-blank name is valid. Blank names and names over 255 chars are invalid with a stated reason.

The server auto-generates a fresh CompAss ID per row at confirm time, STU- for student bulk and TEA- for teacher bulk, with per-row retry on ID collision. It always creates new accounts and never matches existing users by name, since names are not unique. In-file exact full_name repeats are allowed as distinct accounts. Valid rows repeating a name already seen in the sheet are counted as duplicate_matches for information only.

No group or year columns exist. Group, year, learner_code, school_id, email, and identifier keys are refused and never retained. Classroom placement happens only via hand place or move (§4.17) or join (§4.2). Saving the sheet only creates new accounts with fresh CompAss IDs and never places learners into classrooms.

**Response — 200**

Preview and confirm return JSON. The workbook template and the error report return the .xlsx file bytes directly through the template and error-report paths owned in §4.6:

```json
{
  "data": {
    "preview_token": "string — preview only",
    "total_rows": "integer — preview only",
    "valid_rows": "integer — preview only",
    "invalid_rows": "integer — preview only",
    "duplicate_matches": "integer — preview only, in-file valid exact full_name repeats (informational; each repeat still creates its own account — never a database match)",
    "expires_at": "ISO 8601 timestamp — preview only",
    "imported_rows": "integer — confirm only, fresh accounts created (accounts only, explicitly NOT placements)",
    "updated_rows": "integer — confirm only, always 0 (no matching in full_name-only mode; kept for contract stability)",
    "failed_rows": "integer — confirm only, rejected rows (blank/overlong names + save failures)",
    "has_error_report": "boolean — confirm only",
    "error_report_url": "string or null — confirm only"
  }
}
```

Preview performs no writes. Confirm consumes the preview token under a lock claim, with a type pin and an ownership check. A student token cannot confirm as teacher and vice versa, and that mismatch is treated as GONE. A different admin confirming gets 403 FORBIDDEN, and the token survives for its rightful owner. The error-report download checks ownership the same way.

Confirm counts track accounts, not placements. Imported rows are fresh accounts created, updated rows stay 0, and failed rows are rejected rows. Each row saves fully or not at all. Failed rows never block valid rows. The error report is single-use with 7-day expiry, with Row, full_name, and Reason columns plus a Help sheet, and it lists each failed row number with its reason. Counts reconcile, so imported plus updated plus failed equals total. Confirm with zero valid rows succeeds with zero counts and no error report.

The flow requires explicit confirmation of the preview counts. Expired previews return 410, so the caller uploads again.

Ref: migration 2026_09_21_000001_fullname_only_bulk; Cache::lock token claim per ADR-023.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Non-admin caller; non-owner preview access or error-report download (token preserved) | error envelope, code FORBIDDEN |
| 409 | Service-level file too large | error envelope, code FILE_TOO_LARGE |
| 410 | Preview token unknown, already consumed, or past its expiry | error envelope, code GONE |
| 419 | Missing or stale CSRF token on the upload and confirm | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Wrong file type; oversize rejected at validation; row-count ceiling exceeded; malformed rows; missing or stale preview token | error envelope, code VALIDATION_ERROR / TOO_MANY_ROWS, with fields |
| 429 | Import upload budget exhausted | error envelope, code RATE_LIMIT_EXCEEDED |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/admin/import/student-enrollments/preview with multipart file → 200 {"data": {"total_rows": 240, "valid_rows": 238, "invalid_rows": 2, "duplicate_matches": 12}}
```

---

### 4.17 Hand placement and move of a single learner — always fresh account, history-logged

| Field | Value |
|---|---|
| **Endpoints** | POST /admin/enrollments/place · POST /admin/enrollments/{id}/move |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header on writes; role admin; forced-password-change gate applies |
| **Idempotent** | No (each place and move appends a history row) |
| **Rate Limit** | None stated |

**Request**

```json
{
  "full_name": "string — required (place only; trimmed; each place creates a new account — reusing a name creates a duplicate account, never a match)",
  "classroom_id": "integer — required (place body and move body; destination on move)"
}
```

Place accepts {full_name, classroom_id} only. The server auto-generates a fresh STU- CompAss ID with retry on ID collision, creates the Student account, enrolls it, and appends history plus audit. Manual IDs and placement metadata are refused 422 and never silently ignored. This covers id, learner_code, school_id, group, group_assignment, year_level, email, identifier, identifierType, name, role, password, password_hash, is_active, status, must_change_password, plus any other unknown key such as classroomId or fullname typos. The record number is assigned by the system, so the request never supplies one.

**Response — 200 / 201**

```json
{
  "data": {
    "id": "integer — enrollment route key",
    "classroom_id": "integer — enrollment rows",
    "school_id": "string — generated STU- CompAss ID",
    "display_name": "string — enrollment rows",
    "placement_kind": "string — manual on hand-placed rows",
    "placed_at": "ISO 8601 timestamp — enrollment rows",
    "moved_at": "ISO 8601 timestamp or null — enrollment rows",
    "message": "string — move only"
  }
}
```

Place returns 201 with the enrollment object, and classroom shapes additionally carry teacher_school_id per §4.2. Move returns 200 with the updated enrollment object plus the message. Identical destination returns 409, archived destination returns 410, and move requires explicit confirmation via API.

A place always creates a new account, so there is no already-placed match path on place itself. Moving an existing enrollment to its current classroom is rejected 409, so the caller picks a different destination. Every place and move appends a classroom_enrollment_moves row plus an audit row carrying actor, learner, source and destination classrooms, and timestamp, readable through the audit reads.

Hand placing creates one new learner account with a fresh CompAss ID and puts it into one classroom.

Ref: ClassroomService::placeLearner.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Non-admin caller | error envelope, code FORBIDDEN |
| 404 | Unknown enrollment or classroom id | error envelope, code NOT_FOUND |
| 409 | Move destination identical to the current classroom | error envelope, code ALREADY_PLACED |
| 410 | Destination classroom archived | error envelope, code CLASSROOM_ARCHIVED |
| 419 | Missing or stale CSRF token on writes | error envelope, code CSRF_TOKEN_MISMATCH |
| 422 | Missing or malformed full_name or classroom_id; any prohibited legacy/unknown key (learner_code, school_id, group/group_assignment, year_level, email, identifier, identifierType, name, role, id, typos) | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/admin/enrollments/place with {"full_name": "A. Learner", "classroom_id": 3} → 201 {"data": {"school_id": "STU-7100-00042", "placement_kind": "manual"}}
```

---

### 4.18 Learner view-only people list — names only, paged, no photos without opt-in

| Field | Value |
|---|---|
| **Endpoints** | GET /student/classrooms/{id}/people |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie; role student enrolled in the classroom; forced-password-change gate applies |
| **Idempotent** | Yes |
| **Rate Limit** | None stated |

**Request**

No body. Query parameters only:

```json
{
  "page": "integer — optional, minimum 1",
  "per_page": "integer — optional"
}
```

This read defines no search parameter; a ?search= value is rejected with a validation error and no school-wide lookup exists.

**Response — 200**

```json
{
  "data": [
    {
      "display_name": "string — classmate rows",
      "photo_url": "string — present only with a recorded family opt-in, otherwise omitted"
    }
  ],
  "meta": "object — page and total counts"
}
```

The list is read-only; no write action exists on this path. Rows carry display names only (default 15, max 100, alphabetical) with no email, no school_id, no learner_code, and no internal numbers. Photo URLs are omitted unless a clear opt-in is recorded (photo_opt_in default off). Ordering is alphabetical by display name.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Student not enrolled in the classroom | error envelope, code NOT_ENROLLED |
| 404 | Unknown classroom id | error envelope, code NOT_FOUND |
| 410 | Read against an archived classroom | error envelope, code CLASSROOM_ARCHIVED |
| 422 | Malformed page or per_page; any ?search= value | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/student/classrooms/3/people?page=1 → 200 {"data": [{"display_name": "A. Learner"}], "meta": {...}}
```

---

### 4.19 Learner rooms listing — index and detail

| Field | Value |
|---|---|
| **Endpoints** | GET /student/classrooms · GET /student/classrooms/{id} |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie; role student; forced-password-change gate applies |
| **Idempotent** | Yes |
| **Rate Limit** | None stated |

**Request**

No body. The index defines no paging and no search parameters; a page, per_page, or ?search= value on the index is rejected with a validation error. The detail takes the classroom route key in the path and takes no query parameters.

**Response — 200**

Index:

```json
{
  "data": [
    {
      "id": "integer — route key, never a search key",
      "name": "string — room name",
      "school_year": "string",
      "archived_at": "ISO 8601 timestamp or null"
    }
  ]
}
```

Detail:

```json
{
  "data": {
    "classroom": {
      "id": "integer — route key, never a search key",
      "name": "string — room name",
      "subject_name": "string",
      "group_name": "string",
      "school_year": "string",
      "archived_at": "ISO 8601 timestamp or null"
    }
  }
}
```

The index returns an unpaginated data array ordered by room name holding only the caller's enrolled, unarchived rooms. The detail returns the room header with subject, group, and year. Human names are surfaced with route keys retained as identifiers only. Archived rooms are excluded from the index; detail against an archived room reports the archived state.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Student not enrolled where membership is required | error envelope, code NOT_ENROLLED |
| 404 | Unknown classroom id | error envelope, code NOT_FOUND |
| 422 | Page, per_page, or ?search= value on the index; malformed scope filters | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/student/classrooms → 200 {"data": [{"name": "Room 4-A", "school_year": "2026-2027"}]}
```

---

### 4.20 Learner leave — reachable without the people read

| Field | Value |
|---|---|
| **Endpoints** | POST /student/classrooms/{id}/leave |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie + CSRF header; role student; forced-password-change gate applies |
| **Idempotent** | Converges (repeat returns the message with no second write) |
| **Rate Limit** | None stated |

**Request**

No body. The classroom route key travels in the path. Access depends only on enrollment in the classroom and never on the people read.

**Response — 200**

```json
{
  "data": {
    "message": "string",
    "already_left": "boolean"
  }
}
```

First leave removes the enrollment, appends classroom_leave_history plus audit, and returns already_left false. Repeat leave returns the message with already_left true and performs no additional write. The not-enrolled refusal applies only to callers with no current or prior enrollment. Leave is available via API independent of other reads and carries the same contract.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Student with no current or prior enrollment in the classroom | error envelope, code NOT_ENROLLED |
| 404 | Unknown classroom id | error envelope, code NOT_FOUND |
| 419 | Missing or stale CSRF token | error envelope, code CSRF_TOKEN_MISMATCH |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/student/classrooms/3/leave → 200 {"data": {"message": "Left the classroom.", "already_left": false}}
```

---

### 4.21 Password resets — admin-only; no public forgot/reset surface (removed per ADR-018)

| Field | Value |
|---|---|
| **Endpoints** | None public (removed per ADR-018): POST /auth/forgot-password and POST /auth/reset-password do not exist; sole channel is §4.9 POST /admin/users/{id}/reset-password plus §4.1 POST /auth/change-password |
| **Owning Service** | Platform team |
| **Auth Required** | N/A for the removed public surface (no session, no token); manager reset under §4.9 takes session cookie + CSRF header with role admin (forced-password-change gate applies to the caller); change-password under §4.1 stays session-gated |
| **Idempotent** | No (each manager reset issues a fresh temp, sets must_change_password, and revokes the target's other sessions) |
| **Rate Limit** | None on the removed public surface; login limiter in §4.1 intact. There is no public forgot password path, so admins issue the temporary password. |

**Request**

No public request body exists. Rules as in §4.9 plus §4.1; this block only adds that the removed paths stay absent. The live shapes are the manager reset in §4.9 and the change-password in §4.1 with its 8-plus mixed-case-plus-digit policy.

No session, no 401, and no token apply to the removed public paths because the paths do not exist. Probes get 404 or 405 with the uniform envelope.

**Response — 200**

No public 200 body exists. Rules as in §4.9, including must_change_password handling with temp to the calling admin only, session revocation, and audit; this block only adds that no public forgot or reset path exists at all. The recipient sets a new password on next login.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 404/405 | Public forgot/reset path probed (removed per ADR-018) | error envelope, code NOT_FOUND / METHOD_NOT_ALLOWED |
| 403 | Non-admin caller on the §4.9 manager reset | error envelope, code FORBIDDEN |
| 422 | Number-only/weak input on the §4.9 manager reset or weak new password on §4.1 change-password | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
POST /api/admin/users/9/reset-password → 200 {"data": {"temporary_password": "...", "must_change_password": true, "message": "..."}} (temp to calling admin only; recipient changes it on next login).
```

---

### 4.22 Human-name search — names and human codes only, internal numbers refused and hidden

| Field | Value |
|---|---|
| **Endpoints** | Query convention on list reads accepting ?search= (no new paths; identity, paging, and write semantics remain in the owning blocks) |
| **Owning Service** | Platform team |
| **Auth Required** | Session cookie; inherits the caller role of the owning list; forced-password-change gate applies |
| **Idempotent** | Yes |
| **Rate Limit** | None stated |

**Request**

No body. Query parameters only:

```json
{
  "search": "string — optional, minimum 2 characters; matches learner name, CompAss ID (school_id), group name, subject name, room name, and join key",
  "page": "integer — optional, minimum 1",
  "per_page": "integer — optional"
}
```

Record numbers are assigned by the system; no create or edit accepts a client-supplied record number. Search matches human names and CompAss IDs and never matches internal numbers. LIKE wildcards (`%`, `_`, `\`) in the query are escaped so they match literally and never widen the match (ADR-023); this hardening applies to the users, classrooms, subjects, assignments, and competency-tags lists. A search holding only internal-number digits is rejected with a validation error on staff reads; learner-facing reads define no search parameter and reject any ?search= value with a validation error.

**Response — 200**

```json
{
  "data": "array — owning-list rows in the owning shape, empty when nothing matches",
  "meta": "object — page and total counts on paginated lists"
}
```

List and detail displays surface CompAss IDs, names, and join keys; internal route keys remain routing-only and never serve as search keys. Learner-facing payloads omit internal numbers entirely.

**Error Responses**

| Status | Condition | Body |
|---|---|---|
| 401 | Missing or expired session | error envelope, code UNAUTHENTICATED |
| 403 | Caller outside the owning scope | error envelope, code FORBIDDEN / SUBJECT_NOT_ASSIGNED / NOT_ENROLLED |
| 422 | Search below the minimum length; internal-number-only search on staff lists; any ?search= on learner-facing reads | error envelope, code VALIDATION_ERROR, with fields |
| 500 | Unexpected error | error envelope, code INTERNAL_ERROR |

**Example**

```
GET /api/admin/users?search=ada&page=1 → 200 {"data": [{...user rows...}], "meta": {...}}
```

---

## 5. Event Inventory

We don't publish domain events. Everything is synchronous request-response, and scheduled work runs in-process with no events.

| Event | Publisher | Consumers | Schema Link | Trigger | Status |
|---|---|---|---|---|---|
| None — synchronous | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable |

---

## 6. Event Contract Template

Reserved for later. If we ever add events, this is where the envelope and payload rules will go.

---

## 7. Versioning & Compatibility Policy

How we change contracts without breaking callers.

- **Backward-compatible changes:** adding optional fields and adding new optional query parameters or response fields that existing clients can ignore safely
- **Breaking changes:** removal or rename of fields or endpoints, changing a field type, or making an optional field required; these require coordination since the API is served unversioned
- **Deprecation process:** announce in release notes with dual support where needed before removal
- **API versioning mechanism:** no URI versioning; the API is served unversioned and evolves through backward-compatible changes
- **Event schema versioning mechanism:** none; no event schema registry exists while no events are published

---

## 8. Contract Testing Strategy

How we check contracts in automation.

| Layer | Approach | Tooling |
|---|---|---|
| Request validation | Validation failures return field-level errors for missing or malformed input | Automated test suite |
| Auth, role, and gate enforcement | Session, role, and forced-password-change gate behavior verified for allowed and denied callers | Automated test suite |
| Idempotency and repeat-call behavior | Repeat-safe reads and overwrites verified alongside confirmation and single-use semantics for unsafe repeats | Automated test suite |
| Throttling | Rate-limit budgets and limit headers verified on guarded endpoints | Automated test suite |
| Import, AI, and audit behavior | Partial-success imports with single-use error reports, on-demand AI generation with moderation guards, and append-only audit reads verified | Automated test suite |

---

## 9. Error Handling & Resilience Semantics

All API errors use the uniform envelope `{ "error": { "message", "code", "fields?" } }`, with `fields` present only on validation failures.

| Scenario | Expected Behavior |
|---|---|
| Validation failure | 422 VALIDATION_ERROR (or COMPETENCY_MISMATCH on competency triple mismatch) with a per-field `fields` map; the request is rejected before any state change |
| Unauthenticated | 401 UNAUTHENTICATED for missing or expired sessions and invalid credentials |
| Forbidden | 403 FORBIDDEN or the stable rule code for role, ownership, enrollment, and gate denials |
| Not found | 404 NOT_FOUND for unknown ids and out-of-scope access reported as not found |
| Business-rule conflict | 409 with the stable rule code (SEMESTER_ALREADY_EXISTS on duplicate semester, DUPLICATE_CLASSROOM on duplicate classroom, SUBJECT_HAS_ACTIVE_ASSIGNMENTS on blocked subject delete); the request is rejected with no partial write |
| Gone or expired | 410 GONE or the stable rule code for revoked join keys, archived classrooms, consumed or expired single-use error reports, and removed subject-section / teacher-assignment surfaces (GET /api/admin/subject-sections; section/teacher-assignment writes); legacy /terms routes are deprecated aliases, not gone |
| CSRF mismatch | 419 CSRF_TOKEN_MISMATCH for missing or stale tokens on non-GET requests; the client refreshes the token and retries the request once |
| Throttle exceeded | 429 RATE_LIMIT_EXCEEDED with X-RateLimit-Limit, X-RateLimit-Remaining, and X-RateLimit-Reset headers preserved on the response |
| AI timeout or failure | Outbound call uses a 10-second timeout per attempt with at most 2 attempts (initial plus one bounded retry on transient connection failure or 429/5xx with bounded backoff); every failure surfaces as 503 AI_SERVICE_UNAVAILABLE |
| Circuit breaker | None; no circuit breaker is implemented |
| Dead-letter queue | None; no dead-letter queue is implemented |
| Duplicate delivery | Not applicable; no domain events are published or consumed |
| Unexpected error | 500 INTERNAL_ERROR with the uniform envelope |

---

## 10. External / Third-Party Integrations

Systems outside our control that we still call.

| Integration | Direction | Protocol | Owner/Contact | Spec Link | SLA |
|---|---|---|---|---|---|
| External text-generation service | Outbound | HTTPS with JSON | Platform team | None | None; best-effort with bounded retry, failures surface as unavailable |

---

## 11. Open Risks & Questions

| Risk / Question | Impact | Owner | Status |
|---|---|---|---|
| Unversioned API evolves through backward-compatible changes only; any breaking change requires coordinated client updates | High | Platform team | Open |
| Synchronous AI calls couple explanation latency and availability to the external service | Medium | Platform team | Open |
| Throttle budgets are set from estimates and may need tuning under real load | Medium | Platform team | Open |
| No event backbone exists; future async needs require new infrastructure and contracts | Low | Platform team | Open |
| Clients rely on the uniform error envelope and stable codes; any drift breaks error handling | Medium | Platform team | Open |

---

## Revision History

| Version | Date | Author | Summary of Changes |
|---|---|---|---|
| 1.0 | 2026-09-07 | Platform team | Initial v1 from implementation |
| 1.1 | 2026-09-09 | Platform team | Docs refresh across §§4.5, 4.15-4.21 with cross-refs reconciled |
| 1.2 | 2026-09-10 | Platform team | Rework cycle with staged validation notes and recovery flow updates |
| 1.3 | 2026-09-11 | Platform team | Admin-only recovery: public forgot and reset removed (probes 404/405); points to §4.9 manager reset (temp to admin only, must_change_password, revoke others, audit) plus §4.1 change-password |
| 1.4 | 2026-09-12 | Platform team | Presentational overhaul only, no contract change |
| 1.5 | 2026-09-12 | Platform team | Presentational overhaul only, no contract change |
| 1.6 | 2026-09-18 | Platform team | Frontend-agnostic wording; contracts unchanged |
| 1.9 | 2026-09-20 | Platform team | Semester hierarchy restructure: semesters canonical with legacy /terms aliases; subjects scoped by grade; classrooms by subject and section with DUPLICATE_CLASSROOM; competency triple check with COMPETENCY_MISMATCH; removed surfaces report GONE |
| 1.8 | 2026-09-19 | Platform team | Catalog read in §4.6, bulk preview and confirm hardening in §4.16, and search escaping in §4.22 |
| 1.10 | 2026-09-21 | Platform team | CompAss identity rework: CompAss-ID login, full_name-only bulk with fresh IDs, and hand placement without legacy keys |
| 2.0 | 2026-09-21 | Platform team | Major promotion with no contract changes since 1.10 |
