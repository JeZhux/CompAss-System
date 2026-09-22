# Deployment & Security Architecture

| Field | Value |
|---|---|
| **Document ID** | ARCH-006 |
| **System / Project Name** | CompAss |
| **Version** | 2.1 |
| **Status** | Approved |
| **Owner(s)** | Platform team |
| **Last Updated** | 2026-09-21 |
| **Reviewers** | Platform team |
| **Related Docs** | ARCH-001 ArchitectureOverview, ARCH-002 RequirementsQualityAttributes, ARCH-003 ArchitectureDecisionRecords, ARCH-004 DataArchitecture, ARCH-005 APIEventContracts — assumptions in ARCH-002 section 6 (ASSUMED-1/7/8/9 apply throughout) |

---

## 1. Purpose & Scope

How we deploy, run, and secure the system — so classroom work stays up and stays protected.

- **In scope:** Topology, network, identity and access, secrets, and security/ops controls.
- **Out of scope:** Business logic and pipeline internals.

---

## 2. Environments

| Environment | Purpose | Data | Access | Parity with Prod |
|---|---|---|---|---|
| Dev | Individual and shared development with the API service running alongside any client, debug output enabled, and verbose logging | Synthetic | Engineers | Low |
| Testing | Automated and pre-release validation against an isolated store with non-persistent handling, reduced cryptographic cost, and in-memory mail capture | Isolated ephemeral test data | Engineers and QA | Medium |
| Production | Live traffic with debug output disabled, log threshold raised to error level, session state encrypted, secure cookies required, and AI mocking disabled | Real | Restricted with break-glass process | — |

**Promotion path:** Dev → Testing → Production, gated by automated tests and review.

---

## 3. Deployment Topology

### 3.1 Infrastructure Overview

- **Hosting model:** Single-host pilot with an on-host reverse proxy, one DB, and local disk. The React SPA (`frontend/`, ADR-022) runs on :3000 in dev through a same-origin proxy (`/api` + `/sanctum` → 127.0.0.1:8000).
- **Compute model:** One API service and one store for all domain logic. Sessions and rate-limit counters live in the store; the queue runs sync in-process with no backing. Files sit on local disk. One external dependency — the text-generation service.
- **Infrastructure as Code:** None; we set hosts up by hand.

```
[External clients incl. React SPA (frontend/, dev :3000)] -- HTTPS --> [On-host Reverse Proxy]
                             |
                             v
                   [Backend API Service] <-- same-origin proxy (/api, /sanctum → 127.0.0.1:8000) <-- [React SPA dev server :3000]
                                                  |
                        +-------------------------+-------------------------+
                        |                         |                         |
                        v                         v                         v
              [Relational Database]     [Local File Volume]    [External Text-Generation Service]
              (domain + session +       (uploads, attachments,  (outbound HTTPS from backend)
               cache counters)           templates, error
                                         reports)
```


### 3.2 Component Deployment Mapping

| Component | Deployment Unit | Scaling Strategy | Min/Max Instances |
|---|---|---|---|
| API service | Single service instance on the pilot host behind the on-host reverse proxy | Vertical only; single instance with no autoscaling | 1 / 1 |
| Relational store | Single database instance on the pilot host holding domain, session, and cache-counter state; queue is synchronous in-process with no persisted state/backing | Vertical only; single instance with no read replicas | 1 / 1 |
| File volume | Local disk volume on the pilot host holding uploads, attachments, templates, and error reports | Vertical only; single volume with no replication | 1 / 1 |
| Scheduler | In-process scheduler inside the API service running daily retention and cleanup work | Runs with the single API instance; no separate workers | 1 / 1 |
| React SPA frontend | Vite dev server on :3000 in dev (static bundle in production) with a same-origin proxy for `/api` and `/sanctum` to 127.0.0.1:8000 per ADR-022 | Vertical only; single instance with no autoscaling | 1 / 1 |

### 3.3 High Availability & Disaster Recovery

| Aspect | Approach |
|---|---|
| Redundancy | No redundancy; single instance of each service and store on one host with a single file volume and no separate async infrastructure |
| Failover | No automated failover; recovery is manual restart and restore on the same host |
| RPO (Recovery Point Objective) | Per host procedure |
| RTO (Recovery Time Objective) | Per host procedure |
| DR strategy | Restore of the relational store and file volume from host backups onto the same host; no standby |
| DR testing cadence | Per host procedure |

- Retention and report cleanup depend on the daily scheduler running; a stopped scheduler leaves retention posture unmet.

---

## 4. Network Architecture

```
[External clients] -- HTTPS --> [Public: on-host reverse proxy (TLS termination)]
                                   |
                                   v
                     [App: API service]
                                                      |
                   +----------------+-----------------+----------------+
                   |                |                                  |
                   v                v                                  v
       [Data: relational store]  [Data: local file volume]  [External text-generation API]
       (domain + session +       (uploads, attachments,      (single outbound HTTPS call
        cache counters)           templates, error            from the API service only)
                                  reports)
```

| Zone | Purpose | Access Rules |
|---|---|---|
| Public reverse proxy | Single internet-facing entry point; terminates TLS and forwards to the API service (plus the React SPA dev server on :3000 in dev) | Only the proxy accepts outside traffic; forwarded-address headers are honored only from explicitly listed proxy addresses, otherwise the direct connection address is used |
| App API | API service handles all domain logic on the host | Reachable only through the reverse proxy; client API calls are limited to an explicit origin allow-list with credentials, stateful cookie authentication applies only to listed domains, and web routes carry forgery protection |
| Dev frontend (:3000) | React SPA dev server (ADR-022) with a same-origin proxy for `/api` and `/sanctum` to 127.0.0.1:8000; the default avoids localhost-vs-127.0.0.1 SameSite CSRF loss | Binds loopback only; the browser origin is the Vite host while API cookies stay same-site through the proxy |
| Data store + volume | Relational store holding domain, session, and cache-counter state with queue synchronous in-process with no persisted state/backing; local disk volume holding uploads, attachments, templates, and error reports | Reachable only from the API service on the host; no outside route |

- **Ingress control:** TLS ends at the on-host reverse proxy. Only listed origins can call the API, never a wildcard. Loopback dev uses the same-origin proxy so cookies stay same-site, with SANCTUM_STATEFUL_DOMAINS including localhost:8000 and CORS allowing http://localhost:3000 and http://127.0.0.1:3000 with credentials. Per-user throttles guard AI, imports, moderation, join and key routes, with the global per-IP `api` limiter (120/min) as backstop, and there is no public forgot/reset surface [Ref: ADR-018].
- **Egress control:** The API calls out to one service only, the text-generation service. Mail, queue, cache and session state all stay host-local.
- **Service-to-service traffic:** One API behind one proxy. No mesh, no mTLS — trust comes from host-local routing through the proxy.

---

## 5. Identity & Access Management

### 5.1 Human Access

| Role | Access Level | Environments | MFA Required | Provisioning |
|---|---|---|---|---|
| Admin | Full domain admin via API: users, classrooms, bulk enrollment, hand placement, imports, moderation and reporting incl. mastery records. User create takes {name, role} and update takes {name}, with unknown keys refused 422 and CompAss IDs server-generated with retry. Bulk sheets are full_name-only with explicit confirmation, 410 re-upload, `_rows` names, updated=0, in-file repeats noted, Row/full_name/Reason + Help sheet, and no group/year input. Hand placement uses placeLearner(full_name, classroom_id) with 201/200 and 409/410 handling. Imports honor 15 MB, 5000 rows, 60-min preview and 7-day error sheet. See section 8 for validation detail | All (production through break-glass process) | No | Application account created by the seeded provisioning admin or by an existing admin; the CompAss ID is recorded at creation and the first login is forced through the password-change flow |
| Teacher | Own classrooms as the Semester + Grade Level + Subject package. Classroom creation needs subject_id + section_id sharing one grade_level_id, else 422, with duplicates rejected 409 DUPLICATE_CLASSROOM. Content creation is classroom-scoped with subject_id + semester_id and no subject_section_id. Competency context comes from GET /teacher/classrooms/{id}/competency-context with filtered Competencies, mismatch 422 COMPETENCY_MISMATCH. Covers assessments, manual and bulk grading, resubmit with 1-1000-char reason via API, per-group class reports, mastery records, moderation queues, classroom keys, join and link. Bulk flows create accounts; placement stays a hand action | All (production through break-glass process) | No | Application account created by an admin (CompAss ID server-generated and recorded at creation); first login is forced through the password-change flow |
| Student | Own enrollments, classroom join and leave via API with already_left converged, attempts, explanations and past scores history. Reads never create AI explanations. People reads are names-only and view-only in small pages (15/100, alphabetical, 422 on search, photo_opt_in default off) showing display_name only, without school_id, email or learner_code. Classroom list and detail reads are unpaginated and name-ordered. Leave history is kept in classroom_leave_history | All (production through break-glass process) | No | Application account created by an admin (CompAss ID server-generated and recorded at creation; bulk and hand rows always mint fresh IDs); first login is forced through the password-change flow |
| Host operator | Operating-system and host access: service restart, backup restore, environment configuration | Production host | No | Manual host-level provisioning with a break-glass process |

- **Identity provider:** Application accounts with server-side sessions; there is no external single sign-on.
- **Principle applied:** Access is least-privilege and role-based, checked on the server on every route. Clients handle 403/422 and never authorize locally, with UI guards mirroring server roles plus a forced password-change redirect. Identity is CompAss-ID-only: users.school_id NOT NULL UNIQUE with role-prefix, server-generated with retry, users.email dropped, login trimmed and case-sensitive. Bulk and hand creation are admin-only with full_name-only sheets, fresh IDs always (updated=0, duplicate_matches are in-file repeats), `_rows` names, no group/year input, HumanSearch digits-only refused 422, every valid row mints a new account with no name matching, and same-destination moves need a different destination. Reports stay paginated and scoped, enrollment uses one canonical API flow per bulk kind, and try-out and test stay staff-only [Ref: ADR-022; TYPE_STUDENT_ENROLLMENT / TYPE_TEACHER_APPLICATION].
- **Directory and identifier handling:** Login uses the CompAss ID only for all roles, trimmed and case-sensitive with no email login. The learner list is names-only and view-only in small pages (15/100, alphabetical) with ?search= refused 422, unenrolled 403 and archived 410, no school-wide search and no write actions, and photos only with opt-in (photo_opt_in default off). Record numbers assign automatically behind the scenes. List and detail views show CompAss IDs plus teacher_school_id on classroom shapes and join keys for rooms, while internal numbers stay hidden from responses and search.
- **Privileged access:** Admin-role actions are recorded in the audit log, including bulk file enrollment and every hand placement and move with actor, moved learner, and timestamp plus every classroom leave in classroom_leave_history. Accounts flagged for a mandatory password change are blocked from all authenticated API use except signing out, changing the password, viewing their own profile, and checking AI status until the change completes. Deactivated accounts lose all usable sessions, including sessions issued before deactivation.

### 5.2 Service/Workload Identity

- **Service-to-service auth:** Host-local trust only. One API behind the proxy, no mesh, no mTLS, no short-lived certs.
- **Service-to-cloud-resource auth:** Local config in the host environment. No workload federation, no separate cloud identity.
- **Third-party/external auth:** The text-generation key lives on the server and only travels server-to-server — never to the browser.

---

## 6. Secrets Management

| Aspect | Approach |
|---|---|
| Storage | Real values live only in the environment file on the host and are never committed; committed templates ship with empty placeholders and version control excludes real environment files |
| Rotation | Manual replacement on each host individually; there is no vault and no automated rotation |
| Access | Scoped by host file ownership; the provisioning-admin credentials and the AI service key exist only in the host environment, and the AI key is used only by the API service, never sent to the browser |
| Local dev | Throwaway values with mock AI responses in non-production environments only; mocking is disabled in production, where a missing AI key surfaces as a service-unavailable error instead of mock output |

---

## 7. Data Protection

| Data State | Control |
|---|---|
| At rest | Domain, session, cache-counter, and audit state rest in the host-managed relational store; uploads, attachments, templates, and error reports rest on the host-managed local disk volume; session payloads are encrypted in production and credentials are stored only as salted hashes; no additional application-level encryption is in place |
| In transit | TLS terminates at the on-host reverse proxy; production requires secure cookies with HTTP-only and same-site handling; web routes carry forgery protection and cross-origin calls are limited to an explicit origin allow-list |
| In use | Not applicable; no confidential-computing or in-memory encryption beyond normal request handling |
| Key management | Keys and credentials are held in the host environment file and never committed; replacement is manual per host with no dedicated key service and no automated rotation |
| Data masking (non-prod) | No separate non-production masking process; minimization applies everywhere: outbound generation prompts withhold direct identifiers with response content sent only as delimited data, login identifiers (CompAss ID, all roles) are stored in masked form, raw secrets and network addresses are stripped from audit metadata, and stored network addresses are irreversibly anonymized after 90 days |

---

## 8. Application & Infrastructure Security Controls

| Control Area | Approach |
|---|---|
| Input validation | Write paths validate requests with structured rejection and numeric range checks before auth. User create takes {name, role} and update takes {name} with school_id immutable, unknown and legacy keys refused 422 prohibited, and hand place takes {full_name, classroom_id} only. Search inputs escape LIKE wildcards with min length 2 and digits-only rejection on users, classrooms, subjects, assignments and competency-tags lists, and the removed GET /api/admin/subject-sections answers 410 GONE. Semester requires '1'/'2'/'3' else 409 SEMESTER_ALREADY_EXISTS, classroom needs subject_id + section_id sharing grade_level_id else 409 DUPLICATE_CLASSROOM, competency needs (subject_id, grade_level, semester) triple else 422 COMPETENCY_MISMATCH, and preview and error tokens are verified-then-consumed with ownership checks, non-owner peek-GET 403 preserving the token, error download checking the owner, token length min:32 max:128 and student/teacher type pin. Bulk sheets are full_name-only with 15 MB, 5000 rows, per-row atomic save, fresh IDs (updated=0), explicit confirmation with 410 re-upload, 7-day Row/full_name/Reason + Help error sheet saving good rows, API confirmation before bulk delete with moves needing explicit confirmation, and content needing classroom scope subject_id + semester_id with no subject_section_id. Rejections stay plain with `_rows` names [Ref: ADR-023; Cache::lock confirm claim] |
| Authentication & authorization | Server-side session authentication with role enforcement on every route, mandatory password-change gating covering first login and password resets except signing out, password change, profile, and service-status reads, and deactivation that invalidates all sessions |
| Rate limiting | Login is limited to 5 attempts per 15 min per address and account, keyed on the trimmed case-sensitive identifier with no named limiter. Failed sign-ins lock briefly, so wait a moment before retrying. Named per-user throttles apply: import uploads (10/min), AI generation (10/min), AI chat (30/min), moderation writes (60/min), classroom join (20/min), classroom-key create/rotate (10/min), with brief waits when exceeded. The global per-IP `api` limiter (120/min) acts as backstop, with per-user keying so shared classrooms keep separate budgets and no public reset throttle [Ref: ADR-023; ADR-018] |
| Forgery protection | Forgery protection on web routes with stateful cookie handling for listed domains |
| Session security | Server-side sessions with a 30-minute inactivity lifetime, encrypted payload in production, secure HTTP-only same-site cookies, and probabilistic sweeping of expired rows |
| Credential storage | Credentials store as salted one-way hashes with recovery by admin-issued temporary password only and no public forgot/reset surface. Resets hand the temp to the calling admin over the authenticated session, set must_change_password=true, revoke other sessions of the target, preserve deactivation, write an audit row, refuse non-admins with 403 FORBIDDEN and weak input with 422 VALIDATION_ERROR, and force a password change before other use with only sign-out, password change, profile and service-status reads allowed. Handoff and session handling risks remain, and lockout recovery stays admin-assisted. Preview tokens (60-min) and import error reports (7-day) expire separately, never credentials, raw secrets never reach logs, and there is no self-service reset: an admin issues a temporary password and the user sets a new one at next login [Ref: ADR-018; FR-004; ASSUMED-9; ID-G; PARK-2] |
| Audit writes | Centralized append-only audit writes on significant actions with failures logged server-side without blocking the caller |
| Dependency & patch hygiene | Dependencies tracked in version-controlled manifests with manual host-level patching; no automated patch window is defined |
| Vulnerability scanning | None; no software-composition or image scanning is in place |
| Static analysis (SAST) | None; no static-analysis gate is in place |
| Dynamic analysis (DAST) | None; no dynamic-analysis scans are in place |
| WAF / DDoS protection | None; only the on-host reverse proxy fronts public traffic |
| Container/runtime hardening | None; single-host services run without container isolation claims |
| Penetration testing | None; no third-party penetration test is in place |

---

## 9. Compliance & Regulatory Requirements

| Requirement | Scope | Key Controls | Audit Cadence |
|---|---|---|---|
| Privacy posture (data minimization and purpose limitation) | All personal and classroom data | Identifiers withheld from outbound generation prompts with response content sent only as delimited data; CompAss-ID login identifiers stored masked in logs; raw secrets never logged; learner directory is names-only and view-only with paginated display, no school-wide search, and photos only with explicit opt-in; internal numbers stay hidden from display and search for all roles with CompAss IDs (plus teacher_school_id on classroom shapes) and join keys shown instead; no email stored anywhere (users.email dropped) | Per review |
| Audit retention posture | Audit trail | Network addresses irreversibly anonymized after 90 days; audit rows hard-deleted after 365 days by the daily scheduled run | Per review |
| Semester-scoped purge | Semester-bound submission files (`POST /api/admin/semesters/{semesterId}/purge`; legacy `/terms` purge is a deprecated alias) | Semester-scoped purge with preview after closure (`purge_semesters` audit); mastery records, explanations, and materials persist; file cleanup is best-effort | Per review |
| AI transparency | Generated explanations | Every explanation carries a visible supplementary-aid disclaimer plus a distinct notice when not grounded in teacher materials; outages degrade to a service-unavailable message | Per review |
| Formal certification | Whole platform | No formal legal certification is claimed | Per review |

---

## 10. Observability & Security Monitoring

| Aspect | Approach | Tooling |
|---|---|---|
| Centralized logging | Application errors and events go to a single-file application log; significant domain actions go to the relational audit trail | Application log files |
| Metrics & alerting | None; no golden-signal metrics or on-call alerting are in place | None |
| Distributed tracing | None; no trace propagation is in place | None |
| Audit logging | Append-only audit writes capturing actor, event type, message, affected entity, and network context, including full history for all score changes, all learner placements and moves with actor and timestamp, and all classroom leaves; write failures never block the triggering action | Relational audit trail |
| SIEM / threat detection | None; no centralized security-event correlation is in place | None |
| Log retention | Audit network addresses anonymized after 90 days and audit rows hard-deleted after 365 days; learner preview tokens expire after 60 minutes and import error reports expire after 7 days and become unavailable after first download; application log keeps a single file by default with optional daily rotation keeping 14 days; retention and cleanup jobs run daily and require a running scheduler | Application log files |

---

## 11. Incident Response

| Aspect | Approach |
|---|---|
| Detection | Review of the single-file application log and the relational audit trail plus user reports; no automated alerting is in place |
| Escalation path | Reported issues go to the Platform team for triage and recovery |
| Runbooks | Generic operational notes covering service restart, backup restore, account recovery via admin-issued temp with no public reset, and semester purge preview before any destructive action [Ref: PARK-2] |
| Communication plan | Updates shared through school channels |
| Post-incident review | Review conducted as needed with fixes tracked to closure |
| Breach notification | Notification handled under school policy |

---

## 12. CI/CD & Change Management

| Aspect | Approach |
|---|---|
| Pipeline | Automated checks run on push and pull request covering backend lint, migration smoke test, React SPA frontend (Vite + React Router) lint with typecheck and build, backend test suite, and health probe; deployment to the host is manual |
| Approval gates | Review required before merge to the production branch |
| Deployment strategy | Replace in place on the single host, released in stages with a single grade-level pilot running one to two weeks followed by fixes before opening to the whole school (operational, not code; owned by the school operator) |
| Rollback | Forward fix or restore of the relational store and file volume from host backups |
| Change freeze windows | No production changes during school blackout periods, including a freeze on broad structural changes during exam weeks |

---

## 13. Threat Model Summary

The threats we actually worry about.

| Threat | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Repeated login attempts against application accounts | Medium | High | Too many tries trigger the login throttle, and deactivated accounts lose all sessions |
| Classroom join and key guessing | Medium | Medium | Join and key routes carry named throttles with server-side validation |
| Session theft and forgery attacks against web routes | Medium | High | Web routes use forgery protection with encrypted server-side sessions and secure HTTP-only same-site cookies in production |
| CSRF failure from cross-loopback origins (localhost vs 127.0.0.1 SameSite loss) | Medium | High | Dev traffic stays same-origin so cookies stay same-site, with fresh CSRF bootstrap at login, single 419 refresh-and-retry, SANCTUM_STATEFUL_DOMAINS including localhost:8000 and CORS allowing both loopbacks with credentials |
| Direct object access and role bypass attempts | Medium | High | Every route checks roles with password-change gating and numeric range checks |
| AI service key exposure and proxy misuse | Low | High | The AI key lives only in host environment for server-to-server calls, never in the browser, with mocking off in production |
| Malicious or oversized uploads | Medium | Medium | Writes reject oversized and excess files before storage |
| Audit trail tampering or deletion | Low | High | Audit writes stay append-only with failures logged without blocking |

---

## 14. Open Risks & Questions

| Risk / Question | Impact | Owner | Status |
|---|---|---|---|
| Single host with no redundancy; any host failure stops all services | High | Platform team | Open |
| No standby; recovery depends on manual restart and restore on the same host | High | Platform team | Open |
| Uploads, attachments, templates, and error reports held on a single local disk volume with no replication | Medium | Platform team | Open |
| Retention and cleanup depend on the daily in-process scheduler; a stopped API service leaves retention unmet | Medium | Platform team | Open |
| No alerting or centralized security-event correlation; detection rests on manual log review and user reports | Medium | Platform team | Open |
| Secret replacement is manual with no automated rotation | Medium | Platform team | Open |
| Manager-issued temp handoff carries session and handling risk, with lockout recovery admin-assisted [Ref: PARK-2] | Medium | Platform team | Open |

---

## Revision History

| Version | Date | Author | Summary of Changes |
|---|---|---|---|
| 1.0 | 2026-09-07 | Platform team | Initial v1 established from implementation |
| 1.1 | 2026-09-09 | Platform team | Human-access rows, principle and directory notes, input validation ceilings, rate-limit summaries, credential storage and retention notes; word-gap fixes and cross-refs reconciled [Ref: ASSUMED-1] |
| 1.2 | 2026-09-10 | Platform team | Credential storage hardening with prod enforcement, logging and throttle updates, ingress updates, header assumptions [Ref: ADR-016; ADR-017; ASSUMED-1/7/8] |
| 1.3 | 2026-09-11 | Platform team | Admin-only credential flow with no public surface, ingress and rate-limit cleanup, runbook clarification, manager-temp risk row added [Ref: ADR-018; ASSUMED-1/7/8/9; PARK-2] |
| 1.4 | 2026-09-12 | Platform team | Presentational frontend overhaul, no API, schema, auth, throttle, audit or deployment change |
| 1.5 | 2026-09-12 | Platform team | Presentational v2 overhaul, no API, schema, auth, throttle, audit or deployment change |
| 1.6 | 2026-09-18 | Platform team | Frontend-agnostic: single backend API service with external clients (UI service row deleted, diagrams Clients->Proxy->API); §5.1 access reworded to API capabilities with server enforcement only; §8 validation keeps ceilings/confirmations with classroom-scope rule |
| 1.7 | 2026-09-19 | Platform team | React SPA frontend unit (ADR-022): §3 hosting/diagram/mapping carry frontend/ :3000 with same-origin proxy to 127.0.0.1:8000; §4 adds dev-frontend zone and CSRF note (stateful domains incl. localhost:8000, CORS both loopbacks); §5 principle carries mirrored role guards; §12 pipeline names the React SPA build; §13 adds the cross-loopback CSRF threat row |
| 2.0 | 2026-09-20 | Platform team | Semester hierarchy restructure: teacher Semester+Grade+Subject package with competency-context + filtered Competencies (422 COMPETENCY_MISMATCH); input validation semester/subject/competency rules (409 SEMESTER_ALREADY_EXISTS, 409 DUPLICATE_CLASSROOM, 410 GONE subject-sections); Semester-scoped purge (purge_semesters, /terms deprecated alias); search lists subjects; Semester-only wording |
| 2.1 | 2026-09-21 | Platform team | CompAss rework: CompAss-ID-only identity, masking updates, input validation and rate-limit updates, privacy wording [Ref: ADR-025] |
| 1.9 | 2026-09-19 | Platform team | Backend hardening: per-user limits (import 10, AI-generate 10, AI-chat 30, moderation-write 60, join 20, classroom-key 10 per min) with global per-IP backstop (120/min), token ownership checks and search input rules [Ref: ADR-023] |
