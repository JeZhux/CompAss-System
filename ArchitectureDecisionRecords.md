# Architecture Decision Records (ADR)

| Field | Value |
|---|---|
| **Document ID** | ARCH-003 |
| **System / Project Name** | CompAss |
| **Version** | 2.2 |
| **Status** | Approved |
| **Owner(s)** | Platform team |
| **Last Updated** | 2026-09-21 |
| **Related Docs** | ARCH-001 ArchitectureOverview, ARCH-002 RequirementsQualityAttributes, ARCH-004 DataArchitecture, ARCH-005 APIEventContracts, ARCH-006 DeploymentSecurityArchitecture — assumptions in ARCH-002 section 6 (ASSUMED-1/7/8/9 apply throughout) |

---

## 1. Purpose & Scope

How we log decisions here, and what counts.

- **Purpose:** Capture the big calls, what pushed us there, and what else we considered — so the next reader gets the *why*, not just the *what*.
- **What qualifies as "significant":** Things that are painful to undo, touch several components or people, force a real trade-off, or settle a tension from ARCH-002.
- **What does NOT need an ADR:** Routine implementation detail, stuff that's easy to reverse, or calls with only one sane option.
- **Process:** Propose via PR, discuss, then mark *Accepted* or *Rejected*. Once accepted it's frozen — if things change, write a new ADR that *supersedes* it instead of editing history.

---

## 2. ADR Index

The living list. If it's not here, it's not decided.

| ID | Title | Status | Date | Supersedes | Related QA/Req |
|---|---|---|---|---|---|
| ADR-001 | Modular monolith backend with web UI service | Accepted | 2026-09-07 | — | QA-003, QA-005, QA-009 |
| ADR-002 | Classroom-centered model with join-key enrollment | Accepted | 2026-09-07 | — | FR-006, FR-007, FR-008, FR-009, QA-003 |
| ADR-003 | Cookie-session auth with role and password-change gating | Accepted | 2026-09-07 | — | FR-001, FR-002, FR-003, FR-004, QA-004; gate exceptions constrained by ADR-018 (reset endpoints removed) |
| ADR-004 | Assessment authoring with release-gated grading and mastery | Accepted | 2026-09-07 | — | FR-015, FR-018, FR-019, FR-020, QA-001 |
| ADR-005 | Synchronous competency import with templates and error reports | Accepted | 2026-09-07 | — | FR-031, QA-003, QA-009 |
| ADR-006 | Server-side AI proxy with moderation and status reporting | Accepted | 2026-09-07 | — | FR-027, FR-028, FR-030, QA-008, QA-010 |
| ADR-007 | Append-only audit trail with retention and anonymization | Accepted | 2026-09-07 | — | FR-032, QA-006 |
| ADR-008 | Synchronous processing with no background workers | Accepted | 2026-09-07 | — | QA-003, QA-005, QA-009 |
| ADR-009 | Server-rendered shell frontend with role-grouped experiences | Superseded by ADR-021 | 2026-09-07 | — | QA-001, QA-004, QA-007 |
| ADR-010 | File-based learner enrollment through a single manager path with preview and error sheet | Accepted | 2026-09-07 | — | FR-033, FR-040, QA-011, QA-012; constrained by ADR-017 |
| ADR-011 | Hand placement of single learners with history and double-place block | Accepted | 2026-09-07 | — | FR-034, FR-032, QA-012; constrained by ADR-017 |
| ADR-012 | Learner view-only names-only people list with opt-in photos | Accepted | 2026-09-07 | — | FR-035, FR-009, QA-010, QA-013 |
| ADR-013 | Human-name search with auto-assigned hidden record numbers | Accepted | 2026-09-07 | — | FR-039, FR-010, QA-004 |
| ADR-014 | Change-safety with preview before destructive or bulk actions | Accepted | 2026-09-07 | — | QA-011, QA-012, QA-013, FR-033, FR-034 |
| ADR-015 | Staged rollout with grade-level pilot and exam freeze | Accepted | 2026-09-07 | — | C-009, C-002 |
| ADR-016 | Password-reset delivery must never return raw token in production; uniform no-oracle response | Accepted (history only — self-service half superseded by ADR-018) | 2026-09-09 | ASSUMED-5 | FR-038, QA-004; constrained ADR-003; self-service half superseded by ADR-018 |
| ADR-017 | Learner group/year are optional validation-only staged context, not canonical roster columns | Superseded by ADR-025 | 2026-09-09 | — | FR-033, FR-034, QA-011; constrains ADR-010/ADR-011; clarifies ASSUMED-2 via ASSUMED-8 |
| ADR-018 | Admin-only password reset; no unauthenticated forgot/reset surface | Accepted | 2026-09-10 | Self-service half of ADR-016 | FR-003, FR-004, FR-038, QA-004; constrains ADR-003; narrows C11 |
| ADR-019 | Frontend design-system overhaul, presentational-only | Superseded by ADR-021 | 2026-09-12 | — | QA-007; notes ADR-009 (no route/guard change) |
| ADR-020 | Compass v2 presentational-only overhaul | Superseded by ADR-021 | 2026-09-12 | — | QA-007; builds on ADR-019; notes ADR-009 (no route/guard change) |
| ADR-021 | Frontend-agnostic backend; UI out of scope | Accepted | 2026-09-18 | ADR-009, ADR-019, ADR-020 | QA-001, QA-004, QA-007 |
| ADR-022 | React SPA frontend (Vite + React Router JS) with same-origin CSRF proxy | Accepted | 2026-09-19 | — (constrains ADR-021) | QA-001, QA-004, QA-007 |
| ADR-023 | Frontend-overhaul lessons made policy: catalog reads, throttle keying, token ownership, search hardening, grade band 7–12, UI policies | Accepted | 2026-09-19 | — (constrains ADR-005/ADR-010/ADR-013/ADR-014/ADR-022) | FR-031, FR-033, FR-039, QA-001, QA-003, QA-004, QA-007 |
| ADR-024 | Semester hierarchy restructure: School Year > Semester > Grade Level > Subject > Competencies | Accepted | 2026-09-20 | — (reshapes ADR-002/ADR-004/ADR-005; constrains ADR-010/ADR-011) | FR-005, FR-012, FR-015, FR-031, QA-003, QA-010 |
| ADR-025 | CompAss ID-only identity with email removal, full_name-only bulk, and Users-tabs frontend | Accepted | 2026-09-21 | ADR-017 (group/year staged retention) | FR-001, FR-002, FR-003, FR-004, FR-033, FR-034, FR-035, FR-038, QA-004, QA-011; constrains ADR-003/ADR-010/ADR-011/ADR-013/ADR-022/ADR-023 |

**Status key:**
- **Proposed** — under discussion, not yet decided
- **Accepted** — decided and in effect
- **Rejected** — considered and explicitly not adopted (kept for record)
- **Deprecated** — no longer recommended, but not yet replaced
- **Superseded** — replaced by a later ADR (link forward to it)

---

## 3. Records

What we decided, in order. Oldest first so you can follow the story.

### ADR-001: Modular monolith backend with web UI service

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

The domain is tightly coupled around classrooms, competencies, grading, and analytics, so integrity, authorization, and audit behavior must stay consistent across administrator, teacher, and student flows. The pilot serves around 30 concurrent users with headroom toward 500 on a single standard server plus database posture. UI and domain rules need to evolve independently without splitting consistency boundaries prematurely.

#### Decision

> We will build a modular monolith backend that concentrates domain logic in a dedicated service layer behind role-grouped request handling with centralized validation, authentication, authorization, and throttling, paired with a web UI service that renders the shell and presents role-grouped administrator, teacher, and student experiences, with both services sharing one relational store.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Modular monolith with web UI service (chosen) | One backend deployable with layered, role-partitioned domains plus a separate UI service sharing one relational store | Consistent integrity and access rules; simple to build, test, and reason about; UI and domain evolve independently | No independent scaling or deployment per domain; strong availability demands on the shared store | *(chosen — see rationale below)* |
| Independently deployable per-domain services | Separate services per domain with their own stores and integration contracts | Independent scaling and deployment; isolated failure domains | Distributed consistency, duplicated authorization and audit logic, heavy operational cost at pilot scale | Turned down: consistency and cost burden outweighs scaling benefit under C-002 and C-003 |
| Single combined process serving UI and API | One process renders pages and executes domain logic directly | Fewest moving parts; no service boundary to maintain | Presentation and domain logic entangle; role experiences cannot evolve or deploy independently | Passed over: violates layering needed for QA-005 and weakens role-boundary enforcement |

#### Rationale

The chosen option best satisfies QA-005 (separated layers with reviewable, version-controlled structural changes) and QA-009 (single standard server plus database with synchronous processing), while meeting QA-003 at pilot scale through per-client limits rather than independent scaling. We accept shared-store availability pressure and the loss of per-domain deployment under C-002 and C-003 because classroom, grading, and analytics integrity depends on one consistent rule set.

#### Consequences

- **Positive:** One consistent set of validation, authorization, and audit rules across all roles; simpler testing and reasoning; UI and domain rules evolve independently behind a stable request boundary.
- **Negative:** Domains cannot scale or deploy independently; the shared relational store is a single point of pressure; local file handling constrains horizontal file serving.
- **Neutral / Follow-up:** Role-group review becomes the enforcement point for every new endpoint; store capacity and slow-query review needed as load approaches the 500-user ceiling; a future split requires a superseding decision with migration and contract work.

#### Compliance / Verification

Route-group review confirms each new endpoint lands in the correct role group and delegates to the service layer; layering review rejects transport or presentation logic inside domain rules; release review confirms structural store changes ship as version-controlled, reviewable scripts.

### ADR-002: Classroom-centered model with join-key enrollment

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

School years, terms, grade levels, sections, subjects, teacher assignments, and classrooms form the fixed hierarchy that scopes content, enrollment, and reporting. Teachers run classrooms and publish classwork there, while students move between rooms and must gain membership simply and safely. Roster changes, key rotations, and room lifecycle transitions must stay auditable.

#### Decision

> We will center organization, content, enrollment, and reporting on classrooms created one per assigned subject-section per school year with a derived name and a unique 6-character join key, with students joining idempotently by key, keys rotatable with superseded keys staying revoked, joining disablable, archived rooms refusing joins, and removals and leaves recorded.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Classroom-centered model with join keys (chosen) | Fixed hierarchy with teacher-created rooms, unique join keys, idempotent student joins, rotation and archive lifecycle | Simple student onboarding; clear scoping for content and reporting; revocable, auditable membership | Key distribution and key-abuse handling needed; duplicate and reassignment rules add creation checks | *(chosen — see rationale below)* |
| Centrally assigned enrollment | Administrators assign every student to rooms directly with no keys | Tight administrative control; no keys to distribute or abuse | Heavy administrative load; slow classroom formation; poor fit for teacher-managed rooms | Not chosen: fails teacher-managed classroom formation in FR-006 and adds friction at scale |
| Open self-enrollment without keys | Students join any visible room freely with no credential | Lowest friction joining | No membership control; wrong-room joins; weak audit story for roster integrity | Declined: violates QA-004 authorization expectations and roster accountability in FR-009 and FR-032 |

#### Rationale

The chosen option directly implements FR-006, FR-007, FR-008, and FR-009 with distinct handling for unknown, revoked, archived, and join-disabled keys and idempotent joins. It supports QA-003 through per-student join limits that isolate abusive clients on shared networks, upholds QA-004 through server-side membership checks on every classroom-scoped action, and feeds QA-006 through recorded roster transitions.

#### Consequences

- **Positive:** Predictable scoping of announcements, assignments, assessments, grades, and analytics by classroom; fast teacher-driven room setup; revocable and auditable membership.
- **Negative:** Join-key lifecycle (generation, collision retry, rotation history, revocation) is permanent logic to maintain; oversized or repeated join bursts still depend on throttling rather than queueing.
- **Neutral / Follow-up:** Join review confirms distinct feedback per key state without leaking sensitive detail; roster-change auditing stays mandatory; enrollment and mastery summaries derive from this model with no separate membership source.

#### Compliance / Verification

Classroom review confirms creation requires a matching assignment with duplicate creation rejected, join handling distinguishes unknown, revoked, archived, and join-disabled states, joins stay idempotent, and roster removals and leaves emit audit records.

**Consistency note:** The manager sheet path and hand-placement path operate alongside join-key enrollment with duplicate matching by learner code and recorded roster transitions; join-key rules, rotation, and archive behavior remain in effect with no separate membership source.

### ADR-003: Cookie-session auth with role and password-change gating

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Exactly three roles share one browser-based system with a single login field resolving students by school identifier and teachers and administrators by email address. New and reset accounts start from system-generated temporary passwords that must be replaced before any other access, deactivated accounts must lose access immediately, and concurrent multi-device sessions are permitted. Login and other sensitive operations face credential-attack and abuse pressure.

#### Decision

> We will authenticate all users through cookie-based sessions backed by server-side state with a short inactivity timeout, enforce server-side role checks on every request, block every gated action behind forced password change except sign-out, password change, identity, and service-status reads, deactivate accounts with immediate effect, and rate-limit login and repeated operations behind a uniform error envelope. Clients are external and handle 403/422 outcomes themselves (no client implementation prescribed; see ADR-021).

**Constraint note:** Constrained by ADR-018 — see that ADR. The two public reset routes are gone, so the remaining exceptions are sign-out, password change, identity, and service-status reads.

**Constraint note:** Constrained by ADR-025 — see that ADR. Login uses the CompAss ID only, with server-generated accounts.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Cookie sessions with role and password-change gating (chosen) | Server-side session state, session cookie, server role checks, forced-change gate, immediate deactivation, throttled login | Immediate revocation and deactivation; single uniform gate across roles; request-forgery and inactivity discipline in one place | Stateful session handling required; cross-site request discipline needed; store loss forces sign-out | *(chosen — see rationale below)* |
| Stateless signed tokens | Self-contained tokens validated without server state | No session store; easy horizontal scaling | Revocation and forced-change gating require extra blocklists; immediate deactivation is harder to guarantee | Declined: weakens QA-004 revocation and gate enforcement and complicates FR-003 and FR-004 |
| Separate auth mechanism per role | Distinct login and session handling for each role | Per-role tuning of policy and lifetime | Three policies to maintain; inconsistent deactivation and gating behavior; duplicated audit logic | Ruled out: violates uniform session expectations in C-005 and raises QA-005 maintenance cost |

#### Rationale

The chosen option satisfies FR-001, FR-002, FR-003, and FR-004 together with QA-004, including mixed-case credential policy with salted one-way storage, a 30-minute inactivity timeout, login limited to 5 failed attempts per 15-minute sliding window per device-and-account combination with no permanent lockout, and encrypted transport with request-forgery and injection protections. We favor enforced credential, session, and gate controls over sign-in convenience, keeping limit and error messages actionable per the QA-004 and QA-007 tension resolution.

#### Consequences

- **Positive:** Immediate deactivation and forced-change enforcement in one place; consistent role authorization on the server with uniform forbidden/error outcomes for any client; brute-force pressure absorbed without permanent lockout.
- **Negative:** Session backing state is a critical dependency where loss or desynchronization forces sign-out; every authenticated request pays a session read and write; throttled users see refusals under burst use.
- **Neutral / Follow-up:** Authentication review confirms gate exceptions stay limited to sign-out, password change, identity, and service-status reads; login-attempt and gate-block events stay audited; session lifetime and throttling thresholds are revisit triggers if usability or attack pressure shifts.

#### Compliance / Verification

Authentication review confirms role checks on every gated action, the forced-change gate blocks all other navigation except sign-out, password change, identity, and service-status reads, deactivated accounts are refused, login throttling reports limit with remaining attempts and reset time, and all failures return the uniform error shape.

### ADR-004: Assessment authoring with release-gated grading and mastery

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Teachers author assessments as drafts with items that each carry exactly one competency tag, then release them to enrolled students in a classroom. Students start attempts, record responses with auto-save protection, and submit once, while grading mixes automatic scoring of objective items with manual scoring of subjective items. Mastery must reflect only fully scored work, and practice-style assessments must never pollute past scores history (mastery history) or aggregates.

#### Decision

> We will run assessments through a draft-to-released lifecycle where release requires at least one item, post-release structural edits are blocked, students take assessments through start, auto-save, and single-submit flows, objective-only submissions auto-score to scored while subjective submissions wait in pending grading, manual grading plus bounded bulk grading with per-student partial success completes scoring, mastery recomputes deterministically at an 80% threshold persisted for Recorded assessments and returned display-only for Unrecorded ones, and results release is blocked while any submission remains pending grading.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Release-gated lifecycle with mastery recomputation (chosen) | Draft and released states with release and grading gates, auto-scored objective path, manual and bulk subjective path, threshold-based mastery persisted only for Recorded work | Guarantees complete scoring before mastery; keeps practice work out of history; preserves released content integrity | Gate logic and dual Recorded and Unrecorded paths add complexity; bulk partial success needs per-student reporting | *(chosen — see rationale below)* |
| Score and compute mastery immediately on submit | Every submission scores and updates mastery at once with no pending state | Simplest flow; fastest visible results | Unscored subjective work corrupts mastery; practice and recorded results mix; released content can change under live attempts | Passed over: violates complete-scoring and Recorded-only persistence expectations in FR-018, FR-019, and FR-020 |
| Asynchronous grading and mastery pipeline | Submissions queue for background workers that score, recompute mastery, and notify | Smooth handling of grading bursts; isolates slow mastery work | Eventual consistency for grades and mastery; worker infrastructure and failure handling at pilot scale; harder audit story | Set aside: adds operational cost that conflicts with C-002 and C-003 and weakens QA-001 result clarity |

#### Rationale

The chosen option satisfies FR-015 (authoring, tagging, release, and taking with auto-save that never triggers submission), FR-018 (manual and bounded bulk grading with score limits and no double-scoring outside the regrade path), FR-019 (deterministic per-competency mastery at the 80% threshold with zero-point groups skipped), and FR-020 (Recorded persistence with latest-result-wins reads and Unrecorded display-only results). It upholds QA-001 through predictable attempt, grading, and release states with uniform error outcomes for duplicate, expired, and out-of-order actions.

#### Consequences

- **Positive:** Grades and mastery always derive from fully scored work; released assessments stay stable under live attempts; practice work never leaks into history, flags, or aggregates.
- **Negative:** Gate and resubmission rules are permanent logic to maintain; bulk grading can partially succeed and requires callers to handle per-student errors; late and expired attempts need distinct auto-submit handling.
- **Neutral / Follow-up:** Grading review confirms pending-state gating, score bounds, and mastery divergence on every new item type or grading path; threshold and bulk-ceiling changes require a superseding decision.

#### Compliance / Verification

Lifecycle review confirms release requires at least one item, structural edits are refused after release, unscored work never recomputes mastery, Unrecorded results persist nothing, and results release is refused while grading is pending.

### ADR-005: Synchronous competency import with templates and error reports

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Administrators must load large sets of competency tags quickly, and real-world import files routinely contain a mix of valid rows and row-level errors such as missing codes, unknown subjects, or out-of-range grade levels. Failed rows must not destroy valid work, and administrators need a clear path to fix and understand failures. Import volumes must stay bounded so a single upload cannot exhaust request handling.

#### Decision

> We will process competency-tag imports synchronously within the upload request with downloadable templates defining the expected columns, row-level validation that imports valid rows while collecting failed rows, partial success with imported and failed counts, single-use time-limited error reports for failures, file-size and row-count ceilings with decompression safeguards, and no background queuing.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Synchronous import with templates and error reports (chosen) | In-request parsing with templates, partial success, downloadable failure reports, size and row ceilings, no queue | Immediate feedback; valid rows never blocked by bad rows; simplest operations with no worker infrastructure | Large files occupy request handling; very large imports must be split by the uploader | *(chosen — see rationale below)* |
| Queued background import with later notification | Uploads enqueue for workers that parse, validate, and report completion asynchronously | Frees request handling for very large files; isolates slow parses | Delayed feedback; job tracking, retry, and notification machinery; harder failure UX at pilot scale | Set aside: operational and UX cost outweighs benefit under C-002 and C-003 given bounded file sizes |
| All-or-nothing atomic import | Whole file validates first and commits only when every row is valid | Strongest dataset consistency per upload | One bad row discards all valid rows; slow fix-and-retry loops for large files | Turned down: fails partial-success expectations in FR-031 and punishes administrators for isolated row errors |

#### Rationale

The chosen option directly implements FR-031 with template-guided uploads, row-level validation, partial success, and retrievable error reports. It supports QA-003 through request throttling plus file-size, row-count, and decompression ceilings that bound each synchronous import, and meets QA-009 by avoiding worker infrastructure entirely at pilot scale.

#### Consequences

- **Positive:** Administrators get immediate imported and failed counts with actionable failure detail; valid rows always land; no queue or scheduler to operate.
- **Negative:** Oversized files are rejected rather than absorbed, so uploaders must split them; long synchronous parses hold a request slot; error reports need lifecycle cleanup after expiry or consumption.
- **Neutral / Follow-up:** Import review confirms ceiling enforcement before any write, blank-row handling, and single-use report expiry; ceiling changes or a future queued path require a superseding decision.

#### Compliance / Verification

Import review confirms templates match accepted columns, failing rows never block valid rows, counts reconcile with stored rows, error reports are single-use and expire, and oversized or over-expanded files are refused before any write.

### ADR-006: Server-side AI proxy with moderation and status reporting

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Students benefit from generated explanations for work they have not yet mastered, grounded in teacher-provided materials where available. Provider credentials must never reach browsers, student identity must never travel inside generation prompts, and provider outages must degrade gracefully without losing completed work. Teachers need after-the-fact oversight of generated content plus a way to check service health.

#### Decision

> We will proxy every provider call through the backend with credentials held server-side, withhold student identifiers from prompts while delimiting embedded content as data, retry only transient failures within a bound of two attempts with a fixed per-attempt timeout and deterministic output settings, generate only on explicit student request after results are released, persist each explanation immediately on receipt with idempotent per-attempt keying, mark ungrounded output explicitly, and provide post-hoc moderation through flagging, teacher notes, follow-up disabling, and a service status read.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Server-side proxy with post-hoc moderation (chosen) | Backend-held credentials, identifier withholding, bounded transient-only retry, persist-on-receipt, explicit-request generation, flag and note moderation, status read | Credentials and identity protected in one place; outages degrade uniformly; completed groups survive partial failures; teachers keep oversight without blocking delivery | Provider remains a runtime dependency; moderation is reactive rather than preventive | *(chosen — see rationale below)* |
| Direct client-to-provider calls | Browsers call the provider directly with scoped keys | Less backend work; no proxy latency | Credentials exposed to clients; identity and prompt controls unenforceable; inconsistent outage and persistence behavior | Ruled out: violates credential and identity protection in FR-028 and QA-008 |
| Pre-moderation holding queue | Generated content stays hidden until a teacher approves each explanation | Strongest content control before student visibility | Approval bottleneck delays every explanation; queue and notification machinery; poor fit for supplementary study aid | Declined: conflicts with timely post-release support in FR-027 and FR-030 and adds workflow cost |

#### Rationale

The chosen option satisfies FR-027 (explicit-request generation after release with persisted per-item explanations and ungrounded marking), FR-028 (server-held credentials, identifier withholding, delimited prompt data, and uniform outage outcomes), and FR-030 (moderation log with flagging, notes, follow-up control, and audit events). It upholds QA-010 through grounded prompting with explicit ungrounded disclosure and QA-008 through credential containment and post-hoc teacher oversight.

#### Consequences

- **Positive:** One enforced privacy and reliability policy for all generation; partial completions persist and self-heal on retry; students always see disclosure and availability state.
- **Negative:** Provider latency and outages surface as generation failures clients must handle; follow-up turns need permanent caps; moderation never prevents first delivery of a poor explanation.
- **Neutral / Follow-up:** Generation review confirms identifier withholding, retry bounds, persist-on-receipt, and moderation scoping on every new prompt path or model change; retry, timeout, and turn-limit changes require a superseding decision.

#### Compliance / Verification

Generation review confirms no credentials or identifiers leave the backend, prompts delimit embedded content as data, only transient failures retry within the attempt bound, reads never generate, and moderation actions and availability reads behave uniformly.

### ADR-007: Append-only audit trail with retention and anonymization

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Privileged actions across administrator, teacher, and student flows require a trustworthy history showing who acted, what changed, and when. Stored network addresses and agent strings create privacy pressure that grows the longer rows are kept. Cleanup must happen reliably without manual steps, while audit-write problems must never break the action being recorded.

#### Decision

> We will record every audited action as an append-only row through one centralized writer capturing actor, event type, message, affected entity, metadata, and request network data at write time, forbid updates and deletes outside scheduled retention work, irreversibly anonymize stored network addresses after 90 days, hard-delete rows older than 365 days, run both jobs on a running daily scheduler, and treat audit-write failure as non-blocking so the triggering action always completes.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Append-only trail with scheduled retention (chosen) | One writer, no updates, anonymization window plus purge window, daily scheduler, non-blocking writes | Tamper-resistant history; bounded privacy exposure; bounded growth; no operator action | Scheduler must run or rows accumulate; anonymized addresses lose forensic detail | *(chosen — rationale follows)* |
| Mutable editable log rows | Administrators correct or delete entries directly | Easy correction of mistaken entries | Breaks evidence integrity; hides abuse; weak accountability | Turned down: violates append-only expectations in FR-032 and weakens QA-006 |
| Unbounded retention with no anonymization | Keep all rows forever with full network data | Maximum forensic detail; simplest job setup | Unbounded growth; rising privacy exposure; conflicts with minimization | Set aside: fails retention and anonymization expectations in FR-032 and QA-006 |

#### Rationale

The chosen option directly implements FR-032 with centralized append-only writes carrying entity linkage and request network data, null network data for scheduler execution, masked identifiers in login-attempt records, anonymization after 90 days, and purge after 365 days. It upholds QA-006 through a complete tamper-resistant trail where every roster change, authentication block, grading action, and moderation action leaves a row, while privacy exposure stays bounded by time. We accept scheduler dependence and loss of old network detail under C-002 and C-003 because trustworthy history with bounded retention outweighs permanent full-fidelity storage.

#### Consequences

- **Positive:** One enforced integrity rule for all audited actions; bounded store growth; bounded privacy exposure; failures never block users.
- **Negative:** If the scheduler stops, anonymization and purge stop and rows accumulate until it resumes; old network detail is irreversibly lost after the window; high-volume action bursts add write load inside each request.
- **Neutral / Follow-up:** Retention windows stay configurable in one place with window changes requiring a superseding decision; audit review confirms new event types use valid labels and carry entity linkage; scheduler health becomes an operational check.

#### Compliance / Verification

Audit review confirms writes go through the single writer with no direct row updates outside retention jobs, failed writes return a null result and record an error without surfacing to users, scheduler runs anonymization and purge daily with configured windows, and new actions add audited rows with actor, type, and entity linkage.

### ADR-008: Synchronous processing with no background workers

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Pilot load centers on around 30 concurrent users with headroom toward 500 on one standard server plus a relational store, and domain work such as imports, grading, mastery recomputation, and generation must return clear outcomes inside the triggering request. Operating queues, workers, retry machinery, and notification plumbing would add cost without matching load. Large inputs still need bounds so one upload or burst cannot exhaust request handling.

#### Decision

> We will execute all domain work synchronously inside the triggering request against one relational store plus local files, with the queue connection fixed to synchronous execution, no queues or background workers for domain work, and size, count, timeout, retry, and throttling ceilings bounding each operation, reserving scheduled execution only for retention cleanups (audit retention/anonymization plus import error-report expiry).

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Synchronous-only with single store and local files (chosen) | In-request execution, one relational store, local files, ceilings and throttling, synchronous queue connection | Immediate outcomes; simplest operations; consistent rules in one place; no worker infrastructure | Large work holds a request slot; oversized inputs are refused and must be split; local files constrain horizontal serving | *(chosen — rationale follows)* |
| Queued background workers for heavy work | Imports, grading, and generation enqueue for workers with later completion | Frees request handling for large work; isolates slow tasks | Delayed outcomes; job tracking, retry, and notification machinery; harder failure UX at pilot scale | Passed over: operational and UX cost outweighs benefit under C-002 and C-003 |
| Separate stores with object storage | Domain splits across stores with files in external object storage | Independent scaling for reads, writes, and files | Distributed consistency burden; duplicated authorization and audit logic; heavier deployment | Declined: consistency and cost burden outweighs scaling benefit at pilot scale under QA-009 |

#### Rationale

The chosen option meets QA-009 through one deployable backend plus UI service sharing one relational store with synchronous execution and no worker infrastructure, and supports QA-003 through throttling plus file-size, row-count, decompression, timeout, and retry ceilings that bound each synchronous operation. It upholds QA-005 by keeping validation, authorization, and audit rules in layered in-request code that stays reviewable and testable without distributed flows. We accept refused oversized inputs and held request slots under C-002 and C-003 because immediate consistent outcomes outweigh background throughput at pilot scale.

#### Consequences

- **Positive:** Immediate success, partial-success, and failure outcomes with uniform errors; valid rows and completed groups persist without waiting on workers; no queue or notification systems to operate.
- **Negative:** Long synchronous parses, bulk grading, and generation hold request slots and surface provider latency directly; oversized work is refused rather than absorbed; horizontal file serving stays constrained.
- **Neutral / Follow-up:** Import, grading, and generation review confirms ceiling enforcement before any write on every new heavy path; ceiling changes or a future queued path require a superseding decision; store capacity and slow-query review continue toward the 500-user ceiling.

#### Compliance / Verification

Release review confirms the queue connection remains synchronous with no worker-backed domain paths, new heavy operations declare size, count, timeout, and throttling bounds enforced before any write, and failure outcomes return the uniform error shape with actionable counts where partial success applies.

### ADR-009: Server-rendered shell frontend with role-grouped experiences

> Superseded by ADR-021 (2026-09-18): UI is external; backend owns all domain/auth/data/contracts. History retained below unchanged.

| Field | Value |
|---|---|
| **Status** | Superseded by ADR-021 |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | ADR-021 |

#### Context

Administrators, teachers, and students share one system but need distinct navigation, pages, and guards, with authentication, forced password change, and role checks enforced consistently. The shell must load fast with stable layout while role mistakes fail safely to a uniform forbidden outcome. UI and domain rules must evolve independently behind a stable request boundary.

#### Decision

> We will deliver a separate web UI service that renders the application shell on the server and presents administrator, teacher, and student experiences through role-grouped routes, with client guards mirroring server role and password-change enforcement, a shared shell with navigation, skeletons, and uniform error displays, and no domain logic inside presentation components.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Server-rendered shell with role-grouped routes (chosen) | Separate UI service, server-rendered shell, grouped administrator, teacher, and student routes, client guards mirroring server enforcement | Fast initial shell; stable layout without flashes of wrong role; UI and domain evolve independently | Two deployables to operate; guard logic must mirror server rules on every new route | *(chosen — rationale follows)* |
| Pure client-rendered application with no server shell | Browser builds all pages after load with no server rendering | Simple deployment as static assets; rich client interactions | Slow initial paint; layout shifts and flashes of wrong role before guards resolve; weaker sharing of shell discipline | Not chosen: weakens QA-001 clarity and QA-007 layout stability |
| Separate frontend for each role | Three UI codebases, one for each role | Isolated evolution for each experience | Tripled maintenance; inconsistent shell, navigation, and error behavior; duplicated guard logic | Ruled out: raises QA-005 maintenance cost and breaks uniform experience expectations |

#### Rationale

The chosen option supports QA-001 through predictable role-grouped pages with explicit loading, empty, and forbidden states plus uniform error outcomes, upholds QA-004 through server enforcement mirrored by client guards including forced-change gating and immediate deactivation handling, and meets QA-007 through a shared shell with fixed skeletons that avoid layout shift. We accept dual-deployable operations and guard-mirroring discipline under C-002 and C-003 because consistent role experiences with stable layout outweigh single-process simplicity.

#### Consequences

- **Positive:** Consistent navigation, loading, and error behavior across roles; fast initial shell with no flash of wrong role; UI changes ship without touching domain rules.
- **Negative:** Two deployables to operate and keep compatible across the request boundary; every new route must add both server enforcement and mirrored client guards; shell regressions affect all roles at once.
- **Neutral / Follow-up:** Route review confirms correct role grouping and guard mirroring on every new page; shell changes require cross-role visual checks; a future merged or split frontend requires a superseding decision.

#### Compliance / Verification

Route review confirms each new page lands in the correct role group with server enforcement plus mirrored client guards, unauthenticated and forbidden outcomes render uniform displays, and presentation components hold no domain rules with all checks delegated to the backend boundary.

**Consistency note (UI names history only — superseded by ADR-021, not a navigation prescription):** The learner people list remains a view-only names-only PeopleTab by default with photos shown only under explicit opt-in consent (photo_opt_in default off), served in small pages (15/100, alphabetical) with no cross-school search (any ?search= 422) and no edit actions, while the leave action lives in a separate LeaveClassroomCard outside People (already_left converges; classroom_leave_history plus audit); the rooms list/detail sits behind the two home View-classrooms buttons (unpaginated, name-ordered); role grouping and guard mirroring remain in effect. Staff nav exposes Manual Grading, Bulk Grading, Resubmit (Retake) at grading/manual#resubmit (ResubmitDialog, 1–1000-char reason), Mastery Records for admin and teacher, and the dynamic section report as canonical (old /teacher/mastery redirect removed); Nav carries Bulk Enroll to the canonical path.

### ADR-010: File-based learner enrollment through a single manager path with preview and error sheet

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Whole-school onboarding means enrolling many learners at once from a sheet. At the time each row carried a required learner code and full name, with group and year as optional staged context.

Source sheets are messy in practice. Some rows are valid while others have missing codes, unknown groups, or malformed year levels. Codes already in the system match and update the name instead of creating duplicates.

Operators need to see counts before anything is saved, confirm explicitly, then get row-level reasons for failures without losing the valid rows. Each row saves fully or not at all, and failures use plain wording that tells the operator what to do next.

Ref: ceilings 15 MB and 5000 rows, preview token single-use 60-min, error sheet single-use 7-day.

#### Decision

> We will enroll learners from sheets through one manager-only API flow that validates required columns (learner_code, full_name) plus optional group/year against the org vocabulary (unknown values fail the row, never auto-create org rows), presents counts for checking before saving requiring explicit confirmation via preview+confirm, matches existing learners by code with name update rather than duplicate creation, saves valid rows while collecting failed rows into a single-use 7-day error sheet carrying row-level reasons, reconciles identity counts (imported = creates, updated = name updates, failed = rejects; explicitly NOT placements), retains supplied group/year in staged rows plus error and audit metadata ONLY (never canonical columns, never read-honored), and commits each row fully or not at all.

**Constraint note:** Constrained by ADR-017 — see that ADR. Counts are identity counts, not placements.

**Constraint note:** Constrained by ADR-025 (supersedes ADR-017) — see that ADR. Bulk sheets are full_name-only and every valid row creates a new account.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Single manager-only path with preview and error sheet (chosen) | One enrollment path with required-column checks, pre-save counts, code-based duplicate matching, partial success, single-use error sheet | One clear path; valid rows never blocked by bad rows; duplicate-safe identity; actionable failure detail | Large uploads hold request handling; oversized uploads face refusal and splitting; error sheets need expiry cleanup | *(chosen — see rationale below)* |
| Teacher bulk upload alongside manager upload | Both managers and teachers upload sheets | More upload entry points | Duplicated authorization logic; weaker control over group assignment; higher risk of conflicting uploads | Not chosen: dilutes manager-only control and complicates duplicate handling |
| Dual enrollment flows with old and new paths side by side | Keep legacy import flow plus new sheet flow | No removal work | Two look-alike flows confuse staff; doubled maintenance; inconsistent validation and error handling | Declined: preserves confusion and splits feedback quality |
| All-or-nothing atomic upload | Whole sheet validates first and commits only when every row passes | Strong per-upload uniformity | One bad row discards all valid rows; slow fix-and-retry loops for large sheets | Turned down: punishes operators for isolated row defects and blocks valid work |

#### Rationale

Staff get one predictable enrollment path with pre-save counts and partial success. Valid rows land and failed rows come back with reasons. Manager-only enforcement keeps onboarding under accountable control while teachers keep join flows without bulk rights. Group and year intent stays auditable in staged retention without becoming a second grouping source. Per-row atomicity means no half-written learner records.

#### Consequences

- **Positive:** One clear enrollment path; valid rows always land; duplicates resolve by code without double creation; operators receive counts plus row-level reasons for correction.
- **Negative:** Large uploads occupy request handling; oversized uploads face refusal and require splitting by the uploader; error sheets require single-use handling and expiry cleanup; template and ceiling discipline remains permanent maintenance.
- **Neutral / Follow-up:** Enrollment review confirms ceiling enforcement before any write, blank-row handling, count reconciliation, and single-use error-sheet expiry on every change to columns, ceilings, or matching rules; ceiling or role-scope changes require a superseding decision.

#### Compliance / Verification

Enrollment review confirms manager-only access, required-column plus vocabulary checks, duplicate matching by code with name update, pre-save counts reconciled as identity counts, staged-only retention of supplied group and year, failed rows collected with reasons, per-row atomicity, 410 expired-preview handling with re-upload guidance, plain wording that tells the operator what to do next, and refusal of oversized uploads before any write.

Ref: ceilings 15 MB and 5000 rows, single-use 7-day error sheets.

### ADR-011: Hand placement of single learners with history and double-place block

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Daily operation needs manual placement and movement of a single learner by a manager, distinct from bulk sheet enrollment. An already placed learner must not receive a second concurrent placing; the operator needs guidance toward move instead. Every manual move requires a durable history entry recording who moved whom, destination, and time, supporting audit of all learner moves alongside score changes.

#### Decision

> We will place (201 manual shape) and move (200 with message; identical destination 409; archived destination 410) single learners through a manager hand API flow (move requires explicit confirmation via API) that blocks a second placing (409) for an already placed learner with move-guidance toward move, validates any supplied place-time group/year as in ADR-010/ADR-017 and keeps them in audit/history request metadata ONLY (never roster columns, never read-honored), and records every hand move in classroom_enrollment_moves history with actor, learner, destination, and time as an immutable entry plus audit.

**Constraint note:** Constrained by ADR-017 — see that ADR. Supplied group and year are never treated as roster scope.

**Constraint note:** Constrained by ADR-025 (supersedes ADR-017) — see that ADR. Hand place always creates a fresh account.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Hand path with double-place block and history (chosen) | Single-learner place and move with concurrent-place refusal, move guidance, immutable history entries | Prevents duplicate placement; clear move intent; accountable record of who moved whom and when | Extra step when a second placing was intended; history volume grows with each move; corrections require new move entries | *(chosen — see rationale below)* |
| Unrestricted double placement | Manual placement with no concurrent-place check | Fewest clicks for repeated placement | Duplicate placements; ambiguous group membership; room reporting diverges | Ruled out: breaks single-placement integrity and complicates reporting |
| Unlogged hand moves | Manual placement with no history entries | Less write load; simplest implementation | No accountability for moves; disputes lack evidence; audit gaps for roster changes | Passed over: leaves roster movement without a trustworthy trail |
| Sheet-only enrollment with no hand path | All placements flow through sheet upload | One entry mechanism | Slow handling of single-learner cases; no timely correction path for individual moves | Set aside: mismatches daily operational need for single-learner action |

#### Rationale

The chosen option preserves single-placement integrity through an explicit block plus move guidance, while history entries give a complete account of manual roster action. Separation from bulk sheet handling keeps single-learner work fast without weakening duplicate or audit discipline.

#### Consequences

- **Positive:** Duplicate placements stay blocked; operators receive clear move guidance; every hand move carries actor, destination, and time for later review.
- **Negative:** Operators seeking a second placing take the move path instead; history storage grows monotonically; mistaken moves need compensating moves rather than silent edits.
- **Neutral / Follow-up:** Roster review confirms block behavior, move guidance, and history completeness on every change to placement rules; retention and paging of history remain operational checks.

#### Compliance / Verification

Roster review confirms manager-only hand access, refusal of a second placing for already placed learners with guidance toward move, explicit confirmation before move, validation-only handling of any supplied place-time group and year, history entries on every hand move plus audit, and no silent roster writes. Errors use plain wording that tells the operator what to do next.

Ref: history stored in classroom moves table with actor, learner, destination, and time.

### ADR-012: Learner view-only names-only people list with opt-in photos

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Learners benefit from seeing peers in their groups and classrooms, yet privacy requires restraint by default. Photos carry heightened sensitivity and need explicit family consent before display. Result sets remain bounded through small pages rather than whole-school dumps, with no cross-school search and no edit actions inside the learner view. The leave action remains available via API independent of the people read.

#### Decision

> We will present learners with a view-only names-only people listing showing names only (rows {display_name, photo_url?}) with photos withheld unless explicit opt-in consent exists (photo_opt_in default off), served in small pages (default 15, max 100, alphabetical; unenrolled 403; archived 410) with no cross-school search (any ?search= 422) and no write actions, while keeping the leave action available via API independent of the people read (already_left converges; classroom_leave_history plus audit) and the enrolled-classroom list/detail reads available via API (unpaginated, name-ordered).

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| View-only names-only list with opt-in photos (chosen) | Paged learner view, names by default, consent-gated photos, no cross-school search, no edit controls | Privacy by default; bounded result sizes; no accidental edits; consent respected per learner | Cross-group discovery limited; photo coverage sparse until consent collected; paging adds navigation steps | *(chosen — see rationale below)* |
| Full directory with photos by default | Names plus photos for all learners with whole-school search | Richest discovery | Privacy exposure without consent; large result sets; higher misuse potential | Declined: conflicts with consent-first photo handling and bounded display |
| Searchable cross-school directory | School-wide name search across groups and classrooms | Fast peer lookup | Overbroad exposure; weak scoping to permitted groups; larger abuse surface | Turned down: exceeds learner need and weakens group-scoped access |
| Editable list for learners | Learner view with edit or removal controls | Fewer clicks for corrections | Unauthorized roster changes; audit noise; authorization bypass | Ruled out: violates view-only authorization and roster accountability |

#### Rationale

The chosen option balances peer visibility with privacy by limiting default display to names, gating photos on explicit consent, bounding result sizes through paging, and removing search-across-school and edit capabilities from the learner view. Scoping to permitted groups and classrooms keeps exposure aligned with membership.

#### Consequences

- **Positive:** Privacy-preserving default with consent-respecting photos; predictable small pages; no learner-initiated roster mutation.
- **Negative:** Peer discovery across the school remains unavailable; consent administration adds operational work; display updates depend on timely consent-state propagation.
- **Neutral / Follow-up:** People-list review confirms names-only default, consent gating, paging, absence of cross-school search, and absence of edit controls on every change to display or search rules; consent-state changes require immediate display effect.

#### Compliance / Verification

People-list review confirms learner access remains view-only, default display carries names without photos, photos appear only with recorded opt-in consent (photo_opt_in default off), results arrive in small pages (15/100, alphabetical) scoped to permitted groups or classrooms, unenrolled 403 and archived 410 hold, any ?search= 422 holds, no write actions exist on this path, leave converges via already_left plus classroom_leave_history, and enrolled-classroom list/detail reads remain available via API.

### ADR-013: Human-name search with auto-assigned hidden record numbers

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Manual entry of internal record numbers creates typing errors, duplicate identities, and support burden, while exposing internal numbers in lists and detail screens leaks implementation detail without helping staff or learners. Managers, teachers, and learners think in human names for learners, groups, subjects, and rooms, with human codes such as learner code and join key serving as the people-facing identifiers. The system matches names to records internally.

#### Decision

> We will auto-assign every internal record number behind the scenes with no create or edit accepting one, resolve all manager, teacher, and learner searches by human names with internal matching (digits-only search refused; learner reads reject any search input), and display only human codes meant for people while withholding internal numbers from list/detail responses and search inputs.

Ref: name matching lives in the human-search helper.

**Constraint note:** Constrained by ADR-025 — see that ADR. The people-facing code is the CompAss ID.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Auto-assign with name search and hidden numbers (chosen) | System-assigned numbers, name-based search, human codes displayed, internal numbers withheld | No number-typing errors; simpler entry; stable internal identity; cleaner display | Name collisions need disambiguation; support lookups need assisted paths; matching logic needs per-entity care | *(chosen — see rationale below)* |
| Manual number entry | Operators type record numbers on create and edit | Direct control over identifiers | Typing errors; duplicates; onboarding friction; invalid-number handling across create/edit calls | Turned down: shifts identity integrity onto operators |
| Number-based search | Search inputs accept internal numbers | Precise lookup when number known | Forces memorization of internal values; leaks implementation detail; poor fit for name-oriented work | Not chosen: conflicts with name-oriented search and hidden-number display |
| Display of internal numbers | List and detail responses show internal numbers | Visible join story for debugging | Cluttered display; confusion between human codes and internal numbers; wider exposure of internal values | Declined: mixes people-facing codes with implementation values |

#### Rationale

The chosen option removes number entry from human workflows, keeps internal identity stable and opaque, and aligns search and display with how staff and learners actually refer to people, groups, subjects, and rooms. Human codes stay visible and searchable while internal numbers stay out of sight and out of search inputs.

#### Consequences

- **Positive:** Simpler create and edit calls with no number fields; fewer identity errors; displays show only people-meaningful codes.
- **Negative:** Shared names require disambiguation displays; support staff lose direct number lookup and need assisted search; name-matching behavior needs ongoing tuning per entity.
- **Neutral / Follow-up:** Search review confirms absence of number inputs on create and edit, name-based matching, and absence of internal numbers from display and search on every new searchable entity; disambiguation wording remains a review item.

#### Compliance / Verification

Search review confirms no create or edit accepts a client-supplied record number, all search paths accept human names with internal resolution, list and detail responses show human codes without internal numbers, and human codes remain searchable while internal numbers accept no search input.

### ADR-014: Change-safety with preview before destructive or bulk actions

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Destructive and bulk actions can affect many groups, classrooms, and scoring records at once. Operators need to see scope and counts before anything is saved, then confirm explicitly. Reports stay paged and scoped to what the operator may see. Errors explain what to do next, and try-out capabilities stay limited to staff roles by server enforcement.

#### Decision

> We will gate every destructive or bulk action behind preview plus explicit confirmation showing scope, counts, and affected groups or classrooms in plain wording, requiring confirmation before any write, limiting reports to paged views scoped to the permitted group or classroom, and keeping try-out and test capabilities restricted to staff roles by server enforcement.

Ref: expired previews ask for re-upload.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Preview with explicit confirmation (chosen) | Preview data with scope and counts, confirmation gate, paged scoped reports, staff-only test capabilities | Fewer accidental mass changes; clear blast radius; bounded report exposure | Extra confirmation step on each bulk path; paged reports add steps; role scoping needs access discipline | *(chosen — see rationale below)* |
| Direct execution without preview | Destructive and bulk writes apply immediately | Fewest clicks | High accidental-change rate; unclear blast radius; costly recovery | Turned down: exposes classrooms and scoring records to irreversible slips |
| Undo-only without preview | Writes apply immediately with later undo | Fast execution with recovery path | Recovery logic per action; partial undo complexity; audit noise from apply-then-revert | Not chosen: replaces prevention with repair and complicates history |
| Unrestricted reports with full result sets | Reports show unpaged cross-group data | Single-view analysis | Overbroad exposure; large result handling; weaker authorization scoping | Set aside: conflicts with group-scoped paged reporting and least exposure |

#### Rationale

The chosen option trades one confirmation step for protection against mass accidental change, making scope and counts reviewable before any write while keeping reporting bounded and test surfaces separated by role. Plain wording that tells the operator what to do next keeps error handling actionable.

#### Consequences

- **Positive:** Destructive intent stays explicit; operators review scope and counts before commit; reports expose only permitted data in manageable pages.
- **Negative:** Every bulk path pays a confirmation interaction; report consumers page through results rather than single dumps; staff-only scoping requires ongoing server-enforcement discipline.
- **Neutral / Follow-up:** Safety review confirms check-screen presence, scope and count accuracy, confirmation gating, paged scoping, plain wording, and staff-only hiding on every new destructive, bulk, or report path; wording changes require review for clarity.

#### Compliance / Verification

Safety review confirms preview plus explicit confirmation precedes every destructive or bulk write with accurate scope and counts, no write proceeds without confirmation, expired previews ask for re-upload, reports arrive paged and scoped, errors use plain wording that tells the operator what to do next, and try-out capabilities refuse non-staff access under server enforcement.

### ADR-015: Staged rollout with grade-level pilot and exam freeze

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-07 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Whole-school daily use introduces load, data variety, and operational pressure that remain invisible in small trials. A single year level gives a realistic yet bounded pilot cohort for one to two weeks with issue fixing before whole-school opening. Exam weeks demand stability where structural changes risk disruption and need freeze discipline with explicit triage.

#### Decision

> We will roll out by piloting with a single year level for one to two weeks with issue fixing before whole-school opening (operational, not code; owned by the school operator), and freeze structural changes during exam weeks with only break-fix and access-support changes permitted in that window.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Single-year pilot with exam freeze (chosen) | Bounded pilot cohort, fix-before-open gate, structural-change freeze in exam weeks | Bounded blast radius; realistic daily-use feedback; exam stability | Pilot cohort carries early-issue burden; opening waits on pilot fixes; freeze delays structural improvements | *(chosen — see rationale below)* |
| Whole-school opening at once | All year levels start together | Fastest availability; no pilot coordination | Largest blast radius; support overload; exam disruption risk | Turned down: exposes the whole school to undiscovered operational issues |
| Continuous structural changes through exams | No freeze window with ongoing shape changes | Steady delivery cadence | Change risk during high-stakes weeks; harder incident triage; operator confusion | Declined: trades delivery speed for exam instability |
| Extended multi-stage pilot across terms | Long pilot across multiple cohorts | Deepest evidence | Slow time to whole-school value; prolonged dual-operation burden | Passed over: delays school-wide readiness beyond operational need |

#### Rationale

The chosen option bounds early risk to one year level while still exercising daily-use flows, and makes fix-before-open an explicit gate rather than an aspiration. The exam freeze protects high-stakes weeks by narrowing permitted changes to break-fix and access support with explicit triage.

#### Consequences

- **Positive:** Early issues affect a bounded cohort; pilot feedback informs support and wording before wider use; exams proceed without structural disturbance.
- **Negative:** Pilot participants absorb early friction; whole-school opening depends on pilot fix throughput; desirable structural work queues behind the freeze.
- **Neutral / Follow-up:** Rollout review confirms pilot scope, duration, exit criteria, freeze calendar, and change triage ownership; pilot and freeze discipline remains an operational check rather than code enforcement.

#### Compliance / Verification

Rollout review confirms pilot operation limited to the selected year level for the stated duration, whole-school opening follows recorded pilot fixes against exit criteria, and exam-week changes remain limited to break-fix and access support under triage with structural changes refused in that window.

### ADR-016: Password-reset delivery must never return raw token in production; uniform no-oracle response

| Field | Value |
|---|---|
| **Status** | Accepted (history only — self-service half superseded by ADR-018) |
| **Date** | 2026-09-09 |
| **Deciders** | Platform team |
| **Supersedes** | ASSUMED-5 (raw-token pilot fallback) |
| **Superseded by** | ADR-018 (self-service half) |

#### Context

Self-service reset ran alongside manager-driven reset. Both paths promised the same message for known and unknown identifiers.

At baseline there was no mail infrastructure in the repo, so an inline raw token in the forgot-password response was accepted as a pilot fallback. That fallback became an account-takeover path. The field-presence difference plus a timing difference let callers enumerate accounts, capture a token, and take over the account including admin accounts. Per-identifier throttles did not absorb enumeration because each guess got a fresh bucket.

The fix had to close both oracles without adding workers, while keeping both reset legs working.

Ref: mail config and token field details lived in the code at the time.

#### Decision

> We will ban the raw token from API responses on every production path and deliver reset instructions out-of-band in-request via the log/mail channel (sync-only, no workers), gated by PASSWORD_RESET_INLINE_TOKEN=false (mandatory in production; inline-token retrieval permitted only with PASSWORD_RESET_INLINE_TOKEN=true in local/testing, never in production). Forgot-password returns a message-only body with the same message and the same shape for known, unknown, and inactive identifiers (no reset_token, no extra fields, no shape difference); the negative path performs equal hash work (dummy random token generated, hashed, and compared in constant time) so timing converges. Throttling is per-IP at 5 per 15 minutes with limit headers preserved, enumeration-shaped traffic raises Log::warning plus masked audit, hash-only token storage holds, any inline tokens issued before the fix are wiped on deploy, and manager reset stays as the day-one fallback channel with the contract shape unchanged when the out-of-band leg is unconfigured. This decision constrains ADR-003 (reset endpoints stay gate-exempt but message-only).

**History note:** Constrained by ADR-018 — see that ADR. The self-service half is history only.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Message-only prod with flag-gated inline for local/testing plus out-of-band sync delivery (chosen) | No token field in prod, uniform shape plus equal work on all paths, per-IP throttle with alerting, hash-only store, deploy wipe, manager fallback | Closes field-presence and timing oracles at the contract layer; preserves self-service without workers; keeps a working fallback | Operator must configure the out-of-band leg or self-service degrades to manager-issued temps; per-IP throttle may refuse legitimate shared-IP bursts; local/testing flag is a foot-gun if ever enabled in prod | *(chosen — see rationale below)* |
| Keep always-inline token | Raw reset_token in the forgot response as the pilot fallback | Simplest retrieval in tests and demos | Is the reported takeover primitive; field-presence alone breaks the no-oracle contract and enables admin takeover | Ruled out: the red-team blocking finding |
| Queued mail worker with async notify plumbing | Background delivery with later notification | Frees request handling for mail sends | Delayed outcomes plus job machinery at pilot scale; violates synchronous-only posture | Set aside: conflicts with ADR-008 sync-only; disproportionate when in-request log/mail suffices |
| Remove self-service, manager-only resets | Only admins issue temporary passwords | Smallest attack surface | Removes a required reset leg; contradicts the promised self-service plus manager contract | Passed over: kept only as fallback, not as the whole solution |

#### Rationale

Closing the issue at the contract layer plus the timing layer removes the oracle rather than throttling around it, since per-identifier buckets reset per guess. Out-of-band delivery keeps the self-service leg without breaking synchronous processing. Per-IP throttling bounds enumeration rate as defense in depth, with the shared-network tradeoff documented. Retaining manager reset keeps a working day-one channel when the out-of-band leg is unconfigured. Uniform shape keeps the uniform-failure posture intact. In short, asking for a reset always showed the same message whether the account existed or not.

#### Consequences

- **Positive:** Unauthenticated takeover through the inline token is closed. The no-oracle posture becomes testable through shape-equality plus timing checks.
- **Negative:** The operator must configure the out-of-band leg or self-service falls back to manager-issued temps. Per-IP throttle may refuse legitimate shared-IP bursts. The local and testing flag needs production enforcement plus deploy invalidation.
- **Neutral / Follow-up:** Reset review confirms message-only shape on known, unknown, and inactive paths, flag enforcement, throttle with alerting, hash-only storage, and deploy wipe on every change to reset or delivery handling. Re-enabling inline tokens in production needs a superseding decision with security sign-off.

#### Compliance / Verification

Reset review confirms forgot-password returns the same message object with the same keys and no token field on known, unknown, and inactive paths; the negative path does equal hash work with constant-time comparison; throttle is per-IP with limit headers and enumeration alerting; tokens store hashed only with pre-fix inline tokens wiped on deploy; oracle-asserting tests are treated as contract violations and rewritten to shape-equality assertions.

### ADR-017: Learner group/year are optional validation-only staged context, not canonical roster columns

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-09 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | — |

#### Context

Bulk sheets carried learner code, full name, group, and year, while hand placement accepted optional group and year. The canonical store held identity and placement only, with no group or year columns. The sheet also carried no classroom key, so it could not resolve a unique placement on its own.

Requiring group and year while reporting valid rows as imported would silently drop data the contract promised to keep. Classrooms already carried subject-section plus school-year scope, so a single user-level group value could not represent multi-classroom membership. A per-enrollment copy would duplicate the classroom scope and drift as a second grouping source.

Validation-only was the working assumption. This decision makes it explicit with a staged retention boundary. It constrains ADR-010 and ADR-011 and clarifies the earlier assumption.

#### Decision

> We will treat group/year as OPTIONAL validation-only staged context on both bulk sheet rows and hand place. Supplied values validate against the org vocabulary (unknown values fail the row with a reason, never auto-create org rows); validated values are retained in staged group and year fields plus the error sheet and import/audit metadata ONLY — never written to users or classroom_enrollments canonical columns (no such columns exist or are created), never honored by reads (people, rooms, and analytics stay classroom-scoped). Confirm counts are identity counts (imported = User creates by learner-code, updated = User name updates by learner-code, failed = rejected rows including vocab failures), explicitly NOT classroom placements; classroom placement happens only via hand place/move (ADR-011) or join (ADR-002). Zero-valid runs succeed with zeros and no report. No canonical migration ships with this decision; staged-only retention is the persistence boundary.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Optional validation-only staged context (chosen) | Validate if supplied, retain staged/audit-only, identity counts, placement only via hand/join | No silent loss with truthful counts; hierarchy plus single-source preserved; zero canonical migration; staged values stay backfill-able | Operators still place via hand/join as an extra step; old sheets carrying group/year no longer imply automatic placement | *(chosen — see rationale below)* |
| New free-text columns on users | Persist group/year strings on the user row | Simple write path | Stores unvalidated free vocabulary; single value cannot represent multi-classroom membership; dead data no read honors | Turned down: persistence without a readable meaning |
| New free-text or key columns on classroom_enrollments | Persist per-enrollment group/year copies | Per-room values | Duplicates the classroom's own scope; diverges as a second grouping source; needs migration plus new reads and reporting | Not chosen: divergence risk beyond task scope |
| New keys to org structure with auto-placement | Resolve group/year to section/grade rows and place automatically | Bulk places directly | Needs resolution plus migration plus backfill plus new reporting; ambiguous without an explicit mapping; contradicts never-auto-create without one | Deferred: a rejected-for-now future needing a superseding decision with mapping plus migration plus reporting design, not hidden scope |

#### Rationale

Hierarchy integrity wins. Classrooms already scope subject-section plus school-year and reporting reads classroom scope, so parallel grouping columns add divergence without accountability. The sheet is unplaceable on its own because group and year alone cannot resolve a unique classroom without inventing inference. Vocabulary discipline holds because persisting as text stores unvalidated vocabulary, while persisting as keys needs a mapping that does not exist. Auditability without pollution holds through staged rows plus error sheets plus audit metadata. Change safety holds through truthful identity counts with no new transaction scope and no extra machinery. In short, saving the sheet only creates or updates learner identities and never places learners into classrooms.

#### Consequences

- **Positive:** No silent loss with the retention boundary stated; hierarchy and single-source preserved with no reporting divergence; high reversibility with staged values available for a future promotion.
- **Negative:** Bulk onboarding stays identity-only with placement as a separate hand/join step needing operator communication; supplied group/year never drives reads.
- **Neutral / Follow-up:** Enrollment review confirms optional handling, vocabulary validation with row-failure reasons, staged-only retention, identity-count wording, and no canonical columns on every change to columns, matching, or counts; auto-placement from group/year needs a superseding decision, not an implementation improvisation.

#### Compliance / Verification

Enrollment review confirms group/year are optional on sheet rows and hand place; supplied values validate against org enumerations with unknown values failing the row and never auto-creating org rows; validated values land only in staged rows plus error and audit metadata with users and classroom_enrollments carrying no group/year columns; confirm counts read as identity counts with zero-valid runs returning zeros and no report; any read honoring free-text group/year as roster scope or any write reporting group/year as placed is a violation.

### ADR-018: Admin-only password reset; no unauthenticated forgot/reset surface

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-10 |
| **Deciders** | Platform team |
| **Supersedes** | Self-service half of ADR-016 (message-only / hash-only / per-IP / inline-flag / wipe discipline for public tokens — obsolete, not relaxed) |
| **Superseded by** | — |

#### Context

Operator direction, quoted verbatim: "There's no 'forgot password.' The admin can only reset the student or teacher's password. When the admin resets their password, the admin will receive the account's temporary password, give it to the one who forgot their password, and then will have to set their password again. Just like the first login feature."

Prior posture ran two legs: manager reset plus public self-service. The public leg had been hardened message-only in ADR-016 after an unauthenticated-takeover finding. First-login parity comes from the existing temp-plus-forced-change behavior. This decision narrows the contract to the manager leg by operator authority and constrains ADR-003.

#### Decision

> We will run password recovery admin-only with no unauthenticated forgot/reset surface: POST /auth/forgot-password and POST /auth/reset-password are removed/disabled, not flag-gated. The sole recovery channel is authenticated manager POST /admin/users/{id}/reset-password (role admin only): on success it issues a system temp password returned to the calling admin only over the authenticated session, sets must_change_password=true on the target, revokes the target's other sessions, preserves deactivation, writes an audit row, returns the uniform envelope, and refuses number-only or weak input. The recipient's next login follows first-login parity: the forced-change gate blocks every gated action except sign-out, password change, identity, and service-status reads until password change succeeds. Change-password plus gate plus session discipline are unchanged. Immediate deactivation is preserved. Sync-only holds with no new tables. This decision supersedes the self-service half of ADR-016, constrains ADR-003, and narrows the contract to the manager leg.

Ref: removal scope covers routes, controllers, requests, services, migrations, and tests.

Before (removed): the public forgot path returned the same message with the same shape for known, unknown, and inactive identifiers, backed by hash-only single-use tokens with throttle, alerting, flag, and wipe. After: no public forgot or reset paths exist at all. Probes get not-found or method-not-allowed with the uniform envelope. Recovery is a manager-issued temp plus forced change. There is no forgot password. Staff contact an admin for a temporary password, then set a new password on next login.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Admin-only reset with no public forgot/reset routes (chosen) | Remove issuance plus redeem; manager temp plus forced change with first-login parity | Zero unauthenticated issuance to attack; proven temp-plus-gate-plus-revoke-plus-audit pattern; no new trust boundary; sync-only holds | Every forgotten password needs an admin handoff; no self-recovery | *(chosen — see rationale below)* |
| Keep ADR-016 message-only self-service | Same-shape forgot, hash-only store, per-IP throttle, flag-gated inline, wipe | Hardened oracle posture | Is exactly the surface the operator forbids; keeps issuance/redeem plus log-secret/staging/deadlock/purge/throttle/alerting/flag/wipe burden for zero operator value | Turned down: operator forbids the surface |
| Queued/async notify or alternate self-service channel | Mail/SMS/link delivery | Self-recovery without admin | Violates ADR-008 sync-only; adds infra for a rejected leg; re-opens oracle/delivery review | Set aside: infra for a rejected leg |
| Remove both legs (no reset at all) | No recovery channel | Smallest code | No day-one recovery; violates FR-004 and the operator ask | Ruled out: leaves lockouts unrecoverable |

#### Rationale

Operator authority settles the self-service question. The usability tradeoff of an admin bottleneck with no self-recovery in exchange for no unauthenticated issuance is accepted at source. First-login parity already proves the temp-plus-gate-plus-revoke-plus-audit pattern, so no new credential primitive is introduced. Removing issuance plus redeem closes the takeover finding at the surface layer rather than hardening it. The manager channel rides the existing authenticated session plus role plus audit posture, so no new trust boundary is created. Sync-only holds with fewer paths and no delivery leg.

#### Consequences

- **Positive:** The unauthenticated takeover surface is removed entirely. Token storage, throttle, alerting, flag, and wipe handling for self-service become obsolete. Fewer migrations to commit, fewer config keys, fewer tests to maintain.
- **Negative:** Every forgotten password needs an admin handoff, which creates an availability bottleneck with no self-recovery. The help wording on the login screen matters more now. Manager session plus temp-handling risks remain: overhearing on handoff, temp entropy plus lifetime plus one-time-use discipline, enforced change that cannot be bypassed, admin-session blast radius, and audit completeness on every reset.
- **Neutral / Follow-up:** Reversibility is asymmetric. Removal is straightforward to execute with no data migration, while re-adding any unauthenticated self-service later needs a superseding decision with full security review. Manager-temp tuning stays easy while the temp-plus-gate-plus-revoke-plus-audit shape holds. Reset review confirms absence on every change to auth or delivery handling.

#### Compliance / Verification

Reset review confirms the two public reset routes answer not-found or method-not-allowed with the uniform envelope. No token tables, flags, or configs for self-service remain. The manager leg issues the temp to the admin only, sets must_change_password, revokes other sessions, writes audit, refuses non-admin callers, and refuses number-only or weak input. Any test asserting a public forgot or reset shape is a contract violation.

### ADR-019: Frontend design-system overhaul, presentational-only

> Superseded by ADR-021 (2026-09-18): UI is external; backend owns all domain/auth/data/contracts. History retained below unchanged.

| Field | Value |
|---|---|
| **Status** | Superseded by ADR-021 |
| **Date** | 2026-09-12 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | ADR-021 |

#### Context

The frontend received a UI/UX overhaul with zero backend changes: expanded Scholarly Modern tokens, glass, gradients, and motion in globals.css, a global ToastProvider, landing and login redesigns, a glass AppShell/Nav with grouped admin navigation and avatar user card, polished UI primitives plus new Avatar and Progress components, and Heatmap, ErrorBanner, and not-found polish. Routes, guards, auth, throttle, API shapes, data schemas, audit, and deployment must stay exactly as documented.

#### Decision

> We will adopt the refreshed presentational system only: glass sidebar/topbar, gradient brand mark, grouped admin navigation (Manage / People & enrollment / Insights & system) with unchanged routes/guards, Avatar + Progress/MasteryBar primitives, landing split hero + roles + CTA with unchanged redirects, login split-screen brand panel with unchanged login logic/throttle, tinted elevation + noise + spring motion with reduced-motion support, global ToastProvider, and Heatmap + ErrorBanner + not-found polish — with explicitly no API, data schema, auth, throttle, audit, or deployment change.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Presentational-only refresh (chosen) | Design tokens, shell, nav grouping, primitives, landing/login layouts, motion, and polish with identical routes, guards, logic, and contracts | Modern experience; no backend, contract, or migration risk | Shell regressions affect all roles at once; guard mirroring still required on every new route | *(chosen — see rationale below)* |
| Functional frontend rework with route/guard/logic changes | Redesign plus changed navigation targets, guards, or login behavior | Could simplify flows | Reopens ADR-009, auth, and contract review; needs migration and test updates | Set aside: out of scope for a presentational overhaul |
| No refresh | Keep prior styling | Zero change risk | Prior experience stays dated | Passed over: forgoes approved UX improvement at no backend cost |

#### Rationale

The chosen option implements QA-007 presentational quality while leaving every backend ADR (ADR-001..008, ADR-010..018), all API contracts, data schemas, auth/throttle/audit rules, and deployment topology untouched. Grouped navigation only regroups existing admin routes; landing/login only restyle existing redirects and login logic.

#### Consequences

- **Positive:** Consistent glass/gradient/grouped-nav/Avatar+Progress/landing+login/motion language across roles with no contract or migration work.
- **Negative:** Shell regressions affect all roles at once; every new route still needs server enforcement plus mirrored client guards per ADR-009.
- **Neutral / Follow-up:** Route review confirms same routes/guards on every regrouped or restyled page; motion changes keep reduced-motion support.

#### Compliance / Verification

Route/guard review confirms unchanged routes, guards, redirects, login logic/throttle, and contracts; visual review confirms glass sidebar/topbar, gradient brand mark, grouped admin nav, Avatar + Progress primitives, split hero/roles/CTA and split-screen login, tinted elevation + noise + spring motion with reduced-motion support; `git diff --stat HEAD -- backend` stays empty.

### ADR-020: Compass v2 presentational-only overhaul

> Superseded by ADR-021 (2026-09-18): UI is external; backend owns all domain/auth/data/contracts. History retained below unchanged.

| Field | Value |
|---|---|
| **Status** | Superseded by ADR-021 |
| **Date** | 2026-09-12 |
| **Deciders** | Platform team |
| **Supersedes** | — |
| **Superseded by** | ADR-021 |

#### Context

The frontend received a second presentational-only overhaul on the same date (Compass v2), building on ADR-019 with zero backend changes: DS v2 tokens and utilities in globals.css (info/success/warning/chart hues, card-elevated/muted/ring, sidebar-active, per-role cover gradients, KPI accent bars; kpi-grid, dashboard-grid, auto-grid, icon-tile system, hero-band/cover-strip, chart-frame, section-label, divider-fade, interactive-card, stagger; dark-theme and reduced-motion coverage), overhauled shared primitives (Card variants + CardEyebrow + CardFooter toolbar, Badge dot + StatusDot, PageHeader v2 + Stat KPI + statToneForScore, Button soft/danger-outline, Table sticky/zebra, EmptyState density, SkeletonKpi/Table/Card, Progress sizes, Avatar xl + tones), new Section/ChartCard/LegendChip/MethodologyNote and KpiCard primitives, AppShell 280px sidebar + workspace pill + avatar card + pill topbar chip, tonal nav active state, rewritten landing/login, admin KPI band + dashboard-grid, teacher filter Section + derived KPI strip, student hero-band + cover-strip cards, mastery-history KPI strip + muted cards, and restructured domain cards (Assignment, Announcement, Explanation, StatusChip, Heatmap cells, TrendChart, LeaveClassroomCard, Record/Gap tables). Routes, guards, data-fetching logic, auth, throttle, API shapes, data schemas, audit, and deployment must stay exactly as documented.

#### Decision

> We will adopt the Compass v2 presentational system only: DS v2 tokens/utilities, card variants + CardEyebrow, dot badges, PageHeader/Stat KPI, Section/ChartCard/KpiCard primitives, tonal nav, hero bands, cover strips, and restructured role dashboards + domain cards with unchanged routes/guards/data-fetching logic — with explicitly no API, data schema, auth, throttle, audit, or deployment change.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Presentational-only Compass v2 (chosen) | DS v2 tokens, card/eyebrow/dot/KPI/Section primitives, tonal nav, hero/cover system, and restructured dashboards + domain cards with identical routes, guards, logic, and contracts | Modern experience building on ADR-019; no backend, contract, or migration risk | Shell regressions affect all roles at once; guard mirroring still required on every new route | *(chosen — see rationale below)* |
| Functional frontend rework with route/guard/logic changes | Redesign plus changed navigation targets, guards, or data fetching | Could simplify flows | Reopens ADR-009, auth, and contract review; needs migration and test updates | Not chosen: out of scope for a presentational overhaul |
| No second refresh | Keep the ADR-019 system | Zero change risk | Forgoes approved UX improvement at no backend cost | Declined: forgoes approved UX improvement at no backend cost |

#### Rationale

The chosen option implements QA-007 presentational quality while leaving every backend ADR (ADR-001..008, ADR-010..018), all API contracts, data schemas, auth/throttle/audit rules, and deployment topology untouched. It builds on ADR-019 without superseding it; restructured dashboards and domain cards only restyle existing fetches, polls, guards, and login/join logic.

#### Consequences

- **Positive:** Consistent DS v2 card/eyebrow/dot/KPI/Section/tonal-nav/hero/cover language across roles with no contract or migration work.
- **Negative:** Shell regressions affect all roles at once; every new route still needs server enforcement plus mirrored client guards per ADR-009.
- **Neutral / Follow-up:** Route review confirms same routes/guards on every restyled page; data-fetching hooks, endpoints, and payloads unchanged.

#### Compliance / Verification

Route/guard review confirms unchanged routes, guards, redirects, login/join logic/throttle, and contracts; data-fetching review confirms same hooks, endpoints, and payloads; visual review confirms card variants + CardEyebrow, dot badges, PageHeader/Stat KPI, Section/ChartCard/KpiCard primitives, tonal nav, hero bands, cover strips, and restructured role dashboards + domain cards; `git diff --stat HEAD -- backend` stays empty.

---

### ADR-021: Frontend-agnostic backend; UI out of scope

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-18 |
| **Deciders** | Platform team |
| **Supersedes** | ADR-009, ADR-019, ADR-020 |
| **Superseded by** | — |

#### Context

The frontend is being restarted from scratch. The architecture must not determine any navigation, prescribe any framework, components, pages, routes, or design system. What the architecture must keep is the set of features a frontend should implement, expressed as backend capabilities: role-based API contracts, validation, throttling, pagination, error semantics, auth/session gating, audit, retention, and derived data. Prior decisions ADR-009 (server-rendered shell with role-grouped frontend and mirrored client guards), ADR-019, and ADR-020 (design-system refreshes) prescribed frontend implementation and must no longer constrain clients.

#### Decision

> We will treat the backend as the full scope of this architecture and the UI as external: the backend owns all domain logic, authentication and session handling, authorization, data, contracts, throttling, audit, and deployment; any client of any implementation integrates exclusively through the unversioned API contracts. No framework, component, page, route, redirect, dialog, design token, or navigation structure is prescribed. Server-side role checks plus password-change gating are the only enforcement; clients are external, must call the API with a session, and must handle 403/422 outcomes without any mirrored client-guard prescription.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Frontend-agnostic backend with external clients (chosen) | Backend owns domain/auth/data/contracts; UI external with no implementation prescription; server enforcement only | Frees frontend rebuild completely; one enforced rule set on the server; contracts stay the stable boundary | No shared shell, navigation, or component discipline from architecture; each client reimplements presentation and error display | *(chosen — see rationale below)* |
| Keep prescribing routes, guards, and components | Architecture continues to name pages, nav grouping, dialogs, and design tokens | Consistent presentation across clients | Couples architecture to a discarded frontend; blocks clean restart; duplicates enforcement across layers | Turned down: contradicts the from-scratch frontend restart |
| Separate frontend architecture track | New parallel docs prescribe the replacement UI | Restores shared UI discipline | Reintroduces the coupling just removed; premature before product direction settles | Deferred: defer until product needs it; backend contracts suffice |

#### Rationale

The chosen option keeps every feature a frontend should implement while deleting every frontend prescription: roles remain API actors with defined capabilities (admin management, sheet enrollment ceilings with preview/confirm and error sheets, hand place/move with 409/410 and history, password resets, analytics/audit/mastery reads; teacher classroom and content management, manual/bulk grading, resubmission with reason, release, moderation; student join/leave, enrolled-classroom reads, names-only paged people reads, submissions, attempts with auto-save, scores/mastery, explanations), all endpoint paths, status codes, validation, throttles, pagination, error envelope, auth/session/CSRF/gating/deactivation, audit, retention, tables/fields, staged retention boundaries, and derived data stay intact. Measurable usability without design (tasks completable without docs, plain wording that tells the operator what to do next, auto-save, refresh on release, generic client compatibility) stays; design tokens, layouts, and navigation do not.

#### Consequences

- **Positive:** Frontend rebuild starts unconstrained; backend contracts become the single integration truth; no mirrored-guard or dual-deployable discipline to maintain.
- **Negative:** No architecture-level UI consistency until product defines it; clients must independently handle auth gating outcomes, polling/refresh, and error display against the contracts.
- **Neutral / Follow-up:** The backend deploys as a single backend API service with external clients; the UI-service halves of ADR-001 and ADR-008 no longer apply. Any future UI prescription requires a new decision, not a revival of ADR-009/019/020.

#### Compliance / Verification

Contract review confirms no framework, component, page, route, redirect, dialog, token, or navigation prescription in ARCH-001..006 outside superseded history; endpoint paths, methods, status codes, validation, throttles, pagination, error envelope, server role checks, and retention remain verbatim; banned-term grep over the six docs returns zero hits outside ADR-009/019/020 history, revision history, and ADR-021 notes.

---

### ADR-022: React SPA frontend (Vite + React Router JS) with same-origin CSRF proxy

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-19 |
| **Deciders** | Platform team |
| **Supersedes** | — (constrains ADR-021 per owner-approved deviation) |
| **Superseded by** | — |

#### Context

ADR-021 declared the UI out of scope with external generic clients and no framework, route, or guard prescription. The owner has since allowed a deviation: a first-party React SPA now lives in `frontend/` and is the primary client, so the architecture constrains (not supersedes) ADR-021 by prescribing exactly this unit while any other clients stay external and generic. The SPA runs on :3000 in dev against the API on 127.0.0.1:8000 under Sanctum SPA cookie authentication, which demands CSRF discipline: opening the UI on one loopback host (localhost:3000) against the API on the other (127.0.0.1:8000) turns the session and XSRF cookies cross-site and loses SameSite context, breaking login and every mutating action.

#### Decision

> We will ship `frontend/` as the primary client: a React SPA built with Vite + React Router JS on :3000 that reaches the API through a same-origin proxy (`/api` + `/sanctum` → 127.0.0.1:8000) by default with `VITE_API_BASE` empty; Sanctum SPA cookie + CSRF via `GET /sanctum/csrf-cookie` with the decoded XSRF-TOKEN cookie sent as the `X-XSRF-TOKEN` header, a force-fresh CSRF bootstrap at login, and a single 419 refresh-and-retry; role guards (`RequireAdmin` / `RequireTeacher` / `RequireStudent` plus a forced password-change redirect) that mirror the server without enforcing; prototype-derived design tokens; interval polling plus release-triggered refresh; a client 15 MB pre-check with `FormData` uploads and blob downloads honoring `Content-Disposition` filenames; `{ data, meta? }` / `{ error: { message, code, fields? } }` envelope handling with 401/419/403/429 behavior; and named server throttles surfaced with plain wording.

CSRF note: the same-origin proxy is the default because it avoids localhost-vs-127.0.0.1 SameSite cookie loss; `SANCTUM_STATEFUL_DOMAINS` now includes `localhost:8000` (localhost, localhost:3000, localhost:8000, 127.0.0.1, 127.0.0.1:3000, 127.0.0.1:8000); CORS explicitly allows both loopback origins (`http://localhost:3000`, `http://127.0.0.1:3000`) with credentials, never wildcard. Direct-origin mode (`VITE_API_BASE=http://127.0.0.1:8000`) remains supported but requires matching CORS/stateful-domain config and breaks when the UI is opened on the other loopback host.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| React SPA with same-origin proxy default (chosen) | `frontend/` on :3000 proxies `/api` + `/sanctum` to 127.0.0.1:8000; XSRF + session cookies stay same-site; force-fresh login token with 419 retry | Login and mutations pass CSRF on either loopback host with no per-host config; one documented default | Dev proxy only covers dev; production still needs equivalent same-origin serving or explicit CORS/stateful setup | *(chosen — see rationale below)* |
| Direct-origin CORS mode as default | Browser calls `http://127.0.0.1:8000` straight with credentials and explicit CORS/stateful-domain config | No proxy hop; matches a split-host deploy | Breaks when the UI is opened on the other loopback host; every host change needs matching server config | Passed over as default: fragile across localhost vs 127.0.0.1; kept as a supported fallback |
| Keep ADR-021 fully agnostic with no prescribed client | No frontend unit in architecture; all clients external and generic | Zero coupling to any UI implementation | Leaves the shipped first-party client undescribed; CSRF, guard-mirroring, envelope, and upload/download conventions go undocumented | Set aside: owner approved this deviation so the real client is prescribed |

#### Rationale

The chosen option keeps ADR-021's server-side truth intact, with endpoint paths, status codes, validation, throttles, pagination, error envelope, server role checks, password-change gating, audit, and retention unchanged, while giving the shipped client one documented shape: same-origin transport removes the whole class of loopback CSRF failures; mirrored role guards keep navigation consistent with server enforcement without duplicating it; envelope and error handling plus throttle surfacing preserve the plain wording posture; 15 MB client pre-checks, `FormData` uploads, and blob downloads match the server's 15 MB / 5000-row ceilings and template/error-sheet downloads; polling plus release-triggered refresh matches synchronous release-gated grading. We accept a dev-proxy default plus a constrained fallback instead of full agnosticism because the owner explicitly allowed this deviation.

#### Consequences

- **Positive:** Login and mutating actions pass CSRF on either loopback host with zero per-host config; role navigation, password-change gating, polling/refresh, uploads/downloads, and error display follow one documented client contract against unchanged server rules.
- **Negative:** Architecture again names a framework, routes, and guards (narrowly scoped to `frontend/`), so a future client restart needs a new decision; dev and production transport must each preserve same-origin-or-explicit-CORS discipline.
- **Neutral / Follow-up:** Backend hardening ships in the same release with no contract break. Moderation notes carry length caps, and user listing gains an optional backward-compatible search that matches names and CompAss IDs while preserving prior listing on empty input. Any further UI prescription beyond this unit requires a new decision, not a silent extension of ADR-022.

Ref: moderation caps max 5000; search lives in the user-list service.

#### Compliance / Verification

Config review confirms `frontend/vite.config.js` proxies `/api` + `/sanctum` to 127.0.0.1:8000 on :3000 with `VITE_API_BASE` empty by default; login forces a fresh CSRF bootstrap and a single 419 refresh-and-retry exists on request, meta, and download paths; guard review confirms `RequireAdmin` / `RequireTeacher` / `RequireStudent` plus password-change redirect with server-only enforcement; envelope review confirms `{ data, meta? }` success and `{ error: { message, code, fields? } }` failure handling with 401/419/403/429 behavior; upload review confirms the 15 MB client pre-check with `FormData` and blob download with `Content-Disposition` filename; env review confirms stateful domains include `localhost:8000` and CORS explicitly lists both loopback origins with credentials and no wildcard.

---

### ADR-023: Frontend-overhaul lessons made policy — catalog reads, throttle keying, token ownership, search hardening, grade band 7–12, UI policies

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-19 |
| **Deciders** | Platform team |
| **Supersedes** | — (constrains ADR-005, ADR-010, ADR-013, ADR-014, ADR-022; records that migration 2026_08_23 supersedes the old 7–9 grade band) |
| **Superseded by** | — |

#### Context

The frontend overhaul added an admin Competencies catalog page, a teacher class report page, account pages, and a help page. The backend hardening pass ran alongside it. Together they surfaced seven lessons that lived in code and tests but nowhere as policy.

First, competency tags had an import path but no paged catalog read, so the new admin page relied on ad-hoc listing. Second, throttle keying was mixed per-IP and per-user with no settled convention. Third, single-use preview and error-report tokens needed ownership checks so a wrong-owner peek never burns the token. Fourth, admin search accepted wildcards literally and allowed very short or digits-only queries with no shared rule. Fifth, the grade band had widened from 7–9 to 7–12 with old wording still quoted in places. Sixth, screens kept leaking endpoint paths, raw dumps, and numeric-id inputs, with ungated empty states and unclear pagination. Seventh, double-submits and modal stacking caused duplicate writes and trapped dialogs.

Ref: grade widening shipped in the August migration.

#### Decision

> We will (a) serve the competency-tags catalog as the paged read with filters and human-name search alongside the existing import paths; (b) keep the student-enrollment import template branch (constrained by ADR-017, superseded by ADR-025 for full_name-only); (c) key throttles per user for import, AI chat, AI generate, moderation writes, join, and classroom-key operations, with the global per-IP limiter as backstop; (d) verify-then-consume all single-use tokens with ownership checks, where a preview peek that fails ownership preserves the token, error-report download checks the owner, confirm is lock-claimed, and preview-token length is bounded; (e) harden admin search with wildcard escaping plus a server-side minimum length of 2 plus digits-only rejection on the five admin lists; (f) rule the grade band 7–12 everywhere, retiring the old 7–9 band; (g) enforce UI policies covering no endpoint paths or raw dumps or numeric-id inputs, error-gated empty states, honest pagination, double-submit guards, modal layering, and a plain-language Help page.

Ref: catalog path GET /api/admin/competency-tags with {data, meta}; template headers learner_code, full_name, group, year_level; lock via Cache::lock with preview peek-GET preserving the token, see (d); grade migration 2026_08_23; layering scrim < overlay < drawer.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Lessons-made-policy bundle (chosen) | Catalog read, template branch, per-user keying with per-IP backstop, token ownership, LIKE-escape + min:2 + digits-only rejection, 7–12 band, UI policies | One decision pins all seven lessons with code, tests, and docs aligned; no contract break | Touches five docs plus code and tests in one release | *(chosen — see rationale below)* |
| Per-IP throttles everywhere | Key every named limiter by client address only | Simplest single rule; shared-IP bursts absorbed | One abusive client behind shared infrastructure spends every legitimate user's budget; authenticated abuse unattributable | Not chosen: per-user keying attributes cost to the actor with the per-IP backstop still catching address-level floods |
| Consume-then-verify tokens | Burn the single-use token on first presentation, then check ownership | Simpler single code path | A wrong-owner peek destroys the rightful owner's token, blocking correction flows | Turned down: the token discipline in (d) above preserves the token on failed ownership checks |
| Unescaped search with client-side minimums only | LIKE wildcards honored literally; length/digits rules enforced in the SPA only | Less server code | `%`/`_` in a query match everything (information-width bug); any non-SPA client bypasses the minimums; digits-only queries probe internal numbers | Declined: server-side wildcard escaping plus minimum length plus digits-only rejection holds for every client |

#### Rationale

The bundle keeps every existing server contract intact while promoting what the overhaul proved in code and tests to architecture. The catalog read completes ADR-005, since import without a catalog read left the admin page with no contract. The template branch pins the enrollment identity columns next to ADR-010 and ADR-017. Per-user keying with a per-IP backstop satisfies pilot-scale limits without punishing shared-IP classrooms. The token discipline in (d) closes the token-burn path while keeping single-use expiry. Search hardening extends ADR-013's hidden-numbers rule to wildcard probing. The 7–12 ruling retires the last 7–9 wording. UI policies constrain ADR-022 without adding new routes or guards.

Ref: grade change shipped in the August migration.

#### Consequences

- **Positive:** Admin Competencies, teacher class report, account, and help pages ship against documented contracts. Throttle budgets attribute cost per user with a global per-IP backstop. Single-use tokens survive wrong-owner peeks. Admin search is wildcard-safe with shared minimums. Grade band reads 7–12 everywhere. UI screens carry no endpoint paths, raw dumps, or numeric-id inputs with honest pagination and guarded submits.
- **Negative:** Seven policies land in one decision, so a future change to any single policy still requires a superseding note against this ADR. Throttle tuning under real load stays estimate-driven.
- **Neutral / Follow-up:** Throttle budgets may need tuning under real load. Any new admin list inherits the search hardening in (e). Any new single-use token inherits the token discipline in (d). Any further UI prescription beyond these policies requires a new decision, not a silent extension of ADR-023.

#### Compliance / Verification

Contract review confirms the catalog read returns the paged envelope with search and filter behavior. Template review confirms the enrollment branch headers. Limiter review confirms per-user keying with the global per-IP backstop. Token review confirms the discipline in (d) with ownership checks and length bounds. Search review confirms wildcard escaping plus minimum length plus digits-only rejection on the five admin lists. Migration review confirms the band widened to 7–12. UI review confirms no endpoint paths or raw dumps or numeric-id inputs, error-gated empty states, honest pagination, double-submit guards, modal layering, and the help page.

Ref: review passed on 2026-09-19 with contract checks green.

### ADR-024: Semester hierarchy restructure — School Year > Semester > Grade Level > Subject > Competencies

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-20 |
| **Deciders** | Platform team |
| **Supersedes** | — (reshapes ADR-002 organization wording; narrows ADR-004/ADR-005 scoping) |
| **Superseded by** | — |

#### Context

Organization, content, enrollment, and reporting were scoped through school years, generic time divisions, grade levels, sections, globally-coded subjects, and a subject-section join with a separate teacher-assignment table. That shape split teacher scope from classrooms, left subjects unscoped, left Competencies without Semester position, and forced every content write to carry a join key. The product requires trimesters (Semesters 1–3) as the time scope, subjects owned by one grade level, Competencies positioned by subject + grade + Semester, classrooms as the single teacher-scope source, and competency pickers filtered to the classroom triple.

#### Decision

> We will restructure to School Year > Semester (trimesters '1'/'2'/'3') > Grade Level (7–12) > Subject (scoped `grade_level_id`, code unique per grade) > Competencies (`competency_reference` with `subject_id`, `grade_level`, `semester` '1'/'2'/'3'): rename `terms` to `semesters` with an explicit `semester` column plus `UNIQUE(school_year_id, semester)` (migration `2026_09_19_000001_restructure_semester_hierarchy`, model `App\Models\Semester`); rename `term_id` to `semester_id` on grade levels, assignments, assessments, and assessment submissions; scope subjects under grade levels; add `competency_reference.semester` with CHECK; drop `subject_sections` and `teacher_subject_section_assignments` with holders gaining direct `subject_id` (classrooms additionally `section_id`, unique teacher + subject + section + school year, 409 `DUPLICATE_CLASSROOM`); carry `subject_id` + `semester_id` classroom-scoped on announcements/assignments/assessments/mastery/learning materials; derive teacher scope as the Semester + Grade Level + Subject package from owned classrooms with `GET /api/teacher/classrooms/{id}/competency-context` serving the filtered-Competency triple; validate every item/material competency triple with 422 `COMPETENCY_MISMATCH`; filter the catalog via `GET /api/admin/competency-tags?subject_id&grade_level&semester`; reject duplicate semesters 409 `SEMESTER_ALREADY_EXISTS`; keep legacy `/terms` routes only as deprecated aliases of the Semester routes (`App\Models\Term` deprecated alias); and answer `GET /api/admin/subject-sections` plus section/teacher-assignment writes with 410 `GONE`.

Ref: file paths and model names above live in backend code and the September migration.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| Semester hierarchy with dropped joins (chosen) | Rename to semesters, scope subjects/Competencies, drop join tables, classroom-derived teacher scope, triple validation, filtered catalog, deprecated aliases, 410 stubs | One Semester vocabulary; subjects/Competencies positioned; teacher scope from classrooms; filtered pickers; clear legacy signal | One-time migration; legacy callers must move off aliases and 410 paths | *(chosen — see rationale below)* |
| Parallel semesters table with dual-write | Keep old tables plus new Semester tables with sync | Zero-downtime compat | Dual-write divergence; doubled maintenance; pre-deployment cost unjustified | Turned down: codebase is pre-deployment per migration notes |
| Keep joins and add Semester columns only | Add Semester scoping without dropping subject-sections | Smaller diff | Teacher scope stays split; join maintenance remains; pickers stay unfiltered | Not chosen: leaves the core scoping problem unsolved |

#### Rationale

The chosen option makes the hierarchy explicit in schema, models, services, and contracts. Structure services enforce Semester values, per-Semester grade uniqueness, and per-grade subject codes. Classroom services enforce the shared grade package. Assessment and material services enforce the triple. Import validates rows against the scoped triple, and purge uses Semester closure. Vocabulary collapses to Semester and Competencies only, with the old time-division word surviving solely in deprecated-alias notes.

Ref: service names and error codes live in the code.

#### Consequences

- **Positive:** Predictable Semester scoping; scoped subjects/Competencies; classroom-owned teacher scope; filtered competency pickers; uniform 409/410/422 outcomes.
- **Negative:** Migration is one-way in practice (down restores shape only); legacy `/terms` and 410 paths require caller migration; `Term` alias removal needs a follow-up.
- **Neutral / Follow-up:** Semester value set, grade band, and uniqueness rules change only via a superseding decision.

#### Compliance / Verification

Migration review confirms renames, scoping, drops, and trigger rebuild. Model review confirms Semester plus subject, grade, competency, classroom, assessment, and assignment shapes with the old term alias only. Route review confirms Semester canonical plus deprecated aliases plus competency-context plus 410 stubs. Service review confirms triple validation and duplicate handling. Contract review confirms semester and subject identifiers with the stated error codes. Docs review confirms Semester-only wording outside deprecated-alias notes.

Ref: migration 2026_09_19_000001; models Semester, Subject, GradeLevel, CompetencyReference, Classroom, Assessment, Assignment, Term alias; routes file backend/routes/api.php.

---

### ADR-025: CompAss ID-only identity with email removal, full_name-only bulk, and Users-tabs frontend

| Field | Value |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-09-21 |
| **Deciders** | Platform team |
| **Supersedes** | ADR-017 (group/year staged retention: enrollment_import_rows.group_assignment/year_level dropped; no group/year input accepted or retained anywhere) |
| **Superseded by** | — |

#### Context

Identity onboarding used mixed identifiers. Admins and teachers signed in by email, students by learner code. Group and year traveled as optional staged context, duplicates matched by learner code, and confirm counts reported identity counts.

The shipped code replaces this with one uniform identity. Every account carries a server-generated CompAss ID. Email is gone. Bulk student and teacher sheets carry a single full_name column with fresh IDs always created. Hand place takes a name plus classroom and returns the new ID. The SPA organizes account work under Users tabs, with Onboarding limited to hand place and move plus competency import, and login CompAss-ID-only.

This decision supersedes ADR-017's staged group and year retention while keeping its placement separation. It constrains login, bulk and hand columns, search display, frontend surfaces, and the enrollment template branch.

Ref: migrations drop email and staged columns and add teacher bulk type; IDs generated server-side with retry; login resolves trimmed case-sensitive school_id.

#### Decision

> We will (a) identify every account by a server-generated CompAss ID only — `users.school_id` NOT NULL UNIQUE with format + role-prefix CHECKs, `users.email` dropped, fillable `name`/`school_id` only, with single create accepting name and role and update accepting name only, and manual or legacy keys refused rather than ignored; (b) log every role in through the single `identifier` field carrying the CompAss ID, trimmed then matched case-sensitively (no case folding, no email login) in a per device-and-account 5/15-min bucket where spacing variants share one bucket and distinct case variants hold distinct buckets; (c) run bulk student (`student_enrollment`) + bulk teacher (`teacher_application`) flows on a single `full_name` header column (case-insensitive, extras ignored, missing header 422) with 15 MB and 5000-row ceilings, 60-min single-use preview tokens, and 7-day single-use error sheets. Every valid row always creates a fresh account with names never matched and repeats counted as informational only; (d) hand-place single learners by full name and classroom, returning the new school_id with retry and no matching; and (e) ship the account surfaces as Users tabs with single and bulk per role, Onboarding limited to hand place and move plus competency import, and login CompAss-ID-only.

Ref: ID format with role prefixes; create with 3x retry; legacy keys including must_change_password refused 422; bulk lock via Cache::lock; staged learner code stores generated ID; place returns id, classroom, school_id, display name, manual kind, timestamps with teacher school_id on classroom shapes; ceilings 15 MB and 5000 rows; blank or overlong names rejected with plain wording.

#### Alternatives Considered

| Option | Description | Pros | Cons | Why Not Chosen |
|---|---|---|---|---|
| CompAss ID-only with full_name-only bulk and Users tabs (chosen) | Uniform server-generated IDs for all roles, email dropped, fresh IDs on every bulk/hand row, prohibited-key 422 discipline, tabbed account surfaces | One identifier for all roles; no email infrastructure; no name-collision matching errors; no parallel grouping source; loud failures on stale clients | Every bulk/hand row mints a new account (reused names duplicate); clients sending legacy keys must migrate; teacher enum value is irreversible without migrate:fresh | *(chosen — see rationale below)* |
| Keep email + learner-code + staged group/year | Retain mixed identifiers with code-matched updates and staged retention | No migration; existing sheets keep working | Mixed login paths; code-match ambiguity; staged vocabulary without reads; email handling burden | Turned down: the rework brief retires all three; migrations already drop the columns |
| Match bulk rows by name to existing users | Reuse accounts on name equality with updated counts | Fewer accounts on repeated uploads | Names are not unique — wrong-account merges; non-deterministic identity | Declined: names never identify; updated stays 0 by contract |
| Accept manual school_id / email on create | Operators supply identifiers | Control over ID assignment | Collisions, format drift, prefix mismatch, silent typos | Not chosen: server generation with retry plus prohibited-key discipline keeps the invariant |

#### Rationale

Identity becomes uniform across schema, services, contracts, and UI. One school_id column replaces the old email split. One login field resolves all roles with a trim-only case-sensitive bucket that resists cross-account pressure. One create and update pair replaces identifier haggling with loud failures on legacy or typo keys. One full_name column replaces code and group sheets with fresh-ID creation that cannot merge the wrong accounts. Keeping placement separation plus zero-valid handling and per-row atomicity preserves change safety while the staged group and year half is deleted with its columns. The Users-tabs split gives each account path one home with no duplicate flows.

Ref: column details, bucket rules, and key lists live in the code.

#### Consequences

- **Positive:** Single login contract for all roles. No email storage or handling. Bulk and hand rows never merge wrong accounts. Stale clients fail loudly instead of silent drops. Error sheets and preview counts stay actionable. Audit continuity preserved through generated-ID staged rows plus moves history.
- **Negative:** Reused names always duplicate, so operators must find accounts by CompAss ID, not name. Legacy sheets and clients carrying old keys are rejected until migrated. The teacher bulk type cannot be rolled back without a fresh migrate.
- **Neutral / Follow-up:** CompAss-ID format, prefix map, retry budgets, ceilings, token lifetimes, and tab structure change only via a superseding decision.

Ref: enum and reversibility details live in migration tests.

#### Compliance / Verification

Schema review confirms email dropped, school_id unique with format and prefix checks, and group and year dropped with teacher bulk added. Service review confirms account creation with retry, hand place with fresh creation, and bulk preview and confirm with fresh IDs and informational repeats. Contract review confirms login responses carry school_id with no email, create and update with prohibited-key discipline, single-column templates, error sheets with reasons plus help, and place and move shapes with school_id. Frontend review confirms Users tabs, Onboarding hand plus competency import only, and CompAss-ID-only login.

Ref: migrations 2026_09_20_000001 and 2026_09_21_000001; services createAccount and placeLearner; forms SingleAccountForm and BulkWizard; suite passed on 2026-09-21.

---

## 4. Example (Filled)

No worked example retained; each record above follows the template in Section 3.

---

## 5. Revision History

| Version | Date | Author | Summary of Changes |
|---|---|---|---|
| 1.0 | 2026-09-07 | Platform team | Initial v1 established from implementation |
| 1.1 | 2026-09-07 | Platform team | Added six enrollment, placement, people-list, search, safety, and rollout decisions with consistency notes for classroom enrollment and role-grouped experiences |
| 1.2 | 2026-09-09 | Platform team | Docs refresh: filled cross-refs for enrollment, placement, people list, search, safety, and rollout; clarified bulk and hand flows, people display, search rules, and rollout ownership |
| 1.3 | 2026-09-10 | Platform team | Rework cycle 1: constrained bulk and hand to staged group and year with identity counts; added hardened reset delivery and staged-context rules |
| 1.4 | 2026-09-11 | Platform team | Admin-only reset: constrained auth gating, marked self-service history-only, added manager-temp recovery with first-login parity |
| 1.5 | 2026-09-12 | Platform team | Frontend refresh, presentational-only: adopted updated shell and landing and login styling with same routes and guards |
| 1.6 | 2026-09-12 | Platform team | Second presentational refresh building on the prior system with same routes and guards |
| 1.7 | 2026-09-18 | Platform team | Frontend-agnostic backend: UI moved out of scope, prior UI decisions superseded, backend rules intact |
| 1.8 | 2026-09-19 | Platform team | First-party SPA: documented the shipped client with same-origin transport, session handling, guards, uploads, and error display |
| 2.0 | 2026-09-19 | Platform team | Lessons made policy: catalog read, import templates, per-user throttles, token ownership, search hardening, grade band 7–12, and UI policies |
| 2.1 | 2026-09-20 | Platform team | Semester restructure: school year, semester, grade, subject, and competency hierarchy with classroom-owned teacher scope |
| 2.2 | 2026-09-21 | Platform team | Identity rework: CompAss ID-only accounts with full_name-only bulk, hand place, and Users tabs |
