# CLAUDE.md — Sports CRM

## Project Overview
This is a Sports CRM built with a PHP backend, React/TypeScript PWA frontend, and a production
PostgreSQL database hosted on Neon (direct connection, not a Heroku addon). The email and SMS
communication feature is **already built and running in production** — do not rebuild it. The
remainder of this file describes the built system (for reference when extending or debugging)
and tracks new and pending work.

---

## ⚠️ PARALLEL SESSION COORDINATION (added 2026-07-06)

Multiple Claude sessions work this repo concurrently. Rules of the road:

1. **Payments workstream (Stripe)** lives on branch `feature/stripe-payments` in the worktree
   `/Users/maggiemae_1/TeamsElevated/te-stripe-payments/` — do payments work THERE only. It is
   **built and deployed to prod** (Phases 0–4 + audit trail + treasurer reporting, 2026-07-06):
   Stripe Connect onboarding, hosted checkout, split payments, refunds, contribution links,
   payout reconciliation. Do NOT rebuild or touch `lib/StripeGateway.php`, `services/Stripe*`,
   `services/PaymentReportService.php`, `services/ContributionLinkService.php`,
   `api/payment-*`, `api/checkout-sessions.php`, `api/contribute*.php`,
   `api/webhooks/stripe-connect.php` from other sessions. See auto-memory
   `project-payment-processor-decision.md` and `docs/payments-stripe-implementation-plan.md`.
2. **Migration numbers**: 041–051 are taken, with COLLISIONS at 044 (payment_allocations /
   program_participant_type), 045 (codify_audit_log / event_recurrence), 047 (stripe_payouts /
   venues_club_id), and 046
   (contribution_links / comms_tables_baseline / series_invites) — three sessions numbering
   independently. All are applied; filenames differ so nothing clobbers. **Claim the next number
   by checking `ls database/migrations/ | sort` in BOTH the main checkout and the
   te-stripe-payments worktree before creating one.** Next free as of 2026-07-30: **059**
   (048–056 taken: athlete_gender_nullable, club_logo_png,
   emergency_contact_authorize_medical, athlete_medical, program_season_fields,
   users_tos_acceptance, athlete_jersey_size, registration_jersey_size_field,
   clear_default_player_passwords). 048–056 are applied to Neon.
   **057 is RESERVED** for `broadcast_campaigns.body` (scheduled SMS, Workstream C) — not yet
   written. **058** (`chat_conversation_archive`) and **059** (`chat_retention_policy`) are
   **applied to Neon 2026-07-30**. **060–062** (chat message removal / reports / access log) and
   **063** (`consent_source_and_identity`) are **applied to Neon**; 063 on 2026-07-31.
   Next free number is therefore **064**.
3. **Deploys are BOTH driven by git push. Corrected 2026-07-29 — earlier versions of this
   section described a manual `netlify deploy --prod` step, which is the thing that causes the
   wipe described below. Do not do that.**

   - **Frontend** → `git push origin main`. The Netlify site `teams-elevated`
     (`teams-elevated.netlify.app`, id `56713702-1349-420c-9088-6630b8f5cc24`) is connected to
     `magnusmagz/TeamselevatedProduction` branch `main` with auto-build enabled, and builds
     every push. There is nothing to run by hand.
   - **Backend** → `git push heroku main:main` (both workstreams share the app).
   - ALWAYS `git fetch heroku && git merge heroku/main` before pushing, or you will revert the
     other session's deploy.

   **Never run `netlify deploy --prod`.** A manual CLI deploy uploads only YOUR local build
   directory and replaces the whole live site — that is exactly how a calendar-workstream deploy
   silently removed ALL payments UI from production on 2026-07-06. The git-push path has no such
   failure mode: Netlify builds from the shared commit, so whatever is on `main` is what ships.

   **Ordering — frontend BEFORE backend whenever a change adds or tightens an auth requirement**
   (or otherwise changes the frontend/backend contract). The deployed frontend is what does or
   doesn't send the header. Backend-first means the live UI starts getting 401s until Netlify
   catches up. Push `origin`, wait for the Netlify deploy to reach state `ready`, then push
   `heroku`. Check with:
   `netlify api listSiteDeploys --data '{"site_id":"56713702-1349-420c-9088-6630b8f5cc24","per_page":3}'`
   A deploy in state `error` with "Canceled build due to no content change" is BENIGN — it just
   means that commit touched no frontend files.

   **`main` is shared, so "I didn't push" does not mean "not deployed."** Your commit sits on the
   same branch as every other session's. When any session pushes, it carries every commit beneath
   it — on 2026-07-29 a commit deliberately held back shipped anyway inside another session's
   push. If something genuinely must not ship yet, keep it off `main`, not merely unpushed.

   `eit-crm.netlify.app` is a stale April 2026 site, NOT production. Do not deploy to it.
4. **Stripe webhook endpoint** (`we_1TqHljRuWVRricRVa8loa9WV`) is subscribed to: account.updated,
   checkout.session.completed, charge.refunded, payout.paid, payout.failed. Update via API, not
   the dashboard, and keep this list current.

---

## Where things get written down

- **This file** — what is *true now* and what to *do*: architecture, conventions, invariants, live
  gotchas, pending work. Loaded into every session, so everything here costs context every time.
  Keep it to things that change what you do.
- **`teamselevated/CHANGELOG.md`** — what *happened*: migrations actually applied to Neon (they're
  applied by hand, so a committed `.sql` is not proof it ran), ad-hoc prod data fixes, Heroku/Netlify
  deploy coordinates, prod state discovered. **CLAUDE.md holds the lesson, CHANGELOG holds the
  event.** When you fix something in prod, add the dated entry there and the durable lesson here.
- **`git log`** — what code changed. Commit messages in this repo are descriptive; use them before
  writing new archaeology.

**This file lives at `teamselevated/CLAUDE.md` and is version controlled** (since 2026-07-30 — it
previously sat outside the repo with no history, no backup, and no way to diff a bad edit).
`/Users/maggiemae_1/TeamsElevated/CLAUDE.md` is now a symlink to it, so both paths work and both
resolve to the same tracked file. **Commit your edits to it** — that's the whole point of the move.
Caveat: a session whose working directory is inside `teamselevated/` may load this file twice
(once as the subdirectory CLAUDE.md, once through the parent symlink). Harmless, just noisy.

---

## ⚠️ CURRENT STATE — READ THIS FIRST

**The email/SMS communication feature is built.** Earlier versions of this file described it as
greenfield work "to build." That is no longer true as of 2026-04-13. A previous Claude session
built it (commit `1a35afa`, 2026-03-23: "Restore communication backend files lost during repo
sync"). Emails and SMS actually send in production.

**What is built and working — do NOT rebuild:**
- `teamselevated/api/communications-gateway.php` — send-email, send-sms, log, log-detail, analytics, unsubscribe-token, broadcast campaigns (~1677 lines)
- `teamselevated/api/analytics-gateway.php` — reporting dashboard backend
- `teamselevated/api/recipient-search-gateway.php` — Gmail-style typeahead with scope enforcement
- `teamselevated/services/EmailSendService.php`, `SmsSendService.php`, `MergeFieldService.php`
- `teamselevated/services/RedisQueue.php` + `Procfile` worker — **Redis queue is built, not pending**
- `teamselevated/api/webhooks/sendgrid.php`, `webhooks/twilio-status.php` — full webhook handling
- `teamselevated/api/track/pixel.php`, `track/click.php` — open and click tracking
- `teamselevated/api/email-templates.php` — template CRUD with Unlayer `design_json`
- Frontend pages: `EmailReporting.tsx`, `CommunicationLog.tsx`, `TemplateEditor.tsx`, `TemplateLibrary.tsx`, `SmsTemplates.tsx`, plus `EmailCompose`, `SmsCompose`, `RecipientSelector`, `CommunicationHistory` components
- Tables in production Neon: `email_templates`, `email_events`, `email_links`, `email_suppressions`, `communication_log`, `broadcast_campaigns` (NOTE: these tables have no migration files in `/database/migrations/` — they were created ad-hoc in Neon. Schema-migration debt to clean up eventually, but not blocking.)

**What is NOT yet built and IS pending:**
- **Household recipient combining** (a.k.a. "Hi John & Jane" dedupe) — when two guardian rows share `thejones@gmail.com`, outbound sends should deliver ONE email addressed to both. Reference implementation exists in `/Users/maggiemae/crmkiller/backend/services/` (`sharedEmailService.js`, `emailDedupService.js`, `recipientNameResolver.js`) and frontend badge `SharedEmailBadge.tsx`. Port Node/Sequelize → PHP/PDO when ready. See `project_household_shared_email.md` in auto-memory for full design and phasing.
- **Shared-email remaining case** — code-verified 2026-07-06: the read-side fixes in `api/athletes.php`, `api/invoices.php`, `api/financial-permissions.php` HAVE landed (they aggregate across all guardian rows sharing an email). What remains: when `users.email ≠ guardians.email` the parent role is lost — needs the Phase 2 `user_guardians` link table (see note at `api/financial-permissions.php:~99-109`).

**Built since the list above was written (code-verified 2026-07-06 — do NOT rebuild):**
- **Data importer** — BUILT end-to-end: `api/imports-gateway.php` (preview/upload/status), `services/AthleteImportStrategy.php` (family-row: one row = athlete + up to 2 guardians, shared-email-aware matching) plus strategies for teams/facilities/volunteers/coaches, `database/migrations/017_data_imports.sql`, Redis `import_queue` consumed by `workers/queue-worker.php`, frontend `ImportTilesGrid.tsx`/`DataImport.tsx`.
- **Recurring calendar events + RRULE series invites** — BUILT and deployed 2026-07-06 (migrations 045/046). Full design + decision log in `/Users/maggiemae_1/TeamsElevated/CALENDAR-RECURRING-EVENTS.md` — read it before touching `lib/event_recurrence.php`, the series branches in `legacy/events-gateway.php`, or the series methods in `CalendarInviteService`. Key invariant: never send per-event ICS about a series member (it corrupts recipients' recurring calendar event). `legacy/events-gateway.php` now requires auth on all methods (writes: admin/coach standing).

**Orphaned — do NOT build on:**
- `email_accounts` table (0 rows, 0 PHP references, MySQL-syntax experiment from `teamselevated/database/shared-email-schema.sql` never applied properly)
- `guardians.email_account_id` column (0/197 rows populated, no PHP reads it)

---

## Tech Stack
- **Backend:** PHP — Vanilla PHP with custom regex-based router (no framework)
- **Frontend:** React + TypeScript PWA
- **Email Editor:** Unlayer (react-email-editor) — drag-and-drop WYSIWYG template builder
- **Database:** PostgreSQL via Neon (direct connection, not a Heroku addon — config vars `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` on Heroku app `teamselevated-backend`)
- **Email Provider:** SendGrid
- **SMS Provider:** Twilio
- **Queue/Jobs:** Redis — built and running. Implementation at `teamselevated/services/RedisQueue.php`, worker defined in `teamselevated/Procfile`. Add new job types to the existing queue; do not build a new queue system.

---

## Codebase Conventions
- No formal coding standard enforced on the **PHP** side (no phpcs/phpstan config), but code
  follows PSR-4 autoloading
- ⚠️ **The frontend has a lint ratchet, and the number only goes DOWN.** `npm run lint:ci` runs in
  the Netlify build ahead of `CI=false npm run build`, and fails if the warning count exceeds the
  `--max-warnings` ceiling in `frontend/package.json` (**74** as of 2026-07-30, all
  `react-hooks/exhaustive-deps`). If your change pushes it up, fix the warning — raising the ceiling
  to ship is a deliberate decision to undo promptly, not a routine step, and `main` is shared so a
  failing lint blocks everyone's deploy. Rationale, scope and how to work the remaining 74 are in
  `frontend/LINTING.md`. Do **not** "simplify" this by flipping the build to `CI=true`: that
  promotes all 74 known warnings to errors and no deploy ever succeeds again — which is precisely
  how the build ended up catching nothing.
- All API routes follow the `/api/` prefix (e.g. `/api/teams`, `/api/auth/login`, `/api/coach/teams/(\d+)/roster`)
- Mixed architecture: business logic lives in `/controllers/`, `/api/` gateway files, and `/services/` — no strict service layer
- Environment variables managed via custom `Env` class in `/config/env.php` that parses `.env` files and populates `$_ENV` / `putenv()`. Access via `Env::get('KEY', 'default')`

---

## Database
- **Host:** Neon (PostgreSQL), deployed via Heroku
- **ORM/Query style:** Raw PDO with prepared statements (`$pdo->prepare()` / `PDO::FETCH_ASSOC`)

### ⚠️ Do not trust this section over the schema snapshot
**`teamselevated/tests/fixtures/production-schema.json` is authoritative** — a committed
snapshot of all 108 live Neon tables, verified current 2026-07-29. `tests/php/SchemaConformanceTest.php`
checks every `INSERT`/`UPDATE` in the runtime tree against it. Before writing a query, read the
fixture; the prose below is a summary and summaries drift.

This warning exists because the list below used to be wrong, and the wrong version got copied
into shipped code — see "How the phantom columns got there" at the end of this section.

- **Existing relevant tables** (column names verified against live Neon 2026-07-29):
  - `athletes` — athlete profiles (id, first_name, last_name, date_of_birth, club_id, user_id, deleted_at, jersey_size, etc.)
    - `jersey_size` (migration 054) is CHECK-constrained to 12 Y/A-prefixed codes —
      `YXS YS YM YL YXL` / `AXS AS AM AL AXL A2XL A3XL`. Never write it raw: pass it through
      `te_normalize_jersey_size()` in `lib/jersey_size.php`, which maps `''` and anything
      unrecognized to NULL. The athlete form submits `jersey_size:''` for athletes with no size
      on file, and writing that straight to SQL raises 23514 and rolls back the whole edit.
      Frontend list: `frontend/src/utils/jerseySize.ts`. Jersey *size* is athlete-level;
      jersey *number* is per-team on `team_members`.
    - Also collected on the public registration forms (migration 055 seeded a `jersey_size`
      row into all 26 athlete-type `program_form_fields`). Those forms render selects
      generically, so **the option LABEL is the submitted value** — registrations arrive as
      `'Youth Medium (10-12)'`, not `'YM'`. `te_normalize_jersey_size()` resolves labels too.
      `te_normalize_jersey_size()` does NOT match label text against a table — it parses
      group + size structurally, so `'Youth Medium (10-12)'`, `'Youth Medium'`, `'youth med'`
      and `'YM'` all resolve. That is deliberate: a club can retitle its own form option, and
      the labels are also frozen into applied migrations 054/055 and 26 seeded
      `program_form_fields` rows. Label drift is therefore cosmetic, never data loss.
      An unprefixed size (`'M'`, `'Large'`) resolves to NULL on purpose — ambiguous between
      Youth and Adult, so it must not be guessed. `JerseySizeConsistencyTest` locks the PHP
      and TypeScript lists (and both migrations) together; `JerseySizeResolverTest` covers
      the tolerance and the two deliberate refusals.
    - **Crew-editable since 2026-07-30** via `api/athlete-jersey-size.php` (parent portal →
      `JerseySizeCard` on the athlete detail page). Staff and crew write the SAME column, so
      the two surfaces need no syncing and must not grow a second copy of the value — that is
      why migration 054 put size on `athletes` rather than per-membership on `team_members`.
      Authorization is `AthleteScope::userCanAccessAthlete` (club admin / coach / guardian);
      never re-implement the guardian check inline.
    - **On a single-field save, refuse what you cannot read.** `te_normalize_jersey_size()`
      returns NULL both for "deliberately blank" and "unreadable", which is right when the
      size rides along with a bigger save but wrong when the size IS the request — storing
      NULL there reports success while saving nothing. Classify with
      `te_classify_jersey_size_submission()` (SET / CLEAR / INVALID) and 422 the INVALID case.
      Covered by `ParentJerseySizeUpdateTest` + `JerseySizeCard.test.tsx`.
  - `guardians` — parents/guardians (id, first_name, last_name, email, mobile_phone, sms_opt_out, etc.)
  - `athlete_guardians` — athlete↔guardian link. Real columns: **`id, athlete_id, guardian_id,
    relationship, is_primary, can_pickup, emergency_contact, created_at`**.
    - `relationship` is CHECK-constrained to `Parent` / `Guardian` / `Emergency Contact` / `Other` — **capitalized**.
    - The API layer deliberately aliases these: `legacy/guardian-gateway.php` exposes
      `relationship` as `relationship_type` and `is_primary` as `is_primary_contact`. Those two
      names are the **API contract, not column names** — correct in a request body, wrong in SQL.
    - There is **no `receives_communications` column** in live Neon, and no live code path writes
      one. It likely existed in the MySQL era — `controllers/EmailController.php` (known-dead)
      still selects it.
  - `users` — system users (id, first_name, last_name, email, password_hash, role, system_role,
    tos_accepted_at, tos_version, email_signature, etc.). Note `password_hash`, **not** `password`.
  - `user_club_access` — role assignments per club (id, user_id, club_profile_id, role, granted_at,
    revoked_at, active)
  - `teams` — (id, name, program_id, season_id, primary_coach_id, division, age_group, club_id,
    home_field_id, status, deleted_at, primary_color, etc.). The name column is **`name`**; there
    is no `team_name` and no `sport`. No `updated_by` / `last_modified_at` — only `updated_at`.
  - `team_members` — roster (id, team_id, user_id, athlete_id, role, jersey_number, positions,
    primary_position, team_priority, status, **`join_date`**, leave_date, created_at)
    - `role` CHECK: `player` / `assistant_coach` / `team_manager` (only `player` occurs in prod data)
    - `status` CHECK: `active` / `injured` / `suspended` / `inactive`
    - The date column is **`join_date`**. `joined_date` does not exist — that typo silently broke
      tryout-offer acceptance until 2026-07-29.
  - `club_profile` — club info (id, name, logo_url, logo_png, primary_color, slug, social_*, etc.)
  - **`calendar_events`** — the calendar table. **There is no `events` table.** Columns:
    `id, club_id, name, type, event_date, start_time, end_time, program_id, venue_id, location,
    description, status, opponent_name, recurrence_group_id, recurrence_rule, ...`
    - Note the shape differs from the old `events` table that code still assumes: **`name`** not
      `title`, **`event_date` + `start_time`** (separate columns) not `start_datetime`,
      **`type`** not `event_type`, and **no `team_id`** — the event↔team link is the join table
      `calendar_event_teams`.
    - Companions: `calendar_event_teams`, `calendar_event_attendees`, `calendar_event_series`,
      `event_invitations`, `event_attendance`, `calendar_subscriptions`.
  - `consent_records` — GDPR/COPPA consent tracking (guardian_id, athlete_id, consent_type,
    consent_given, confirmation_token, ...). `athlete_id` is `NOT NULL`, so this table cannot hold
    account-level consent such as ToS acceptance — that lives on `users.tos_accepted_at`.
  - `audit_log` — audit trail (**singular**; there is no `audit_logs`). Write via `lib/AuditLogger.php`,
    never a raw INSERT.
  - `data_retention_policy` — retention rules, enforced by `scripts/retention-check.php`
- **Migration approach:** Raw SQL files in `/database/migrations/` with sequential numbering (001_, 002_, etc.), applied manually

### How the phantom columns got there
Every column name corrected above was wrong in this file first, and the wrong name reached
production code because someone (including Claude, twice on 2026-07-29) trusted this doc instead
of the database. Live consequences found so far:

- ~~`services/MergeFieldService.php` queries `FROM events`~~ — FIXED 2026-07-30. It now reads
  `calendar_events` (+ `calendar_event_teams` for `{{team_name}}`), and `MergeFieldServiceTest`'s
  SQLite fixture was rebuilt to the live shape. **That test is exactly how the bug survived**: it
  `CREATE TABLE events` with the pre-calendar columns, so the suite stayed green for months while
  every `{{event_*}}` tag in production resolved to nothing. A fixture that does not mirror
  `tests/fixtures/production-schema.json` is worse than no fixture.
- `frontend/src/components/GuardianManagement.tsx` (live — reached from `AthleteManagement`) has a
  "Gets Comms" checkbox bound to `receives_communications`. No backend writes it; the only PHP
  reference is in `controllers/EmailController.php`, which is known-dead. The toggle does nothing.
- `api/practices.php` and `controllers/EmailController.php` both query `events`; both are on the
  known-dead list in `SchemaConformanceTest.php`.
- ~~`api/analytics-gateway.php` filtered on `cl.team_id`~~ — FIXED 2026-07-30. `communication_log`
  has **no `team_id` column**, so picking any team in the Email Reporting filter was a hard SQL
  error that 500'd the overview. The recipient's team is reached through the athlete:
  `communication_log.athlete_id` → `team_members`, or `communication_log.recipient_id` (a guardian)
  → `athlete_guardians` → `team_members`. `buildTeamFilter()` does this with **EXISTS, not JOIN** —
  joining `team_members` multiplies log rows when an athlete is on two teams or a guardian has two
  athletes, which would silently inflate every COUNT on the page.

- ~~`legacy/medical-gateway.php` bound `''` into DATE columns~~ — FIXED 2026-07-30. Saving an
  athlete who had a medical row but no physical date on file died with
  `SQLSTATE[22007] … invalid input syntax for type date: ""`, surfacing as "medical information
  could not be saved". `AthleteForm` initialises every medical date to `''`, and the gateway bound
  it with `isset()` — true for `''`. **The numeric half of the same bug had been patched in the
  browser** (the form converts `height_inches`/`weight_lbs` to null), which is exactly why the date
  half survived: a coercion on the client only protects the caller that has it. Both writers now go
  through `te_normalize_athlete_medical_values()` in `lib/athlete_medical.php` — empty
  dates/numerics to null, booleans to `'true'`/`'false'`. The partial UPDATE binds on
  `array_key_exists`, not `isset`, so clearing a date persists as NULL instead of being skipped.
  Guarded by `tests/php/AthleteMedicalValuesTest.php`.

**When you correct a column in code, correct it here in the same commit.** Fixing the code alone
regenerates the bug the next time someone reads this file.

---

## Credentials & Environment Variables
The following environment variables will be available — do not hardcode any values:

```
SENDGRID_API_KEY=
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM_NUMBER=
SENDGRID_FROM_EMAIL=        # bulk path; all three below are the SAME address now
EMAIL_FROM=                 # transactional (lib/Email.php) — NOT SENDGRID_FROM_EMAIL
SMTP_FROM_EMAIL=            # calendar invites (PHPMailer)
REDIS_URL=                  # Already set — reuse existing connection
APP_URL=                    # Needed for unsubscribe landing page links
```

Set via Heroku config vars in production. Locally, loaded from `.env` file via custom `Env` class in `/config/env.php`.

---

## Roles & Permissions

### Club Admin
- Can compose and send email or SMS to any contact in the club
- Can access all communication logs and reporting

### Team Admin (Coach)
- Can compose and send email or SMS to:
  - Athletes on their team
  - Parents/guardians of athletes on their team
- Cannot contact contacts outside their assigned team(s)
- Can view communication logs and reporting scoped to their team only

### Permission Enforcement
- Enforce permissions server-side on all send and reporting endpoints — never trust the frontend
- When a coach selects recipients, the backend must validate every recipient is within their
  scope before sending
- Roles are stored in `user_club_access` table (user_id, club_profile_id, role). Role values per the live CHECK constraint: `club_admin`, `coach`, `parent`, `player`, `volunteer`, `treasurer` — the last two were undocumented here until 2026-07-29, and `volunteer` is in active use (2 rows). This table is authoritative — NOT `users.role`. Coaches are scoped to teams via `team_members` table.

### Impersonation: the token IS the target — `lib/impersonation.php` (2026-08-14)
Super admin only. `super-admin-gateway.php?action=impersonate` mints a token whose
`user_id` is the **target's**, so `refreshRolesFromDb()` derives the target's roles and
every existing scope check applies unchanged. There is no "acting as" flag for gateways to
honour, and therefore no gateway that can forget to.

The `imp` claim (`by`, `by_email`, `by_name`, `started_at`, `exp`) records who is behind
the session. **It is evidence and an exit route, never a grant** — nothing anywhere reads
it to allow something.

- **`imp.exp` and the JWT's `exp` are the same value** (1h, `TE_IMPERSONATION_TTL`).
  Otherwise an abandoned impersonation is a 24h key to someone else's account.
- **Every re-mint must call `te_carry_impersonation()`.** `verify-session` and
  `switch-context` both issue a fresh token on every call; dropping the claim there
  converts an impersonation into a permanent, unmarked, unrevertible login as the target.
  `ImpersonationTest` parses both handlers and fails if one stops carrying it. The
  original expiry rides along, so refreshing the page cannot extend the window.
- **`stop-impersonation` lives on `auth-gateway.php`, not the super-admin gateway** — the
  caller holds a token whose re-derived `system_role` is the target's, so it would fail
  that file's own gate and strand the admin until expiry. The admin's id comes off the
  signature-verified claim; nothing is read from the body.
- **A super admin cannot be impersonated**, so revoking someone's badge can't be undone by
  anyone who still has one, and the audit trail can always say which admin acted.
- **Writes are permitted.** Support cases are usually "this save doesn't work for me",
  which a read-only view cannot reproduce. The protections are accountability-shaped
  instead: the 1h ceiling, `impersonation_started` / `impersonation_stopped` audit rows, and
  a banner with no dismiss control (`ImpersonationBanner`) — it is the only thing on screen
  distinguishing the session from a real login, and it renders on the parent portal too.
- Frontend parks the admin's own token at `auth_token_impersonator` purely so an **expired**
  impersonation returns them to their account instead of the login screen. `stopImpersonation`
  prefers the server round-trip; the parked token is the fallback, never the primary path.
- `AuthMiddleware::getImpersonator()` is where the real operator is recoverable — the
  refreshed DB context is all target.

### ⚠️ Reading an athlete and writing one are different permissions (2026-07-30)
`AthleteScope` exposes two predicates and they are not interchangeable:

- **`userCanAccessAthlete`** — super admin / club admin of their club / coach of their team /
  **guardian**. This is a READ gate. A parent passes it, which is correct for viewing their child.
- **`staffCanManageAthlete`** — the same minus the guardian branch. This is the WRITE gate.
  `userCanAccessAthlete` is defined in terms of it, so the two cannot drift.

**Never gate a mutation on `userCanAccessAthlete`.** Doing so is how, until 2026-07-30, any
parent-portal token could PUT their child's `date_of_birth` (age-group eligibility), soft-delete
the athlete off every roster, and delete the other parent's `athlete_guardians` link. Worse,
`legacy/guardian-gateway.php` POST/PUT had **no scope check at all** and took `athlete_id` from the
request body — so any authenticated user could attach a guardian row carrying their own email to
any athlete in any club, thereby becoming a guardian of a stranger's child and unlocking that
child's record and health data through the read predicate. None of it was clickable; every caller
is a staff screen. **The absence of a UI is not an access control** — bound what the endpoint
accepts, not what the form happens to send.

Guardians get narrow, purpose-built doors instead: `api/athlete-jersey-size.php` for a jersey size,
`api/consent.php?action=request-deletion` for data removal. Add another one when a parent-facing
need is real; do not widen a staff gateway to meet it.

**One deliberate exception: `legacy/medical-gateway.php` writes stay on the READ predicate**, so a
guardian can edit their own child's health record. Decided 2026-07-30, not inherited: a parent is
the authoritative source for their own child's allergies and medications, and the parent portal now
offers it (`MedicalInfoPage`). The *clinical* fields — concussion history, last concussion date,
return-to-play — are withheld at the client by omitting them from the payload, because the gateway
binds on `array_key_exists`. That is a product boundary, not a security one: a determined guardian
could still POST them. If it needs to become a real boundary, split the field whitelist by standing
inside the gateway, the way `staffCanManageAthlete` splits the athlete one.

Guarded by `tests/php/AthleteWriteScopeTest.php`, which also parses both gateways and asserts the
write handlers call the stricter predicate — the bug was never in the predicate, it was in which
one got called.

### Dual roles: capabilities accumulate, surface is a choice (2026-08-17)
7 accounts hold two roles in one club (6 `coach+parent`, 1 `club_admin+coach`), and **12
staff are also guardians** — the larger number, because parent standing is usually derived
from the guardian chain rather than a `user_club_access` row. Any audit keyed on role rows
undercounts this population by half.

**Never write `$isParent = !$isCoach`.** Evaluate each independently. Three separate bugs
came from that one line shape:
- `handleChatSearch` — a coach-parent could not reach one parent on their own child's team
- `handleChatResolveTeams` — and could not select that team as a group (403)
- the same file, 2026-08-15 — a coach with no team was classed as a parent and got an
  **empty** search

`api/financial-permissions.php:86` is the reference implementation: loop every role setting
each flag independently, then add `$isParent` if any guardian row yields athletes.
`AthleteScope` and the chat server's `getAccessibleTeamIds` are also correct.

**Additive is not club-wide.** A coach-parent gains *their child's team*, not every family in
the club. Pinned by `testCoachParentDoesNotGainUnrelatedFamilies`.

**Standing comes from ROLE, never from having data.** "No team assigned yet" does not stop
someone being a coach — conflating the two is what emptied the typeahead for nine accounts.

**Where one answer IS required, make it explicit, never arbitrary.** Which app to show can
only be one at a time:
- `lib/JWT.php` orders roles `club_admin > treasurer > coach > volunteer > parent > player`
  so `roles[0]` is deterministic. Without it the active role was physical row order and could
  flip after a vacuum, silently removing an admin's nav. All six live CHECK values are
  ordered — omitting any re-randomises those. `ActiveRolePrecedenceTest`.
- Staff `ProfileMenu` → "Parent Portal", portal More menu → "Staff view". Before this the
  12 staff-guardians could not reach the portal **at all**: `ParentRedirect` leaves anyone
  with a staff role on the dashboard, and nothing linked to `/parent`. The link's predicate
  is deliberately IDENTICAL to `ProtectedParentRoute`'s — broader would mean a link that
  leads to a bounce.

Full investigation: `SCOPE-Dual-Role-Parent-Coach.md`.

### ⚠️ "My children" is not "athletes I can see" — `financial-permissions.php` (2026-08-17)
`accessible_athletes` is a user's own children **UNION every athlete on the teams they
coach**. Correct for payment screens, wrong for anything meaning family. The parent portal
read it, so a coach-parent got their whole roster wherever the portal asked about their
children — and `ConsentGate` asked them to give **parental consent for other people's kids**.

**It was a lockout, not a cosmetic leak.** `consent.php?action=record` correctly 422s a
non-guardian, `handleSubmit` throws on the first failure, and the gate renders *instead of*
the portal — so those accounts could not enter the parent portal at all. Luis Escamilla
(157, father of one, reached by **20** athletes' worth of coaching scope) pressed Submit five
times on 2026-08-17, re-recording his own son's consent each time. **Seven** coach-parent
accounts were in that state; the write guard held, so no false consent was ever stored.

⚠️ Count the roster with `getCoachTeamIds()`, not `teams.primary_coach_id` — the endpoint
does, and it also counts `assistant_coach` / `team_manager` memberships. Using the column
undercounted Luis at 11 and reported Samantha Archer (196) as unaffected when she was not.

- **`my_children` / `my_children_ids`** are guardian-derived only. Everything in the parent
  portal reads those; `accessible_athletes` is unchanged and still serves payments.
- Four files had it, not one — `ConsentGate`, `useParentAthletes` (which feeds the
  dashboard, athlete detail and medical pages), `AthleteDetailPage`, `MedicalInfoPage`.
  Fixing one and missing three is why the guard is a **scan**, not a unit test:
  `ParentPortalChildScopeTest` fails if any non-test file under `parent-portal/` mentions
  `accessibleAthletes`, and if a coach roster is ever `array_merge`d into `$myChildren`.
- ⚠️ **The context falls back with `??`, never `||`.** An ABSENT `my_children` means an old
  backend (`main` is shared, deploys are by push) and must fall back to the wider list —
  visibly wrong for minutes. Treating it as "no children" would silently stop prompting
  every family for consent. An EMPTY list is a real answer and must survive.
- Same shape as `userCanAccessAthlete` vs `staffCanManageAthlete` and `canAccessClub` vs
  `te_is_club_admin`: the predicate was never wrong, which one got called was.

### ⚠️ Guardian email comparisons must be LOWER() on both sides (2026-08-18)
Reported by CKU: Emily Govier could sign in but the parent portal said no athletes were
registered to her. Her `guardians` row read `Emilygovier0@gmail.com`, her `users` row
`emilygovier0@gmail.com`. One capital letter.

Parent standing is derived by comparing those two columns, and **ten** query sites used
`=`, which is case-sensitive in Postgres. Verified against prod:
`g.email = 'emilygovier0@gmail.com'` returned **0**, `lower(...)` returned **1**.
Three accounts were in that state (users 152, 235, 253), each holding a valid `parent`
role — so nothing looked broken to staff, the family just saw an empty portal. Same
shape as the coach told "no athletes are registered to you" for months.

- All ten now normalise: `api/financial-permissions.php` (x2), `lib/AthleteScope.php`
  (x2), `api/invoices.php` (x2), `api/recipient-search-gateway.php` (x2),
  `api/calendar-events-gateway.php`, `api/sibling-discount.php`.
- Migration 071 adds functional indexes on `LOWER(email)` for both tables, so nobody is
  tempted to optimise the LOWER() back out.
- **The stored data was deliberately NOT lowercased.** Normalising those three rows
  would fix the symptom and hide the class — the next capital letter typed anywhere
  would break again at whichever site was missed. The comparison was wrong, so the
  comparison changed. `GuardianEmailCaseTest` scans the runtime tree for a regression.
- ⚠️ **The PHP tree is not the whole product.** The chat server carried the same
  comparison at three more sites and was missed on 08-18, staying broken two extra days
  (fixed 2026-08-20, chat v19). It is a separate Heroku app on a **subtree** deploy, so
  `git push heroku` never ships it — when a fix touches identity, sweep `chat-server/`
  and deploy it separately. Guarded there by
  `chat-server/__tests__/guardian_email_case.test.js`.
- **A fourth account appeared two days later** (user 370, two children), which is the
  point of not normalising: the class keeps producing cases and the comparison keeps
  absorbing them. Counting affected users is a snapshot, never a fix.

### ⚠️ `index.php` performs NO authentication — the controller must (2026-08-17)
There is no auth layer in the router. Every route it dispatches is as open as the method
it lands on, and `AthleteController` called `resolveAuth()` in exactly one of its methods.
Verified against production with no token:

```
POST   /api/athletes/999/guardians      -> 500
DELETE /api/athletes/999/guardians/999  -> 200
```

That DELETE reached `DELETE FROM athlete_guardians` with both ids taken from the URL.
**Two integers detached a parent from a child**, anonymously. `createAthlete` was equally
open and creates guardian rows from the request body. Now fixed: all three authenticate,
and `addGuardian` / `removeGuardian` gate on **`staffCanManageAthlete`** — the read
predicate's guardian branch would let one parent remove the other.

- **No frontend code calls these routes**, which is exactly why it survived; the guardian
  UI goes through `legacy/guardian-gateway.php`. Same lesson that file already taught:
  the absence of a UI is not an access control. **When adding a route to `index.php`,
  the auth is yours to write — nothing upstream does it.**
- `GuardianLinkWriteScopeTest` parses the controller and fails if any of the three stops
  authenticating, gates on the read predicate, or skips attribution. It was confirmed to
  fail on the pre-fix code.

### Guardian link changes are audited by TRIGGER — migration 070 (2026-08-17)
Attaching or detaching an adult from a child decides who may read a minor's record and
whose click counts as parental consent. It was the only sensitive mutation writing no
audit trail at all, which is why the link that let a non-guardian record consent for
athlete 435 on 2026-07-31 is permanently unexplainable.

- **A trigger, not `AuditLogger` calls, and that is a deliberate exception to the
  no-raw-INSERT rule.** 16 mutation sites across 6 files, plus the case that actually
  matters — a link changed by hand in psql, which this team does regularly — goes through
  no PHP at all. The trigger sees every writer including ones not yet written.
- Actions: `guardian_link_added` / `_removed` / `_changed`.
- **Attribution is best-effort by design.** `lib/db_actor.php`'s `te_db_set_actor()` sets
  `app.user_id`; the trigger reads it with the **missing_ok** form of `current_setting`
  (the strict form throws and would fail every insert on an uninstrumented path). A NULL
  actor is a signal, not a gap: it means the change did not come through a request path.
- `set_config(..., false)` is **session** scope. `SET LOCAL` would be discarded outside a
  transaction, which is where most of these gateways write.
- `registrations-api.php` deliberately does not set an actor — a public sign-up has no
  operator, and NULL is the honest record.
- No backfill is possible. The 197 pre-existing links show no origin, and inventing one
  would be worse than the blank.

### ⚠️ "A parent logged in and saw the coach's portal" — the diagnosis (2026-08-15)
Reported by CKU. If it happens again, this is the checklist. **Left UNFIXED deliberately**
after both live cases were repaired by hand — the decision was to wait for a third report
rather than change routing. So expect it to recur.

**The mechanism.** `Login.tsx` (and `VerifyMagicLink.tsx`) choose the landing page from the
JWT's `roles` array, and `JWT::buildOrganizationalContext()` builds that array **only from
`user_club_access`**:

```js
const isParent = userRoles.some(r => r.role === 'parent');
… isParent ? navigate('/parent') : navigate('/dashboard');
```

No `parent` role row ⇒ `/dashboard` ⇒ the staff app. `ParentRedirect` then asks a
**different** source — `api/financial-permissions.php`, which derives `is_parent` from the
guardian chain **by email** — and bounces them to `/parent`. Two definitions of "is this a
parent", and they disagree.

- **The staff NAV renders during the bounce.** `AppContent` draws it outside the route
  element, so while `ParentRedirect` waits on the permissions API the family is looking at
  *My Teams / Programs / Communications / Calendar*. That is what gets reported.
- **If `financial-permissions` fails, there is no rescue at all** — its catch does
  `setRoles(defaultRoles)` (`is_parent: false`) and they stay in the staff app.
- **`ParentRedirect` only wraps `/dashboard`.** Any other route has no bounce.

**Diagnosing a new report — the two live causes were different:**
1. **The account is the CHILD's.** `te_create_athlete()` used to mint a `player` users row
   from the athlete form's email, which for a youth athlete is the parent's. `users.email`
   is unique, so the child owned the address and the parent signed in *as their own child*.
   Fixed at source and all 33 rows removed (migration 067) — but check
   `SELECT * FROM users WHERE role='player'` before assuming.
2. **users.email ≠ guardians.email.** Allix Boyce logged in on `@gmail` while her guardian
   record said `@yahoo`, so nothing could derive her parent standing. She also turned out to
   have **two accounts** — an invited `@yahoo` one carrying the CKU role that she had never
   used, and a self-created `@gmail` one with no role. **Always check for a second account
   on the other address before editing either.**

**Query to run first:**
```sql
SELECT u.id, u.email, u.last_login_at,
  (SELECT string_agg(role,',') FROM user_club_access c
    WHERE c.user_id=u.id AND c.active AND c.revoked_at IS NULL) AS jwt_roles,
  (SELECT COUNT(ag.athlete_id) FROM guardians g JOIN athlete_guardians ag ON ag.guardian_id=g.id
    WHERE lower(g.email)=lower(u.email)) AS athletes_by_email
FROM users u WHERE u.email ILIKE '%<their address>%';
```
`jwt_roles` without `parent` ⇒ they land on `/dashboard`. `athletes_by_email = 0` ⇒
`ParentRedirect` cannot rescue them either, and they are **stuck**, not merely flashed.

**Repair:** add the `parent` `user_club_access` row for their club on the account they
actually sign in with, and make the `guardians` row carry that same address. Match guardians
on **email AND name** when editing (`lib/guardian_sync.php` — six addresses are held by two
guardians each).

**Root cause, unfixed:** identity is an email string. `ParentInvite` creates the role row, so
invited families are fine; anyone who self-signs-up is not. The durable fixes are deriving
`parent` in `buildOrganizationalContext()` the way `coach` is already derived from team
membership, and the `user_guardians` link table. Neither is scheduled.

### ⚠️ Parental consent is captured in the PARENT PORTAL, and nowhere else (2026-07-30)
`ConsentGate` wraps `ParentPortalLayout` and records via `api/consent.php?action=record`.

**Do not put a consent checkbox on a staff screen.** `AthleteForm` carried a "Parental Consent
(Required)" block for months: two checkboxes held in local React state, never POSTed, never
written to `consent_records`, and force-set to `true` whenever anyone edited an existing athlete.
They gated that form's submit button and nothing else — so the product asserted COPPA consent
capture and stored none, while `api/consent.php` sat fully implemented with nothing ever calling
`action=record`. Beyond the storage bug, a club admin ticking "As the parent/legal guardian, I
consent" is not parental consent in the first place. Removed 2026-07-30.

**Consent is captured TWICE, on purpose** (2026-07-31). The public registration form records
at sign-up (`lib/consent_capture.php`, `source='registration'`), and `ConsentGate` asks the
parent to re-affirm the first time they enter the portal (`source='portal'`). This is a
product decision, not redundancy to optimise away:

- The sign-up consent is the one with the most legal weight — consent at the point of
  collection — and it is the only record a family who never opens the portal will ever have.
  It was being **discarded entirely** until 2026-07-31: the form sent
  `consent_data_collection` / `consent_medical_data` and `registrations-api.php` never read
  them.
- The portal consent is what ties the agreement to an actual **account**. At sign-up there is
  no user row to attribute it to (`guardian_id` is NULL there).
- **The gate keys on `source='portal'`.** If it ever starts clearing on a registration row,
  the second prompt silently disappears for every family who signed up online. Pinned by
  `ConsentGate.test.tsx`.
- The two flags sit at the **top level** of the registration payload, beside `form_data`, not
  inside it. Reading them from `form_data` finds nothing and records nothing, silently — the
  original bug. Pinned by `RegistrationConsentCaptureTest`.
- Registration capture runs in a **SAVEPOINT**: `main` is shared and deploys are by push, so
  this code can reach production before its migration does, and on Postgres a failed statement
  poisons the whole transaction — an unguarded insert would roll back the family's
  registration, not just the consent row.

Design points that are load-bearing:
- **Per child, always.** `consent_records` is keyed (guardian_id, athlete_id, consent_type) with
  `athlete_id NOT NULL` — consenting for one child says nothing about a sibling. The gate shows
  the statements ONCE and writes one row per (child × type), so a parent reads it once but the
  artifact stays per-child and a newly added sibling re-raises the gate for that child alone.
- **`guardian_id` is a `users(id)`**, not a `guardians(id)` — it is an FK to the account. The
  endpoint rejects a user who is not a guardian of that athlete.
- **The gate clears on RECORDED, not VERIFIED.** `action=status` only reports
  `has_active_consent` once `email_confirmed_at` is set (COPPA double opt-in). Blocking a parent
  inside the portal until they leave, find an email and return would strand anyone whose mail is
  slow or filtered, so the wall drops on the recorded row and a banner chases confirmation. Staff
  reporting must keep the two distinguishable.
- **Blocking, with a decline path.** It renders instead of the portal (no route around a screen
  that never mounted) and has no dismiss — but declining explains and signs out, because consent
  that cannot be refused is not consent (GDPR Art.4(11)). Do not "tidy" that away.
- **A failed status read lets the parent through.** The gate is a prompt, not an access control;
  the real enforcement is server-side. Never let a flaky read lock a family out.

Guarded by `ConsentGate.test.tsx`.

### Staff-facing consent: `action=summary`, and the ladder means something
Added 2026-07-31. Powers the **Consent column on the athlete list** (`AthleteManagement`),
sortable and filterable; the status ladder is defined once in `lib/consent_capture.php`
(`te_consent_rollup_status`) and labelled once in `frontend/src/utils/consentStatus.ts`.

- **verified** — every required type agreed in the portal AND email-confirmed. The defensible one.
- **confirmed** — agreed in the portal, emailed link not clicked.
- **signup_only** — agreed on the registration form only. Real consent, not tied to an account.
- **partial** / **none** — some types missing / nothing at all.

**`signup_only` counts as outstanding on purpose.** It is genuine consent, but it is not attached
to a user account and has not been verified, which is precisely what the portal step exists to
obtain. Do not "simplify" the ladder to consented/not — the rungs are the COPPA distinction.

⚠️ **`summary` is scoped with `AthleteScope::staffManageableAthleteIds`, NOT
`accessibleAthleteIds`.** The difference is the guardian branch. On the wrong one, a parent hitting
this endpoint gets a report about their own child instead of nothing, and a coach who is also a
parent sees a child from outside their teams. Super admin is the only unrestricted branch and is
checked explicitly — an empty scope and "everything" are opposite answers, and conflating them is
how a scope check becomes a data leak. Pinned by `ConsentRollupTest`.

A missing or unrecognised status renders as **Unknown**, never as a blank cell: blank reads as
"fine", which is the wrong default for a compliance column.

### `getAccessibleClubIds()` must return a re-indexed list
Fixed 2026-08-04, reported as "Facilities shows Something went wrong".

`array_unique()` and `array_filter()` both **preserve keys**. A user holding two roles in
the same club de-duplicated to `[0 => 32, 2 => 50]` — a gap at index 1. All ten callers
pass that array straight to `PDO::execute()` as positional parameters, and PDO rejects a
non-sequential array, so the endpoint 500'd. The fix is `array_values(...)` around the
return; it only re-indexes and cannot change which clubs come back.

- **7 accounts hold two roles in one club** — eli@ (club_admin + coach in 32) and six
  Central Kansas coach+parent accounts, the same population as the financial-permissions
  break. Every one of them was broken on venues, teams, programs, coaches, fields,
  tournaments and chat moderation.
- The frontend compounded it: `VenueManagement` calls `.map()` on the response without
  checking it is an array, so a 500 became "Something went wrong" for the whole page
  rather than an error state on one panel. Not fixed — noted.
- `AccessibleClubIdsTest` asserts sequential keys AND binds the result through a real PDO
  statement. The key assertion alone would not have caught it; the shape is what PDO
  rejects.

**A revoked role was still being minted into tokens.** `lib/JWT.php` filtered
`uca.active = TRUE` and never checked `revoked_at`. The two columns can disagree — one live
row had `active = TRUE` with `revoked_at` set 2026-07-08 — and when they do, the revocation
is the newer fact. Now `AND uca.revoked_at IS NULL`. Exactly one row was affected, so this
changes access for one user, correctly.

⚠️ **Already-issued tokens keep the revoked role until they expire.** The filter applies at
mint time only.

### Club MEMBERSHIP is not club STAFF — `lib/club_standing.php`
Fixed 2026-08-04. `AuthMiddleware::canAccessClub()` returns true for ANY role scoped to
the club, `parent` included. `handleClubParents` gated on it, so a parent POSTing their
own `club_id` to `auth-gateway.php?action=club-parents` received **every guardian in the
club** — name, email, mobile phone, portal status, children's names. Verified against
production with a real parent token. Club 32 exposed 196 guardians to 13 parent accounts;
club 51, 148 to 2. `volunteer`, `player` and `treasurer` passed the same check.

- **`te_is_club_admin`** — club-wide staff data. Used by `handleClubParents`.
- **`te_is_club_staff`** — admin OR coach. Used by `handleSendParentInvite` and
  `handleParentPortalStatus`, which act on one guardian or one athlete and which coaches
  legitimately reach from `AthleteProfileEnhanced` / `GuardianManagement`.
- A coach is deliberately NOT admin: they are team-scoped, so the club-wide roster is the
  same over-sharing with a smaller audience.
- **No live `canAccessClub()` call remains in `auth-gateway.php`**, and `ClubStandingTest`
  fails if one comes back. The predicate was never wrong; which one got called was.

⚠️ **`ProtectedRoute` checks only that someone is signed in — it has NO role logic.**
`/crew` used it, so the page was never admin-gated; it was merely absent from the parent
nav. Anyone signed in who typed the URL rendered it. An earlier version of this file
claimed the page was "admin-only in the nav", which was true of the nav and false of the
access. Club-admin pages use `ProtectedClubAdminRoute`; `ProtectedRoute` alone is
authentication, not authorisation.

### A missing `response.ok` check is sometimes deliberate — read the page first
`fetch()` does not reject on 4xx/5xx. It only rejects when the network fails, so
`const data = await response.json()` happily parses an error body and the page uses it as
if it were data. That is why `/legacy/venues-gateway.php` returning 500 surfaced as
`t.map is not a function` and took the whole Facilities page through the ErrorBoundary
(2026-08-04).

**Do NOT sweep the codebase adding `if (!response.ok) throw`.** Measured 2026-08-05: of
363 `fetch()` call sites, 22 assign the parsed body straight to state and only **one**
(the venue detail panel in `VenueManagement.tsx`) is genuinely unguarded. The rest either
guard nearby — `if (data.error)`, `if (data && data.id)` — or read the error body **on
purpose**:

- `pages/ConsentConfirm.tsx` branches on `result?.reason === 'invalid_or_expired'`, which
  arrives on a **400**. Adding an `ok` check there replaces a tailored expired-link message
  with a generic error and undoes the fix commit "consent confirm: an expired link is not
  'Something Went Wrong'".

So the rule is: before adding an `ok` check, read what the component does with the body.
A page that renders a specific message out of a non-2xx response is working as designed.

**Loud beats silent, and the ErrorBoundary is already loud.** A crash on `.map` shows
"Something went wrong" with a Reload button, and the user reports it within minutes — which
is exactly how the venues 500 was found. `setVenues([])` on failure would be worse: it
renders "no facilities", which is indistinguishable from a club that has none. If you do
guard a site, give it a real error state; never a false empty.

The guards that actually catch this class are server-side — `AccessibleClubIdsTest`,
`MysqlOnlySqlTest`, `QueriedTablesExistTest` and `scripts/smoke-test.php`. Fix the 500;
the page then has nothing to mishandle.

### MySQL-only SQL functions 500 on Postgres — `MysqlOnlySqlTest`
`legacy/programs-gateway.php` ordered by `FIELD(p.season_type, 'Spring', …)`. Postgres has
no `FIELD()`, so the Programs list threw 42883 for **every user** — not a scoping bug and
not user-specific, just a query that could never have run. Found 2026-08-04 only because it
sat behind the same page as a real scoping bug.

The identical substitution had already been made in `api/athletes-profile.php`, comment and
all — fixed in one file, missed in the other. That is what a scan catches and a review does
not. `MysqlOnlySqlTest` now scans for `FIELD(`, `GROUP_CONCAT(`, `IFNULL(`, `DATE_FORMAT(`,
`STR_TO_DATE(` and `DATEDIFF(`.

⚠️ **It requires a SQL keyword near the match, and that is load-bearing.** The first version
flagged `services/ImportStrategy.php`, which has a PHP method legitimately named `field()`.
Same lesson as `QueriedTablesExistTest`: scan SQL, not source, or the checker cries wolf and
gets deleted. `NOW()` and `CONCAT()` are deliberately absent from the list — Postgres has both.

### A read against a missing table is invisible to SchemaConformanceTest
`QueriedTablesExistTest` (added 2026-08-03) scans `FROM`/`JOIN` and checks every
table against `tests/fixtures/production-schema.json`. SchemaConformance only ever
checked `INSERT`/`UPDATE`, so `api/financial-permissions.php` joined `team_coaches`
and `coaches` — **neither exists** — and 500'd for every coach for months.

It hid because the failure read as a product statement: the parent portal takes its
athlete list from that endpoint, so a coach who was also a parent was told "no
athletes are registered to you" while Crew and Athletes showed the child fine.
**Parent-only accounts worked**, so a 148-family rollout produced no reports; the
first person to hit it was the first coach who was also a parent.

- **Scan SQL literals, not source.** The first version matched English — "unsubscribe
  from all club emails" — and produced 14 false positives, enough to bury the two real
  findings. A test that cries wolf gets deleted.
- **`KNOWN_BROKEN` is not `KNOWN_DEAD`.** The three entries there are live files with
  an unreached broken query. Delete an entry when it is fixed; never add one to
  silence a new finding.
- Coach team scoping is `getCoachTeamIds()` in `lib/coach_scope.php` and nowhere else.
  Re-deriving it is what produced a join against tables nobody had checked existed.


### "Send login link" is a different button from "Invite to portal"
Added 2026-08-04. `api/portal-access.php?action=send-login-link`, surfaced on the Crew
page as one context-aware control.

**"Invite to portal" does nothing for an existing account.** `ParentInvite::send` returns
`already_active` before minting anything, and `handleSendParentInvite` only emails inside
the `invited` branch — so the admin saw "They already have an account" and the parent
received nothing. The Crew page then rendered **no button at all** for `active` rows, so a
family who could not get in had no path that did anything. One control now covers all
four states: active → *Send login link*, invited/invite_expired → *Resend*,
not_invited → *Invite to portal*, no_email → nothing.

- **Admin-sent links live 24h; the login page's live 15 minutes.** Both TTLs are in
  `lib/magic_link.php` and the difference is the point — an admin clicks now and the
  parent reads tonight, so a 15-minute admin link is usually dead on arrival. The
  response returns the phrase (`expires_in`) and the UI shows it, so what the admin is
  told cannot drift from what was minted.
- ⚠️ **This endpoint gates on `hasRole('club_admin')`, NOT `canAccessClub()`.** The latter
  is club *membership* and a `parent` row satisfies it (see the open `handleClubParents`
  finding), so using it here would let any parent mail a sign-in link to any other family
  in their club. Pinned by `MagicLinkTtlTest`.
- **The token is never in the response** — the email is the channel, which is what stops
  an admin gaining access to a family's account. Also pinned.
- The send is audited as `portal_login_link_sent` whether or not the mail left: an admin
  caused a sign-in link to exist for someone else's account, and that is the fact worth
  being able to show later.
- `send-magic-link` on `auth-gateway.php` is deliberately left unauthenticated and
  untouched — it only ever mails the account owner, so identity proves nothing there.

### A one-time link needs THREE answers, not one — `lib/parent_invite_token.php`
Added 2026-08-03 after a parent completed setup, re-clicked his link, was told it had
expired, and emailed support four minutes later (CHANGELOG). His token had four days
left; it was spent, not expired.

`handleSetParentPassword` had folded `used_at IS NULL AND expires_at > NOW()` into the
token lookup's WHERE clause, so **not found / already used / expired / invalidated by a
re-send** were indistinguishable — every one answered `Invalid or expired link`. Classify
with `te_classify_parent_invite_token()` and never put those predicates back in the query:
filtering the evidence away is what makes the cases unknowable.

- **Used is checked before expired.** A spent token whose window has since closed is, to
  the parent, an account they already have — "expired" sends them to the club for an
  invite they do not need.
- **Already-used is not an error state.** The response carries `reason: already_used` and
  `SetParentPassword.tsx` renders it in blue with a *Go to sign in* button, not in red.
- **The unknown-token branch stays vague on purpose** — it is the only one reachable by
  someone guessing tokens, so it must not confirm what exists.
- **The token is spent only after the account is resolved**, inside a transaction with the
  password write, keyed on the resolved `users.id` rather than the email string, with the
  UPDATE's row count checked. Previously the order was: write password (row count ignored)
  → spend token → look up user → fail. For any invite address with no `users` row that
  burned the parent's link on their first attempt and made every retry genuinely fail.
  When there is no account the token is deliberately **left unspent** — nothing was
  accomplished, so the link must still work once the account is repaired.
- The invite email now states the link is **single-use** as well as 7 days, in both the
  HTML and plain-text bodies. It previously promised only the 7 days, which is why the
  parent reasonably concluded the system was wrong.

`ParentInviteTokenTest` covers the ladder and parses the handler to assert both the query
shape and the spend-after-resolve ordering.

### Platform access is a DATE, not a badge — `lib/portal_status.php`
Added 2026-08-03. One predicate, used by the Crew page (`handleClubParents`) and the
Coaches page (`legacy/coaches-gateway.php?action=available`). Labels are shared too, in
`frontend/src/utils/portalStatus.ts`. **Do not re-derive this in a page or a gateway.**

The badge it replaced said `active` when `password_hash` was non-empty. Three bugs in
one CASE, all of which looked fine on screen:

- **A password is not a login.** Passwords are set by admins (`coaches-gateway.php`
  seeds a literal) and by auto-created shells. On 2026-07-31 two coaches displayed as
  portal-active having never signed in and never been invited. The Coaches page was
  worse — its Status column was the hardcoded string `Active`, in **both** of the
  tables `CoachManagement.tsx` renders.
- **An expired invite decayed into `not_invited`.** The test was
  `used_at IS NULL AND expires_at > NOW()` with no third branch, so a lapsed invite
  became indistinguishable from never having been contacted. 64 Central Kansas invites
  lapse 2026-08-07; the club would have lost the record it ever wrote to those
  families, and "send a reminder" would have read as "make first contact".
  `invite_expired` is now its own state and bulk-invite targets it.
- **The join is on email alone** and cannot be fixed here — it is the missing
  `user_guardians` table. So it is **disclosed, not hidden**: `shared_account` +
  `shared_reason` mark a row whose evidence may belong to another account on that
  address, and the UI marks it rather than asserting.

Evidence order: `audit_log` `login_success` → `users.last_login_at` → `magic_link_tokens`.

⚠️ Two live gotchas, both pinned by `PortalStatusTest`:
- `audit_log.resource_type` holds **both `'user'`** (68 rows, to 2026-07-29) **and
  `'users'`** (123 since). Matching one loses six people's first-login date.
- **15 users have `last_login_at` and no audit row**, so the `COALESCE` fallback is
  load-bearing. Drop it and they all report as never having signed in.

`handleClubParents` joins `athletes` with **no `deleted_at` filter**, so guardians of
soft-deleted athletes still appear on Crew (153 rows for club 51 where the funnel counts
148). Pre-existing; left alone because changing it changes what staff see.

Measure the funnel with `scripts/onboarding-funnel.php` (read-only, crew + coaches).


### A parent's profile edit syncs to `guardians` — `lib/guardian_sync.php`
Added 2026-08-04. `users` holds the login; `guardians` holds what the club sees (Crew
page, sends, exports). `/parent/settings` (`api/user-profile.php`) wrote only `users`, so
a parent could change their email or name and the club kept the old one indefinitely.

⚠️ **Match on email AND name, never email alone.** Six production addresses are held by
two guardians each — John & Jane Jones on `thejones@…`, Morgan & Zach Powell on
`morganbmiles@…`, and four more. Email-only matching rewrites BOTH people's contact record
when one of them edits theirs, handing the club a wrong address for someone who never
touched their settings. Pinned by `GuardianSyncTest`.

- Matched against the **pre-update** users row — the guardian row still carries the old
  email and name at that point.
- Only submitted keys are written, so a partial save cannot blank a field (same rule as
  `legacy/guardian-gateway.php`).
- `phone` on `users` maps to **`mobile_phone`** on `guardians`.
- **Every sync is audited**, including the misses: `profile_guardian_synced` or
  `profile_guardian_sync_no_match`, with the old and new email, the guardian ids touched,
  and `old_email_shared_with_others`. A no-match is not an error — staff-only accounts have
  no guardian row — but it means the club still holds the old details, which is precisely
  what someone will need to look up later.
- This is a workaround for identity-by-email, not a fix. The fix is the `user_guardians`
  link table on the backlog.

### Editing a crew member's contact details goes through the POST branch
`legacy/guardian-gateway.php` PUT updates the **relationship** row only
(`athlete_guardians`: relationship, is_primary, can_pickup, emergency_contact). It never
touches `guardians`. Until 2026-07-30 the POST branch didn't either — it matched an existing
guardian on email+first+last, took the id and moved on — so **no code path anywhere could
change a guardian's name, email or phone.** Editing a parent's phone number returned success
and silently did nothing; the only `UPDATE guardians` statements in the tree were `sms_opt_out`
and `last_contacted`, from the Twilio webhook and the send services.

POST now writes the submitted contact fields, and resolves the guardian from the
`athlete_guardians` **link id** that `AthleteForm` sends (`GuardianData.id`) before falling back
to identity matching. That ordering matters: identity matching cannot handle an edit *to* the
identity — a rename or a new email matched nothing, inserted a second guardian and left the old
one attached to the athlete. Only keys present in the payload are written, so a partial save
cannot blank a field it never sent. Guarded by `tests/php/GuardianContactUpdateTest.php`.

### ⚠️ Crew / parent-portal status is inferred, not recorded (known-weak, 2026-07-30)
`handleClubParents` and `handleParentPortalStatus` in `api/auth-gateway.php` — which power the
Crew page and the athlete-profile invite UI — derive portal state like this:

- `active` = a `users` row **whose email equals the guardian's email** has a non-empty `password_hash`
- `invited` = an unused, unexpired `<email>:parent_invite` row in `magic_link_tokens`
- `not_invited` / `no_email` otherwise

**Neither branch proves the guardian did anything.** The join is on email alone, so ANY account
sharing that address answers for the guardian — the athlete's own auto-created row, a coach who is
also a parent, anyone. `active` really means "some account exists on this address with a password,"
not "this person accepted an invite." That is how 18 Central Kansas guardians displayed as active
on 2026-07-30 when only 2 had ever logged in (see the Pending Work entry). Migration 056 removed
the bad data, so the display is currently correct-by-accident; the inference is unchanged, and the
2 coach rows still read `active` without a crew invite.

Fixing it properly is the same missing piece as the "Shared-email remaining case" above: a
`user_guardians` link table, so a guardian's portal account is a recorded fact rather than an email
string match. The cheap interim is to key `active` off an **accepted** invite
(`magic_link_tokens.used_at IS NOT NULL`) instead of off `password_hash`. Do not "fix" this by
adding more email-matching.

### ⚠️ `users.email` is UNIQUE, so one address = one account, forever
`users_email_key` means an email can only ever belong to ONE row. That is the constraint the whole
identity model breaks against: when a child's auto-created shell took the parent's email (see the
`defaultpass` entry in CHANGELOG), the parent could not be given their own account at all.

`parentInvite_ensureUserAndToken()` therefore **reclaims** rather than reuses: if the row it finds
by the guardian's email is one an athlete points at, it detaches `athletes.user_id`, renames the row
to the guardian, sets `role='parent'`, and audits it as
`parent_invite_reclaimed_athlete_shell`. Without that, the parent would set a password on an account
named after their kid, with `users.role='player'`, that `athletes.user_id` still pointed at — one
login for two people.

**The safety boundary is the password check that runs first.** A row with a `password_hash` returns
`already_active` before any repair logic, so a live account can never be renamed out from under its
owner. `ParentInviteReclaimTest::testRowWithAPasswordIsNeverReclaimed` pins that; if it ever fails,
the reclaim has become an account takeover. Do not move the repair above the password check.

This is a mitigation, not the fix — it repairs one collision at invite time. `user_guardians` is
what removes the collision, because identity stops depending on a unique email.

---

### ⚠️ A coach with no team is still a coach — chat typeahead (2026-08-14)
`handleChatSearch` in `api/recipient-search-gateway.php` derived standing from data:
`$isCoach = !$isAdmin && !empty($coachTeamIds)`. A coach with no team assigned therefore
fell through to `$isParent`, matched no athletes, and got an **empty** typeahead — HTTP 200,
no error, unable to find their own club admin or any other coach. Nine live accounts, four
at CKU. Broken since the typeahead shipped (`08396c6`, 2026-05-05); it only surfaced once
coaches stopped being shown every team in the club.

- **Role decides standing; team assignment decides which FAMILIES you reach.** Conflating
  them makes an unstaffed coach indistinguishable from a parent. `$isCoach` now reads
  `$auth->hasRole('coach', …) || !empty($coachTeamIds)`.
- **`array_fill(0, 0, '?')` produces `IN ()`, which is a syntax error, not an empty
  result.** Both the participant filter and the team-groups query would have 500'd once a
  team-less coach reached them, so each is guarded. `getTeamFilterClause` already returned
  `AND 1=0` for this case — the newer code just did not copy the precaution.
- The chat server needed no matching change: `ALLOWED_PARTICIPANTS_SQL`'s second branch
  already allows any club staff, and `= ANY('{}')` on an empty array is valid SQL.
- `ChatSearchCoachScopeTest` executes the real handler against SQLite (the gateway loads
  lib-only under `TE_RECIPIENT_SEARCH_LIB_ONLY`) and was confirmed to fail on the old code
  with both reported symptoms.

### ⚠️ Chat team scope — `chat-server/lib/team_scope.js` (2026-08-14)
Reported on Central Kansas United: every coach saw every team's chat. Root cause was
`getAccessibleTeamIds` unioning in the whole club whenever `canInitiateConversation(role)`
was true — **that list contains `coach` and `parent`**. It gated the listing, the join
(`isConversationParticipant`), and nothing at all on `getTeamMembers`, so a coach could
also read any team conversation in the club and pull any team's roster.

- **The JWT has no team scope, and that is the trap.** `getCoachTeamIds(payload)` filtered
  `payload.roles` for `scope_type === 'team'`; `JWT::buildOrganizationalContext()` mints
  every role `scope_type: 'club'`, so it returned `[]` for **every user, always**. The
  club-wide branch was not supplementing a coach's team list, it WAS the list — removing it
  alone would have left every coach with nothing. Coach teams now come from the DB
  (`COACH_TEAM_IDS_SQL`, a port of `lib/coach_scope.php`). Never route team scope back
  through the token until the token actually carries it.
- **Use `expandsToWholeClub(role)`, never `canInitiateConversation(role)`, for visibility.**
  The second is "may start a conversation" and includes coach and parent. Pinned by
  `__tests__/team_scope.test.js`, which asserts the absence.
- **Coach and guardian scopes are UNIONed for everyone.** `getUserRole()` collapses a user
  to one role and prefers coach over parent, so a coach who is also a parent never took the
  parent branch. Six CKU coaches are in that position and would have lost their own child's
  team chat — a regression wearing a security fix's clothes.
- **One scope function serves the list and the join.** They were the same mistake written
  twice; `isConversationParticipant` now delegates rather than re-deriving.
- **On a team conversation a `conversation_participants` row is per-user STATE, never a
  grant.** `ARCHIVE_SQL` / `MARK_READ_SQL` upsert, so merely opening a team chat leaves a
  permanent row — and both the list query and `isConversationParticipant` consulted it
  *before* team scope. Scoping coaches down would therefore have exempted precisely the
  people who had already browsed other teams' chats (**10 live rows** across CKU and club 32,
  one coach holding three). Team access is now scope-only on both paths, which makes the fix
  self-healing and avoids a data migration that would need repeating on every scope change.
  DMs and groups still take membership from the row — that is what it means there.
- **A team conversation takes its club from the TEAM.** `ensureTeamConversation` used to be
  handed the *viewer's* club, harmless only while every team list was built by club. Two
  live teams have `club_id` NULL, and moderation/reporting are club-scoped, so the old form
  would have invented an association.
- **`mergeTeamIds` requires a positive integer, not `Number.isFinite`.** `Number(null)` is
  `0`, which a finite check admits into the accessible-ids list as team 0.
- Scoping is club-independent on purpose: the question is "is this your team", and
  club-filtering would depend on `active_context` being right — when it is null the coach
  gets nothing. Verified 2026-08-14: **no coach's teams span more than one club**, so this
  has no live effect today.
- Deploy is its own path — `git subtree split --prefix=chat-server` → remote `chat`
  (`teamselevated-chat`), NOT the backend push.

### ⚠️ Chat has archive, and deliberately has NO delete
Added 2026-07-30 on `feature/chat-archive`. Full rationale in `docs/chat-archive-plan.md`.

- **Archive** (`conversation_participants.archived_at`, migration 058) hides a conversation from
  one user's list. Nothing is removed, no other participant is affected, and a new message
  un-archives it for everyone who had archived it.
- **There is no user-facing delete, and this is a product decision, not an omission.** A control
  labelled "delete" that soft-deletes tells the user their message is gone when it is not; in a
  product carrying minors' communications that gap is the liability. The only removal path is
  admin moderation, which tombstones and writes `audit_log`.
- **COPPA is not the reason to retain chat.** COPPA pushes the other way — retain children's data
  only as long as necessary, honor deletion requests, no indefinite retention. What argues for
  keeping chat is child-safety recordkeeping (SafeSport-style) and club defensibility; COPPA
  supplies the *ceiling*, enforced by the retention plans in `scripts/retention-check.php`.
- **Do not implement archive with `left_at`.** That column's six read-side uses make it behave like
  *leave group* — you disappear from every other participant's roster. Different verb.
- **Per-user chat state must be UPSERTed, never UPDATEd.** `ensureTeamConversation()` creates team
  conversations with **no participant rows**; members reach them through the `c.type = 'team' AND
  c.team_id = ANY(...)` branch of `getUserConversations`. A bare `UPDATE ... WHERE conversation_id
  AND user_id` therefore hits zero rows on team chats — which is exactly why `markRead` never
  cleared team-chat unread badges until 2026-07-30. SQL lives in `chat-server/lib/archive.js`,
  guarded by `chat-server/__tests__/archive.test.js` (`npm test` in `chat-server/`, uses built-in
  `node:test`, no new deps).
- The archive predicate must sit **outside** the `OR` group in the conversation-list WHERE, or the
  team branch re-admits archived team chats. Locked by test.
- **The chat server is a separate Heroku app** — git remote `chat` → `teamselevated-chat.git`, not
  the `heroku` backend remote, and it deploys **by subtree** (its repo root is the contents of
  `chat-server/`): `git subtree split --prefix=chat-server -b <ref>` then push that ref to `chat`.
  `git push heroku` does not deploy it and never has.

### Retention rules live in `lib/retention_plans.php`
Moved out of `scripts/retention-check.php` on 2026-07-30 so they can be unit-tested — that script
connects to Neon at load, so a test that required it would have hit the production database.
`ChatRetentionPlanTest` covers them.

- **A purge must clear inbound references before deleting.** `chat_read_receipts.last_read_message_id`
  is a **NO ACTION** FK onto `chat_messages`, so a naive `DELETE FROM chat_messages` raises
  SQLSTATE 23503 and fails the whole run. Verified against live Neon, not inferred: the naive delete
  was rehearsed in a rolled-back transaction and did fail. A plan may carry a `before` list of
  statements, run in the same transaction as the delete. `chat_reactions` cascades and needs nothing;
  `conversation_participants.last_read_message_id` has no FK but is cleared anyway (11 live rows
  point at messages today).
- `chat_read_receipts` is **empty and nothing writes it** — it predates the `conversations` model
  and `conversation_participants` superseded it. That is exactly why the FK matters: the purge would
  pass every test today and fail the first time the table had rows.
- **Every seeded policy is `auto_delete = FALSE`, including both chat ones.** Declare, don't destroy.
  Purging needs BOTH `--purge` on the command line AND an armed policy. `chat_messages`' 1095 days is
  a placeholder that reports only — how long a club's communications should live is a policy
  decision, not a number to guess at in code.
- `chat_messages_removed` is **inert until admin moderation removal ships** (Phase 2). Nothing writes
  `chat_messages.deleted_at` yet, so it reports zero. Correct, not broken.

---

## Recipient Selection (Compose UI)
The recipient selection experience should feel as close to Gmail/Outlook as possible:

- Typeahead search as the user types a name, email, or phone number
- Contacts displayed as chips/tags that can be individually removed
- Ability to add a whole group at once (e.g. "All of Team A", "All parents of Team A")
- Ability to explicitly exclude individual recipients from a group selection
- Contacts that have unsubscribed should be visually flagged and excluded by default
  (with a warning shown, not a silent drop)
- Respect role-based scoping in search results — coaches should never see contacts outside
  their team in the typeahead
- SMS recipients must have a valid phone number — flag and exclude those who don't,
  with a visible warning

---

## Email Template Library
- A library of reusable email templates must be built and managed within the CRM
- Templates can pull in dynamic data from the **`calendar_events`** table (practices, meetings,
  games, tournaments)
  - Event merge tags work as of 2026-07-30 — `MergeFieldService::loadEventData()` reads
    `calendar_events` (`name` / `event_date` + `start_time` / `type`), resolves the venue through
    `venue_id` and falls back to the free-text `location` column, and gets the team from
    `calendar_event_teams` (an event can have several — the lowest team_id wins, deterministically).
    `{{event_venue_name}}` and `{{event_address}}` exist alongside `{{event_location}}` because the
    seeded copy says "Venue: X / Address: Y" and the combined string repeated the venue name.
- Available template variables should include event name, date, time, location, and team name
  at minimum — inspect the `calendar_events` schema and expose all relevant fields as variables
- Club admins can create, edit, and delete templates
- Coaches can use templates but cannot create or modify them
- Templates should support a rich text / HTML body with variable placeholders
  (e.g. {{event_name}}, {{event_date}})
- A preview mode must be available before sending, showing resolved variable values
- Templates are scoped per club and flow down in availability to each team
- Each club can control which teams see which templates: all teams, specific teams, or private to the club level only

---

## Email Feature Requirements

### Sending
- Staff can compose and send email to one or more contacts from within the CRM
- Supports free-form compose and template-based compose
- Emails are queued via Redis and sent asynchronously — follow the existing job pattern
- Failed sends should be retried up to 3 times before marking as permanently failed
- Staff receive an in-app notification if a send permanently fails

### Tracking & Webhooks
- Enable SendGrid event webhooks for: delivered, opened, clicked, bounced, spam reported,
  unsubscribed
- Store webhook events against the relevant `communication_log` record
- SendGrid webhook URL: `{APP_URL}/api/webhooks/sendgrid` — configure in SendGrid dashboard once deployed

### Unsubscribes (CAN-SPAM / GDPR Compliant)
- Every outbound email must include a compliant unsubscribe link
- Clicking unsubscribe loads a branded landing page (hosted within this app at /unsubscribe)
  that allows the contact to:
  - Unsubscribe from all club emails
  - Unsubscribe from team emails only
  - Confirm their choice with a single click — no account login required (use a signed token)
- Unsubscribe preferences are stored on the contact record and respected on all future sends
- Unsubscribes triggered via SendGrid webhook must also update the contact record
- Suppression list must be maintained to prevent re-sending to unsubscribed contacts
- Right to erasure / data deletion (GDPR Article 17) is NOT required now, but keep the data model extensible to support it in the future

---

## SMS Feature Requirements
- Staff can compose and send SMS to one or more contacts from within the CRM
- ~~Free-form text only (no templates required for SMS)~~ — stale. SMS templates exist and are in
  the nav at `/sms-templates` (`SmsTemplates.tsx` → `email-templates.php?action=list&channel=sms`).

### The category taxonomy is shared — `frontend/src/constants/templateCategories.ts`
Both template libraries render the same 10 tags from that one module (chip cluster with counts,
then sections grouped in taxonomy order with "Other" last). **Do not re-declare the list in a
page.** SMS originally kept its own four-item list (`General` / `Game Day` / `Practice` /
`Administrative`) while the data used the 10-tag slugs, so its filter matched almost nothing and
its editor wrote categories no page could read back — fixed 2026-07-30. Both channels store the
slug in `email_templates.category`; unknown values fall into "Other" rather than disappearing.

### SMS merge tags resolve in `lib/sms_merge.php`, per recipient
`resolveSmsBodies()` is the only place SMS merge fields are resolved, and both callers use it:
`send-sms` and the SMS branch of `send-broadcast`. Until 2026-07-30 **neither** resolved anything
— `send-sms` handed the raw body to the queue, and `send-broadcast` resolved only inside its
email branch — so every one of the 55 SMS templates texted families the literal
`{{athlete_first_name}}`.

Resolution is **per recipient**, not per batch: the body differs for each person, so it rides on
the recipient as `_resolved_body`, and `SmsSendService::queueSms()` prefers it over the shared
`$body` for BOTH the Twilio payload and the `communication_log` row — the log has to record what
that person actually received. An unresolved tag returns 422 and stops the whole send, matching
`send-email`: a raw `{{tag}}` in a text cannot be unsent.

`SmsCompose` sends no `event_id`, so the 3 templates using `{{event_*}}` (Season Kickoff, Game
Day Reminder, Volunteer Reminder) will 422 until it gains an event picker like `EmailCompose`
has. The other 52 resolve — `{{team_name}}` included, via the recipient's own roster row.

### ⚠️ `recipient_types` is SINGULAR in the broadcast API, PLURAL in the group API
- `resolveBroadcastRecipients` (`api/communications-gateway.php`) tests
  `in_array('athlete', …)` / `'guardian'` / `'coach'`.
- `resolve-group` (`api/recipient-search-gateway.php`, called by `RecipientSelector.tsx`) takes
  `['athletes','guardians','coaches']`.

Each is correct for its own endpoint. Passing the plural array to `send-broadcast` resolves **zero
recipients and sends nothing** — HTTP 200, `total_recipients: 0`, no error anywhere. Locked from
both sides by `BroadcastRecipientResolutionTest::testPluralRecipientTypesResolveNobody` and
`BroadcastCompose.test.tsx`.

### There are two recipient-resolution paths, and they are not interchangeable
- **`send-sms` / `send-email`** take a `recipients` array of already-resolved people. `SmsCompose`
  and `EmailCompose` always use these, at any count (the "CA-49" comments).
- **`send-broadcast`** takes `scope` + `team_ids`/club + `recipient_types` and resolves server-side.
  `BroadcastCompose.tsx` is its only caller. This is the path that writes a `broadcast_campaigns`
  row, so **only broadcasts appear in Reporting as a campaign**; the other path produces N loose
  `communication_log` rows.

### Twilio error 30024 on a NEW number is not a bug — wait ~5 minutes
`[30024] Numeric Sender ID Not Provisioned on Carrier` means the message reached
Twilio fine and the **carrier** rejected the sender. On a freshly-purchased number
it almost always means carrier provisioning onto the A2P 10DLC campaign hasn't
propagated yet, not that anything is misconfigured.

Observed 2026-07-30, club 32 (`+13605164604`), all three sends from the same number:

| Sent after being added to the Messaging Service | Result |
|---|---|
| 65 seconds | ❌ 30024 |
| ~2 minutes | ❌ 30024 |
| **~5 minutes** | ✅ delivered |

It looks exactly like a send-path bug and is not one. Before touching code, check
the number's `date_created` on the Messaging Service and the brand/campaign status
(`/v1/Services/{MG}/Compliance/Usa2p` — want brand `APPROVED`, campaign `VERIFIED`).
If those are healthy, the answer is to wait and resend.

⚠️ **Do NOT "fix" 30024 by putting a shared Messaging Service SID in Club Profile →
Messaging.** `MGce1e99cb…` ("Mixed A2P Messaging Service") holds ~11 numbers spanning
multiple clubs. Sending via a service lets Twilio pick any number in that pool, so
one club would text families from another club's number — the exact cross-club bleed
per-club senders exist to prevent. A per-club Messaging Service (one number, same
approved campaign) is safe; a shared one is not.

### Inbound SMS: the auto-reply has two rules that look optional and are not
`api/webhooks/twilio-inbound.php` answers replies with a pointer to the parent
portal and stores nothing (Tier 0 of the reply plan in `docs/broadcast-sms-scope.md`).

1. **STOP / HELP and friends must get EMPTY TwiML, never the auto-reply.** Twilio
   still forwards those to the webhook, but blocks any outbound to that number
   afterwards — so a reply fails silently at best, and reads as ignoring an opt-out
   at worst. Only the *bare* keyword counts; "can we stop by the field at 6?" is an
   ordinary message. Keyword list and matching live in `TE_SMS_CARRIER_KEYWORDS`.
2. **The reply must stay ≤160 GSM-7 characters** (it is 139). Over that and every
   reply bills as two segments forever. And a single non-ASCII character — a curly
   apostrophe, an em dash — forces the whole message to UCS-2, where the limit
   collapses to **70** and the cost triples. Straight quotes and hyphens only.
   Both are pinned by `tests/php/SmsAutoReplyTest.php`.

Nothing is persisted, and the outgoing text says as much — so a database write here
makes the product lie to families. `SmsAutoReplyTest::testNothingIsStored` fails on
one if it appears.

**A number's inbound webhook is set when it is saved** (`te_configure_twilio_inbound`,
called from `api/sms-numbers.php`). Numbers saved before Heroku v456 have an empty
`sms_url` and silently swallow replies — re-save them, or set `SmsUrl` via the Twilio
API. Club 51's was fixed by hand on 2026-07-30; check any other early number.

### SMS suppression: one predicate, in `lib/suppression.php`
`te_sms_skip_reason` is the single answer to "should this person get this text", used by both
`SmsSendService::queueSms` and `handlePreviewBroadcast`. It checks `email_suppressions`
(`channel='sms'`, scope-aware) **and** `guardians.sms_opt_out`, on the **normalized** phone.
- Never re-implement either half inline. Preview and send disagreeing is how a club-wide send
  quietly reached fewer people than the UI promised (fixed 2026-07-30).
- `email_suppressions.phone` stores **E.164**. Comparing it against a raw `mobile_phone` column
  value matches nothing. Normalize with `te_normalize_sms_phone` on both sides.
- Phone normalization has exactly one implementation: `te_normalize_sms_phone` (non-throwing).
  `SmsSendService::normalizePhone` is a throwing wrapper around it.
- Sent via Twilio, queued via Redis asynchronously
- Delivery status stored against the contact record via Twilio status callbacks
- Outbound only for now — inbound/replies not required in this phase
- Contacts can opt out via standard STOP reply (Twilio handles this automatically) — sync
  opt-out status back to the contact record via Twilio webhook

---

## Communication Log
All sends (email and SMS) must be logged to a `communication_log` table including:
- Contact ID (recipient)
- Sender user ID
- Channel (email / sms)
- Subject (email only)
- Body / message content
- Status (queued / sent / delivered / failed / bounced)
- Timestamps (created_at, sent_at, delivered_at)
- Related event ID (`calendar_events.id`) if sent from a template referencing an event

### Contact Record — Communication History
- A new "Communications" tab or section must be created on the contact profile page
- Displays a chronological log of all emails and SMS sent to that contact
- Shows: date, channel, subject/preview, sender name, and delivery status
- This section must cover communications sent to:
  - The contact directly (athlete)
  - The contact as a parent/guardian (communications regarding their athlete)
- Both parent and athlete records should reflect relevant communications — a team email sent
  to a parent about their athlete should appear on both the parent's and the athlete's record

---

## Email Reporting & Performance

### ⚠️ `action=overview` is a contract with exactly one file
`api/analytics-gateway.php`'s overview response must be `{success, stats: {...}}`, and `stats` must
carry every field of the `OverviewStats` interface in `frontend/src/pages/EmailReporting.tsx` —
including `delivery_rate`, `total_pending` and the four `prev_*` trend values. The frontend does
`setOverview(data.stats)`; a flat or partial response sets `overview` to `undefined` and the entire
tile grid silently falls through to "No overview data available." That is exactly what shipped: the
gateway returned flat keys (`delivered`, `opened`, `clicked`) for months while metrics landed in
`communication_log` correctly, and the page just looked empty. Fixed 2026-07-30.

**`EmailReporting.test.tsx` did not catch it and could not have** — it mocks the `stats` shape the
frontend wants, so it proves only that the frontend parses what it is handed. Its four tests cover
the per-email report and link panel, not the tiles. `tests/php/AnalyticsOverviewContractTest.php`
now parses both files and asserts the backend supplies what the interface declares. Change the
interface, the mock, and `overviewAggregate()` together or that test fails.

### Per-Email Report
- Accessible from the communication log — clicking any sent email opens a detail view
- Shows: subject, sender, sent time, recipient list, and per-recipient status
- Metrics: total sent, delivered, opened, clicked, bounced, unsubscribed, spam reports
- Open and click rates shown as percentages
- Per-recipient status breakdown (who opened, who bounced, etc.)

### Summary Dashboard
- A dedicated Email Reporting section in the CRM (create a new nav item)
- Club admins see reporting across all teams
- Coaches see reporting scoped to their team only
- Summary metrics: total emails sent, average open rate, average click rate, bounce rate,
  unsubscribe rate — filterable by date range and team
- A table of recent sends with top-level metrics per send
- Dashboard should include both email and SMS volume stats

---

## What Claude Should NOT Change
- Do not modify the authentication system (`lib/JWT.php`, `lib/AuthMiddleware.php`, `api/auth-gateway.php`)
- Do not alter existing table structures — add new tables/columns only.
  **One approved exception exists**: migration 063 dropped `NOT NULL` from
  `consent_records.guardian_id`, because that column is an FK to `users(id)` and a parent
  filling in the public registration form has no account yet — requiring one meant consent
  could only be recorded from people who already had accounts, i.e. never at sign-up. Agreed
  with Maggie 2026-07-31; the table held 0 rows at the time. The FK is intact, so a non-null
  value is still guaranteed to be a real user. Do not treat this as a precedent for relaxing
  other constraints.
- Do not change the existing API routing pattern in `index.php` beyond adding new routes
- The existing Redis/push notification reminder system must continue to work unchanged
- Existing tests should continue to pass
- **Do not rebuild the communications subsystem.** `teamselevated/api/communications-gateway.php`, `analytics-gateway.php`, `recipient-search-gateway.php`, `email-templates.php`, `services/EmailSendService.php`, `services/SmsSendService.php`, `services/MergeFieldService.php`, `services/RedisQueue.php`, `api/webhooks/sendgrid.php`, `api/webhooks/twilio-status.php`, `api/track/pixel.php`, `api/track/click.php`, and the related frontend pages are built and working in production. Extend them; do not reimplement.
- **Do not build on `email_accounts`.** It's an orphaned MySQL-syntax experiment.

---

## Pending Work Checklist

**Open items only.** Completed work moved to `teamselevated/CHANGELOG.md` on 2026-07-30 — check
there (and `git log`) before concluding something is unbuilt. The whole communications feature set,
the data importer, household combining, recurring events, payments, and the event merge tags are
all **built and in production**; the "do NOT rebuild" list is in CURRENT STATE above.

- [ ] **⚠️ Coach accounts are created with a shared literal password** (found 2026-08-03).
      `legacy/coaches-gateway.php:144` does
      `password_hash($data['password'] ?? 'password123', …)` and
      `CoachManagement.tsx` hardcodes `password: 'password123'` in the form default and
      shows the admin "Default password: password123". So every coach made through that
      screen gets the same credential — and the 2026-07-31 Central Kansas kickoff blast
      **emailed it in plaintext** to 11 people ("Temporary password: password123").
      This is the same class migration 056 fixed for athletes, but 056 scoped itself
      `WHERE role = 'player'`, so coaches were never covered. ~13 coach accounts have
      never been signed into and would still carry it.
      Fix is three parts and the third is what stops it recurring:
      1. remove the literal from the gateway and the form (no password at creation);
      2. give coaches a real invite — reuse `parentInvite_ensureUserAndToken` with a
         `:coach_invite` suffix, so there is no shared secret and the funnel gets a real
         `used_at` instead of an inferred email open;
      3. a 056-style migration clearing existing hashes, guarded on
         `last_login_at IS NULL` so it cannot lock out anyone actually using the account.
      Clearing hashes without (1) just regenerates the problem on the next coach added.

- [ ] **⚠️ `api/invitations-gateway.php` authenticates but does not AUTHORIZE** (found 2026-08-17).
      `create-link` and `send` require a token (401 without one) and then check nothing
      else: `clubId` comes from the request body and the caller's standing in that club
      is never verified. So any signed-in user — parent, player, volunteer — can mint a
      **`club_admin`** invitation link for **any club** and redeem it. Same shape as the
      `handleClubParents` finding but worse: there is not even a membership check.
      Role values are now whitelisted (`TE_INVITABLE_ROLES`), which stops junk reaching
      the CHECK constraint at accept time but does nothing about who may invite.
      Fix: gate both handlers on `te_is_club_admin($pdo, $auth, $clubId)`, and treat a
      `parent`-role invite as club-admin-only too.
- [ ] **Crew invited by link land on their family only if the email matches** (2026-08-17).
      `parent` is now an invitable role and needs no new linking code, because parent
      standing is derived from `guardians.email = users.email`. The accept response
      returns `linked_athletes` so a zero is visible. **Not yet built** (agreed with
      Maggie, in this order): (1) parent portal empty state telling them to ask their
      club admin to connect them to their athlete, (2) a club-admin tool to make that
      connection. Until then a mismatched address means a silently empty portal.
- [ ] **Shared-email remaining case** — `users.email ≠ guardians.email` loses the parent role; needs Phase 2 `user_guardians` link table (the read-side fixes in the 3 legacy files are DONE, verified 2026-07-06)
- [ ] **Portal status is still inferred from a shared email** — same missing `user_guardians` table
      as above, failing in the opposite direction: any account sharing a guardian's email answers
      for them, so the Crew page reports invites nobody sent. Migration 056 removed the bad data
      but not the inference. Full explanation and the cheap interim fix are in Roles & Permissions.
- [ ] **Scan for crossed guardian emails before the next bulk invite.** The Mills household had the
      two parents' addresses swapped, so each invite went to the other's inbox (fixed 2026-08-03,
      CHANGELOG). Athlete 339 (Leonel Jimenez) looks like the same shape. The tell is a guardian
      whose email local-part contains the OTHER guardian's name; a shared household address is
      normal and must not be flagged. Worth a one-off query, not a feature.
- [ ] **⚠️ Consent audit readiness — scheduled for Monday 2026-08-03. Full scope, verbatim
      evidence and landmines in `docs/consent-audit-readiness.md`; read that, don't re-derive.**
      Consent *capture* and *staff visibility* are done and live. What is missing is the
      defensibility half Maggie actually asked for — "log these in case we experience an audit or
      a complaint". Two gaps:
      1. **The wording is not stored and has already diverged.** Every row stamps
         `consent_version='1.0'`, but nothing records what 1.0 said — the statements are JSX in
         `ConsentGate.tsx` and `PublicRegistrationForm.tsx`, and the medical one now differs
         between them ("for example" vs "may include but is not limited to" — different scope
         claims, same version string). So you can prove a parent consented and when, but not to
         what. Fix: statements server-side as the single source, both surfaces render from it,
         and store the exact text on each row (migration 064). Start versions at **1.1** — 1.0 is
         already ambiguous.
      2. **The portal promises withdrawal it does not offer.** `ConsentGate` says "you can
         withdraw it at any time from the portal"; `action=revoke` exists and **nothing calls
         it**. Same silent-promise pattern this workstream spent two days removing, introduced by
         the 2026-07-30 copy. Needs a "Your consents" section — and a product decision on what
         withdrawal *means* (revoking currently re-raises the blocking gate, i.e. a loop).
- [ ] **Consent status is not on the Crew page.** Shipped on the athlete list instead
      (2026-07-31) — consent is per-child, so a guardian row covering three athletes cannot show
      one honest badge, and `api/auth-gateway.php` (which powers Crew) is on the do-not-modify
      list. If Crew needs it, merge `action=summary` client-side and show one badge per athlete
      name in the row, not one for the guardian.
- [ ] **Crew add says "already in the system" for an email that is not in the system**
      (reported 2026-07-30, PAUSED mid-investigation — resume here, don't re-dig).
      Case: an admin adding `jacqueline.devora@icloud.com` as crew on the Devora family
      (athlete Sofia Devora 452, club 51; existing crew Leya Devora, guardian 463).

      **Established, no need to re-check:**
      - That email is in **zero** tables — guardians, users, invitations, magic_link_tokens.
      - **No unique constraint on `guardians.email`** (only an index), so a crew add can
        never fail as a duplicate email. `users.email` IS unique — so any genuine
        "already exists" is about a USER account, not a guardian row.
      - Athlete deletion is **soft**, and `athlete_guardians` FKs are ON DELETE CASCADE off
        the hard row — guardians survive. So nothing was created then cleaned up; the add
        never succeeded.
      - Two candidate messages, different code paths:
        `AthleteController::addGuardian` → "Guardian already linked to this athlete";
        `CrewRoster.tsx:88` / `GuardianManagement.tsx:76` → "They already have an account."
        (the latter is the portal invite, and `ParentInvite::send` keys off the **stored**
        `guardians.email`, not the address just typed).

      **Needed to finish:** the exact wording, which screen/button, and whether the email
      field was filled before submitting.

      **Related real bug found on the way — fix regardless of the above.**
      `AthleteController::createOrFindGuardian` matches on `email = :email AND first_name`
      (no last name). **25 guardians have `email = ''`** — an empty STRING, so they compare
      equal, where NULL would not. Adding an emailless crew member whose first name matches
      an existing emailless guardian silently returns **that other person's** id, merging two
      unrelated humans. Live pair already present: `Juan Rocha` / `Juan Coca`. Fix: skip
      identity-matching entirely when the email is blank, and match first+last+email like
      `legacy/guardian-gateway.php` does.

- [ ] **`/api/analytics` returns 403 for club 32** (seen in Heroku logs 2026-07-30 23:04, all
      actions: overview, teams, campaign-performance, recent-sends, link-analytics). Noticed
      while debugging the above; not investigated. Likely the same active-context/role family
      as the SEC-11 regressions.

- [ ] **"Gets Comms" checkbox does nothing** (found 2026-07-29) — `GuardianManagement.tsx` binds it to `receives_communications`; no column exists and no live backend writes it. Either add the column and honor it at send time, or remove the control.
- [ ] Migration files for the ad-hoc comms tables (`communication_log`, `email_events`, `email_links`, `email_templates`, `email_suppressions`, `broadcast_campaigns`) — currently exist in Neon but not in `/database/migrations/`. Schema-migration debt, not blocking.
- [ ] Unit tests for email service, SMS service, permission scoping (status unknown — verify before writing duplicates)
- [ ] **⚠️ `createConversation` validates no participants** (found 2026-07-30) — it takes
      `participantIds` from the client, resolves names from `users`, and inserts. No club, team or
      role check, and `canInitiateConversation` includes `parent`, so any authenticated initiator
      can open a DM with **any user id in any club**. No athlete has ever been a participant, but
      the hole has been reached: conversation 52 holds user 27 (`john@nomail.com`), tied to club 32
      by neither the guardian chain nor a staff role. Fix is **M0** in
      `docs/chat-moderation-plan.md`, ships first.
      **Implement as an allowlist, NOT a blocklist on `athletes.user_id`.** That column is not a
      "this account is the child" signal: of 26 populated, **23 point at an account whose email is a
      guardian's** and **10 hold staff roles**, while **0** users hold the `player` role. Measured
      against the built allowlist: club 51's is 26 people and **16 of them are `athletes.user_id`
      values** — blocklisting would have cut 62% of that club's contacts. Product rule (Maggie,
      2026-07-30): coaches cannot DM athletes at all; DMs are coach↔crew.
      ⚠️ **A guardian's club is the guardian chain, not their `user_club_access` row.** Comparing
      `user_club_access.club_profile_id` to `conversations.club_id` produces false cross-club
      findings — it did on 2026-07-30, flagging a legitimate DM as a leak.
- [ ] **Chat admin-review notice + ToS — with the attorney, lands later.** Club chat carries **no
      expectation of privacy** (Maggie, 2026-07-30) and admins will be able to read reported
      conversations. **Chat is live and approved for the beta clubs ahead of that copy — decided
      2026-07-30, the business owns the risk tolerance. This is not a blocker; do not re-raise it as
      one.** Still to land: the header line "Club administrators can review messages", and ToS
      acceptance via `users.tos_accepted_at` / `tos_version`. Detail in
      `docs/chat-moderation-plan.md` → M5.
- [x] **Chat moderation — BUILT AND DEPLOYED 2026-07-30** (M0–M4, M7). Was scheduled for the week
      of 2026-08-03. Full plan in
      `docs/chat-moderation-plan.md`. Report/auto-flag → admin notified → admin opens that
      conversation with full read → removes or dismisses. Every admin read is logged — a
      defensibility control, not a privacy one. Admin read is **flag-gated**, not blanket browse.
      Order M0 → M1 → M2 → M3 → M4. Auto-flagging **flags, never censors**, and profanity is one
      rule among several: the patterns that matter in youth sports chat (off-platform contact,
      secrecy) carry no profanity at all.
- [ ] **`athletes.user_id` is largely mis-linked** (found 2026-07-30 while scoping chat M0). 23 of
      26 populated values point at an account whose email belongs to a *guardian*, and 10 point at
      accounts holding staff roles. Anything that treats `athletes.user_id` as "the athlete's own
      login" is probably wrong. Not fixed — flagged because it silently breaks any feature that
      assumes otherwise.
- [ ] **`athlete_profiles` retention policy has no rule and has never done anything** (found
      2026-07-30 while adding the chat policies). `data_retention_policy` carries an
      `athlete_profiles` row at 1825 days, but `lib/retention_plans.php` has no entry for it, so
      `retention-check.php` prints `UNSUPPORTED — no rule defined`. In a report where every other
      line reads "nothing to do", that is easy to scan past as fine. Either write the plan (what
      counts as an expired athlete profile — presumably soft-deleted + inactive, matching the
      health plans) or drop the policy row. A declared policy that silently does nothing is worse
      than an absent one, because the report implies coverage that isn't there.
- [ ] **Scheduled SMS sends + replies Tier 1/2** — full scope, landmines and testing criteria in
      **`docs/sms-scheduled-and-replies-scope.md`** (written 2026-07-30 for the week of 08-03).
      Headlines only:
      - Scheduled is blocked on **migration 060+** adding `body`/`html_body` to
        `broadcast_campaigns`. *(An earlier version of this item said 057 — that number was claimed
        by per-club SMS numbers; 058/059 are chat archive and retention.)* Without them a scheduled
        campaign stores everything except what to say. A dispatcher alone cannot fix it.
      - Dispatch belongs as a throttled tick inside the already-running `workers/queue-worker.php`,
        **not** a new scheduler process — that hits the cost wall keeping `calendar-sync-scheduler`
        and `waitlist-expiry-scheduler` switched off. The 400 guard in `handleSendBroadcast` stays
        until it ships.
      - ⚠️ **An uncaught throw in that tick stops every queue** — email, SMS, imports, calendar
        sync. A club that cleared its number makes `queueSms` throw, so catch per campaign. Write
        that test first.
      - ⚠️ Building replies Tier 1 makes the live auto-reply copy ("This number is not monitored")
        **false**. The wording and its test change in the same commit.
- [ ] **Staff phone number on profile (Workstream D)** — roadmap P0 #1. `users.phone` exists but is
      rarely populated, so the coach branches of `resolveBroadcastRecipients` resolve near-empty.
      No migration needed; normalize through `te_normalize_sms_phone` on save.
- [ ] **Silent-failure bugs found in code verification 2026-07-06** (UI promises, backend doesn't deliver — see `TeamsElevated-Product-Roadmap.docx` Tier 1): scheduled broadcasts never dispatch; tryout "send offers" sends no notifications; invoice/receipt/reminder emails are demo stubs and registration confirmation is commented out; email signatures stored but never appended; facility contacts dropped on save; unsubscribe scope ignored at send time; volunteer direct-assignment bypasses background-check block

---

### Run `scripts/smoke-test.php` after a day of deploys
Read-only walk of the staff read surface against deployed prod, minting tokens for a
discovered club_admin / coach / parent per club. `main` is shared, so a push carries commits
no one in your session wrote — checking only what you touched is not enough.

- It checks **refusals as carefully as successes.** An empty scope and "everything" are
  opposite answers, so `consent?action=summary` returning *zero* athletes to a parent is
  the assertion that proves the scope is right; a 403 there would actually be wrong.
- Some read endpoints are **POSTs** — `club-parents` takes `club_id` in a JSON body. A GET
  gets `400 club_id is required`, which reads like a bug and is not one.
- A 404 from `inbox.php` is **correct** for a club without `inbox_enabled` (the flag lives
  on `sms_phone_numbers`, not `club_profile`).
- **Do not add a write to it.** It is only worth running against production, and that is
  only safe while it cannot change anything.


## Helpful Context

### Every email comes from ONE address, under the CLUB's name — `lib/email_sender.php`
Set 2026-08-04. `notifications@teamselevated.com`, display name = the sending club
("Central Kansas United"), so a parent recognises the sender.

**Before this there were three senders, two on hardcoded fallbacks nobody had chosen:**
`lib/Email.php` sent as `maggie@eyeinteams.com` / "Teams Elevated", `EmailSendService` as
`notifications@teamselevated.com` / *the staff member's name*, and `CalendarInviteService`
as `maggie@eyeinteams.com` / "Maggie - Teams Elevated". A family registering, being invited
and then getting a club broadcast saw two domains and three names — and never their club's.

- **Resolve through `te_email_from_address()` / `te_email_from_name($pdo, $clubId)`.** No send
  path may carry its own address; `EmailSenderTest` fails the build if one does.
- **The address must stay on a SendGrid-authenticated domain.** `teamselevated.com` is the
  authenticated one — that is why everything consolidated onto it rather than onto
  `eyeinteams.com`. Moving it is not cosmetic: an unauthenticated From puts password resets
  in spam.
- **No club ⇒ "Teams Elevated", never a guess.** Password reset and magic-link sign-in from
  the login page have no club (the person may span several or none). `EmailBranding` answers
  `'Your Club'` for an unknown id — fine as a page heading, terrible as a sender — so that
  value is treated as unknown and never reaches an inbox.
- **Bulk: FROM is the club, REPLY-TO is the staff member.** Broadcasts used to show the
  sender's name; they now show the club, and a reply still reaches the person.
- **Calendar: stamped per event via `sendAsClub()`**, because PHPMailer holds one From for the
  instance and the constructor runs before any event is known. Every public send path calls
  it; a new one that forgets sends as the platform.
- Transactional callers opt in with `(new Email())->forClub($pdo, $clubId)`. **All club-aware
  sites are wired** (2026-08-04): parent invite, Crew login link, consent confirmation, team
  invitation + resend, calendar invite, donation receipt + resend, Stripe payment receipt.
  `EmailSenderTest::testEveryClubAwareSendSiteBrandsAsTheClub` counts `new Email()` against
  `->forClub(` per file, so a new send that skips branding fails the build.
- **Two paths stay unbranded on purpose**, and the test asserts it: `auth-gateway`'s magic
  link and password reset (sent from the login page, where the person may span several clubs
  or none) and `organization-gateway` (invites people TO the platform, before they have a
  club). Changing either is a decision, not a fix.
- The calendar ICS `organizerName` is club-branded too. `organizerEmail` is deliberately
  left as `events@rsvp.eyeinteams.com` — RSVP REPLY parsing keys off it, so changing it
  breaks replies.

### ⚠️ There are THREE email send paths, and they behave differently
Know which one you are touching before you debug a delivery or rendering problem — they fail in
different ways, and a test that exercises the wrong one proves nothing.

| Path | How it sends | Used by |
|---|---|---|
| `services/EmailSendService.php` | POST JSON → `api.sendgrid.com/v3/mail/send` | Bulk/compose: `api/communications-gateway.php` (send-email, broadcasts) |
| `lib/Email.php` | Same SendGrid HTTP API (`sendViaSendGrid`), with a PHP `mail()` fallback | Transactional: magic link, password reset, parent invite, consent confirmation, team invitation, donation/payment receipts |
| `services/CalendarInviteService.php` | **PHPMailer over SMTP** to `smtp.sendgrid.net` | Event/calendar invites with ICS: `legacy/events-gateway.php` |

- The two HTTP-API paths are **UTF-8-safe by construction** — the payload is JSON, so encoding is
  not something you can get wrong.
- **Club branding lives in `lib/EmailBranding.php`, not in template HTML** (added 2026-07-29).
  Templates are shared across clubs (platform scope), so they carry no club identity; the sending
  club's logo, colour, socials and the unsubscribe link are wrapped around the body at send time by
  `EmailSendService::processHtml()`, mirrored by `preview-email` and by the Template editor's
  Preview button so preview == what ships. `CalendarInviteService::getClubBranding()` delegates to
  the same class — do not re-add a second copy of that `club_profile` query. `wrap()` is idempotent
  via `<!--te-brand-header-->` / `<!--te-brand-footer-->` markers. The logo comes from
  `api/club-logo.php` (migration 049); a club with no cached PNG degrades to text, never a broken
  image. Guarded by `tests/php/EmailBrandingTest.php`.
- **Insert before the LAST `</body>`, never every match.** Several seeded templates contain
  explanatory HTML comments that themselves contain markup, so `str_ireplace('</body>', …)`
  injected the tracking pixel and footer twice — the second copy landing inside the comment, which
  broke the comment pair and leaked its prose into the rendered email (visible in the four "Club
  platform announcement" templates until 2026-07-29). Use `EmailBranding::appendToBody()`.
- `CalendarInviteService` holds the **only PHPMailer instance in the codebase**, and PHPMailer
  defaults `CharSet` to `iso-8859-1`. It is now set explicitly to UTF-8; do not remove that line.
  Without it every emoji, curly quote, em dash and accented name (José, Muñoz) ships as mojibake,
  in the Subject as well as the body. Guarded by `tests/php/MailerCharsetTest.php`.
- `lib/Email.php` picks its transport from `EMAIL_PROVIDER` (`Env::get('EMAIL_PROVIDER', 'mail')`).
  The **default is `mail`**, which on a Heroku dyno delivers nothing. Production sets it to
  `sendgrid` — check that config var first when transactional mail silently vanishes.
- **This split has already burned us.** `scripts/send-kickoff-test.php` reflects the PHPMailer out
  of `CalendarInviteService` rather than calling `EmailSendService`, so the 2026-07-29
  household-combining test rendered mojibake while the real compose-and-send path was fine. The bug
  it exposed was real, but in calendar invites — not in the feature under test. **When writing a
  send test, call the same service the product calls.**
- CTA buttons in `lib/Email.php` templates must set their white label **inline on the anchor and on
  a nested `<span>`**, not via the `<style>` block — mail clients override anchor color with their
  own link styling, which renders a blue label on the dark green button. Guarded by
  `tests/php/EmailButtonContrastTest.php`.
- `users` table has `first_name`/`last_name` columns, NOT a single `name` column
- Parent detection relies on `guardians` table email matching `users.email` — if they differ, parent role is lost
- Role determination uses `user_club_access` table, NOT `users.role` column
- Two frontends exist: OLD in `teamselevated-backend-folder/frontend/` and NEW in `teamselevated/frontend/` — build new UI in the NEW frontend only

### Prior Art — CRM Killer Email System Spec
- **`/Users/maggiemae/TeamsElevated/email-system-technical-spec.md`** contains a full technical spec from a previous email system built on Node/Express/Postmark
- Use as a **blueprint** for database schema, sending pipeline, tracking, webhooks, suppression, analytics, and frontend architecture
- Key adaptations needed: Postmark → SendGrid, Sequelize → raw PDO, Express → vanilla PHP router, org scoping → club/team scoping, add SMS/Twilio, add Redis queue worker
- Template editor: Unlayer (react-email-editor) — same as the prior system. Store `design_json` (JSONB) + `html_output` (TEXT) per template
