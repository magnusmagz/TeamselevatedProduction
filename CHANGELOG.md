# Production Changelog

What actually reached production, when, and what state it left behind.

## What belongs here (and what doesn't)

Git already records what code changed, and the commit messages in this repo are good. This file
exists for the things git does **not** capture:

- **Migrations applied to Neon.** They're applied by hand, so a committed `.sql` file is not
  evidence it ran. Record the date it was actually applied.
- **Ad-hoc data fixes.** UPDATEs and backfills run against prod that have no commit at all, or
  whose commit doesn't convey the blast radius. Note the row count and where the backup went.
- **Deploy coordinates.** Heroku release number and Netlify deploy, so "is this live?" is
  answerable without archaeology.
- **Prod state discovered.** "The importer never set club_id, 3 clubs affected" — findings that
  explain why later data looks the way it does.

**Not here:** durable lessons and invariants. Those go in `../CLAUDE.md`, which is loaded into
every session. The split is:

> **CLAUDE.md holds the lesson. CHANGELOG.md holds the event.**

If knowing it changes what you *do* next time (never send per-event ICS about a series member;
don't trust the schema prose over the fixture), it's a CLAUDE.md invariant. If it's a record that
something *happened* on a date, it's an entry here. When an entry has a durable lesson attached,
link to the CLAUDE.md section rather than restating it.

Newest first. Times are Pacific.

---

## 2026-07-30

### Crew can edit their athlete's jersey size in the parent portal
`aa41ee4` (merged as `3d2c7b1`) · Heroku **v447** · Netlify build of `3d2c7b1` · **no migration**

Uses `athletes.jersey_size` as shipped by migrations 054/055 on 2026-07-29 — nothing new in the
schema, so there was nothing to apply to Neon.

Deployed **backend first**, which is the opposite of the usual ordering note in CLAUDE.md and
deliberate: the new Uniform card calls a brand-new endpoint, so a frontend-first deploy would have
put a Save button in front of families that 404s until Heroku catches up. The CLAUDE.md rule
(frontend first) is about *tightening* auth on an existing contract; this is the inverse case — new
UI depending on new backend.

**The Heroku push carried 3 commits that were not ours.** `main` was 5 ahead of `heroku/main` and
only 2 of those were the jersey work — the broadcast-SMS session had pushed to `origin` (so Netlify
built their `BroadcastCompose` UI) but never to Heroku. Their UI had been live in prod against an
undeployed backend; v447 is what actually made it work. Confirmed with Maggie before pushing.
This is the shared-`main` hazard CLAUDE.md describes, observed rather than theorized: check
`git rev-list --left-right --count main...heroku/main` before deploying, not just `origin`.

**Prod state discovered:** `heroku/main` can sit well behind `origin/main` for hours. "It's on
origin" says Netlify built it; it says nothing about the backend.

Post-deploy check: `GET /api/athlete-jersey-size.php` answers 401 unauthenticated and 401 on a
bogus token.

### Email Reporting tiles were empty while metrics flowed in fine
commit pending · backend-only

Reported as "metrics boxes are empty even though email metrics are reporting into the app." The
data was never the problem — club 51 had 132 sends, 129 delivered, 30 opens, 1 click, and
`email_events` held 182 rows.

Three separate defects on the read path:

- **Response shape mismatch.** `handleOverview` returned counters flat at the top level
  (`delivered`, `opened`, `clicked`); `EmailReporting.tsx` reads `data.stats` and expects `total_*`,
  `delivery_rate`, `total_pending` and four `prev_*` values. `data.stats` was always `undefined`, so
  `overview` stayed null and **every tile rendered the empty state**. No error surfaced anywhere.
- **Phantom column.** The team filter emitted `AND cl.team_id = ?`. `communication_log` has no
  `team_id` — selecting any team was a hard SQL error that 500'd the overview. Now reached through
  `athlete_id` / `athlete_guardians` → `team_members` via EXISTS (a JOIN would multiply log rows and
  inflate every count).
- **Missing action.** The frontend has always called `action=teams`; the gateway had no such case
  and answered `400 Unknown action`, so the team dropdown was permanently empty.

Also added, because the interface asked for them and the backend never supplied them:
`delivery_rate`, `total_pending` (queued rows, previously excluded wholesale by a blanket
`status != 'queued'`), and period-over-period `prev_*` values computed over the preceding window of
equal length so the trend arrows compare like with like.

**Guard:** `tests/php/AnalyticsOverviewContractTest.php` parses the `OverviewStats` interface out of
the .tsx and asserts the gateway supplies every field, plus that the payload is nested under `stats`
and that `cl.team_id` never returns. Verified against live Neon: club 51 now reports 132 sent /
97.7% delivery / 22.7% open.

**Note on the numbers:** delivery rate counts only rows the SendGrid webhook confirmed as
`delivered`. Rows stuck at `sent` (226 platform-wide) were accepted by SendGrid but never got a
delivery callback, so club-wide delivery rates can read lower than reality. Worth a look separately.

### Cleared default passwords off auto-created athlete accounts
**Heroku v444** · commit `93e8e5d` · **migration 056 applied to Neon**

Found while investigating a Central Kansas (club 51) report: the Crew page showed guardians as
having accepted a portal invite that was never sent.

- **Code:** `te_create_athlete()` (`lib/athlete_writes.php`) no longer writes `password_hash`. It
  had defaulted to `password_hash('defaultpass')` — a constant in the source — making every
  athlete-with-an-email a live, loginable account. A youth athlete's form email is the *parent's*,
  so the credential sat on a real adult's address.
- **Data:** migration 056 nulled `password_hash` on **31 rows** (`role='player'`), guarded by
  `last_login_at IS NULL`. 19 used `defaultpass` (14 of them Central Kansas parents' addresses),
  12 were seeded `@student.com` demo rows using `password`. Verified by running `password_verify`
  against the live hashes.
- **Exposure:** none observed. All 31 had `last_login_at` NULL — no one ever signed in with one.
- **Backup:** `../backups/weak-player-passwords-backup-2026-07-30.csv` (31 rows, hashes included).
- **Side effect:** club 51 Crew went from 18 "active" to 4. The 14 phantoms now correctly read
  `not_invited` and are genuinely awaiting an invite.
- **Guard:** `tests/php/AthleteUserNoDefaultPasswordTest.php`, verified to fail on reintroduction.
- **Lesson (in CLAUDE.md):** Roles & Permissions → "Crew / parent-portal status is inferred, not
  recorded." The email-match inference that produced the wrong display is **not** fixed — it was
  only starved of bad data. Two coach accounts still read `active` without a crew invite.

### Event merge tags: repointed at `calendar_events`
**Heroku v443** · commit `e900200`

`MergeFieldService` queried `FROM events`, a table that no longer exists, so all five advertised
`{{event_*}}` tags resolved to nothing in production. Now reads `calendar_events` (`name` /
`event_date` + `start_time` / `type`), resolves the venue through `venue_id` with a fallback to the
free-text `location` column, and gets the team from `calendar_event_teams` (an event can have
several — lowest `team_id` wins, deterministically). `{{event_venue_name}}` and `{{event_address}}`
were added alongside `{{event_location}}` because the seeded copy says "Venue: X / Address: Y" and
the combined string repeated the venue name. Verified against real Neon rows for both the venue and
no-venue cases.

**`MergeFieldServiceTest` is how this survived for months**: its SQLite fixture did
`CREATE TABLE events` with the pre-calendar columns, so the suite stayed green against a schema
production doesn't have. Fixture rebuilt to the live shape. The durable rule — a fixture that
doesn't mirror `tests/fixtures/production-schema.json` is worse than no fixture — is in CLAUDE.md
under "How the phantom columns got there."

### Template merge-tag audit — 67 of 142 templates were unsendable
**Heroku v445** · commit `1d20b28`

The send-time unresolved-tag guard was 422'ing whole sends because the seeded `[Youth]` library used
camelCase tags the resolver never knew: `{{recipientName}}` `{{playerName}}` `{{coachName}}`
`{{teamName}}` `{{eventDate}}` `{{eventTime}}` `{{eventLocation}}` `{{venueName}}` — **456 tags
rewritten across 61 templates**. A further 59 templates had `design_json` but no `html_output` at
all and rendered blank; those now render.

Separately, the tournament waitlist templates used `{{accept_url}}` / `{{decline_url}}` /
`{{offer_expires_at}}` / `{{division_gender}}` / `{{venue_name}}`, which `WaitlistService` builds
into the merge context but `resolveVariables()` never substituted — it only replaces keys its own
loaders return. Those emails mailed a raw `{{accept_url}}` where the accept button should have
been. Now handled by `MergeFieldService::CONTEXT_PASSTHROUGH_KEYS`, an explicit whitelist so a
stray context key (ids, tokens) can never become a substitutable tag. `{{unsubscribe_link}}` is
filled by `EmailSendService::processHtml()`, where the signed token exists, and is exempt from the
guard.

To re-audit: `psql` against `email_templates` plus the tag audit script. The authoritative key list
is `MergeFieldService::getAvailableFields()` + `CONTEXT_PASSTHROUGH_KEYS`.

---

## 2026-07-29

### Household recipient combining + `{{recipient_first_name}}`
**Heroku v430** · commits `1cff646`, `7998958`

Two guardian rows sharing an email now receive ONE email addressed to both ("Hi John & Jane") via
`lib/NameFormatter.php`. Added `{{recipient_first_name}}` and a send-time guard that blocks any
send containing unresolved `{{tags}}` — that guard is what later surfaced the merge-tag audit above.

---

## Earlier

Entries before 2026-07-29 predate this file. The following were verified built and in production
during a code sweep on 2026-07-06 and are listed so nobody rebuilds them; exact ship dates were not
recorded at the time.

- **Email & SMS communications, end to end** — club-admin and coach-scoped sending with server-side
  permission enforcement, Gmail-style typeahead recipient selection with chips and group
  include/exclude, unsubscribed contacts flagged and excluded, Redis-queued async sends, full
  `communication_log` status tracking, Communications tab on the contact profile, unsubscribe
  landing page and token flow with preferences enforced on later sends, Twilio STOP sync,
  per-email performance reports, summary reporting dashboard, SendGrid and Twilio webhooks.
- **Email template library** with Unlayer editor, event variables and preview mode.
- **Data importer** — `api/imports-gateway.php`, the per-entity import strategies, migration 017,
  Redis `import_queue` consumed by `workers/queue-worker.php`, `DataImport.tsx`.
- **Recurring calendar events + RRULE series invites** (2026-07-06, migrations 045/046) — design
  and invariants in `../CALENDAR-RECURRING-EVENTS.md`.
- **Stripe payments** (2026-07-06) — Phases 0–4 plus audit trail and treasurer reporting. Master
  record is the `project-payment-processor-decision` auto-memory; read it before any payments work.

For anything else, `git log` and `heroku releases -a teamselevated-backend` map commits to versions
back through the whole project. Migrations 001–055 are all applied to Neon per CLAUDE.md's
parallel-session section, but individual application dates were not recorded.
