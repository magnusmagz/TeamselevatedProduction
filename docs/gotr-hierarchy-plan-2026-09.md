# Girls on the Run — Hierarchy, Compliance and Scale Plan

Written 2026-09-02 from Maggie's brief and a code discovery pass. Roadmap rows R44–R63.
Companion: the roadmap execution plan (`docs/roadmap-execution-plan-2026-09.md`) and the
association scope from 2026 (`/Users/maggiemae_1/TeamsElevated/association-scope.md`).

## The ask, in their terms

Girls on the Run (GOTR) is a national nonprofit: **national → division** (their word is also
"region": a collection of federated councils) **→ council** (city, metro or state, depending on size) **→ site**
(where a team meets). About **270 sites**, and roughly **30,000 coaches and volunteers**
joining the platform. What they need to run it:

- **Council leaders** see every coach and volunteer in their council: background check,
  CPR/First Aid, GOTR training, council-specific requirements (a California mandated-reporter
  course, for example), with expiry dates, filters, and bulk reminders.
- **Division and national staff** see the same thing rolled up: compliance rate by council,
  critical gaps, trend, drill-down, exportable reports for the board and insurers.
- **Coaches** see their own requirements for their role, upload proof from a phone, get
  90/60/30/7-day reminders, and know when it was accepted.
- Roles differ by requirement: head coach, junior coach (as young as 16), team helper.
- Later: LMS integration (Cornerstone OnDemand), email-in document submission, training
  provider search. Not in this plan's build phases.

## What exists today, and why it does not stretch

Everything in Teams Elevated is **club → team**. Findings that decide the shape of this plan:

| Fact | Where | Consequence |
|---|---|---|
| No entity above club. A league tier was built (`001_league_hierarchy.sql`) and deliberately removed (`004_merge_league_into_club.sql`); `_backup_leagues` etc. still hold the shape. | migrations 001, 004 | The blueprint for a tier exists; the collapse migration is the reversal recipe. |
| The JWT embeds one object per club role, **with the club name**. `requireAuth()` re-derives roles from the DB on **every request**, including a full-table `teams ⟕ team_members` scan. | `lib/JWT.php:44-172`, `lib/AuthMiddleware.php:305` | A national admin with 270 roles gets a ~40 KB token that exceeds the router's header limit. They cannot log in. Per-request cost grows with the whole platform's team count. |
| `active_context` is one club; the frontend context switcher is a stub (`console.log`). The backend switcher exists and works. | `OrgContext.tsx:50`, `auth-gateway.php:1191` | Multi-club navigation is UI work, not backend work. |
| Scope predicates materialise id lists into `IN (?,?,…)`. `accessibleAthleteFilter` inlines **one placeholder per athlete**. Postgres caps bind parameters at 65,535. | `lib/AthleteScope.php:254`, 40+ sites | A division admin over 30 councils is within an order of magnitude of a hard protocol error, and planner time dies long before. |
| No list endpoint paginates. Club roster, volunteers, super-admin club list (four correlated COUNTs per row), club-wide recipient resolution. | `auth-gateway.php:1098`, `volunteer-gateway.php:474`, `super-admin-gateway.php:378` | 270 councils × unbounded lists. |
| Background check status is stored **per (team, user)** row; the predicate takes "any cleared row wins" with `LIMIT 1` and **never returns `expired`**. No person-level record. | `team_volunteers`, `lib/background_check.php:16-49` | A coach cleared on a team they left is cleared everywhere, forever. |
| `guardians.safesport_trained` / `safesport_expiry` exist and nothing reads them. | schema | The only cert+expiry pair, unwired. |
| Documents: `documents(slot, is_required, expires_at)` and polymorphic `document_assignments` are sound and correctly scoped. **`document_acknowledgments` is an empty table with zero code** (audit 2026-09-02), and uploads go to the dyno's local disk, unauthenticated while they exist. | `032_document_system_unified.sql`, `api/upload.php` | Reuse the first two. The signing flow and durable, authorised storage are built from scratch in G3/G4, and storage is a live defect to reconcile before reuse (decisions 14, 15). |
| Compliance dashboard computes exactly the right summary — for **one** club id. No export. | `volunteer-gateway.php:511-604`, `ComplianceDashboard.tsx` | The aggregation generalises to a set of clubs unchanged. |
| No reminders sweep anything. | `workers/queue-worker.php` | Expiry is a screen someone has to open. |
| Import: one row per transaction, one progress UPDATE per row, whole CSV in memory twice, **one club per job**, imported users get no invite. | `ImportJobProcessor.php:102-114`, `CoachImportStrategy.php:59` | 30,000 coaches means 30,000 unclaimed accounts and a multi-hour single-threaded job. |
| One worker dyno, single-threaded FIFO for every queue. | `Procfile` | One onboarding blast serialises behind everything else. |
| Missing indexes: `team_members` (none), `athlete_guardians` (none), `teams.primary_coach_id` (none). | migrations | Every scope query joins these. |

## Architecture

### 1. Hierarchy: a tree above the club, not a bigger club

**Council = club.** A GOTR council becomes a `club_profile` row. Every existing scope
predicate, page, import, and permission keeps working for council staff on day one. Sites are
`venues` (and programs) under the council, which already exist.

**Above the club: `org_units`.**

```
org_units (id, parent_id → org_units, type CHECK ('national','division','council'),
           name, external_code, path TEXT, depth INT, created_at)
club_profile.org_unit_id → org_units   -- a council row; NULL for every non-GOTR club
user_org_access (id, user_id, org_unit_id, role CHECK ('org_admin','org_viewer'),
                 granted_at, granted_by, revoked_at, revoked_by, active)
```

- `path` is a materialised path (`/1/4/17/`). "Every council under division 4" is
  `path LIKE '/1/4/%'` with a text index. No recursion, no closure table to maintain.
- Existing clubs are untouched: `org_unit_id` is nullable, so CKU and club 32 never see
  any of this.
- Roles at a tier are two: `org_admin` (manage requirements, review, edit) and `org_viewer`
  (read-only rollups). National staff are `org_admin` on the national row. This is the
  `user_league_access` pattern from migration 001 with the audit columns it already had.
- The floating-volunteer case (one coach at two sites) is `user_club_access` rows in two
  councils, as today.

### 2. Scope resolution: sets, not lists

`lib/org_scope.php` becomes the one answer to "which clubs can this user reach":

- `te_org_club_ids_sql($auth)` returns a **subquery**, never a materialised list:
  `SELECT c.id FROM club_profile c JOIN org_units o ON o.id = c.org_unit_id
   WHERE o.path LIKE ANY (:prefixes)` unioned with the user's direct `user_club_access`.
- Every `IN (?,?,…)` built from a scope list is rewritten to `IN (<subquery>)` or
  `EXISTS`. The materialised-id helpers stay for the guardian branch only (a family is
  never more than a handful of athletes).

### 3. Token diet and cached context

- The JWT stops carrying club roles by name. It carries `user_id`, `system_role`, the (few)
  `user_org_access` rows, and `active_context`. Club roles are resolved server-side.
- `refreshRolesFromDb()` is cached in Redis for 5 minutes per user, invalidated by any
  write to `user_club_access` / `user_org_access` (the grant and revoke paths are few and
  already audited). The unbounded `teams` scan gets a `WHERE t.club_id IN (<scope>)`
  predicate and the missing indexes.
- Frontend: implement the context switcher against the existing backend `switch-context`.
  A council picker for division and national users; council staff see no change.

### 4. Compliance model: person-level, expiring, verified

```
compliance_requirements (id, org_unit_id → org_units NULL, club_profile_id → club_profile NULL,
    -- exactly one of the two is set: a tier-wide rule or a single club's own
    kind CHECK ('background_check','cpr_first_aid','training','document','custom'),
    name, description, proof CHECK ('document','attested_date','external_link'),
    proof_url, validity_days INT NULL, required BOOL, active BOOL, sort_order, created_by)
person_credentials (id, user_id, requirement_id, status CHECK
    ('missing','submitted','verified','rejected','expired'),
    issued_at DATE, expires_at DATE, document_id → documents NULL,
    submitted_at, verified_by → users, verified_at, rejection_reason,
    source CHECK ('portal','admin','import','lms','email'), notes,
    UNIQUE (user_id, requirement_id))
club_staff_roles (user_id, club_profile_id, staff_role CHECK (head_coach, junior_coach,
    team_helper, volunteer))   -- the GOTR role vocabulary; today's user_club_access.role stays as is
compliance_requirement_roles (requirement_id, staff_role)   -- a requirement applies to a SET of roles
compliance_reminder_streams (id, requirement_id, org_unit_id, active,
    steps JSONB: [{days_before, subject, body_markdown}], created_by)
compliance_reminder_log (credential_id, stream_id, days_before, sent_at)  -- never twice per step
```

- **Requirements are user-defined, never a fixed list** (Maggie, 2026-09-02): we cannot
  predict what paperwork makes someone compliant — concussion protocol, SafeSport, a state
  background check, a council's own training. So the builder is generic: name, description,
  what counts as proof (an uploaded document, a completion date the person attests, or a
  link to complete elsewhere), validity period, which roles it applies to, and a
  **required** flag that decides whether a missing or expired record puts the person out
  of compliance. `kind` is a category for reporting, with `custom` always available.
  **Because club = council, every club admin gets this tool**, GOTR or not — CKU can define
  SafeSport and concussion protocol for its coaches the same way. That also finally gives
  the unwired `guardians.safesport_*` columns a home.
- **Admins at any tier define requirements**: a national, region or
  council admin creates a check type such as *State background check*, *CPR/First Aid*,
  *GOTR core training*, and says which staff roles it applies to. Coaches and volunteers
  have different lists that may overlap, so a requirement carries a set of roles, not one.
  Each person's record for it holds **status, completed date, expiry date**; everything
  downstream (alerts, reminders, rollups) works backwards from the expiry date.
- **No checker is built.** We record that a check was completed and when. Manual turn-in
  is the primary path: the coach uploads the proof, the council marks it verified. Vendor
  integrations (Sterling, Checkr) may come later as a `source`; the model does not depend
  on them.
- **Reminder streams are data, not code** (later phase, but modelled now): an admin can
  attach a *communication stream* to a requirement — a sequence of offsets before expiry
  (90/60/30/7 days) each with its own subject, body and "these are the steps to renew"
  copy, activated per requirement and per tier. Sends go through the existing queue and
  the club-branded transactional path; one send per person per step, recorded on the row.
- Requirements **inherit down the tree**: national defines the baseline, a division or
  council adds its own (California's mandated-reporter course lives on that council's row).
  "What does this person need" = requirements on every ancestor of their council, filtered
  by their staff role.
- **Expiry is computed, never guessed**: `expires_at = issued_at + validity_days`, and a
  nightly tick moves `verified` → `expired`. `te_background_check_status()` reads
  `person_credentials` first and falls back to `team_volunteers` during migration, so the
  existing volunteer gate keeps working and finally learns the word "expired".
- **Verification is a state, with a reviewer.** Upload goes through the existing
  `api/upload.php` (Phase 5 of the roadmap makes that durable; it is a prerequisite here
  because GOTR documents must survive a dyno restart) and `documents`; the council reviewer
  approves or rejects with a reason. Every transition is audited.
- **Reminders** are a throttled tick in `workers/queue-worker.php` (the chat-notification
  pattern), emailing at 90/60/30/7 days before `expires_at`, with the switch
  `TE_FEATURE_COMPLIANCE_REMINDERS`. One email per person per threshold, recorded on the row.
- **The coach's portal is a constrained view, mobile first.** A coach signs in to their
  own dashboard: an alert per requirement that is missing, expiring or rejected, one tap
  to upload (camera capture on a phone, file picker on desktop, same PWA), then a clear
  "received / under review / accepted / rejected because…" state. Nothing else of the
  staff app is in that view.
- **Rollups** are one SQL shape: credentials joined to `club_profile` joined to `org_units`,
  grouped by whichever ancestor the viewer picked. Compliance rate, critical gaps, expiring
  in 30 days, trend by month. The existing `action=compliance` payload and dashboard extend
  to take an `org_unit_id` instead of a `club_id`.
- **Export** is CSV first (the roster-export pattern: BOM, caps reported, staff-gated), PDF
  later.

### 5. Scale workstream

Independent of the hierarchy and worth doing regardless:

- Indexes on `team_members (team_id, user_id, athlete_id)`, `athlete_guardians
  (athlete_id, guardian_id)`, `teams (primary_coach_id)`.
- Pagination on every list endpoint that a council will hit (crew, volunteers, athletes,
  coaches, super-admin clubs), with a stable cursor and a reported cap.
- `IN`-list rewrites at the sites the discovery listed; a test that fails on any new
  `array_fill(0, count($ids), '?')` over a scope list.
- Worker: a second dyno and per-queue consumers (email vs import vs ticks), so an
  onboarding blast cannot starve a scheduled broadcast.
- Import: streamed CSV (generator, not the whole string), batched inserts, a progress row
  per 500 not per 1, a **`council_code` column** so one national file can populate many
  councils, and an **invite** per imported person (reusing the parent-invite token pattern
  with a `:coach_invite` suffix, which the roadmap's coach-password item already calls for).
- Smoke test: sample councils rather than iterate all of them.

## Phases

Each slice follows the roadmap rules: one branch, one revert, failing test first, additive
migrations with reverse SQL, switches for anything that sends. Migration numbers are
claimed when written; next free is 085.

| Phase | Scope | Tests | Rollback / risk |
|---|---|---|---|
| **G0 Discovery with GOTR** (1 wk) | Requirement matrix per role and per council; their background-check vendor and whether we record results or run checks; which councils have extra requirements; LMS is later; who at national is the admin; data they can export today (a coach list with council codes). | Signed-off matrix and a sample file. | None. This decides G3's seed data. |
| **G1 Hierarchy, dark** (1 wk) | `org_units`, `club_profile.org_unit_id`, `user_org_access` (migration); `lib/org_scope.php` with the subquery resolver; a super-admin page to build the tree and attach councils. Nothing else reads it. | Resolver on SQLite: descendant prefix, union with direct roles, empty scope is `1=0`; tree CRUD gated on super admin. | Reversible by dropping three objects; no existing club has an `org_unit_id`. |
| **G2 Scale foundations** (2 wk, parallel with G1) | Indexes; cached role context + token diet (frontend and backend together, frontend first); context switcher UI; pagination on the five list endpoints; `IN`-list rewrite at the scope sites; second worker dyno. | Token size assertion for a 300-role user; cache invalidation on grant/revoke; pagination contract tests; a scan for new `array_fill` over scope lists; existing 1,101 PHP + 164 chat tests green. | Each is its own revert. Cache has a switch (`TE_FEATURE_ROLE_CACHE`). The token change is the one with a deploy-order rule: frontend must read the new shape before the backend mints it. |
| **G3 Compliance model** (2 wk) | Three tables; requirement inheritance; `person_credentials` write paths (admin entry, import, portal upload); `te_background_check_status()` reads credentials first; migration script from `team_volunteers` rows to person-level records (one per person, latest date wins, `expired` computed). Behind `TE_FEATURE_COMPLIANCE`. | Inheritance and role filtering on SQLite; expiry computation on the boundary days; the volunteer gate still refuses non-cleared on both old and new data; migration dry run reports counts. | Tables are additive; the gate's fallback means the old data keeps working if the new path is switched off. |
| **G4 Council + coach surfaces** (2.5 wk) | Requirement builder for tier admins (name, kind, roles it applies to, validity days); council compliance dashboard (extends the existing one), list with filters (compliant / expiring / expired / missing), document review queue with approve/reject and reasons; **coach portal**: constrained dashboard with per-requirement alerts and one-tap mobile upload (camera capture), status after upload; default reminder tick 90/60/30/7 behind `TE_FEATURE_COMPLIANCE_REMINDERS`; CSV export. | Jest for each screen; PHP for the review transitions and reminder dedupe; a reminder never sends twice for one threshold. | Switches for reminders; UI reverts alone. Depends on roadmap Phase 5 (durable photo/document storage) for uploads. |
| **G5 Division and national** (1.5 wk) | Rollup dashboard by descendant council; drill-down; trend; "highest risk councils"; export by date range / council / requirement; impersonation-style "open this council" for org admins using the existing switcher. | Rollup SQL against a fixture tree; an `org_viewer` cannot write; a division admin cannot see a sibling division. | Read-only surfaces; revert alone. |
| **G6 Onboarding 30,000** (1.5 wk) | Streamed, batched, multi-council import with `council_code`; per-person invite with a real accepted timestamp; a national funnel report (accounts created, invited, activated, compliant). Rate-limited send through the queue. | Import 50k-row fixture within memory limit; invite tokens single-use; funnel counts match fixtures. | Import behind a switch; invites are a queue you can pause. |
| **G7 Streams and integrations** (later) | Admin-authored reminder streams per requirement (offsets, copy, renewal steps), activated per tier; LMS (Cornerstone) completion → `person_credentials` with `source='lms'`; email-in submissions; background-check vendor result feeds. | Stream never sends a step twice; per integration. | Each behind its own switch. |

Rough total for G1–G6: **about ten engineering weeks**, with G1/G2 in parallel and G4/G5
overlapping. G0 gates G3's seed data but nothing else.

## Decisions this plan needs from GOTR (via Maggie)

1. ~~Record or run checks?~~ **Answered 2026-09-02 (Maggie): record only, manual turn-in first;
   vendor feeds maybe later.**
2. The requirement matrix per role, and which councils have extras. (Plan assumes national
   baseline + council additions; a division adding requirements is supported but rare.)
3. Validity periods: background check (typically 2 years), CPR (2 years), GOTR training
   (per season?). Numbers drive the reminder cadence.
4. Is a coach's proof reviewed by the council or accepted on upload? (Plan assumes council
   review — Maggie's description has the coach upload and the council confirm; a national
   "auto-accept for LMS-sourced" rule is G7.)
5. What do national and division staff need to edit, if anything? (Plan assumes read-only
   rollups plus impersonation-style drill-in.)
6. Their export today: a coach roster with council codes and emails. That file is G6's
   input and G0's sample.

## What this changes for existing clubs

One thing, and it is a gain: club admins get the requirement builder and the coach compliance
view (G3/G4), usable for SafeSport and concussion protocol today. Everything else is nothing, by
construction. `org_unit_id` is null for every non-GOTR club, the token diet and
cached context apply to everyone and make things faster, and the compliance tables are
unused unless a club is under an org unit or turns the switch on. CKU keeps its volunteer
gate as it is today, now with an honest "expired" state.
