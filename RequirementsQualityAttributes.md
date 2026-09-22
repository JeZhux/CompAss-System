# Requirements & Quality Attributes

| Field | Value |
|---|---|
| **Document ID** | ARCH-002 |
| **System / Project Name** | CompAss |
| **Version** | 2.0 |
| **Status** | Approved |
| **Owner(s)** | Platform team |
| **Last Updated** | 2026-09-21 |
| **Reviewers** | Platform team |
| **Related Docs** | ARCH-001 ArchitectureOverview, ARCH-003 ArchitectureDecisionRecords, ARCH-004 DataArchitecture, ARCH-005 APIEventContracts, ARCH-006 DeploymentSecurityArchitecture — assumptions in §6 below (ASSUMED-1/7/8/9 apply throughout) |

---

## 1. Purpose & Scope

This is the anchor for architecture decisions — what the system must do, and how well it has to do it.

- **Purpose:** Lay out the functional requirements and measurable quality attributes so we can judge trade-offs objectively and keep the design tied to what matters.
- **How to use this doc:** Every significant ADR should trace back to one or more entries here. If it doesn't, we probably don't need an ADR for it.
- **Out of scope:** Visual design detail, timelines and resourcing, and full data schemas and interface contracts — those live in their own docs.

---

## 2. Functional Requirements

What the system has to do, kept at architecture level — enough to drive decisions, not a full product spec.

| ID | Requirement | Priority | Source | Notes |
|---|---|---|---|---|
| FR-001 | The system shall support exactly three roles, Admin, Teacher and Student, each with a distinct set of permitted actions enforced on the server. CompAss ID prefixes bind identity to role (Admin→ADM, Teacher→TEA, Student→STU per FR-002). | Must | Admin | Role check on every action |
| FR-002 | The system shall authenticate every role by CompAss ID through a single login `identifier` field. The ID lives in users.school_id, format ^(ADM\|TEA\|STU)-[0-9]{4}-[0-9]{5}$ with role-prefix check Admin→ADM, Teacher→TEA, Student→STU and NOT NULL UNIQUE. Matching trims surrounding space then compares case-sensitively with no case folding, inside a per device-and-account rate-limit bucket. Concurrent sessions on multiple devices are permitted. Email login does not exist. Ref: migration 2026_09_20_000001_compass_id_only; rate details in ARCH-005. | Must | All users | Sessions expire after inactivity |
| FR-003 | The system shall issue system-generated temporary passwords for new (createAccount per FR-004, bulk confirm, hand place) and reset (FR-038) accounts, force a password change on next CompAss-ID login (FR-002) before any other access except sign-out, password change, profile, and service-status reads, and let users change their own password at any time. | Must | All users | Single uniform password policy |
| FR-004 | Admins shall be able to create, edit, deactivate, reactivate, and password-reset any account, preserving historical data on deactivation. Account creation is `createAccount(name, role)` only. POST /admin/users accepts {name, role} and the server auto-generates the CompAss ID with collision retry (up to 3 attempts on users.school_id unique hit). Manual school_id plus legacy keys (email, identifier, identifierType, learner_code, group/group_assignment, year_level, password/password_hash, is_active/status, must_change_password, full_name, classroom_id, id) and any other unknown key are refused 422 prohibited (never silently ignored). Account edit is name-only: PUT /admin/users/{id} accepts {name} with school_id immutable and the same prohibited-key 422 discipline. | Must | Admin | Teacher deactivation blocked while grading is pending |
| FR-005 | Admins shall be able to manage school years, terms, grade levels 7 through 12, sections, and subjects, including teacher-to-subject-section assignments. | Must | Admin | One teacher per subject-section; reassignment requires removal first |
| FR-006 | Teachers shall be able to create one classroom per assigned subject-section per school year with a unique join key and derived name. | Must | Teacher | Creation requires a matching assignment |
| FR-007 | Students shall be able to join a classroom by entering its join key, with distinct feedback for unknown, revoked, archived, and join-disabled keys. | Must | Student | Joining is idempotent |
| FR-008 | Teachers and Admins shall be able to rotate a classroom join key, enable or disable joining, and archive or restore a classroom while retaining all its data. | Must | Teacher | Superseded keys stay revoked; archived rooms refuse joins |
| FR-009 | Teachers shall be able to view the classroom roster and remove students, Admins shall be able to remove from any classroom, and Students shall be able to leave a classroom themselves via API independent of any people-list read (first leave already_left false, repeats already_left true with no second write; never-enrolled 403; each leave appends classroom_leave_history). | Must | Teacher | Removals and leaves are audited |
| FR-010 | Admins shall be able to move teacher assignments and classrooms between school years and to list, search by human names and human codes, filter, and inspect classrooms with enrollment and mastery summaries. | Should | Admin | Duplicate assignments rejected |
| FR-011 | Teachers shall be able to create, edit, and delete announcements with optional file attachments scoped to a single classroom, and Students shall see only announcements for classrooms they joined. | Must | Teacher | Attachments follow standard file limits |
| FR-012 | Teachers shall be able to create and manage assignments with due dates and file attachments bound to a single classroom. | Must | Teacher | No competency tagging on assignments |
| FR-013 | Students shall be able to submit assignment files with timestamps marking each submission On-Time or Late, and late submissions shall not be blocked. | Must | Student | Deletion with submissions is confirmed hard delete |
| FR-014 | Teachers shall be able to give written feedback on submissions with no formal grade, and Students shall be able to view feedback on their own submissions. | Must | Teacher | Feedback only, no points |
| FR-015 | Teachers shall be able to author assessments with titles, descriptions, time limits, availability windows, and objective and subjective items each tagged to exactly one competency and designated Recorded or Unrecorded at creation. | Must | Teacher | Drafts auto-save; release needs at least one item |
| FR-016 | Teachers shall be able to publish assessments and edit only non-scoring metadata after release, with structural edits disallowed once released. | Must | Teacher | Metadata edits take effect immediately |
| FR-017 | Students shall be able to take assessments in a distraction-free full-screen mode with periodic auto-save, availability enforced at start, unanswered time-expired items scored as blank, and exit without auto-submit. | Must | Student | No monitoring of any kind |
| FR-018 | Teachers shall be able to score subjective items through a Pending Grading queue, enter grades for many students in one batch, save draft grades, re-grade attempts, and request resubmissions as new numbered attempts. | Must | Teacher | Draft grades never affect records |
| FR-019 | Teachers shall release results only when all subjective items are scored, at which point objective-only work proceeds directly to mastery calculation. | Must | Teacher | Release gate blocks on pending grading |
| FR-020 | The system shall compute per-competency mastery as points earned divided by points possible, treat 80 percent and above as Mastered, flag below-threshold Recorded results as Not Competent, and treat the most recent Recorded result as current status while keeping full history. | Must | System | Deterministic; zero-point groups skipped with warning |
| FR-021 | Unrecorded assessment results shall be scored and shown to the student but excluded from mastery records, dashboards, and flagging. | Must | Student | Practice stays out of official record |
| FR-022 | Teachers shall see analytics including a classroom-scoped competency mastery heatmap and competency summary, with below-threshold gap report, trend charts, and drill-down to individual student records scoped to the subject-section. | Must | Teacher | Recorded data only |
| FR-023 | Admins shall see a school-wide mastery overview across grade levels and sections plus classroom detail searchable by human names with enrollment and mastery summaries. | Must | Admin | Aggregates from Recorded data only |
| FR-024 | Students shall be able to review their own past scores history covering overall scores, per-question results, and per-competency mastery history over time, restricted to their own Recorded data. Reviewing a past score never creates a new AI explanation. | Must | Student | Reviewing results never triggers generation |
| FR-025 | Dashboards shall refresh automatically on a periodic cycle and immediately when results are released, without manual page refresh. | Should | Teacher | Polling plus release-triggered refresh |
| FR-026 | Teachers shall be able to upload, list, update, and delete competency-aligned learning materials in restricted document formats, persisting independently of term boundaries. | Must | Teacher | Materials ground generated explanations |
| FR-027 | Students shall be able to explicitly request AI explanations for their own unmastered items after results release, with explanations persisted so repeat requests reuse stored content. | Must | Student | Never generated automatically or on view |
| FR-028 | Every AI explanation shall carry a visible supplementary-aid disclaimer plus a distinct notice when not grounded in teacher materials, shall withhold direct identifiers with response content sent as-is, and shall degrade to a clear service-unavailable message when the AI service is down. | Must | Student | Non-AI features keep working during outages |
| FR-029 | Students shall be able to request simplified follow-up explanations for objective items up to a fixed turn limit per item. | Should | Student | Subjective items excluded |
| FR-030 | Teachers shall be able to inspect, flag with visible warning notes, and disable follow-ups on AI explanations through a section-scoped moderation log. | Should | Teacher | Flagged content stays visible with warning |
| FR-031 | Admins shall be able to bulk-import competency tags from spreadsheet files with row-level validation, downloadable templates, throttled processing, and a per-attempt error report for failed rows. | Must | Admin | Competency-tags import only; valid rows import; failures never block them |
| FR-032 | The system shall maintain an audit trail of significant events including roster changes, learner placements and moves with actor and timestamp, score changes including re-grades and resubmissions, key rotations, imports, and moderation actions. | Must | Admin | Supports accountability and review |
| FR-033 | Admins shall be able to enroll many Students at once (bulk student flow) and create many Teachers at once (bulk teacher flow) from a sheet carrying a single `full_name` header. Sheet ceilings are 15 MB and 5000 rows. Preview tokens are single-use for 60-min and error sheets are single-use for 7-day with Row, full_name and Reason columns plus a Help sheet (TYPE_STUDENT_ENROLLMENT is student_enrollment, TYPE_TEACHER_APPLICATION is teacher_application). Every valid row creates a fresh account with a server-generated CompAss ID (STU- for students, TEA- for teachers, 3x retry on collision). Names are never matched since names are not unique, so updated stays 0 for contract stability and duplicate_matches counts only in-file valid exact repeats, each still creating its own account. No group or year columns exist. Group, year, learner_code, school_id, email and identifier keys are refused and never retained. Ref: migration 2026_09_21_000001_fullname_only_bulk. Invalid rows such as blank names, names over 255 chars or save failures skip to the error sheet with row-level reasons while valid rows still save. Counts are checked in preview before explicit confirm, with 410 on expired preview asking for re-upload. Failures state what to fix and who to ask. No internal IDs are accepted. Each row saves fully or not at all with no partial save, even for very large files. Counts reconcile as imported for creates, updated for 0 and failed for rejects. This never places learners into classrooms. Placement happens only via hand place or move in FR-034 or join in FR-007, and zero-valid runs succeed with zeros and no report. See ARCH-005 §4.16 canonical. | Must | Admin | Admin-only; per-row atomic; preview counts required |
| FR-034 | Admins shall be able to place or move a single Student by hand via `placeLearner(full_name, classroom_id)`. Place returns 201 with {id, classroom_id, school_id, display_name, placement_kind manual, placed_at, moved_at null}. Move returns 200 with message. Identical destination is 409 and archived destination is 410, and move needs explicit confirmation before submit. Place accepts {full_name, classroom_id} only and always creates a fresh Student account with a server-generated STU- CompAss ID with 3x retry on collision. There is no matching by name and no manual IDs. Legacy keys such as learner_code, school_id, group, year_level, email, identifier and other unknown keys are refused 422. No learner_code, group or year input exists. Responses carry school_id, and classroom shapes also carry teacher_school_id. Placing an already-placed Student is blocked 409 in favor of an explicit move. Errors state what to fix and who to ask. No internal IDs are accepted. Every hand placement and move is written to the audit trail and classroom_enrollment_moves history with actor and timestamp. | Must | Admin | Double-place blocked; history-logged |
| FR-035 | Students shall be able to view a read-only people listing showing names only in paged views (default 15, max 100, alphabetical) with no school-wide search (any ?search= 422) and no edit controls, with unenrolled 403 and archived 410, and photos appear only with explicit opt-in consent (photo_opt_in default off). Rows carry display_name only (plus photo_url solely on recorded opt-in); no school_id, email, learner_code, or internal numbers appear in the payload. | Must | Student | Names-only; paged; consent-gated photos |
| FR-036 | Students shall be able to list their enrolled classrooms and retrieve classroom detail via API (index unpaginated, enrolled unarchived only, name-ordered, 422 on page/per_page/search; detail carries name, subject_name, group_name, school_year, archived_at). | Must | Student | Enrolled scope only |
| FR-037 | Manual scoring, batch scoring, resubmission handling (1–1000-char reason via API), per-section reports, and school-wide mastery records for admin and teacher shall each be available via API to the authorized role, with try-out and test capabilities restricted to staff by server enforcement. | Must | All users | Role-scoped API availability |
| FR-038 | Admins shall be able to reset a password for any account through manager reset only. The call is POST /admin/users/{id}/reset-password with role admin only, giving 403 FORBIDDEN for non-admin callers. On success it issues a system temp password returned to the calling admin only over the authenticated session. It sets must_change_password=true on the target, revokes the target's other sessions, preserves deactivation per FR-004, writes an audit row, returns the uniform envelope, and refuses number-only or weak input with 422 VALIDATION_ERROR. The recipient's next login follows first-login parity. The forced-change gate blocks every gated action except sign-out, POST /auth/change-password, GET /me and GET /ai/status until change succeeds. There is no public forgot or reset surface. Probes expect 404 or 405 with the uniform envelope. Login, session and related checks stay unchanged. The temp is subject to the forced-change gate before other access. See ARCH-005 and ARCH-003 canonical. Ref: ADR-018; ASSUMED-9; sync-only with no new tables. There is no self-service reset. The admin hands the temp to the recipient, who sets a new password on next login. | Must | Admin | Extends uniform password policy |
| FR-039 | The system shall assign all internal record numbers automatically. No create or edit accepts one. Internal numbers stay hidden from list and detail responses, which show only human codes. All search resolves by human names and human codes, and internal-number search is refused (digits-only search 422 via HumanSearch; learner reads reject any ?search= 422). | Must | System | Auto-assign; hidden; name-based search |
| FR-040 | Learner bulk enrollment shall follow a single canonical sheet-file API flow with no duplicate flows, and all announcement, assignment, assessment, and material creation shall require classroom scope with no standalone subject-group creation split. | Must | Admin | Single API flow; classroom-scoped creation |

**Priority key:** Must-have / Should-have / Could-have / Won't-have (this phase) — MoSCoW

---

## 3. Quality Attributes (Non-Functional Requirements)

The "-ilities" that actually shaped the design. Each one is written so we can test it — vague goals like "fast" don't help when we have to trade off.

### 3.1 Quality Attribute Summary

| ID | Attribute | Requirement (measurable) | Priority | Verification Method |
|---|---|---|---|---|
| QA-001 | Performance | Standard user actions (page loads, form submissions, searches) complete within 2 seconds under normal load; dashboard visualizations load within 3 seconds for a section of 50 students and up to 10 competencies. | Must | Load testing |
| QA-002 | Availability | The system is available 99% of the time during school hours (7:00 AM–5:00 PM Philippine Standard Time, application timezone Asia/Manila); transient failures (timeouts, dropped connections) produce a user-friendly message with no technical detail, and all non-AI features keep working normally while the AI service is down. | Must | Uptime monitoring |
| QA-003 | Scalability | The system serves 30 concurrent users without degradation and is designed to scale to 500. Per-user limits protect shared capacity. Classroom joining allows 20 requests per minute per student. Classroom creation and key rotation allow 10 per minute per teacher or admin. AI generation allows 10 per minute per student. Moderation writes allow 60 per minute per teacher. Exceeding a limit returns a rate-limit response that names the retry wait. | Must | Load testing |
| QA-004 | Security | Passwords require at least 8 characters with a letter, a digit and mixed case. They are stored only in salted one-way form. New or reset accounts must change the temporary password before other access, except sign-out, password change, profile and service-status reads. Login uses the CompAss ID only for all roles. The identifier is trimmed then matched case-sensitively with no case folding, and users.email does not exist. Sessions expire after 30 minutes of inactivity. Every action is authorized against the requesting role on the server. Login allows 5 failed attempts per 15-minute sliding window per device-and-account combination. The bucket keys on the trimmed identifier case-sensitively, so spacing variants share one bucket while distinct case variants hold distinct buckets. There is no permanent lockout. All traffic is encrypted in transit with protections against request forgery and injection. | Must | Security audit |
| QA-005 | Maintainability | Presentation, business-logic, and data-access layers stay separated; all code follows recognized industry standards for its language; 100% of structural database changes ship as version-controlled, reviewable update scripts; the relational store uses native enum types; the system deploys on standard server environments with no dependency on queue services or managed cloud services, and all processing runs synchronously within the user request with scheduled maintenance requiring a running scheduler. | Should | Code review |
| QA-006 | Observability | Every API error returns a uniform envelope carrying a message, a machine-readable code, and field details where applicable; significant events (roster changes, learner placements and moves, classroom leaves, score changes, key rotations, imports, moderation actions, rate-limit refusals) are written to an append-only audit trail retained 365 days with IP addresses irreversibly anonymized after 90 days; logs use a default single-file log with daily rotation only when selected keeping 14 days; retention and cleanup jobs run daily on schedule requiring a running scheduler. | Should | Incident review |
| QA-007 | Usability | Core tasks (creating and releasing assessments, taking assessments, viewing results) are completable without external documentation; the interface works in the latest two major versions of Chrome, Firefox, and Edge on Windows 10 or later at 1280×720 and above; error messages state in plain words what went wrong, how to fix it, and who to contact for help; contextual help covers non-obvious features; drafts auto-save every 60 seconds; dashboards refresh on a periodic cycle and immediately on results release; acceptance is a weighted mean score of at least 4.50 from at least 10 teachers and 200 students. | Should | User testing |
| QA-008 | AI responsiveness | Explanations requested by a student are delivered within 15 seconds under normal network conditions with worst-case up to about two timeout windows on the retry path; each AI call times out after 10 seconds with at most one retry on transient failures only, then degrades to a distinct service-unavailable message; generated explanations persist so repeat requests reuse stored content; follow-up explanations are limited to 2 turns per objective item and unavailable for subjective items; mastery math is deterministic with an 80% mastery threshold. | Must | Load testing |
| QA-009 | Cost efficiency | Synchronous in-request processing with no background workers or managed services keeps hosting to a single standard server plus database; uploads are capped at 5 files of 15 MB each (learning materials restricted to PDF and DOCX); stored explanations are reused rather than regenerated; import error reports are one-time downloads, not long-lived records. | Should | Cost monitoring |
| QA-010 | Privacy posture | Prompts sent to the AI service withhold direct identifiers with response content sent as-is; learner people-list views show names only in paged views with photos only on explicit opt-in consent; every AI explanation carries a visible supplementary-aid disclaimer plus a distinct notice when not grounded in teacher materials; submission files are scoped to a term with a term-scoped purge offering a preview option after closure while mastery records, explanations, and materials persist and file cleanup is best-effort; data handling follows data-minimization, purpose-limitation, and secure-storage principles with no formal legal certification claimed. | Must | Compliance audit |
| QA-011 | Bulk-operation safety | Bulk sheet-file runs enforce stated size caps (15 MB, 5000 rows), save each row fully or not at all, place invalid rows in a downloadable single-use 7-day error sheet with reasons, show preview counts with disabled-save until explicit check, and complete fully for very large files with no partial save (counts reconcile). | Must | Load testing |
| QA-012 | Change safety | Every action that deletes or changes many records shows a preview screen with counts for checking before saving (Save disabled until explicit check; 410 expired-preview asks for re-upload), and saving proceeds only on explicit confirmation. | Must | User testing |
| QA-013 | Reporting scope and paging | Reports render in paged views scoped to the authorized section or classroom only, with no school-wide search or listing for learner roles. | Must | Security audit |

### 3.2 Quality Attribute Scenarios

For the riskiest attributes we wrote testable scenarios — source, what hits us, under what conditions, how we respond, and how we measure it.

**Scenario QA-003-S1: Peak classroom load**

| Field | Value |
|---|---|
| Source | 30 concurrent classroom users (pilot), growing toward 500 concurrent at full rollout |
| Stimulus | Students submit assessments, join classrooms, and request explanations while teachers release results and load dashboards at the same time |
| Environment | Normal operation during school hours |
| Artifact | Web application and API |
| Response | Requests keep flowing under per-user limits. Heavy use from one client does not block others. |
| Response Measure | Standard actions complete within 2 seconds; dashboards load within 3 seconds for 50 students and 10 competencies; no performance degradation at 30 concurrent users |

**Scenario QA-008-S1: AI service outage during results review**

| Field | Value |
|---|---|
| Source | External AI service |
| Stimulus | AI service becomes unreachable while a class reviews released results and requests explanations |
| Environment | Normal operation with the AI dependency down |
| Artifact | Explanation generation pipeline |
| Response | Each AI call stops after a 10-second timeout with at most one retry on transient failures. Students see a distinct service-unavailable message instead of explanations. All non-AI features keep working. Stored explanation groups stay saved, and missing groups rebuild on the next explicit retry. |
| Response Measure | Non-AI error rate unchanged during the outage; no explanation request hangs longer than two 10-second attempts; zero partial or malformed explanations stored |

**Scenario QA-004-S1: Credential attack and session enforcement**

| Field | Value |
|---|---|
| Source | Unauthenticated attacker and legitimate users |
| Stimulus | Repeated failed logins against one account from one device, plus a new account logging in for the first time and an idle session left open |
| Environment | Normal operation |
| Artifact | Authentication and session management |
| Response | Logins past 5 failed attempts in a 15-minute sliding window for the same device-and-account combination are refused. The bucket keys on the trimmed CompAss ID case-sensitively. Spacing variants share one bucket and distinct case variants hold distinct buckets, so a lower-case flood cannot lock the exact upper-case ID. The refusal names the limit, remaining attempts and reset time. A successful login resets the counter. No account is ever permanently locked. New accounts stay blocked from all navigation except sign-out, password change, profile and service-status reads until the temp is replaced. Idle sessions require re-authentication after 30 minutes. |
| Response Measure | 6th failed attempt within the window is refused 100% of the time; forced password-change gate blocks 100% of other navigation except sign-out, password change, profile, and service-status reads; idle sessions expire at 30 minutes |

**Scenario QA-003-S2: Join-key abuse under shared network**

| Field | Value |
|---|---|
| Source | Student client on a shared school network |
| Stimulus | Burst of classroom-join requests well above normal use (e.g., scripted retries) |
| Environment | Normal operation, many students behind one network address |
| Artifact | Classroom join endpoint |
| Response | Joins past 20 requests per minute for that student identity are refused with a rate-limit response. Joins from other students on the same network address proceed normally. |
| Response Measure | 21st join request within the minute is refused; unaffected students see no increase in join latency or failure rate |

---

## 4. Attribute Trade-off Matrix

These attributes pull against each other. We're calling out the tensions explicitly so ADRs can point back here.

| Attribute A | Attribute B | Tension | Resolution Approach |
|---|---|---|---|
| Security | Usability | Strict password rules, forced temporary-password change, 30-minute idle expiry, and login throttling add friction to everyday sign-in and recovery. | Keep enforced credential and session controls ahead of sign-in convenience. Error and limit messages stay actionable so legitimate users recover without help. |
| Performance | Cost efficiency | Fast page responses and dashboard loads compete with a single-server hosting posture with no managed services. | Hold to a single standard server plus database. Capped uploads, reused stored explanations and one-time import reports carry the load instead of provisioned capacity ahead of demand. |
| Availability | AI responsiveness | Explanations depend on an external text-generation service while core classroom work must stay usable during outages. | Isolate AI failure from core work. Bounded timeouts with a single transient-only retry fall back to a distinct unavailable message while non-AI features keep working. |
| Scalability | Maintainability | Synchronous in-request processing, including imports and AI calls, keeps the design simple but bounds how much concurrent work one server absorbs. | Stay with synchronous processing plus per-client rate limits at pilot scale. Bursts are absorbed by refusing excess requests from one client rather than queueing work. |
| Privacy posture | AI responsiveness | Useful explanations benefit from rich item and response context while prompts withhold direct identifiers with response content sent as-is. | Strip direct identifiers from prompts and send response content as-is with only competency, item and response content. Accept occasionally less-personalized explanations over transmitting identifying data. |

---

## 5. Constraints

Hard boundaries. Not goals to optimize — limits we designed inside.

| ID | Constraint | Type | Rationale |
|---|---|---|---|
| C-001 | The system is delivered as a browser-based web application requiring stable internet connectivity, with no offline mode. | Technical | Classroom, assessment, and analytics flows assume continuous connectivity. |
| C-002 | Initial deployment is a single-school pilot sized around 30 concurrent users with design headroom toward 500. | Organizational | Rollout scope and capacity planning stay bounded by the pilot school. |
| C-003 | All processing runs synchronously within the user request, with no background workers, message queues, or managed cloud services. | Technical | Simplicity and portability across standard server environments take precedence over asynchronous throughput. |
| C-004 | Uploads, attachments, submission files, templates, and error reports reside on local file storage. | Technical | Single-host file handling keeps retrieval control simple at pilot scale. |
| C-005 | All users authenticate through the same browser-session mechanism with a 30-minute inactivity timeout and concurrent multi-device sessions allowed. | Technical | A uniform session model keeps access control and deactivation enforcement consistent across roles. |
| C-006 | Grade levels are restricted to Grades 7 through 12; out-of-range grades are rejected. | Organizational | Curriculum structure, sections, and reporting are defined for that grade band. |
| C-007 | AI explanations are produced by an external text-generation service reached through a server-side proxy and degrade to a visible unavailable message when that service is down. | Technical | Explanation capability depends on third-party service reachability and latency. |
| C-008 | Internal record numbers are assigned automatically by the system, never supplied on create or edit and never shown on list or detail responses; visible identifiers are human codes only. | Technical | Identity handling stays uniform and hides internal storage detail. |
| C-009 | Structural changes are frozen during exam weeks, with rollout staged one grade level first for one to two weeks before whole-school opening (operational, not code; owned by the school operator). | Organizational | Assessment periods stay protected and initial load stays bounded. |

---

## 6. Assumptions

What we took as true when we drew this up. Worth re-checking if any of them break. ASSUMED-1/7/8/9 below are the canonical wording — other docs point here instead of repeating it.

| ID | Assumption | Risk if Invalid | Owner |
|---|---|---|---|
| A-001 | Concurrent use stays near 30 users during the pilot and within the 500-user design ceiling at rollout. | Response times degrade and synchronous operations contend under excess load. | Platform team |
| A-002 | Users have stable internet access on supported browsers during school hours. | Connectivity loss or unsupported clients block classroom activity with no offline fallback. | School administration |
| A-003 | The external AI service remains reachable with acceptable latency for explanation requests. | Sustained outages or slowness turn explanations into unavailable messages while core features continue. | Platform team |
| A-004 | Teachers supply enough competency-aligned materials to ground explanations. | Sparse materials increase ungrounded explanations carrying the supplementary-aid notice. | Teaching staff |
| A-005 | Local disk capacity plus scheduled retention cleanups keep pace with uploads, submissions, and audit growth. | Exhaustion or stalled cleanup blocks submissions, imports, and downloads. | Platform team |
| ASSUMED-1 | The six short help papers (ToBeBuilt B8/C10) are the six live v1 docs ARCH-001..006. | Docs update lands in the wrong place and help wording stays stale. | Platform team |
| ASSUMED-6sp | The speed rules are the named throttles (login 5/15-min per device+account, join 20/min, create/key 10/min, import 10/min, AI generate 10/min + explain-further 30/min, moderation 60/min — forgot-password per-IP removed per ADR-018, no public reset throttle live); two plain summaries added in QA-003 cover the creation/key and moderation rules while the remaining rules carry their contract-level wording in ARCH-005. | Throttle wording stays inconsistent across docs. | Platform team |
| ASSUMED-7 | At baseline no mail infra exists in-repo (no mailables, MAIL_MAILER log/array, queue sync); the reset out-of-band leg therefore shipped only pre-ADR-018 as in-request log/mail-send within the sync constraint (removed per ADR-018), and the exact channel (log-for-pilot vs configured mailer) was parked with the operator to name it; PASSWORD_RESET_INLINE_TOKEN=false in production held under either choice (removed with the public surface per ADR-018; history only). | Self-service reset removed per ADR-018 (history); recovery is manager-issued temp only. | Platform team |
| ASSUMED-8 | Group/year input is removed (migration 2026_09_21_000001_fullname_only_bulk drops enrollment_import_rows.group_assignment/year_level; no group/year columns exist on users, classroom_enrollments, or staged rows). Bulk sheets carry full_name only and hand place carries full_name + classroom_id; any group/year/learner_code/school_id/email/identifier key is refused 422 and never retained. | Any future grouping need requires a superseding decision with an explicit mapping, not an implementation improvisation. | Platform team |
| ASSUMED-9 | Temp-handoff lifecycle = first-login parity: admin receives system temp over authenticated manager channel, hands it to the recipient (in person / trusted channel — operator procedure, not a contract), recipient logs in with temp then POST /auth/change-password before any other gated action (gate exceptions: sign-out, change-password, GET /me, GET /ai/status); other sessions of the target revoked at reset; deactivation preserved per FR-004. Basis: operator quote + FR-003/004 (decided by ADR-018). | Resumption if contested: operator names any deviation (different exceptions, no revoke, different temp lifetime) via superseding ADR, not implementation improvisation. | Platform team |

---

## 7. Stakeholders & Concerns

Who cares about what — handy when we argue about priorities.

| Stakeholder | Primary Concerns | Related QA IDs |
|---|---|---|
| Students | Usability, performance, AI responsiveness, privacy | QA-001, QA-007, QA-008, QA-010 |
| Teachers | Usability, performance, scalability, AI responsiveness | QA-001, QA-003, QA-007, QA-008 |
| Administrators | Security, availability, observability, privacy | QA-002, QA-004, QA-006, QA-010 |
| Platform team | Maintainability, scalability, observability, cost efficiency | QA-003, QA-005, QA-006, QA-009 |
| School administration | Availability, security, cost efficiency | QA-002, QA-004, QA-009 |

---

## 8. Traceability to Decisions

Where each requirement group landed in this version.

| Requirement / QA ID | Addressed By | Status |
|---|---|---|
| FR-001 – FR-005 | Covered here | Partial |
| FR-006 – FR-010 | Covered here | Addressed |
| FR-011 – FR-014 | Covered here | Addressed |
| FR-015 – FR-019 | Covered here | Addressed |
| FR-020 – FR-021 | Covered here | Addressed |
| FR-022 – FR-025 | Covered here | Addressed |
| FR-026 – FR-030 | Covered here | Addressed |
| FR-031 – FR-032 | Covered here | Partial |
| FR-033 – FR-040 | Covered here | Addressed |
| QA-001 – QA-004 | Covered here | Addressed |
| QA-005 – QA-007 | Covered here | Addressed |
| QA-008 – QA-010 | Covered here | Addressed |
| QA-011 – QA-013 | Covered here | Addressed |

---

## 9. Out-of-Scope / Explicitly Deferred

What we deliberately left out, and why — so we don't re-argue it later.

| Item | Reason Deferred | Revisit When |
|---|---|---|
| Multi-school support | Single-school pilot keeps academic structure, administration, and capacity planning bounded. | Additional schools request onboarding. |
| Offline mode | Classroom, assessment, and analytics flows require continuous connectivity. | Stable connectivity cannot be assumed for target users. |
| Assessment proctoring and monitoring | Assessment flow is distraction-free with no monitoring of any kind. | Permanently excluded with no revisit. |
| School Head role | Three enforced roles cover all current administrative, teaching, and learning actions. | Operational need emerges for an intermediate approval or oversight layer. |
| Advanced scaling with background processing | Synchronous in-request processing with individual-client limits meets pilot load. | Sustained load approaches the 500-user design ceiling or operations contend. |

---

## Revision History

| Version | Date | Author | Summary of Changes |
|---|---|---|---|
| 1.0 | 2026-09-07 | Platform team | Initial v1 established from requirements and implementation |
| 1.1 | 2026-09-09 | Platform team | Docs refresh. FR-009 and FR-033 to FR-040 cover leave history, bulk ceilings with preview counts and 410 handling, hand place 201/200/409/410 with explicit confirm, people listing 15/100 with 422 on search and photo_opt_in default off, rooms list and detail, staff navigation and single canonical flows. Wording gaps fixed for past scores history, speed-rule summaries, class files first and sign-out exceptions. C-009 operational note added. ASSUMED-1 and ASSUMED-6sp recorded. |
| 1.2 | 2026-09-10 | Platform team | Rework cycle 1 (ADR-016 and ADR-017). FR-033 covers bulk identity with staged handling and identity counts. FR-038 covers reset messaging with manager fallback, sync-only. ASSUMED-7 and ASSUMED-8 carried. Header carries ASSUMED-1, 7 and 8. |
| 1.3 | 2026-09-11 | Platform team | Follow-up (ADR-018 admin-only). FR-038 rewritten to manager-only with temp to admin, must_change_password, revoke others, deactivation preserved, audit, 403 and 422 handling and first-login parity. No public forgot or reset paths. ASSUMED-9 added. Header carries ASSUMED-1, 7, 8 and 9. |
| 1.4 | 2026-09-12 | Platform team | Presentational-only design overhaul. No API, schema, auth, throttle, audit or deployment change. Routes, guards and logic unchanged. |
| 1.5 | 2026-09-12 | Platform team | Presentational-only follow-up on the same date, building on 1.4. No contract change. Same routes, guards and logic. |
| 1.6 | 2026-09-18 | Platform team | Frontend-agnostic: FR-009/033/034/035/036/037/040 reworded to API capabilities with UI page/component/redirect/dialog language removed; QA-007 design-system sentences deleted with measurables intact |
| 1.7 | 2026-09-19 | Platform team | React SPA frontend unit (ADR-022, constrains ADR-021): requirements unchanged; version bump records the owner-approved deviation prescribing frontend/ (Vite + React Router JS, :3000 same-origin proxy, Sanctum cookie+CSRF, mirrored guards, envelope/error + throttles) with server contracts, validation, ceilings, and gating intact |
| 1.9 | 2026-09-19 | Platform team | Deviation-free hardening (ADR-023). No requirement change. FR and QA statements stand as written. The release covers catalog read, throttle keying, token ownership, search hardening, 7 to 12 band and UI policies as implementation under existing requirements. |
| 1.10 | 2026-09-21 | Platform team | CompAss rework (ADR-025). Login is CompAss-ID-only for all roles with role prefix, trim-only case-sensitive matching and no email. Single-account create accepts name and role with server-generated IDs and strict 422 on legacy keys. Bulk student and teacher flows are full_name-only with fresh IDs, updated at 0 and in-file repeats counted, plus Row, full_name and Reason error sheets. Hand placement creates one fresh account per call. Related FR and QA entries anchored to the same contracts. ASSUMED-8 retired to dropped columns. 164 tests pass, build green. |
| 2.0 | 2026-09-21 | Platform team | Major version promotion: no contract changes since 1.10 — 1.10 content (CompAss-ID identity, full_name-only bulk, Users tabs) promoted to v2 major for breaking-change visibility |
