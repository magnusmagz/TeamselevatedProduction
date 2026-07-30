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
   te-stripe-payments worktree before creating one.** Next free as of 2026-07-30: **057**
   (048–056 taken: athlete_gender_nullable, club_logo_png,
   emergency_contact_authorize_medical, athlete_medical, program_season_fields,
   users_tos_acceptance, athlete_jersey_size, registration_jersey_size_field,
   clear_default_player_passwords).
   All applied to Neon.
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
- No formal coding standard enforced (no phpcs/phpstan config), but code follows PSR-4 autoloading
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
SENDGRID_FROM_EMAIL=
SENDGRID_FROM_NAME=
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

Guarded by `tests/php/AthleteWriteScopeTest.php`, which also parses both gateways and asserts the
write handlers call the stricter predicate — the bug was never in the predicate, it was in which
one got called.

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
- Do not alter existing table structures — add new tables/columns only
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

- [ ] **Shared-email remaining case** — `users.email ≠ guardians.email` loses the parent role; needs Phase 2 `user_guardians` link table (the read-side fixes in the 3 legacy files are DONE, verified 2026-07-06)
- [ ] **Portal status is still inferred from a shared email** — same missing `user_guardians` table
      as above, failing in the opposite direction: any account sharing a guardian's email answers
      for them, so the Crew page reports invites nobody sent. Migration 056 removed the bad data
      but not the inference. Full explanation and the cheap interim fix are in Roles & Permissions.
- [ ] **`legacy/medical-gateway.php` writes are still gated on the READ predicate** — its
      POST/PUT/DELETE use `userCanAccessAthlete`, so a guardian can write and delete their own
      child's health record. Left deliberately when the athlete/guardian gateways were tightened
      on 2026-07-30: unlike `date_of_birth`, a parent genuinely IS the authoritative source for
      their child's allergies and medications, so this may be correct product behavior rather
      than a hole. Decide it explicitly — there is no parent-facing UI for it today
      (`MedicalInfoPage` is read-only), so it is currently capability without a purpose.
- [ ] **"Gets Comms" checkbox does nothing** (found 2026-07-29) — `GuardianManagement.tsx` binds it to `receives_communications`; no column exists and no live backend writes it. Either add the column and honor it at send time, or remove the control.
- [ ] Migration files for the ad-hoc comms tables (`communication_log`, `email_events`, `email_links`, `email_templates`, `email_suppressions`, `broadcast_campaigns`) — currently exist in Neon but not in `/database/migrations/`. Schema-migration debt, not blocking.
- [ ] Unit tests for email service, SMS service, permission scoping (status unknown — verify before writing duplicates)
- [ ] **Broadcast SMS — scheduled sends (Workstream C)**, plan in `docs/broadcast-sms-scope.md`.
      Blocked on **migration 057**: `broadcast_campaigns` has no `body`/`html_body` column, so a
      scheduled campaign stores everything about the send except what to say (the SMS body survives
      only as `name`, truncated to 80 chars). A dispatcher alone cannot fix that. Dispatch should be
      a throttled tick inside the already-running `workers/queue-worker.php` — **not** a new
      scheduler process, which would hit the same cost wall that keeps `calendar-sync-scheduler`
      and `waitlist-expiry-scheduler` switched off. The 400 guard in `handleSendBroadcast` stays
      until that ships.
- [ ] **Staff phone number on profile (Workstream D)** — roadmap P0 #1. `users.phone` exists but is
      rarely populated, so the coach branches of `resolveBroadcastRecipients` resolve near-empty.
      No migration needed; normalize through `te_normalize_sms_phone` on save.
- [ ] **Silent-failure bugs found in code verification 2026-07-06** (UI promises, backend doesn't deliver — see `TeamsElevated-Product-Roadmap.docx` Tier 1): scheduled broadcasts never dispatch; tryout "send offers" sends no notifications; invoice/receipt/reminder emails are demo stubs and registration confirmation is commented out; email signatures stored but never appended; facility contacts dropped on save; unsubscribe scope ignored at send time; volunteer direct-assignment bypasses background-check block

---

## Helpful Context

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
