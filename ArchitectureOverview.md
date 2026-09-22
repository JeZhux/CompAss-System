# Architecture Overview

| Field | Value |
|---|---|
| **Document ID** | ARCH-001 |
| **System / Project Name** | CompAss |
| **Version** | 2.4 |
| **Status** | Approved |
| **Owner(s)** | Platform team |
| **Last Updated** | 2026-09-21 |
| **Reviewers** | Platform team |
| **Related Docs** | ARCH-002 RequirementsQualityAttributes, ARCH-003 ArchitectureDecisionRecords, ARCH-004 DataArchitecture, ARCH-005 APIEventContracts, ARCH-006 DeploymentSecurityArchitecture — assumptions in ARCH-002 section 6 (ASSUMED-1/7/8/9 apply throughout) |

---

## 1. Purpose & Scope

What this doc is, who it's for, and what we left to the other docs.

- **Purpose:** Give us one shared picture of how the system is put together, where its edges are, and what big choices we made — as built, not as wished.
- **In scope:** The backend API, relational DB, file storage, and AI proxy, plus what they deliver together. The React SPA in `frontend/` is an in-scope presentation unit (ADR-022, constraining ADR-021); other clients stay external. That covers session auth with forced password change, admin-only resets, and role-based access. It also covers classroom-centered org structure, bulk onboarding from full_name-only sheets with preview/confirm and error sheets, hand placement and moves, plus competency-based assessment and grading. Limits, shapes, and error codes live in ARCH-005. This section is just the map.
- **Out of scope:** Quality thresholds, individual ADRs, full data schemas and retention rules, endpoint-by-endpoint contracts, per-environment deployment detail, and runbooks. Those live in ARCH-002 through ARCH-006.
- **Intended audience:** Engineers building it, architects reviewing it, product folks scoping against what's actually built, and security/ops/audit reviewers checking boundaries and access.

---

## 2. System Context

Where CompAss sits, who uses it, and what it depends on.

- **Business context:** CompAss is a school platform built around competencies. Teachers run classrooms, publish work tied to competencies, grade by hand and in bulk, offer retakes, and review class reports. Students join rooms, submit work, and track mastery. Admins handle enrollment (bulk sheets plus hand placement), resets, and school-wide reporting and audit. We roll out one year level for a week or two before opening to the whole school, and we freeze big changes during exam weeks.
- **External actors:** Admins, teachers, and students — all through the API, from any client. Admins manage users, org structure, imports, classrooms, and audit. Teachers manage rooms, content, grading, and AI moderation. Students join rooms, submit work, and view their own mastery. Lookup is by human names and CompAss IDs with internal numbers hidden; staff-only powers are enforced on the server.
- **Upstream dependencies:** One relational DB for persistent and session state, local disk for uploads, and an external text-generation service reached only through our server-side AI proxy.
- **Downstream consumers:** No external downstream systems. The React SPA in `frontend/` is the primary client; anything else just calls the backend API as the logged-in user.

**System Context Diagram**

```
  +----------+     +--------------------------------+
  |  Admin   |---->| External Clients               |
  | (any     |     | (any implementation; consumes |
  |  client) |<----| API on behalf of the           |
  +----------+     | authenticated user; no         |
  +----------+     | architecture prescription)     |
  | Teacher  |---->|                                |
  | (any     |     |                                |
  |  client) |<----|                                |
  +----------+     |                                |
  +----------+     |                                |
  | Student  |---->|                                |
  | (any     |     |                                |
  |  client) |<----|                                |
  +----------+     +---------------+----------------+
                                   | JSON over HTTPS (session cookie)
                                   v
                        +----------------------------+
                        | Backend API                |
                        | (auth, roles, domains)     |----> [Relational Database]
                        |                            |----> [Session / Cache / Queue]
                        +-------------+--------------+
                                      |
                                      v
                        +----------------------------+
                        | File Storage (local)       |
                        +----------------------------+
                                      |
                                      v
                        +----------------------------+
                        | AI Proxy -> External       |
                        | Text-Generation Service    |
                        +----------------------------+
  Arrows are direct calls
```

In practice the React SPA in `frontend/` (Vite + React Router, dev on :3000 with a same-origin proxy to 127.0.0.1:8000 — see ADR-022) is what most people use. Other clients work the same way through the API.

---

## 3. Architecture Goals & Constraints

What drove the design.

| Goal / Constraint | Description | Source |
|---|---|---|
| External Clients | External to the architecture; any implementation that consumes the backend API on behalf of the authenticated user with a session. No framework, component, route, or navigation prescription. | External | Any tech |
| Strict separation of administrator, teacher, and student capabilities | Each role has dedicated endpoints and authorization checks so users can reach only their own functions and data. | Organizational |
| Session authentication with enforced password change and immediate deactivation | All authenticated calls require a valid session; accounts flagged for password change are blocked from every other gated action until the change completes, while sign-out, change-password, identity, and service-status reads remain available, and deactivated accounts lose all access immediately. | Regulatory |
| Classroom-centered organization structure | School years, semesters (trimesters 1–3), grade levels, sections, scoped subjects, classroom-derived teacher scope, and classrooms form a fixed hierarchy (School Year > Semester > Grade Level > Subject > Competencies) that scopes content, enrollment, and reporting. | Business |
| Competency-based assessment and mastery tracking | Announcements, assignments, assessments, grades, and mastery records tie learning content to competencies, with manual scoring, bulk scoring, release-gated results, and auto-save during taking. | Business |
| Complete auditability of significant actions | Authentication, content, grading, import, moderation, and structural changes produce append-only audit records with actor identity and network context under defined retention and anonymization rules. | Regulatory |
| Robust batch import and file enrollment | Bulk onboarding covers competency data plus manager-only student and teacher sheets. Sheets use downloadable single-column templates with format and integrity checks, size caps, and preview counts before saving. Good rows save while bad rows go to a Row/full_name/Reason + Help sheet for correction and retry through one file-enrollment flow per kind. | Technical |
| CompAss-ID identity handling with hidden record numbers | Every account uses a server-generated CompAss ID as its only login. No create or edit takes a record number. CompAss-ID rules: see ARCH-005 §4.1/§4.9 and ARCH-004 §4.1. That covers format and role prefix, login handling, single-account shapes, full_name-only bulk and hand placement with fresh IDs, and names plus CompAss-ID lookup with hidden numbers. | Business |
| School-scale safety and consent | Bulk deletes and bulk changes need explicit confirmation via API before they apply. Reports come back in small pages scoped to group or classroom. Score changes and hand moves keep full history. Errors say what failed and who can help. People listings show names only, with photos only with consent and no school-wide search. Staff-only powers are checked on the server. Bulk creates accounts only, never placements. Teachers join and link instead of bulk-placing by file. Moves to the same room are rejected with guidance. | Business |
| Server-side mediation of external AI capabilities | All text-generation calls go through a backend proxy that withholds direct identifiers and sends only competency, item, and response content with response content sent as-is, stores results with identifiers alongside for moderation views, retries transient failures in a bounded way, and subjects explanations to teacher moderation with status reporting. | Technical |
| Consistent validation, throttling, and error handling | Every mutation validates input, sensitive and repeated operations are rate-limited, and all failures return a uniform error envelope. | Technical |

---

## 4. Architectural Style & Rationale

The pattern we picked and why it fits.

- **Style:** Modular monolith backend, shipped as one service, layered and split by role: the React SPA in `frontend/` is our frontend unit (ADR-022, constraining ADR-021). Anything else is just an external client. Domain logic lives in a dedicated service layer behind role-grouped request handling, with validation and auth gates in one place. Clients call the API as the logged-in user. That means classroom-scoped creation, one file-enrollment flow per bulk kind, names-only people reads, enrolled-classroom reads, and human-name plus CompAss-ID search. We run one API service and one relational DB for persistent and session state, with local disk for files. Text generation goes only through the server-side proxy.
- **Rationale:** Classrooms, competencies, grading, and analytics are tightly coupled. Splitting them early would have bought us distributed consistency headaches for no pilot-scale payoff. One shared model keeps integrity, auth, and audit consistent. Layering keeps transport, validation, domain, and persistence apart. Splitting by role mirrors how the school actually works, so server-side access control stays straightforward.
- **Key trade-offs accepted:** One deployable is simpler to build and reason about, but we give up independent scaling per domain. One relational store plus local disk keeps consistency simple, but the DB becomes the availability bottleneck and files don't scale horizontally. Cookie sessions make revocation and forced password changes easy, but we pay with stateful sessions and CSRF discipline. Strict gating, validation, throttling, and audit cost latency and code. That's worth it for security and traceability. Synchronous request-response keeps flows simple, at the price of timeouts and explicit retry handling on long work.

---

## 5. High-Level Component View

The main building blocks and how they fit together — the core of this doc.

**Component / Container Diagram**

```
                         +---------------------------------------------------+
                         | External Clients (any implementation;             |
                         | consumes API on behalf of the authenticated user; |
                         | no architecture prescription)                     |
                         +-------------------------+-------------------------+
                                                   | JSON over HTTPS (session cookie)
                                                   v
                         +---------------------------------------------------+
                         |               Backend API                         |
                         | +------------------------------------------------+|
                         | | Role-Grouped Request Handling                  ||
                         | | (admin / teacher / student + shared grading,   ||
                         | |  competency, analytics, AI, audit, classroom)  ||
                         | +-----------------------+------------------------+|
                         |                         | validation,             |
                         |                         | authentication, gates,  |
                         |                         | throttling              |
                         |                         v                         |
                         | +------------------------------------------------+|
                         | | Service Layer                                  ||
                         | | (classroom, assignment, assessment, grading,    ||
                         | |  competency, analytics, import, AI, audit)     ||
                         | +-----------------------+------------------------+|
                         +-------------------------+-------------------------+
                        +------------------+       |       +------------------+
                        | Relational       |<------+------>| Session / Cache /|
                        | Database         |       |       | Queue Backing    |
                        +------------------+       |       +------------------+
                                                   |
                            +----------------------+---------------------+
                            |                                            |
                            v                                            v
                 +----------------------+                   +------------------------+
                 | File Storage (local) |                   | AI Proxy               |
                 | uploads, attachments |                   |  -> External Text      |
                 | error reports,       |                   |     Generation Service |
                 | templates            |                   +------------------------+
                 +----------------------+
Arrows are direct calls. The AI Proxy leg is the only outbound server-side call.
```

### 5.1 Component Inventory

| Component | Responsibility | Owner / Team | Technology |
|---|---|---|---|
| Role-Grouped API Handling | Receives HTTP requests, enforces authentication, role checks, password-change gating, input validation, and throttling, then delegates to the service layer. | Platform team | PHP web framework |
| Domain Service Layer | Implements classroom, assignment, assessment, grading, competency mapping, analytics, import, announcement, user listing (optional backward-compatible `?search`), and AI rules independently of transport and presentation. | Platform team | PHP web framework |
| Relational Persistence | Holds users, org structure, classrooms and enrollments, content, grades, mastery, AI artifacts, imports, and audit records. Users carry a server-generated CompAss ID (school_id NOT NULL UNIQUE ^(ADM\|TEA\|STU)-[0-9]{4}-[0-9]{5}$ with role-prefix CHECKs, retry on collision; email column dropped; photo_opt_in default off; no group/year columns). Classrooms carry subject_id + section_id and are the sole roster source. Hand placement always creates a fresh account and bulk always creates fresh IDs. Imports use staged full_name-only rows with single-column templates and Row/full_name/Reason + Help sheets. There's no self-service reset token store per ADR-018. Manager temps use users.must_change_password plus session revoke plus audit with no new tables. Audit keeps integrity constraints, hidden record numbers, and history for score changes, moves, and leaves. Ref: subject_sections and teacher_subject_section_assignments deleted; learner_code holds generated school_id; classroom_enrollment_moves, classroom_leave_history. | Platform team | Relational database |
| Session, Cache and Queue Backing | Persists session state for cookie authentication in the relational store, holds only rate-limit counters in cache with no domain read-through caching, and enqueues no background jobs with all processing synchronous and scheduled maintenance running in-process. | Platform team | Relational store with in-process cache handling |
| File Storage | Stores uploaded attachments, submission files, import templates, and generated error reports under access-controlled retrieval. | Platform team | Local file storage |
| AI Mediation Proxy | Forwards explanation and follow-up requests to the external text service by withholding direct identifiers and sending only competency, item, and response content with response content sent as-is, retries bounded transient failures, persists results with identifiers alongside for moderation views, and reports service status. | Platform team | PHP web framework with outbound HTTP client |
| Batch Import Handling | Handles competency data plus manager-only student sheets (TYPE_STUDENT_ENROLLMENT) and teacher sheets (TYPE_TEACHER_APPLICATION). Limits are 15 MB, 5000-row ceiling, 60-min single-use preview token, and 7-day single-use Row/full_name/Reason + Help sheet. Counts reconcile as accounts-not-placements with updated always 0 and duplicate_matches as in-file repeats. It runs synchronously with no queuing. It checks size caps, shows preview counts, and needs explicit confirmation before saving. Good rows save while bad rows go to the error sheet. It always creates fresh CompAss IDs and never matches by name. Jobs and logs are recorded, with error reports for correction and retry through one flow per kind. | Platform team | PHP web framework with synchronous processing |
| Audit Recorder | Captures append-only records of authentication, content, grading, score changes, learner hand moves with who moved whom and when, classroom leaves, import, moderation, and structural changes with actor identity and network context. | Platform team | PHP web framework with relational storage |
| React SPA Frontend | Primary client in frontend/ (Vite + React Router JS, dev :3000). It uses Sanctum cookie session with CSRF bootstrap (force-fresh at login, single 419 refresh-and-retry). Role guards mirror the server with forced password-change redirect. It polls on an interval and refreshes on release, with 15 MB pre-checked FormData uploads and blob downloads. It handles `{ data, meta? }` and `{ error: { message, code, fields? } }` with 401/419/403/429 behavior and plain throttle messages. Same-origin proxy (/api + /sanctum to 127.0.0.1:8000) is the default per ADR-022. Pages cover admin Competencies catalog (paged GET /api/admin/competency-tags per ADR-023), teacher ClassReport, Account + Change Password, Users tabs List / Enroll Students / Apply Teachers with Enroll Student + Apply Teacher buttons and SingleAccountForm {name, role} plus BulkWizard full_name-only preview/confirm per ADR-025, Onboarding for hand place/move via placeLearner(full_name, classroom_id) plus Competency import only, CompAss-ID-only Login, and Help. Modals layer scrim < overlay < drawer. Scale follows server counts via meta.total with Showing X of N pagination and per_page ceiling 100. Shell is presentational only with no API or auth change. It keeps one fixed 220px sidebar on every screen, portal-centered modal, OS-driven theme with stored pin, flat cards and tables with elevation on modal/drawer only, skip-link to #main-content, and SPA Link nav. Ref: InitTheme, drawer below 960px. | Platform team | React + React Router (Vite) |

### 5.2 Component Interactions

How the pieces work together on the flows that matter most.

- **Flow 1: Classroom assignment lifecycle:** External admin client -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence enrolls learners two ways. Bulk uses manager-only full_name-only sheets with one flow per kind, preview counts plus explicit confirmation, and 410 re-upload guidance. IDs stay fresh with updated = 0 and duplicate_matches as in-file repeats. Counts reconcile as accounts, not placements. Hand placement uses placeLearner(full_name, classroom_id) to school_id (201 shape, always a fresh account). Moves (200) reject the same destination with 409 plus guidance. Ref: classroom_enrollment_moves. External teacher client -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence creates the classroom via POST /api/teacher/classrooms with subject_id + section_id. No subject_section_id. Subject and section must share one grade_level_id, else 422. Duplicate teacher + subject + section + school year returns 409 DUPLICATE_CLASSROOM. Creation needs classroom scope. Teacher scope is the Semester + Grade Level + Subject package from owned classrooms. There's no assignment table. It's partitioned per Semester via ?semester_id with the triple from GET /api/teacher/classrooms/{id}/competency-context. Attachments sit in File Storage. External student client -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence joins with a join key. It lists enrolled rooms with detail and reads the names-only paged people listing. It pulls classwork, then submits with files in File Storage. It can leave via API. Ref: already_left converges; classroom_leave_history plus audit. External teacher client -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence reviews submissions and records feedback. That includes manual scoring, bulk scoring, and resubmit/retake (1-1000-char reason via API). Audit captures enrollment, moves, leaves, submissions, feedback, and score changes.
- **Flow 2: Assessment lifecycle with grading and release:** External teacher client -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence authors the assessment with items and releases it to the classroom. External student client -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence starts an attempt, auto-saves responses to Relational Persistence, and submits for scoring. Domain Service Layer to Persistence applies automatic scoring with remaining manual items held for synchronous scoring. Then teacher client scores pending items with manual and bulk grading, releases results, and updates mastery records. Student client to AI Mediation Proxy requests explanations for released results, with moderation reviewable by teacher role.
- **Flow 3: Competency import with analytics and mastery:** External admin client -> Role-Grouped API Handling -> Batch Import Handling -> Relational Persistence generates a template on demand. It uploads competency data or manager-only full_name-only student/teacher sheets (TYPE_STUDENT_ENROLLMENT / TYPE_TEACHER_APPLICATION). Limits are 15 MB, 5000-row ceiling, 60-min preview token, and 7-day Row/full_name/Reason + Help sheet. It checks size caps and shows preview counts before saving with explicit confirmation. It validates format and integrity with atomic per-row handling. IDs are always fresh and never matched by name, with updated = 0 and duplicate_matches as in-file repeats. It records the import job through one flow per kind. Batch Import Handling to File Storage builds an error report for correction and retry on failure. Service Layer to Persistence recomputes mastery and competency summaries from grades and outcomes. External clients -> Role-Grouped API Handling -> Domain Service Layer -> Relational Persistence read heatmaps, gap reports, trends, drill-downs, school-wide overviews, per-group class reports, manager views, and past scores history from updated state. Reads come back in small pages scoped to group or classroom, with human-name search (digits-only refused 422) and hidden record numbers. The React SPA shows these reads with interval polling plus release-triggered refresh under the same contracts.

---

## 6. Data Flow Overview

How data moves through the system at a glance.

```
[External clients] -- session cookie + JSON over HTTPS --> [Backend API]
[Service Layer] <-- delegation -- [Backend API]
       |
       +--> [Relational Database]
       +--> [Session Store]
       +--> [Cache Store for rate-limit counters only]
       +--> [File Storage]
       +--> [AI Proxy] --> [External Text-Generation Service]

Responses come back the same way. No background workers — everything runs in-request, with cleanup on the in-process scheduler.
```

- **Primary data stores:**
  - Relational database: durable record for users, organization structure, classrooms and enrollments, content, submissions, grades, mastery, AI artifacts, imports, and audit logs.
  - Session store: server-side session state backing cookie authentication, password-change gating, and CSRF handling.
  - Cache store: rate-limit counters only with no domain read-through caching.
  - Queue handling: no queued background work with all processing synchronous and scheduled cleanup running in-process.
  - File storage: local disk holding uploads, attachments, submission files, import templates, and generated error reports.
- **Data ownership summary:**
  - Authentication and user management owns identity, credential state, and activation state. Identity uses server-generated CompAss IDs for all roles. CompAss-ID rules: see ARCH-005 §4.1/§4.9 and ARCH-004 §4.1. Resets are manager-only with no self-service surface per ADR-018. It also owns hidden record numbers and human-name plus CompAss-ID matching. Ref: createAccount(name, role) with retry; update name-only; no email column.
  - Organization administration owns school years, semesters, grade levels, sections, and scoped subjects. Semesters are trimesters 1-3. Grade levels are 7-12 with semester_id. Subjects hang off grade_level_id with code unique per grade. Ref: table semesters; App\Models\Semester; migration 2026_09_19_000001_restructure_semester_hierarchy.
  - Classroom management owns classrooms, join keys, enrollments, membership, leave with history, enrolled-classroom reads, and names-only paged people reads with consent-gated photos. Classrooms carry subject_id + section_id with unique teacher + subject + section + school year, else 409 DUPLICATE_CLASSROOM. GET /api/admin/subject-sections answers 410 GONE and legacy /terms routes stay only as deprecated Semester aliases. Ref: subject_sections and teacher_subject_section_assignments deleted, never referenced.
  - Content management owns announcements, assignments, assessments, items, retakes, and learning materials. All classroom content carries subject_id + semester_id with classroom scope. No subject_section_id. Items and materials validate the Competency triple, else 422 COMPETENCY_MISMATCH. Duplicate semesters return 409 SEMESTER_ALREADY_EXISTS.
  - Submission and grading owns assignment submissions, assessment attempts, responses, auto-saves, scores, score-change history, grades, mastery records, and competency flags.
  - Batch import handling owns import jobs, import logs, preview counts, size-cap enforcement, and error reports through a single file-enrollment path.
  - AI mediation owns stored explanations, follow-up turns, moderation flags, and teacher notes.
  - Audit recording owns append-only audit records including score changes and learner hand moves with who moved whom and when.
  - Analytics and competency reporting derive read-only views from grades, mastery, and assessment outcomes in small pages scoped to group or classroom and own no primary data.

---

## 7. Integration & Communication Patterns

How the pieces talk to each other and the outside world.

| Interaction | Pattern | Protocol | Sync/Async |
|---|---|---|---|
| Clients <-> Backend API | Request/response with session cookie and CSRF header. React SPA defaults to same-origin proxy to 127.0.0.1:8000 with force-fresh login token and single 419 refresh-and-retry. Direct-origin fallback needs matching CORS/stateful config. Bodies are JSON, multipart for uploads. Login uses CompAss ID only (school_id, all roles, trimmed, case-sensitive). CompAss-ID rules: see ARCH-005 §4.1/§4.9. Single create takes {name, role} and update takes {name} with prohibited-key 422. Bulk sheets are full_name-only with fresh IDs. Lookup is by human names and CompAss IDs with hidden numbers, showing CompAss IDs plus teacher_school_id. Reads come in small pages scoped to group or classroom via meta.total with per_page ceiling 100 and Showing X of N display. Bulk deletes and changes need preview plus explicit confirmation. Errors say what failed and who can help. UI carries no endpoint paths, raw dumps, or numeric-id inputs. Empties are error-gated and submits carry ref+state guards. Modals layer scrim < overlay < drawer per ADR-023. Shell keeps one fixed 220px sidebar, portal-centered modal, OS-driven theme with stored pin, flat cards/tables with modal/drawer elevation only, skip-link, and SPA Link nav with no API change. | JSON over HTTPS | Sync |
| Backend API <-> Relational Database | Direct query and object-relational persistence with integrity constraints | SQL over database connection | Sync |
| Backend API <-> Session and Cache Stores | Session read and write on every authenticated request; cache holds only rate-limit counters | Internal store protocol | Sync |
| Backend API <-> Queue Handling | No queued jobs with all processing synchronous and scheduled maintenance running in-process | Internal store protocol | Sync |
| Backend API <-> File Storage | Direct local-disk write and access-controlled read of uploads, attachments, templates, and error reports | File-system I/O | Sync |
| AI Proxy <-> External Text-Generation Service | Outbound request with bounded timeout and single retry on transient failures; results stored immediately | JSON over HTTPS | Sync |

---

## 8. Deployment Topology (Summary)

One backend API service holds all domain logic, backed by one relational DB (including session and rate-limit state) plus a local file volume. The only outbound call is to the text-generation service for AI explanations. The React SPA in `frontend/` is the primary client (dev on :3000 through the same-origin proxy). No other UI service is deployed.

```
[External clients incl. React SPA (frontend/, dev :3000)] -- HTTPS --> [On-host Reverse Proxy] -- JSON over HTTPS --> [Backend API Service] -- SQL --> [Relational Database]
        |-- session / cache / queue backing (database-backed, sync processing)
        |-- React SPA dev proxy (/api + /sanctum → 127.0.0.1:8000, same-origin CSRF-safe)
        |-- file I/O --> [Local File Volume]
        |-- HTTPS --> [External Text-Generation Service]
```

- **Environments:** Dev runs the API next to any client with debug and verbose logs on. Testing uses an isolated store with cheaper crypto and in-memory mail. Production turns debug off, logs at error level, encrypts sessions, requires secure cookies, and never mocks AI. Rollout itself is operational (owned by the school operator): one year level pilots for a week or two, then the whole school; big changes stay frozen during exam weeks. Retention cleanups need the daily scheduler running to hold.
- **Hosting model:** Single-host pilot: on-host reverse proxy in front, one DB, one disk volume, no async infra. We only trust forwarded headers from the on-host proxy, and browser domains/origins are pinned to the pilot hosts.

---

## 9. Cross-Cutting Concerns

Things that cut across components — summarized here, detailed where they live.

| Concern | Approach | Detail Reference |
|---|---|---|
| Observability (logging, metrics, tracing) | Stacked logging writes to a single local stream with environment-scoped verbosity, error threshold in production and debug threshold elsewhere, and significant domain actions additionally produce structured audit records with actor identity and network context. | Covered here |
| Error handling & resilience | All API failures return a uniform envelope. Errors say what failed and who can help. Every mutation validates input centrally. Sensitive and repeated ops are rate-limited. Bulk deletes and changes need explicit confirmation via API with preview counts, size caps, and atomic per-row handling. Outbound AI calls use a bounded timeout with one retry on transient failures, storing results at once. | Covered here |
| Configuration management | All behavior differences across environments come from environment variables with local, testing, and production defaults; secrets and provisioning credentials live only on the host runtime and are never committed, mock AI output is prohibited outside non-production use, live rollout is operational (single year-level pilot for one to two weeks before whole-school use with big shape changes frozen during exam weeks, owned by the school operator), and staff-only capabilities are enforced on the server. | Covered here |
| Authentication & authorization | Sessions use cookies backed by server-side state with encrypted secure cookies in prod, short inactivity timeout, and CSRF protection. Password-change gating blocks other actions while sign-out, password change, profile, and status reads stay open. Login is CompAss-ID-only (school_id, trimmed, case-sensitive, per device-and-account bucket, no email login). Single create takes {name, role} with server auto-gen and update takes {name} with prohibited-key 422. Resets are manager-only per ADR-018. Temp goes to the calling admin only, with must_change_password, revoke of others, deactivation preserved, audit, and 403/422. No public forgot/reset. That returns 404/405. First-login parity per ASSUMED-9. Deactivation is immediate. Search is human-name plus CompAss-ID with digits-only refused 422 and hidden numbers. Role checks run on every request with manager-only bulk flows and names-only paged reads. | Covered here |
| Compliance | Significant auth, content, grading, score changes, hand moves, leaves, import, moderation, and structural actions are append-only audit with actor and network context. AI prompts withhold direct identifiers and send only competency, item, and response content as-is, storing identifiers alongside results for moderation. People listings show names only with photos only with consent and no school-wide search. Reports come in small pages scoped to group or classroom. Students can generate and read explanations for released results with teacher review via flagging, notes, and disabling further generation plus status reporting. | Covered here |

---

## 10. Known Risks & Open Questions

| Risk / Question | Impact | Owner | Status |
|---|---|---|---|
| Single relational database holds all durable and session state; outage or overload halts classroom activity, grading, and audit capture across all roles | High | Platform team | Open |
| Local disk file storage holds uploads, attachments, templates, and error reports; exhaustion, loss, or single-host binding blocks submissions, imports, and downloads | High | Platform team | Open |
| Cookie sessions depend on backing session state and cross-site request discipline; store loss or desynchronization forces sign-out and blocks enforcement flows | Medium | Platform team | Open |
| Explanations depend on an external text-generation service with bounded timeout and single retry; sustained latency or outage delays or fails explanations and follow-up answers | Medium | Platform team | Open |
| Large learner sheet files require size caps, preview counts, atomic per-row handling, and correction cycles; oversized or repeated uploads contend for synchronous processing capacity and delay enrollment and mastery refresh | Medium | Platform team | Open |
| Bulk deletes and bulk changes without preview and check discipline risk wide data impact across groups and classrooms | Medium | Platform team | Open |
| Append-only audit records grow with every significant action; unbounded accumulation raises storage demand and requires consistent enforcement of retention and anonymization rules | Medium | Platform team | Open |
| Retention and anonymization cleanups depend on a daily scheduler running; stopped scheduler leaves retention posture unmet | Medium | Platform team | Open |
| AI explanations and competency imports run synchronously in-request with no queuing; long operations risk timeouts and processing contention | Medium | Platform team | Open |

---

## 11. Glossary

| Term | Definition |
|---|---|
| Classroom | A teacher-managed group with enrolled students where announcements, assignments, assessments, and materials are shared |
| Competency | A defined knowledge unit linked to items, assignments, grades, and mastery. Teacher pickers use filtered Competencies with the triple from GET /api/teacher/classrooms/{id}/competency-context. Mismatched tags return 422 COMPETENCY_MISMATCH. Ref: competency_reference(subject_id, grade_level, semester '1'/'2'/'3'); GET /api/admin/competency-tags?subject_id&grade_level&semester. |
| Mastery | The recorded attainment level of a student for a competency derived from graded work and assessment outcomes; learners read it as part of past scores history |
| Past scores history | A learner's past scores over time. That means overall scores, per-question results, and per-competency mastery history. Looking never creates new AI explanations. |
| Classroom-scoped creation | Class files need a classroom. Announcements, assignments, assessments, and materials can't be created without one. |
| Assessment | A structured set of items assigned to a classroom with attempt, auto-save, scoring, and release flows |
| Assignment | Teacher-created classwork that students submit for review, feedback, and grading |
| Join key | A 6-character classroom join code that grants a student membership in a classroom upon joining |
| CompAss ID | The people-facing identity for every role. Format is users.school_id ^(ADM\|TEA\|STU)-[0-9]{4}-[0-9]{5}$ with role-prefix check Admin to ADM, Teacher to TEA, Student to STU, NOT NULL UNIQUE, server-generated only with retry. It's the sole login identifier, trimmed and case-sensitive. It's shown in list and detail responses plus teacher_school_id on classroom shapes. It stays distinct from hidden record numbers. |
| Learner code (historical) | An old people-facing code term, no longer an input. It's kept as the generated-school_id audit store. Ref: enrollment_import_rows.learner_code ('' when row failed before generation). |
| People listing | A names-only paged view of learners with no school-wide search, no edit actions, and photos only with consent |
| Rooms listing | The learner's classrooms with join and leave available via API |
| Human-name search | Lookup by learner, group, subject, and room names plus CompAss IDs matched to records inside, with record numbers hidden and CompAss IDs and join keys shown |
| Record number | A system-assigned internal identifier never supplied on create or edit and never shown in list or detail responses |
| Grading release | The teacher-controlled action that makes scores and feedback visible to students and updates mastery |
| Moderation | Teacher review and approval of AI explanations with status tracking and notes |
| Audit log | An append-only record of significant actions with actor identity and network context |
| Batch import | Bulk onboarding of competency data plus manager-only full_name-only student sheets and teacher sheets through single-column templates, validation, preview counts, and Row/full_name/Reason + Help error reports with correction and retry |
| Announcement | A classroom message from a teacher that may include attachments for enrolled students |
| Submission | Student-provided work or responses for an assignment or assessment including uploaded files |
| School year | The top-level time boundary that organizes semesters, grade levels, sections, and classrooms. Subjects hang off grade levels and Competencies hang off subjects (School Year > Semester > Grade Level > Subject > Competencies) |
| Semester | A trimester time division within a school year. It scopes grade levels, sections, and classroom activity. Legacy /terms routes stay only as deprecated aliases of the Semester routes. Ref: semesters table, semester '1'/'2'/'3', App\Models\Semester; App\Models\Term deprecated alias for removal. |
| Learning material | Teacher-shared content that supports classroom study outside graded work |

---

## Appendix: Diagram Legend / Notation

Boxes are actors, services, stores, and the external text service. Arrows are direct calls, reads, writes, and the one outbound server-side request. Responses come back the same way they came in.

---

## Revision History

| Version | Date | Author | Summary of Changes |
|---|---|---|---|
| 1.0 | 2026-09-07 | Platform team | Initial v1 established from implementation |
| 1.1 | 2026-09-09 | Platform team | Docs refresh. Documented bulk, manual, and move flows with preview, 410 handling, and move guidance. Covered rooms list and detail, PeopleTab plus LeaveCard, staff nav, Mastery Records, and legacy redirects. Fixed wording gaps for past scores history and gating. Reconciled x-refs to ARCH-002 to 006 with ASSUMED-1 in header. |
| 1.2 | 2026-09-10 | Platform team | Rework cycle 1 (ADR-016 + ADR-017). Scoped message-only reset and staged bulk identity. Updated persistence and auth rows. Header carries ASSUMED-1/7/8. |
| 1.3 | 2026-09-11 | Platform team | Follow-up for admin-only reset (ADR-018). Scope is manager-only with temp to admin only, must_change_password, revoke of others, deactivation preserved, audit, and 403/422. No public forgot/reset (404/405). First-login parity per ASSUMED-9. Updated persistence and auth rows. Header carries ASSUMED-1/7/8/9. |
| 1.4 | 2026-09-12 | Platform team | Presentational shell iterations, no API or data change (covers 1.4-1.5). Updated shared frontend styling with same routes, guards, and logic. |
| 1.6 | 2026-09-18 | Platform team | Frontend-agnostic: UI out of scope; backend API + relational DB + file storage (+AI proxy) with external generic clients; no framework/components/routes/navigation prescribed; server role checks only |
| 1.7 | 2026-09-19 | Platform team | React SPA frontend unit (ADR-022, constrains ADR-021). Scoped frontend in §1 and §2 with Vite plus React Router client. Added SPA row in §5 with CSRF, guards, polling, uploads, and envelope. Carried same-origin proxy in §7 and dev proxy in §8. |
| 1.9 | 2026-09-19 | Platform team | Frontend overhaul plus backend hardening (ADR-023). Added admin Competencies catalog, ClassReport, Account plus Change Password, and Help with scrim, overlay, and drawer layering plus server-count scale. Carried UI policies for inputs, empties, and submit guards. |
| 2.0 | 2026-09-20 | Platform team | Semester hierarchy restructure. School Year to Semester (trimesters 1-3) to Grade Level to Subject to Competencies. Classrooms use subject_id plus section_id with 409 DUPLICATE_CLASSROOM. Content uses subject_id plus semester_id. Teacher scope comes with competency-context read. Filtered Competencies return 422 COMPETENCY_MISMATCH and duplicates return 409 SEMESTER_ALREADY_EXISTS. Subject-sections returns 410 GONE with legacy terms as aliases. Ref: migration 2026_09_19_000001_restructure_semester_hierarchy; semesters table. |
| 2.1 | 2026-09-20 | Platform team | Sidebar and shell refinements, presentational only with no API or auth change (covers 2.1-2.3). Kept one fixed 220px sidebar, OS-driven theme with stored pin, and portal-centered modal. |
| 2.4 | 2026-09-21 | Platform team | CompAss rework (ADR-025). Identity is CompAss-ID-only with server-generated IDs and no email. Bulk student and teacher sheets are full_name-only with fresh IDs and Row/full_name/Reason plus Help sheets. Hand placement uses placeLearner with Users tabs and Onboarding updates. Login is CompAss-ID-only. Tests pass. |
