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

## 2026-09-06

### Lineup builder (8.5 / R67) — Heroku v612, migration 096 applied, then Netlify
- `lib/lineups.php`, `api/lineups.php`, 14 formation presets mirrored PHP↔TS. Coach screen at
  `/teams/:id/lineup?event=`, print view, "Lineup" link on game event modals, Lineups card on
  the team page. Template lineup = NULL-event row. Parents see a published lineup only
  (slots + bench names) on the portal schedule page. Built to decisions 16–19 recommendations.

### Access controls moved to Club Settings → Users (Heroku v611, then Netlify)
- Maggie: the Coaches page keeps Edit / View schedule / View teams only. Invite / Resend /
  Send login link / Set password now sit on the Users tab for club_admin, coach, treasurer,
  volunteer rows; parent/player rows point to Crew. Invite copy is role-aware.
- ⚠️ `club-users-gateway.php` GET was gated on `canAccessClub()` — any parent could list every
  staff name and email in their club. Now `te_is_club_admin()`; `ClubUsersGatewayTest`.

### Staff access controls moved to Club Settings → Users; club-users GET tightened (branch `feature/coach-access`, backend first)
- Invite / Resend invite / Send login link / Set password now render on the Users tab
  (`ClubUserManagement.tsx`) for club_admin / coach / treasurer / volunteer rows, with a
  Status column; parent/player rows read "Managed on Crew". Removed from the Coaches page,
  whose rows now show exactly Edit / View Schedule / View Teams in both tables (Maggie).
- `api/coach-access.php` accepts any active unrevoked staff role (`TE_STAFF_INVITE_ROLES`);
  parent/player → 422 `not_staff`. Invite email is role-aware: "Set up your {club} account" /
  "You're invited to join {club} as {Club Admin|Coach|Treasurer|Volunteer}". Suffix stays
  `:coach_invite`.
- ⚠️ Security: `api/club-users-gateway.php` GET gated on `canAccessClub()` — a parent could
  list every staff member's name and email. Now `te_is_club_admin()`, matching PUT/DELETE
  (`ClubUsersGatewayTest`). The GET response gains portal-status fields.

### Coach access controls on the Coaches page (Heroku v609, migration 097 applied, then Netlify)
- Per-coach context button: Invite / Resend invite / Send login link (24 h), and a "Set password"
  modal that shows the password once and spends any outstanding invite. `api/coach-access.php`,
  `lib/coach_access.php`; gated on `te_is_club_admin()` of the coach's club; audited
  (`coach_invite_sent/_resent`, `portal_login_link_sent`, `password_set_by_admin` — never the
  password). `users.password_set_by_admin_at` (097) drives a dismissible dashboard banner until
  the coach changes it. No forced change (Maggie: accepted B2B tradeoff).

### GOTR G6 — onboarding at scale (Heroku v606, migration 094 applied, then Netlify), dark
- v605: `TE_FEATURE_COACH_INVITE_EMAIL=off`, `TE_FEATURE_NATIONAL_IMPORT=off` set BEFORE the push.
- v608: `TE_FEATURE_COACH_INVITE_EMAIL=on` — Maggie: no flag needed, make it live. Add Coach and
  imports now email the invite.
- Coach creation no longer seeds `password123`: `legacy/coaches-gateway.php` and the import
  create a passwordless account plus a single-use 7-day `:coach_invite` token
  (`lib/coach_invite.php`, `api/coach-invite.php`, `/accept-coach-invite`). ⚠️ With the email
  switch OFF a newly created coach gets no invite; they can still sign in via the login page's
  magic link. Existing ~13 never-signed-in coach hashes NOT cleared (Maggie's call).
- `services/NationalCoachImportStrategy.php` (`council_code` → `org_units.external_code`),
  `ImportJobProcessor` streams rows; `api/imports-gateway.php` gates national uploads on org_admin
  standing. `import_jobs.org_unit_id` (094).
- Invite sends queue as `coach_invite` jobs on `email_queue` under the existing rate limiter.
  **Worker bug fixed on the way:** two consecutive `pop()` calls discarded the first job taken.
- `api/onboarding-funnel.php` + `/organizations/:id/onboarding` (created / invited / accepted /
  signed in / compliant per council). Fixture +1 column.

### GOTR G5 — division/national compliance rollups (Heroku, then Netlify), dark
- `api/compliance-rollup.php` (`?view=units|summary|trend|club`), `lib/compliance_rollup.php`
  (one CTE over `te_org_descendant_club_ids_sql`), `compliance-export.php?org_unit_id=`
  (Council column first). Gated on `te_user_org_standing()`; sibling division → 403; no
  writes. Frontend `/organizations/:id/compliance`. Behind `TE_FEATURE_COMPLIANCE` (off).
  No migration.

### Referee feedback (8.6 / R68) — Heroku v602, migration 095 applied, then Netlify
- Coaches rate the referee(s) of a past game from the event modal (name, 1–5, categories,
  comments, incident flag); club admins review, filter and export at `/referee-feedback`.
  `lib/referee_feedback.php`, `api/referee-feedback.php`. Writes gate on
  `te_event_staff_standing()`; list/export on `te_is_club_admin()`; families see nothing.
- 095 applied via `scripts/apply-migration.php`; fixture +14 lines (one table); both
  PENDING entries removed.

## 2026-09-03

### Parent-invite redemption writes the user_guardians link (Heroku) — decision 13
- Approved by Maggie. One guarded call to `te_link_guardian_on_accept` after the commit in
  `handleSetParentPassword`; the only edit to `api/auth-gateway.php` outside the G2 exception.
  Invited families now get a recorded link instead of relying on the email match.

### Treasurer finished as a money-only role (Heroku, then Netlify)
- Decision: keep the role rather than fold it into club admin (least privilege for a
  volunteer parent). `te_is_financial_admin()` admits club_admin OR treasurer for all nine
  `te_assert_financial_admin` endpoints; `payment-reports.php` shares it. Treasurer added
  to `TE_INVITABLE_ROLES` and the Invite form. `TreasurerScopeTest` pins both halves.
  Closes decision 2 (Revenue tile "Unavailable").

### Queued work shipped (Heroku v592, then Netlify) — migrations 089, 091, 092, 093 applied
- v591: `TE_FEATURE_COMPLIANCE=off`, `TE_FEATURE_COMPLIANCE_REMINDERS=off` set BEFORE the
  push (unset means on). Compliance ships dark.
- v592: G2 worker lane (`worker_sends` at 0 dynos, tick locks, rate limiter), G2 lists
  (keyset pagination, set-based scope subqueries), G3 compliance model + G4 compliance UI
  backend, 2.5 rich email signatures with the plain-text branch now escaped (was a live
  injection — any markup typed into a profile signature shipped as HTML to families).
- Migrations 089 `scale_indexes`, 091 `compliance`, 092 `user_email_signature_format`,
  093 `compliance_default_reminder_stream` applied via `scripts/apply-migration.php`
  (`migration_applied` audit rows). Fixture refreshed: +66 lines, the four compliance
  tables and `users.email_signature_format`. Both PENDING blocks emptied.
- Then `git push origin main` → Netlify ships the signature editor and compliance UI.

## 2026-09-02

### Documents audit — prod state discovered

Migration 032 (`documents`, `document_assignments`, `document_acknowledgments`) and the archive
of `club_documents` are applied to Neon (present in the fixture) with no entry here; date not
recorded. `033_archive_club_documents.sql` (untracked) and `036_archive_club_documents.sql`
(tracked, self-labelled 035) are the same file. `document_acknowledgments` has never been written
to. Files uploaded through the Club Document Center land on the dyno's local disk and do not
survive a restart; rows created with the Upload tab hold dead absolute URLs. Decisions 14, 15.


### G1 organisation tiers, dark (Heroku v586, then Netlify) — migration 090 applied; v584 worker lanes

`org_units`, `club_profile.org_unit_id`, `user_org_access` applied via the script; fixture
refreshed. Nothing reads them yet except the new super-admin Organizations page. (v584 and v585
were branch-worktree pushes carrying docs only — the queue-worker lane was NOT in them; see the
next backend deploy.)

### No primary guardian (Heroku v583, then Netlify)

Maggie's rule, reaffirmed today: guardians are equal. `athlete_guardians.is_primary` stays in the
table, unread and unwritten. Every writer stopped; eleven billing-contact joins on the flag
(invoices, roster fee status, outstanding balances, transaction report, payment receipts,
reminders and failure notices) would have gone silently blank and now take the first crew member
by link id; the athlete list returns a `guardians` array and shows Crew; every crew member is
billable. Decision 1 (five broken flags) is moot. R78 is superseded, not fixed.

### Phase 6 — one age rule, tryout narrowing, field size (Heroku v582, then Netlify) — migration 088 applied

Decision: the age matrix runs 1 August to 31 July. `lib/age_rule.php` + a shared 18-case fixture
run by both PHP and TypeScript. AgeEligibilityService now rolls the season year on Aug 1 — a
tournament starting 15 Aug 2026 is season 2027, so a player born 2016 is no longer U10-eligible
for it (the staff app already said U11). Coaches' tryout lists narrow to their age groups with a
visible toggle. `fields.field_size` applied via the script; pickers group fields by fit and
warn, never block. Also v579 tryout-create admin-only, v580 evaluator from token + RSVP link
60-day expiry + stale venues PUT refused, v581 documents upload folder refused.

### Documents areas made coherent (Netlify `eb955b0` first, then Heroku v578)

From the audit: `expiring` was readable by any club member; `for-target` by any member for any
target; a club-wide document by any signed-in user of any club; assignment targets were never
checked against the document's club; the coach/volunteer picker called a non-existent gateway
action and silently never rendered; three routes lacked guards. All fixed; dead document code
in the unbuilt root `src/` tree and `AthleteProfile.tsx` removed. Lint ceiling 50 → 48.
Storage (decision 14) and the empty signing table (decision 15) are unchanged.

### Mid-year evaluations + coach-invited player (Heroku v577, then Netlify) — migrations 086, 087 applied

Both applied via `scripts/apply-migration.php`; fixture refreshed (three tables). Staff athlete
profile gains a Performance tab (evaluations, IDP goals, season trend); parents see it
read-only. Tryouts gain "Invite to my team" with an admin Coach invites tab; its email is behind
`TE_FEATURE_TRYOUT_COACH_INVITE_EMAIL` = **off**. (v575/v576 were branch-worktree pushes
carrying docs only.)

### Assign coaches to programs (Heroku v574, then Netlify) — migration 085 applied

`program_staff` applied 15:1x PT via `scripts/apply-migration.php`; fixture refreshed (diff = one
table). Admins assign coaches to a program from the program page; an assigned coach sees the
program's events on their upcoming calendar and can reach its registrant families in compose
("All families in <program>"). CKU R66. Nothing narrows; every widened read is additive.

### Links written at source (Heroku v573; v572 carried nothing new)

Registration and shareable-invite accept now write `user_guardians` (exact-one-guardian rule;
shared household addresses are left for the Crew tool). Parent-invite redemption still does not
— it lives in `auth-gateway.php`; decisions doc item 13.

### Crew: connect a stuck account to its family (Heroku v571, then Netlify)

`api/crew-link.php` + `CrewAccountLinkPanel` on the Crew page (club admins only, hidden when
nothing needs repair). Lists parent-role accounts that resolve to no guardian, suggests crew
records by name, writes `user_guardians` (source `admin_link`, audited, trigger-attributed) and
reports how many athletes the family can now see. Backend pushed first — new endpoint.
(v570 was the changelog alone; a branch-worktree push.)

### Chat server on the same identity rule (chat v26, Heroku v569)

`chat-server/lib/guardian_identity.js` ports `te_guardian_link_sql()`; team scope, the DM
boundary and the participant picker use it, and `lib/chat_notification_scope.php` mirrors it
so the notification audience equals the chat scope. Fixed on the way: blank guardian emails
matched each other (`'' = ''`), so an account with no address was in the notification audience
for every one of the 24 blank-email families' team chats. Deployed together: backend push +
chat subtree push.

### Guardian identity resolver + parent-portal empty state (Heroku v568, Netlify `977dc0d`)

Parent standing now resolves through `lib/guardian_identity.php` (user_guardians links UNION
the email match) at sixteen PHP sites — six more than the identity plan listed, four of them
still case-sensitive after the 08-18 sweep (documents-gateway hid a parent's own child's
documents on one capital letter). Strictly wider: no account can have lost access. A family
whose account resolves to no athletes now sees "No athletes connected yet" with the email they
signed in with, instead of a blank portal. Chat server and the admin connect tool follow.

### Scheduled broadcasts dispatch, dark (Heroku v567) — migration 083 applied

`broadcast_campaigns.body / html_body / event_id / failure_reason` applied 14:09 PT via
`scripts/apply-migration.php`; fixture refreshed (diff = the four columns). Dispatcher tick
in the queue worker, switch `TE_FEATURE_SCHEDULED_DISPATCH` **off** (v566). While off,
"schedule for later" still returns the 400 it always did. Flip on after a staged test:
schedule one campaign to yourself two minutes out and watch the worker log. Also Netlify
`61f60f5`: checkout page no longer blanks when the plans answer has no array.

### Phase 2 sends shipped DARK (Heroku v564; switches set OFF in v563)

The demo stubs now send for real, behind config-var kill switches, all three **off** in prod:
`TE_FEATURE_TRANSACTIONAL_EMAIL` (invoice, receipt, reminder, failure notice),
`TE_FEATURE_REGISTRATION_CONFIRMATION`, `TE_FEATURE_TRYOUT_OFFER_EMAIL`. While off, each
endpoint answers `sent:false, feature_disabled:<NAME>` instead of the old fake success.
Flip one with `heroku config:set -a teamselevated-backend TE_FEATURE_<NAME>=on` (no deploy);
unset also means on. Recommended order: REGISTRATION_CONFIRMATION first (one family, one
email), then TRANSACTIONAL_EMAIL, then TRYOUT_OFFER_EMAIL before CKU's next offer batch.
Also closed: payment-receipt `get`/`email` and payment-failures `notify`/`resolve`/`retry` took
any transaction id with no access check. Smoke 103/103, PHP suite 1029.

### Three more open reads closed (Heroku v561) + RSVP test coverage

The new smoke-test route walk found, on its first run: `GET /api/athletes` (329 athletes,
every club, with primary guardian email + mobile), `/api/coach/players/search` (20 athletes
with DOB per query) and `/api/seasons` answering 200 with no token. Reached by directory
shadowing, not the route table — lesson in CLAUDE.md. Gated and scoped, verified 401 after
deploy. Also: 25 RSVP phpunit tests (three defects pinned as KNOWN_DEFECT, on the
decisions doc as item 11) and the reply parser's email match is now LOWER() both sides.

### Home overview + evaluations sort shipped (Netlify `2ce4368`, frontend only, Heroku stays v560)

- **R88** `/dashboard` is now a four-tile overview (Teams / Athletes / Programs / Revenue;
  coaches get three). Teams moved to `/teams`; `/` → `/dashboard` and `ParentRedirect`
  unchanged. Known: a treasurer sees Revenue as "Unavailable" — `revenue-summary.php` gates
  on club_admin while `financial-permissions` grants treasurers `can_view_revenue`. Decision
  pending, not a regression.
- **R85** tryout Evaluations tab: sort + status + age-group filters. Also fixed: an athlete
  with zero evaluations showed "View/Edit" instead of "Evaluate" (`"0"` is truthy).

### Phase 3 CKU quick wins shipped (Heroku v560, Netlify `056fb3e`) — migration 084 applied

Merged `fix/cku-quick-wins`, frontend first. **Migration 084** (`programs.sort_order`,
`archived_at`, `archived_by` + index) applied 13:06 PT through the new
`scripts/apply-migration.php` (writes a `migration_applied` audit row); schema fixture
refreshed, diff = the three columns. Smoke test 75/75, PHP suite 912.

- **R78 primary guardian sticks.** Cause was `AthleteForm.tsx` writing primary by list
  position on every save. Existing athletes with two primary links are untouched —
  `scripts/report-duplicate-primaries.php` lists them (read-only) for a decision.
  ⚠️ **Superseded the same week:** on 2026-09-02 Maggie reaffirmed that there is no
  primary parent/guardian in Teams Elevated — guardians are equal — so the concept was
  removed end to end rather than repaired, and that script was deleted. The two primary
  links those athletes carry are now data nobody reads.
- **R89–R91 programs**: reorder (move up/down), archive without deleting, collapsible
  type sections. Admin-only writes, audited.
- Lint ratchet 74 → 50.

### Phase 0 security sweep shipped (Heroku v559, Netlify `71acc12`)

Eight slices from `docs/roadmap-execution-plan-2026-09.md` Phase 0, merged from
`fix/security-phase0`, frontend first (the tryouts UI sent no bearer token before this).
Lessons are in CLAUDE.md under "JWT::decode() is NOT an auth gate". What was open in prod
until 12:51 PT today, each verified 401 afterwards:

- Forged-signature tokens passed `invitations-gateway`, `user-profile`, `coach-notes`.
- Any signed-in user could mint a club_admin invitation link for any club.
- `/api/teams*` (15 routes) had no auth; volunteer assignment took the background-check
  status from the request body.
- `registrations-api` GET returned every family's form data by program id; PUT/DELETE open.
- `tryouts-api` — 20 paths open, including registrations with DOB.
- `event-attendance` get/save had no standing check; `rsvp-webhook?action=status` had no
  auth and returned attendee emails.
- `payment-reminders` batch OR-precedence (latent — `payment_reminder_log` was empty).
- Four `CURDATE()` calls (42883 on Postgres) in `models/Team.php`, `SeasonController`.

No migrations. No data changes. `scripts/smoke-test.php` 75/75 after deploy. PHP suite 888.

**Behaviour changes staff may notice:** creating a tryout now requires the club to be in
the active context (400 instead of silently creating it in club 1); a parent who reaches the
staff calendar gets a 403 on Take Attendance instead of the roster.

---

## 2026-08-26

### Chat: own message appeared twice, and as somebody else's (Netlify `cf5c960`)

Found while Maggie tested chat after the notifications deploy. **Pre-existing, not from that
deploy** — verified: the notifications merge changed 8 frontend files, none of them chat, and
the chat send path was last touched 2026-07-30.

`senderId` arrives from the chat server as a **string** (`lib/JWT.php:201` casts the claim),
the client compared it against a `number`, and `"75" === 75` is false. Broke `isOwnMessage`
and the optimistic-message reconciliation together. **No data was affected** — one row per
message throughout; both copies were a rendering artifact.

Fixed frontend-only with a single `sameUser()` predicate at all three comparison sites, plus
a scan test. No Heroku or chat-server deploy. Heroku stays at v513.

### Chat notifications shipped — email + web push (Heroku v513, Netlify 24106c6)

Merged `feature/chat-notifications` to `main` and deployed both halves, frontend
first (the service worker is a frontend change and only reaches people on their
next visit). Peer session `feature/support-ticket-context` held its merge until
this landed.

**Migrations applied to Neon by hand:**
- **073** `chat_notifications` — `chat_notification_state`, `chat_notification_prefs` (2026-08-25)
- **074** `chat_moderation_alerts` — creates `chat_moderation_alert_state` (2026-08-26).
  ⚠️ The filename is not the table name; a peer session reported it missing from the
  schema fixture on that basis and it was not.
- **076** `push_subscriptions` (2026-08-26). Renumbered off 075, which the support-ticketing
  session had claimed within the hour.
- **077** `notification_centre` (2026-08-26) — creates NO table. Two indexes on the existing
  `notifications` table, and widens `chat_notification_state.last_notified_channel` to admit
  `in_app`.

Next free migration number: **078**.

**Heroku config vars set:** `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`,
`VAPID_SUBJECT` (v512). The private key exists only there — it is not in the repo
and not in any Netlify build variable. The public key is served from
`api/push-subscriptions.php?action=vapid-public-key` so no build-time copy can
drift out of sync with it.

**Composer:** added `minishlink/web-push` and `guzzlehttp/guzzle`. Guzzle is not
optional decoration — web-push discovers a PSR-18 client at runtime and there was
none, so it would have failed on the first send rather than at install. Heroku has
no `gmp` or `bcmath`; both are listed by web-push as optional performance extras
only, and the crypto path was verified working without them.

**First production tick, and the number that matters.** The worker booted at
23:09:59 UTC and immediately sent 13 chat digests, 4 high-severity moderation
alerts and 2 weekly digests. Verified against Neon straight after:

> Conversation 75 holds **11 messages**. Exactly **1** was inside the 60-minute
> lookback window, and all 13 recipients were notified about that one message
> only.

That is the whole design working on real data. Team conversations have no
`conversation_participants` rows, so the read watermark falls back to zero — the
naive implementation would have emailed 13 families the entire 11-message history
of a chat most of them had never opened. Nobody was pushed (no subscriptions exist
yet), so all 13 went by email, and 13 in-app rows were written: one per closed
notification, not one per attempt.

**Prod state discovered:** 4 high-severity chat flags were sitting open and
un-alerted at deploy time. Auto-flagging has fired on every message since
moderation shipped 2026-07-30 and nothing had ever told an admin.

**Smoke test:** 75 passed, 0 failed against deployed prod. New endpoints checked
separately — `vapid-public-key` public and configured, and `notifications.php`,
`push-subscriptions.php?action=status` and `chat-moderation.php?action=open-count`
all 401 without a token.

**Also in this deploy:** the queue worker's Neon reconnect fix. The worker had been
dying overnight since at least 2026-07-09 and self-healing only when Heroku cycled
the dyno.

## 2026-08-25

### Roster download (CSV) — new feature, shipped
Requested by Maggie · new `api/roster-export.php` · frontend + backend

Coaches and club admins can download a team's roster from the **Team Detail** page and the
**Manage Roster** page. Two flavours, chosen from one Download control:

| `include=` | Columns |
|---|---|
| `athletes` | Jersey #, Last Name, First Name, Date of Birth, Age, Position, Status |
| `crew` | The above, plus Name / Relationship / Email / Phone per crew member |

- **Caps: 1000 rows, 25 columns** — provisional numbers agreed in the request, all in
  `TE_ROSTER_EXPORT_MAX_ROWS` / `_MAX_COLUMNS`. 25 columns is 4 crew groups. Anything the cap
  drops is reported in the audit row, the `X-Roster-Export-Truncated` response header and the
  UI. Measured against prod at build time: no live team is near either cap.
- **Staff only**, via the new `lib/team_roster_scope.php`. A guardian on the team can see the
  roster on screen and cannot download it. `legacy/team-players-gateway.php` was refactored to
  delegate its edit gate to the same function — no behaviour change, one definition.
- Every download writes an `audit_log` row (`roster_exported`, action target = the team) with
  the flavour and row count. Nothing else is written.
- Pre-existing and unrelated: `SchemaConformanceTest` / `QueriedTablesExistTest` fail on
  `lib/CanvaClient.php` (`canva_integrations` is not in `production-schema.json`) — another
  session's in-flight work, untracked at the time of this commit.

Lesson and invariants: `../CLAUDE.md` → "Roster download is STAFF-gated".

---

## 2026-08-20

### Schedule Practices scheduled everything one day late — fixed, no data change
Reported by CKU · commit `4284e20` · Netlify deploy `ready` 09:15 UTC · frontend only

A coach picked **Tuesdays** in the Schedule Practices button and got **Wednesdays**.
Scheduling the same sessions through the calendar produced the right days, which is what
made it look intermittent — the calendar sends the typed date string and never builds a
`Date`, so it was never exposed.

`PracticeScheduler.tsx` asked `.getDay()` (local) and wrote `.toISOString()` (UTC) in the
same loop. Reproduced exactly with the shipped code:

```
TZ=America/Chicago  ->  label: tuesday   stored: 2026-08-26  (a Wednesday)
TZ=UTC              ->  label: tuesday   stored: 2026-08-25  (a Tuesday)
```

**Prod state — nothing was migrated, on purpose.** The fix only changes date generation in
the browser; stored `event_date` values are untouched. Six club 51 teams show the
fingerprint of a scheduler batch (staggered `created_at`, one POST per practice) followed
by hand edits row by row, hours later:

```
5th-6th Purple              9 practices,  9 edited after   (created 08-19 14:53)
Prek-K Blue                 9 practices,  8 edited after   (created 08-19 14:56)
1st-2nd Tigers             10 practices, 10 edited after   (created 08-17 23:10)
1st-2nd Young Warriors      9 practices,  9 edited after   (created 08-18 14:31)
Prek-K Sharks               8 practices,  7 edited after   (created 08-16 23:44)
Girls Prek-1st Wild Fires   9 practices,  8 edited after   (created 08-17 00:11)
```

Coaches had been repairing this by hand for about a week before it was reported.

⚠️ **Batches created by the scheduler and never edited may still be on the wrong day**, and
the data cannot say what day was intended — `calendar_events` writes no audit rows, so the
original dates are unrecoverable. Needs a coach to confirm, then a manual edit:
Girls Prek-1st Dash (Sun/Mon/Wed), Girls Prek-1st Crushers (Mon), 1st-2nd Tigers' second
Wed batch, Girls 2nd-4th Sunflower Strikers (Fri), 3rd-4th Red (Tue and Thu).
Girls 5th-8th Orange came through the recurring-calendar path (single `created_at` for all
10 rows), not the scheduler, and is unaffected.

Durable lesson and the utils to use are in CLAUDE.md, "A date-only value must be read and
written in the SAME timezone".


### user_guardians created and backfilled — migration 072
`072_user_guardians.sql` applied to Neon (see note) · backfill run 2026-08-20 ·
**176 rows** · actor user 118 · plan in `docs/user-guardians-identity-plan.md`

⚠️ **072's table already existed when this session applied it.** An earlier,
interrupted session had created it and left no CHANGELOG entry, so the file read as
unapplied. The table was empty with no audit rows, and its live structure matches
the committed file exactly (columns, both FKs, the UNIQUE pair, the reverse index).
Re-applying was idempotent — `IF NOT EXISTS` on table and index, `CREATE OR REPLACE`
on the audit function, `DROP TRIGGER IF EXISTS` before the trigger.

Backfill: **176 linked, 6 held, 0 athlete-set mismatches.** The plan predicted 173
and 6 — three more accounts became linkable in the two days between measuring and
running, which is the normal drift of a live club, not a rule change.

Held for a human (shared address, `--apply` writes nothing for these):

```
user 69   eli@teamselevated.com       -> Arturo Alvarez (g210) | Elias Ulvi (g300)
user 223  clovis_2011@hotmail.com     -> Taylor Cox (g351)     | Kyle Cox (g352)
user 238  carmenlynnhawk@gmail.com    -> Carmen Haej (g322)    | Carmen Hawk (g341)
user 254  thejones@example.com        -> John Jones (g246)     | Jane Jones (g247)
user 282  morganbmiles@gmail.com      -> Morgan Powell (g325)  | Zach Powell (g326)
user 284  briannaquinley6@gmail.com   -> Brianna Quinley (g399)| Kevin Quinley (g400)
```

### The five held households linked — 6 rows, not 10
`user_guardians` now holds **182** rows · actor 118 · no deploy, nothing reads it yet

Maggie confirmed the five remaining held accounts are genuine households and asked
for them to be linked. Checking the account holder's NAME against the two guardian
rows changed the answer, and "link both rows" would have been wrong for four of five:

```
223 Taylor Cox      g351 Taylor  | g352 Kyle    (spouse)  both -> same child Rhett
238 Carmen Hawk     g341 Hawk    | g322 Haej    (SAME human, misspelt) -> different children
254 Jane Jones      g247 Jane    | g246 John    (spouse)  both -> same athlete (seed)
282 Morgan Powell   g325 Morgan  | g326 Zach    (spouse)  Hank / NO athletes
284 Brianna Quinley g399 Brianna | g400 Kevin   (spouse)  both -> same child Kai
```

Only **238** is one human holding two guardian rows, and it is the only one needing
both: g322 and g341 reach *different* children (Sloane and Jader), which is the case
the plan warned name-matching would silently drop. The other four are two different
people sharing an address — linking the spouse's row would assert Taylor *is* Kyle,
Morgan *is* Zach, Brianna *is* Kevin, durably and under audit, and it buys nothing:
in all four the spouse's row reaches the same child or none at all.

So the self row for each, plus both of Carmen's: **6 rows, `source='admin_link'`,
`confidence='household'`.** Verified before writing that every account's athlete set
is byte-identical before and after — `{364}`, `{345,356}`, `{271,333}`, `{347}`,
`{397}`. Nobody gained or lost a child.

**Every account the email fallback answers for now has a link row except user 69**
(eli, deliberately never linked). That is the phase 4 precondition met for today's
data — it still needs a re-run immediately before the fallback is retired.

254 is seed data (`@example.com`, never logged in, its club-51 athlete soft-deleted).
Linked anyway so phase 4's divergence log reads zero rather than needing a footnote.

**`eli@` resolved same day: never link.** Maggie — Eli is an employee and the account
is a test one. All five of its athlete links are in clubs 32 and 50 (internal/demo),
none soft-deleted, and no other guardian is attached to any of them, so no real
family's mail routes through that address. Phase 4 removes his derived access to
those five test athletes, as intended. **Five accounts remain held**, all households.

`eli@` was the one that is not a household — a staff address on two guardian rows
reaching four children across four surnames. Surname difference does not identify
it: `carmenlynnhawk@` also spans two surnames and is one family (both athletes are
Hawks). All six keep working unchanged through the email fallback and must be
resolved before phase 4 retires it.

Parent-role accounts with no guardian row — already broken, unchanged by this run,
**8 not the 7 the plan measured**:

```
101-105  seed/demo rows (@email.com, never logged in)
208  Allix Boyce      allix12boyce@yahoo.com     (yahoo login, gmail guardian row)
265  Maddison Mathis  jbaughman1972@yahoo.com    (NEW since the plan was written)
369  Nancy De Santiago nancyberenice124@gnail.com ("gnail" typo)
```

**Nothing reads the table yet**, so this changed no behaviour and needed no deploy.
Verified after the run: 176 rows / 176 distinct users / 176 distinct guardians, 176
`user_guardian_linked` audit rows all attributed to actor 118 rather than NULL, and
a second `--apply` wrote 0 (`ON CONFLICT DO NOTHING`).

⚠️ **Re-run the backfill immediately before phase 4.** Anyone who accepts an invite
between now and phase 3 has no link row and is carried only by the email fallback;
dropping it without a re-run would strand exactly those families.

### Prod state discovered: the worker's Neon connection dies when idle
No deploy · no migration · **UNFIXED as of this entry**

Found while scoping chat notifications. `workers/queue-worker.php:23` opens one PDO
handle at boot and shares it with `EmailSendService`, `SmsSendService`,
`ImportJobProcessor` and `CalendarSyncService` for the dyno's whole life. Neon's
pooler drops idle connections, PDO does not reconnect, and `config/database.php` has
no ping-or-reconnect path, so after a quiet stretch the handle is dead until the dyno
cycles.

Observed at 09:01 UTC: 226 consecutive worker log lines, one per minute, of
`[Worker] import reconciliation error: SQLSTATE[HY000]: General error: 7 no connection to the server`.
The 60s import reconciliation sweep is the only thing that *reports* it; every service
holds the same dead handle, so a job enqueued during a dead window fails three times
over ~8 minutes and lands in `failed_jobs` with no automated recovery.

Redis is **not** the problem and was cleared explicitly: addon
`heroku-redis (redis-graceful-48200)` mini, `REDIS_URL` set, predis vendored, live
`PING` → `PONG`, and all four queues (`email_queue`, `sms_queue`, `import_queue`,
`calendar_sync_queue`) empty with nothing in retry.

`failed_jobs` holds exactly one entry — `2026-07-09T18:01:15+00:00`,
`General error: 7 SSL connection has been closed unexpectedly` — the same class. So
this has already cost one real send.

Masked because Heroku cycles dynos roughly daily, so it self-heals each morning, and
queued email only breaks if someone happens to send inside a dead window. Timeline
fits: worker booted 15:37 on 08-19, last email delivered 19:27, dead by 05:10 on 08-20.
The 189 emails delivered in the preceding 14 days all went out during healthy windows.

Fix agreed but not yet written: a reconnect path in `Database` called at the top of the
worker loop, and resolving `$db` per job instead of sharing the boot-time handle. It is
a prerequisite for chat notifications, whose dispatcher would run on a timer and fail
every night.


### Chat server got the case-insensitive guardian match it was missed by
Heroku `teamselevated-chat` **v19** · no migration · lesson in `CLAUDE.md`

Migration 071 fixed ten case-sensitive `guardians.email = users.email`
comparisons in the PHP tree on 2026-08-18. The chat server is a separate Heroku
app on its own subtree deploy and was not included, so it carried the bug for two
more days. **Three sites**, not the two first counted: `lib/team_scope.js`
(guardian team scope), `lib/participants.js` (DM participant allowlist) and
`server.js:440` (participant picker).

Verified against prod before the fix — **four** accounts have a guardian row
differing from their login by case alone, one more than the three found on 08-18:

| user | login | guardian row | teams before → after |
|---|---|---|---|
| 152 | `maggie+tracey@4msquared.com` | `Maggie+tracey@…` | test athlete, no team |
| 235 | `emilygovier0@gmail.com` | `Emilygovier0@…` | 0 → 1 |
| 253 | `monica.82.mh82@gmail.com` | `Monica.82.mh82@…` | 0 → 1 |
| 370 | `mailrebekah@hotmail.com` | `Mailrebekah@…` | 0 → 2 |

235, 253 and 370 are Central Kansas families (club 51) with children on teams.
All four hold `parent` and only `parent`, so no coach or admin branch rescued
them: guardian-derived team ids resolved empty, giving no team chat and absence
from other people's participant pickers. HTTP 200 throughout.

User 370 (Rebekah Phillips, two children on two teams) appeared **after** the
08-18 investigation — the class is still producing cases, which is expected:
stored emails were deliberately not normalised.

Change is strictly widening, confirmed against prod: distinct (user, team) pairs
reachable through the guardian chain went 194 → 198, exactly the four recovered.
Nobody lost access.

No data was changed. Guarded by `chat-server/__tests__/guardian_email_case.test.js`,
a scan of every `.js` in the app rather than three assertions about three known
constants, confirmed to fail on the pre-fix code.

---

## 2026-08-18

### Guardian email matching made case-insensitive — migration 071
`071_guardian_email_case_index.sql` applied to Neon 2026-08-18 · lesson in `CLAUDE.md`

Two CKU support reports investigated:

- **Emily Govier — real bug, fixed.** guardians `Emilygovier0@gmail.com` vs users
  `emilygovier0@gmail.com`. Ten query sites compared them with case-sensitive `=`, so
  her guardian chain returned nothing and the parent portal was empty despite a valid
  `parent` role and a successful login (2026-08-18 12:19). **Three accounts affected:
  users 152 (maggie+tracey), 235 (Emily Govier), 253 (Monica).** Stored emails were NOT
  normalised — see CLAUDE.md for why.

- **Morgan Powell — no code fault found.** guardian 325 carries a valid email and the
  invite resolver was dry-run against prod in a rolled-back transaction: returns
  `status: invited`, so "Invite to portal" works today (the earlier "no email" was
  before her record was corrected). Her "no emails received" is not a send failure —
  `communication_log` shows two emails to her, both `delivered` by SendGrid
  (2026-07-29 and the Fall practice schedule 2026-08-18 12:15), no
  `email_suppressions` row, and `EMAIL_PROVIDER=sendgrid` is set. Points at her inbox
  (spam/promotions), not the platform. Nothing changed for her.

Note: `invitation_links` holds one `league_admin` row, a role absent from
`user_club_access`'s CHECK constraint and from `TE_INVITABLE_ROLES`. If anyone redeems
that link it will fail at accept time. Pre-existing, untouched.

---

## 2026-08-17

### Parent portal scoped to a user's own children, not a coach's roster
`8bdc8e3` · Netlify `ready` on `8bdc8e38` · **Heroku v503** · no migration

`financial-permissions.php?action=check` now also returns `my_children` /
`my_children_ids` (guardian-derived only). `accessible_athletes` is unchanged and still
serves payments. `ConsentGate`, `useParentAthletes`, `AthleteDetailPage` and
`MedicalInfoPage` read the new list.

**Prod state discovered:** seven coach-parent accounts were being shown their whole
roster in the parent portal, and `ConsentGate` asked them for parental consent over it.
Because `consent.php?action=record` correctly 422s a non-guardian and the gate throws on
the first failure, **the parent portal was unreachable for all seven**. Luis Escamilla
(157) pressed Submit five times on 2026-08-17 20:38–21:37, writing `consent_records`
231–238 — ten rows, all legitimately for his own son (448), five duplicate pairs. Left in
place; they are genuine, just repeated.

Verified post-deploy by minting a token per account against the deployed endpoint: all
eight coach-parents return `my_children` narrower than `accessible_athlete_ids` (Elias
Ulvi 69 identical at 5/5 — he coaches no one). Luis: 1 child vs 20.

⚠️ Roster sizes here were first miscounted from `teams.primary_coach_id`;
`getCoachTeamIds()` also counts `assistant_coach` / `team_manager`, which is what the
endpoint uses. That undercount initially reported Samantha Archer (196) as unaffected.
Corrected in `6f7b479`.

### consent_records 23 and 24 revoked — recorded by a non-guardian
ad-hoc fix, no commit · 2 rows · **not reversible from the app**

Jaia Hanks (user 241) recorded portal consent for **Sebastian Luna (athlete 435)** on
2026-07-31 22:50. His only guardian is Eva Estrada (guardian 443 / user 174). These were
athlete 435's **only** consent rows, so he read as consented on a stranger's click while
his actual mother — who last signed in 2026-08-16 — was never prompted.

`revoked_at` set on both (not deleted; a consent record is evidence). Two
`consent_revoked_wrong_guardian` audit rows written with `user_id` NULL, audit_log
1406–1407. Athlete 435 now has no active consent, so Eva gets the gate on her next
portal visit.

**Cause not determined, and now unknowable.** `AthleteScope::isGuardianOfAthlete` is an
exact email match and shipped 2026-07-29, two days before — so it ran and *passed*,
meaning a `guardians` row carrying `jaiahanks@icloud.com` really was linked to athlete
435 at that moment. That link no longer exists and nothing recorded its removal. Swept
all of `consent_records`: this was the only such case.

### Migration 070 applied — athlete_guardians audit trigger
`070_athlete_guardians_audit.sql` · applied to Neon 2026-08-17 · lesson in `CLAUDE.md`

Trigger on `athlete_guardians` writing `guardian_link_added` / `_removed` / `_changed` to
`audit_log`. Rehearsed in a rolled-back transaction against live Neon: attributed writes
carry `user_id`, hand-run SQL records NULL, rollback left 0 audit rows and athlete 435's
single guardian intact. 426 guardian links exist and none carry an origin — no backfill
is possible.

### Unauthenticated guardian-link routes closed
**no migration** · backend only, no frontend caller existed

`index.php` performs no authentication, and `AthleteController` authenticated in only one
method. Probed against production with no token:
`DELETE /api/athletes/999/guardians/999` → **200**. Athlete 999 does not exist, so nothing
was modified (426 links before and after). `createAthlete`, `addGuardian` and
`removeGuardian` now authenticate; the two guardian methods gate on
`staffCanManageAthlete`.

---

## 2026-08-03

### Parent invite: "used" and "expired" are now different answers
`b963cf2` · Netlify build of `b963cf2` · Heroku push follows it · **no migration**

Direct follow-up to the Mills ticket below — that parent's link was spent, not expired, and the
message could not tell him so. Three fixes:

1. **`handleSetParentPassword` no longer folds `used_at IS NULL AND expires_at > NOW()` into the
   token lookup.** Doing so made not-found / already-used / expired / invalidated-by-re-send
   indistinguishable; all four answered `Invalid or expired link`. Classification moved to
   `lib/parent_invite_token.php` (testable without booting the auth gateway). Used is reported
   ahead of expired when both apply. The response now carries a `reason`, and
   `SetParentPassword.tsx` renders `already_used` in blue with a *Go to sign in* button — it is
   not a failure state.
2. **Ordering bug fixed.** The handler wrote the password keyed on an email string without
   checking the row count, spent the token, and only then looked up the user. For any invite
   address with no `users` row that burned the parent's link on the first attempt and made every
   retry fail for real. Now the account is resolved first, the write and the spend happen together
   in a transaction keyed on `users.id`, and **the token is left unspent when no account exists** —
   nothing was accomplished, so the link must still work once the account is repaired.
3. **The invite email now states the link is single-use**, in both the HTML and plain-text bodies.
   It previously promised only "7 days", which is why the parent checked the one fact he had,
   found it held, and reasonably concluded the system was wrong.

**Touches `api/auth-gateway.php`, which CLAUDE.md lists as do-not-modify.** Maggie asked for these
three specifically. The edit is confined to that one handler; the logic lives in a lib, and
`ParentInviteTokenTest` parses the file to pin both the query shape and the spend-after-resolve
ordering. That source guard was mutation-tested — restoring the old WHERE clause fails it.

Deployed **frontend first** so the "sign in" button exists before the backend starts telling people
to sign in. 547 PHP tests green.

### Mills household — two parents' emails were crossed; ad-hoc prod data fix
No code change. Reported by the parent as "our link has expired"; it had not.

**The report was a misdiagnosis, and so was the error message.** Colton Mills wrote in saying the
set-up link kept saying expired, well inside the 7-day window. The token
(`colton.mills193@gmail.com:parent_invite`) was minted 2026-07-31 22:43, expires 2026-08-07, and
was **used successfully at 2026-08-03 21:55:19** — `users.updated_at` matches `used_at` to the
second. He emailed **four minutes later**. The link is single-use, so his re-click returned
"Invalid or expired link", which is the same string the endpoint returns for genuinely expired,
already used, and invalidated. He read "expired" and reported expiry.

**Root cause: the two parents' email addresses were swapped on their guardian rows** (athlete 409
Ryker Mills, club 51), so each parent's invite went to the other's inbox:

| | before | after |
|---|---|---|
| guardian 412 Colton | `ericapwells@gmail.com` | `colton.mills193@gmail.com` |
| guardian 413 Erica | `colton.mills193@gmail.com` | `ericapwells@gmail.com` |

Colton therefore set a password on the account keyed to *his* address but named *Erica Mills*.
The fix corrects the guardian emails and renames the user rows so the name follows whoever owns
the inbox — `users.272` → Colton (holds the password he set), `users.271` → Erica (still awaiting
setup). **No `users.email` changed**, so `users_email_key` was never in play, and no password moved.
Applied in one transaction behind a `DO` block asserting all four rows matched the reviewed state,
rehearsed as `BEGIN … ROLLBACK` first.

**Phone numbers deliberately NOT touched.** They may be crossed too — 412 and 413 hold different
numbers — but nothing in the data proves which belongs to whom, and a guess is worse than a known
gap. Confirm with the family.

**Prod state discovered:** Ryker has **zero `consent_records`**, so nobody reached the consent gate
that shipped 2026-07-31. The likely sequence is that Colton set his password, landed on the new
full-page blocking consent screen instead of a dashboard, did not read it as success, went back to
the email and clicked again. He is the first real family to hit that screen. Worth watching before
concluding the gate's first-run experience is fine.

**Resolved 2026-08-04.** Erica was re-invited at 00:14:55, set her password at 01:05:37, signed
in at 01:05:58, and completed the consent gate at 01:06:08 (both types, `source=portal`, email
confirmation not yet clicked). Colton's password was set 2026-08-03 21:55 but `last_login_at` is
still null — he has never signed in. Both rows now show `active` on Crew, so the control on each is
*Send login link*, not *Resend*.

**Other suspected crossed pairs, not actioned:** athlete 339 (Leonel Jimenez) has the same shape —
Alejandro Jimenez carrying `Monica.82.mh82@gmail.com` while Monica Hernandez has no email. A proper
scan is still owed before the next bulk invite.

### Parent portal was broken for anyone who is also a coach
Reported by Central Kansas 2026-08-03 (Samantha Archer: "no athletes are
registered to her"). Her data was correct throughout — guardian 426 linked to
athlete 419 (Alia), both roles active. Deployed same day.

`api/financial-permissions.php` joined `team_coaches` and `coaches`; **neither
table exists**, so every request from a user holding a coach role returned HTTP
500 (SQLSTATE 42P01) and the portal rendered "no athletes are registered to
you". The parent branch runs first and had already found Alia — the coach branch
then threw and took the whole response, so being a coach cost her her own child.

Parent-only accounts were unaffected, which is why it survived a 148-family
rollout with no reports. Verified against prod before and after: Samantha and
Jed Phillips (coach+parent) 500 → 200 with 9 athletes; Peter Mendez and Leya
Devora (parent-only) 200 throughout.

Wider than the athlete list: `FinancialPermissionsProvider` is mounted app-wide
and `ConsentGate` / `ProtectedParentRoute` both read it.

Both occurrences (`check`, `check-athlete`) now scope through `getCoachTeamIds()`.

**Prod state discovered:** three more phantom tables, all confirmed absent from
Neon and all currently unreachable — `team_players` in
`calendar-events-gateway`'s send-invite path (whose query also has `u.email !=
""`, an identifier in Postgres, so it would fail twice), and
`insurance_policies` / `athlete_sports` in `athletes-profile.php`, which has no
frontend callers at all. Recorded in `QueriedTablesExistTest::KNOWN_BROKEN`.

### Portal status now reports a first-login date instead of guessing
`lib/portal_status.php` + `frontend/src/utils/portalStatus.ts`, shared by the Crew
page and both Coaches tables. Detail and the three bugs it replaced are in
CLAUDE.md; this is the event log.

**Prod state discovered while measuring it:**
- 373 people tracked; **77 have actually signed in** (21%). Crew invites convert at
  **50%** (126 sent, 63 set a password), median 2h 8m to act. The coach email blast
  converted at **18%** (11 recipients, 2 clicks).
- Central Kansas is the only real rollout: 148 crew, 61 in, 53 through consent, **62
  invited and stalled**, 24 with no email address at all.
- Teams Elevated: 97 of 196 crew were emailed but never invited — nothing to click.
- **64 invites expire 2026-08-07.** Before this change they would have silently
  reverted to "Not invited".
- The kickoff blast published `password123`. See the new CLAUDE.md pending item.

### Two more duplicate guardians merged, and a smoke test that found a 500
Covered in the 2026-07-31 entries below; `scripts/smoke-test.php` and
`scripts/onboarding-funnel.php` are both read-only and safe against prod.

## 2026-07-31

### `/api/teams` had been returning 500 since the move off MySQL — fixed
Found by the new `scripts/smoke-test.php`, not by a report. Heroku v476.

`Team::getTeams` derived its count query with
`str_replace('SELECT t.*', 'SELECT COUNT(*)', $sql)`, which rewrote only the
first line of the select list and left `CONCAT(u.first_name, ...)`, `s.name`
and `f.name` beside an aggregate with no GROUP BY — SQLSTATE 42803, a hard
500 on every call. Introduced 2025-10-02; MySQL accepted it, Postgres does
not. It survived because the main Teams screen uses
`legacy/teams-gateway.php`; the one live caller is
`VolunteerSignupRequests.tsx`, where the team list just came back empty.

Two more MySQL-isms in the same function:
- `ORDER BY t.$sortBy $sortOrder` interpolated `$_GET['sort_by']` and
  `$_GET['sort_order']` straight into the statement. ORDER BY cannot be
  bound, so it now comes from a fixed column list and collapses to exactly
  DESC or ASC.
- `LIKE` is case-insensitive in MySQL and not in Postgres, so searching
  "Thunder" for a team stored as "thunder" silently matched nothing. Now
  ILIKE.

`TeamListQueryTest` pins all three. It **strips comments before asserting**:
the comments in `getTeams` quote the code they replaced, so reading the file
naively made each test fail against its own explanation — and would have
passed again the moment someone deleted the comment.

**Prod state discovered:** a parent can read their club's entire crew roster
(`auth-gateway.php?action=club-parents`) — 196 guardians with emails and
phones in club 32, 148 in club 51. Gated on `canAccessClub`, which is club
membership rather than staff standing. Logged as open work in CLAUDE.md
rather than fixed, because that file is on the do-not-modify list.

### Two more duplicate guardians merged — Central Kansas
No code change. Found via the ambiguous-sender analysis for inbox M5.

`314 Jarref Green` → **381 Jarred Green** (name typo; same email and phone, one on
each of two siblings) and `407 Josh Hill` → **320 Josh Hill** (one row had the
email, the other proper capitalization; one on each of two athletes). Both were
repoints, not link-deletes — each duplicate sat on a DIFFERENT athlete, so the link
moves rather than being dropped. Guarded by a DO block asserting the pair still
shares a phone and that no athlete link would be duplicated.

Guarded on **phone, not email**: the Hill pair had an email on only one row, which
is part of why they duplicated in the first place.

Also normalized `joshua hill` → `Josh Hill`. First names are used in message
personalization, and "Hi joshua" reads as a mistake to the person receiving it.

**These would NOT have been caught by the createOrFindGuardian fix.** That matches
on email + first + last, and here the FIRST NAME differs — Jarref vs Jarred, joshua
vs Josh. A typo anywhere in the identity makes a new person by definition. Catching
these needs same-phone near-duplicate detection, which nothing does.

**Prod state discovered:** ambiguous senders are rarer than the design assumed.
Across the platform only 7 phone numbers are shared by multiple guardians, and in
Central Kansas there were 3 — of which two were these duplicates. Exactly **one**
genuine shared-handset household remains there (Eric Hawk / Carmen Hawk, plus a
suspected third duplicate row `322 Carmen Haej` sharing Carmen's email). So inbox
M5's ambiguity handling covers a much smaller real case than expected.

### SMS inbox M4 — replying from the inbox — **migration 066 applied to Neon**
`066_thread_existing_sms.sql` · Heroku **v475** · Netlify `307cb6a`

An admin can answer a thread and it goes out as a text from the club's own number,
via `SmsSendService::queueSms` — so a reply inherits per-club sender resolution,
the suppression predicate, segment counting, `from_number` and the retry queue.

**The copy did NOT change with it — Maggie's call, and the scope was revised.**
The plan bundled them, reasoning that "this number is not monitored" goes false the
moment a human *can* answer. Shipping a Reply button does not mean anyone is
watching; promising 152 families someone will respond, before the club has agreed
to keep that promise, leaves a family who texts and hears nothing worse off than
one told plainly the number is unmonitored. The auto-reply keeps firing, unchanged,
until a club says they are ready to engage.

**Threading was the real work.** `queueSms` set no `conversation_id`, so a reply
would have started a new thread — and the broadcast that PROMPTED every reply was
unthreaded too, meaning an admin would open "I did not receive an email" with
nothing above it explaining what email. queueSms now threads every outbound
message, and 066 backfilled the existing **140 sms rows into 131 conversations**.
The SQL hash was verified byte-identical to `te_sms_conversation_id()` before
running — a mismatch would have silently created a second thread per contact
rather than failing.

Verified live after deploy: an inbound from Cathy Rice landed under her existing
broadcast thread, showing the 07-30 22:58 announcement, her reply, and the
auto-reply marked automated. Check rows removed; 510 outbound rows and 131 threads
intact.

Two refusals in the endpoint: the recipient is resolved from the THREAD, never the
request body (a client-supplied number would make the club's sender an open relay),
and `queued = 0` returns 409 with the reason rather than reporting a reply that was
never sent — queueSms skips a suppressed contact rather than throwing.

### SMS inbox M3 — the replies are readable in the app
Heroku **v474** · Netlify `6b16165` · **no migration** · `inbox_enabled` switched ON for club 51

`/communications/inbox` — threads, filters, read state. Admin-only, gated on the
per-club flag in `inboxAuthError` rather than only by hiding the nav item.
Central Kansas enabled; club 32 left off.

**Recording the auto-reply was a prerequisite nobody had spotted.** It leaves as
TwiML in the webhook response, so Twilio sends it and nothing here knew it
happened — an admin would have opened a thread, seen a family's question with no
answer, and written a reply contradicting what the family already received.

Machine-vs-human is `user_id`: auto-reply is outbound with NULL, a human reply
carries the admin. That single distinction styles the bubble AND keeps a thread in
"needs reply" after the robot answered. Without it, recording the auto-reply would
have EMPTIED the inbox instead of filling it.

The thread query is Postgres-only (`ARRAY_AGG … FILTER` with array subscripting)
so the SQLite fixtures cannot exercise it. Verified by seeding three realistic
threads in a rolled-back transaction against Neon — robot-answered stayed in the
queue, human-answered left it, outbound-only broadcast excluded — then again
end-to-end after deploy with a real inbound, and the check rows removed.

⚠️ **The auto-reply copy still says "this number is not monitored"**, which is now
only nearly true for club 51: an admin can read but not yet reply. The copy and the
reply capability ship together in M4, deliberately. Revert with
`UPDATE sms_phone_numbers SET inbox_enabled = FALSE WHERE club_profile_id = 51`.

### SMS inbox M2 — STOP is recorded when it arrives — **migration 065 applied to Neon**
`065_sms_suppression_unique.sql` · backend only

Until now the only opt-out sync was REACTIVE: `handleStatusCallback` on Twilio
error 21610, which fires only after a send has already failed against a blocked
number. Observed on 2026-07-30 — a guardian texted `Stop` then `Start` fourteen
seconds later, Twilio blocked and unblocked at the carrier, and both
`email_suppressions` and `guardians.sms_opt_out` stayed empty. Between a STOP and
the next send the broadcast preview counted that family as reachable, and the
eventual failure read as "failed" rather than "opted out".

Now `STOP`/`STOPALL`/`UNSUBSCRIBE`/`CANCEL`/`END`/`QUIT` write the club-scoped
suppression and set the person flag at arrival; `START`/`YES`/`UNSTOP` clear both.
`HELP`/`INFO` do neither. Only the BARE keyword counts — "can we stop by the field
at 6?" is a question about a field.

**Prod state discovered — the existing ON CONFLICT never fired.**
`idx_email_suppressions_unique` covers (club, EMAIL, channel, scope, team) and an
SMS suppression has `email = NULL`, which Postgres treats as distinct. So the
`ON CONFLICT DO NOTHING` in handleStatusCallback silently did nothing for phone
rows, and repeated STOPs would have accumulated duplicates — each counted
separately in the preview's suppressed tally. Nothing had gone wrong only because
there were **zero** SMS suppression rows. Migration 065 adds a partial unique index
on (club, phone, scope, team) WHERE channel='sms', rehearsed by inserting a
duplicate STOP inside a rolled-back transaction and confirming it collapsed to one.

Suppression is club-scoped, which is meaningful now that each club sends from its
own number: a STOP to Kansas's number is a STOP to Kansas.

⚠️ **Known over-block:** `guardians.sms_opt_out` is a single person-level boolean
and cannot express "this club only", so a family in two clubs who stops one is
currently stopped for both. Matches what handleStatusCallback already did, and errs
toward respecting the opt-out; worth revisiting only when a family is actually in
two clubs.

`START` deliberately clears only `twilio_stop` suppressions — a hard bounce or a
manual admin suppression is not something a parent can undo by texting START.

### SMS inbox M1 — inbound replies are recorded — **migration 064 applied to Neon**
`064_sms_inbox_capture.sql` · Heroku **v472** · backend only

Replies used to be answered and thrown away, so the seven real replies to the
2026-07-30 Central Kansas broadcast lived only in Twilio's message log. They are
now written to `communication_log` with `direction='inbound'` and a
`conversation_id`. Nothing reads them yet — M1 of `docs/sms-inbox-scope.md`.

**First migration to alter `communication_log`**, which holds real history (510
rows). Additive and backfill-free: `direction` DEFAULTs to `'outbound'`, so every
existing row became correct without an UPDATE. Numbered 064, not the 060 the scope
doc says — 060-063 were claimed by the chat and consent work while it was written.

**Rehearsed before applying**, using Postgres transactional DDL (BEGIN → migrate →
inspect → ROLLBACK) since there is no Neon CLI or API key here. That rehearsal
caught a defect that would have reached production: `communication_log.user_id` is
NOT NULL **with an FK to users**, and an inbound message has no sending staff
member. Writing 0 threw a constraint violation; naming a club admin would have
credited them with words they did not write. The migration drops the NOT NULL and
inbound rows store NULL.

**Prod state discovered — reporting would have silently inflated.** `status` has no
`received` value, so inbound rows carry `delivered`. All ten analytics queries
counted `status <> 'queued'` as sent, so every reply would have counted as a
message the club sent AND as a successful delivery. They funnel through
`buildCoachScope`, which now adds `AND cl.direction = 'outbound'` in one place.

**A second defect caught by a test, not by review:** Twilio reports E.164
(`+17855550100`, 11 digits) while stored numbers are hand-entered and usually 10.
A whole-string digit comparison never matches, so *every* sender would have
resolved as "unknown". Matching is now on the last 10 digits.

`api/webhooks/twilio-inbound.php` deliberately does NOT use
`Database::getInstance()`: `config/database.php` `die()`s a JSON error on
connection failure, which would emit JSON instead of TwiML and take the auto-reply
down with the database. It connects directly so the failure stays catchable — a
lost log row beats a family getting silence.

Capture is **not** behind `inbox_enabled` (added to `sms_phone_numbers`, default
false). Storing is not monitoring, so the "this number is not monitored" wording
stays true; it changes in M4 when a human can reply.
`SmsAutoReplyTest::testNothingIsStored` retired — it pinned exactly the promise
this milestone sets out to break.

### Merged a duplicate Taylor Cook — ad-hoc prod data fix
No code change. Neon snapshot taken beforehand at Maggie's request.

Guardians **306** and **382** were the same person: identical name, email
(`tcook0921@yahoo.com`) and phone, each attached to one of the two Cook girls —
306 to Calani, 382 to Tiana. Christina Cook, on the same family, was correctly ONE
row linked to both, which points at the importer matching the first guardian on a
family row and duplicating the second.

Merged into 306 inside a single transaction guarded by a `DO` block that aborts
unless the state matches what was reviewed — both rows present, still sharing an
email, duplicate holding exactly one link, and no existing link between keeper and
that athlete (which would have produced a duplicate pair, since
`athlete_guardians` has **no** unique index on `(athlete_id, guardian_id)`).
Repointed the link, moved 1 `communication_log` row so her history stays in one
place, then deleted 382 — by which point it owned nothing, so its `ON DELETE
CASCADE` took nothing with it.

After: Taylor is one row, 2 athlete links, 3 comms rows. Zero orphans.

**Prod state discovered while checking:** 306 carried the `tcook0921@yhaoo.com`
typo (the address that was *rejected* on 2026-07-29) and 382 had it spelled right
(*delivered*). Both read correctly now.

**Maddison Mathis merged too** (same session). Inverse shape: 424 and 467 both sat on the SAME
athlete (Benson Mathis), so the fix deletes the duplicate link rather than repointing it — getting
that backwards would have produced two identical pairs, which nothing prevents since
`athlete_guardians` has no unique index on `(athlete_id, guardian_id)`. Kept **424** for its send
history and inherited what 467 had right: the correctly spelled `jbaughman1972@yahoo.com` (424
carried `jbaugjman1972@` — the address that **bounced** on 2026-07-29) and the `is_primary` flag,
which lives on the LINK, not the guardian. Someone had re-added her with the corrected address
instead of editing the original.

**Still NOT actioned** — Maddison Mathis (424 typo/bounced
vs 467 correct, both on the same athlete, so a merge deletes the duplicate link
rather than repointing it) and Laura Thompson (63 orphaned test row vs 464 real).
**Root cause corrected.** Both pairs were first blamed on guardian matching; the data says
otherwise. Each duplicate had a DIFFERENT email when created — `tcook0921@yhaoo.com` vs `@yahoo.com`,
`jbaugjman1972@` vs `jbaughman1972@` — so matching behaved correctly and a typo simply is a different
identity. The typos were corrected later, which is why the rows looked identical by the time they
were found. No matching rule can see through a misspelled address; catching it needs a same-name
near-duplicate warning at import preview, which does not exist.

### Staff can see who still owes parental consent
`571b736` (merged as `59c88da`) · Heroku deploy of `59c88da` · Netlify build of `59c88da` ·
**no migration** (uses the columns migration 063 added this morning)

Closes the loop opened by the two consent changes earlier today: both surfaces were capturing,
nothing staff-facing was reading. New `api/consent.php?action=summary` plus a sortable,
filterable **Consent** column on the athlete list.

**Deployed backend-first**, inverting the CLAUDE.md default, for the same reason as the
jersey-size endpoint on 2026-07-30: the new column calls a brand-new action, so a frontend-first
deploy would have shown "Unknown" in every row until Heroku caught up. The frontend-first rule is
about *tightening auth on an existing contract*; new UI depending on new backend is the inverse.

**The scoping is the part that mattered.** `summary` uses a new
`AthleteScope::staffManageableAthleteIds`, not `accessibleAthleteIds` — the difference is the
guardian branch. On the wrong predicate a parent hitting the endpoint would receive a report about
their own child instead of nothing, and a coach who is also a parent would see a child from
outside their teams. `accessibleAthleteIds` is now *defined as* the staff half plus the guardian
branch, the same shape as `userCanAccessAthlete` / `staffCanManageAthlete`, so the two cannot
drift. Both cases are tested in `ConsentRollupTest`.

**Not on the Crew page**, which is where it was originally scoped. Consent is per child, so a
guardian row covering three athletes cannot carry one honest badge, and `api/auth-gateway.php`
(which powers Crew) is on the do-not-modify list. Reasoning and the path forward are in CLAUDE.md.

Post-deploy: `action=summary` answers 401 unauthenticated and on a bogus token; `status` and
`record` unchanged. Frontend suite still shows the known 10 pre-existing failing suites / 33
tests — unchanged by this work.

### Email delivery rate: club 32's 2.6% is pre-instrumentation history, not a bug
No code change. Recorded because it looks like a broken webhook and is not.

**The SendGrid webhook works.** It was fixed on 2026-07-28 by `3e9d442` ("Fix tracking + webhook
handlers querying columns that don't exist") — all four engagement handlers selected or inserted
`contact_id`, a column none of those tables have, and threw SQLSTATE 42703 **inside a try/catch that
logged and carried on**. Every endpoint returned a healthy response while recording nothing:
no delivered/open/click/bounce/spam/unsubscribe, no opens, no click timestamps, and **no SMS STOP
suppressions**. The swallowed exception is why it survived months; a hard failure would have been
caught in a week.

Post-fix the pipeline is exact: **135 `delivered` events, 135 rows at status `delivered`.**
Club 51 reads **97.7%** (129/132), club 32 **100%** (6/6).

**What remains is cosmetic and deliberate.** The 226 rows stuck at `sent` are all club 32,
2026-03-21 to 2026-05-21, sent before anything was recorded. SendGrid does not replay webhooks, so
they can never resolve. They sit in the delivery-rate denominator, which is why club 32's dashboard
shows **2.6%** against a real post-fix rate of 100%. Club 51 — the actual customer — has zero
pre-fix rows and reads correctly.

**Decision (Maggie, 2026-07-31): leave it.** It self-heals as volume grows and only distorts the
internal demo club.

⚠️ **Do not backfill those rows to `delivered`.** That fabricates delivery confirmations SendGrid
never sent; the status column's only value is that it reflects what the provider actually confirmed.
Excluding pre-2026-07-28 rows from rate denominators was also considered and rejected — it bakes a
magic date into `analytics-gateway.php` for a demo club's cosmetics.

Unexplained and left alone: **3 `open` events dated March 2026**, before the handler could have
worked. The other 83 opens are post-fix. Three rows, no impact — flagged rather than rationalised.


### Registration consent is recorded now — migration 063 applied to Neon
`b40e5d1` (merged as `cb70159`) · migration **063_consent_source_and_identity.sql** applied
2026-07-31 · Netlify build of `cb70159` · Heroku push follows the Netlify build

**Prod state confirmed before applying: `consent_records` had 0 rows.** Not "few" — zero. The
public registration form has always asked the parent for both COPPA consents and sent
`consent_data_collection` / `consent_medical_data`; `registrations-api.php` never read either
key. Combined with the staff-form theatre fixed on 2026-07-30, nothing anywhere had ever
written a consent record. That is the explanation for an empty table, not data loss.

**Migration 063 relaxes a NOT NULL, which is a documented exception to the add-only rule.**
`consent_records.guardian_id` is `NOT NULL REFERENCES users (id)` and a parent completing the
public form has no account — so consent could only be recorded from people who already had
accounts, i.e. never at the point of collection. Agreed with Maggie before applying. The FK is
intact; identity for account-less consent is carried by the new `guardian_email` /
`guardian_name`, copied in as frozen evidence rather than joined (a join returns *today's*
answer; a consent record must preserve the answer as of the moment).

- Rehearsed in a `BEGIN … ROLLBACK` against live Neon first, then applied. `UPDATE 0` on the
  backfill — consistent with the 0-row finding.
- `tests/fixtures/production-schema.json` regenerated **from Neon**, not hand-edited; diff is
  exactly the 3 new columns (111 tables, unchanged).
- `SchemaConformanceTest` caught the missing columns before deploy, which is precisely what it
  is for — the failure was the guard working, not a problem.

**Both surfaces capture now, deliberately** — see CLAUDE.md. Registration records at sign-up;
`ConsentGate` re-affirms once there is an account to attribute it to. The gate keys on
`source='portal'`, so the second prompt cannot silently vanish for families who signed up online.

**Deployed frontend-first** (the CLAUDE.md default) rather than backend-first: shipping the
backend first would start writing `source='registration'` rows while the old bundle still
treated any consent row as sufficient, briefly letting sign-up consent clear the gate and
skipping the re-affirmation. Frontend-first has no such window.

Registration capture runs in a SAVEPOINT so that a consent failure can never roll back a
family's registration — relevant because `main` is shared and this code could have reached a
dyno before the migration reached Neon.

## 2026-07-30

### Chat moderation — M0 through M4 and M7, shipped in one run
migrations **060, 061, 062** applied to Neon · chat app **v12 → v16** · Heroku **v466, v467** ·
Netlify builds of `a1ca50a` and `da05d5b` · plan and design record in `docs/chat-moderation-plan.md`

Built ahead of its scheduled week at Maggie's direction: run all milestones, guess where a decision
is needed and log it for revisit, no destructive actions. The guesses taken are listed under
"Revisit after launch" in the plan.

- **M0 — `createConversation` validated nothing.** It took `participantIds` from the client and
  inserted them; no club, team or role check, and `canInitiateConversation` includes `parent`. Any
  initiator could open a DM with any user id in any club. Fixed with an allowlist built from
  guardians + club staff. **Prod state discovered:** conversation 52 held user 27
  (`john@nomail.com`), tied to club 32 by neither the guardian chain nor a staff role — the hole had
  been reached. No athlete has ever been a participant.
- **M1 — moderation removal.** Soft delete, tombstone, audit inside the transaction, text never
  copied into `audit_log`. Migration 060.
- **M2 — reports + review queue.** Migration 061; human reports and auto-flags share one table so
  admins get one inbox. `api/chat-moderation.php` reads the queue and closes items but deliberately
  cannot remove anything.
- **M3 — flag-gated admin read + `chat_access_log`.** Migration 062. An open report is what
  authorises opening a conversation; the log is written before the conversation is served, and a
  failed log refuses the read.
- **M4 — auto-flagging.** Flags, never censors. Profanity is the lowest severity; secrecy and
  off-platform contact are the high-value rules.
- **M7 — queue health + compliance summary.** Counts actions, never content.

**NOT built:** the weekly admin digest (needs a tick inside `workers/queue-worker.php`, not a new
scheduler), and M5's notice + ToS (with the attorney; explicitly not a blocker — chat is live to
beta clubs and the business owns that risk tolerance).

**Correction recorded during this work:** an earlier entry and CLAUDE.md cited conversation 18 as a
cross-club DM. It is not — that user is a guardian of a club 32 athlete via the guardian chain, and
their `user_club_access` row (club 25) is a stale secondary role. **A guardian's club is the guardian
chain, not their `user_club_access` row.**

### Parental consent actually gets recorded now — and the portal's Medical page was blank
`3d1f2ba` (merged as `fffbf4c`) · **frontend-only** · no Heroku push, **no migration**

**Prod state discovered — `consent_records` was never being written.** `AthleteForm`'s "Parental
Consent (Required)" block was two checkboxes held in local React state: never POSTed, never stored,
and force-set to `true` whenever anyone edited an existing athlete. They gated that form's submit
button and nothing else. `api/consent.php` has been live and complete the whole time (verified
responding in prod today) with **nothing ever calling `action=record`**. So the product asserted
COPPA consent capture and stored none. Expect `consent_records` to be empty or near-empty for
everything predating this deploy — that is the explanation, not corruption.

Consent is now captured in the parent portal (`ConsentGate`), which is the only place a *parent*
can give it — a club admin ticking "As the parent or legal guardian, I consent" was never parental
consent regardless of storage. Design rules that must not be undone are in CLAUDE.md.

**Second blank-page bug, same shape.** `MedicalInfoPage` read `allergies` / `medications` /
`blood_type` / `insurance_*` off `api/athletes/?action=get` — i.e. off the `athletes` row, which
has never had those columns. Every value resolved `undefined`, and the renderer *hid* empty fields,
so it presented as a family with nothing on file rather than as a broken page. **The portal's
Medical tab has been blank for every user for as long as it has existed.** Now reads
`legacy/medical-gateway.php` (the only reader that decrypts the PHI) and renders "Not provided".

Medical is also crew-editable now, by decision rather than inheritance — a parent is the
authoritative source for their own child's allergies. Clinical fields (concussion history, last
concussion, return-to-play) are withheld client-side only; noted in CLAUDE.md as a product
boundary, not a security one.

**⚠️ User-visible on next load:** every parent already in the portal meets a blocking consent
screen. Intended, and confirmed with Maggie before pushing. It cannot be dismissed, but it does
offer a decline path that signs out rather than trapping — consent that cannot be refused is not
consent. Nothing is recorded on decline.

Frontend-only, so Heroku was not pushed; the API this depends on was already deployed.

### First real per-club SMS delivered — and a 30024 red herring
No code change. Recorded because it looks like a regression and is not.

Club 32 (Teams Elevated) got its own number `+13605164604` at 20:10:24Z. Two sends
failed with `[30024] Numeric Sender ID Not Provisioned on Carrier` — at 65 seconds
and ~2 minutes after the number joined the Messaging Service. The third, at ~5
minutes, **delivered**. Nothing was changed in between.

Brand `BNc61ab4e0…` was `APPROVED` and campaign `QE2c6890da…` `VERIFIED` throughout,
and the number was correctly attached — this was purely carrier-side propagation.
The earlier "successful" test at 19:16 was club 32 sending from the *Kansas* number
(assigned to it 18:59, moved to club 51 at 19:58), so it never validated the new one.

**Number history is now two clubs deep**, which is what made this legible:
`sms_phone_numbers` rows 8–11 show the Kansas number moving 32 → 51 and club 32
getting its own. Deactivating rather than deleting is why the timeline was
reconstructable at all.

Rule (wait ~5 min; never paste the shared Messaging Service SID) is in `CLAUDE.md`.

### Inbound SMS auto-reply — replies stop vanishing
**Heroku v456** · Netlify `046f525` (carried this commit) · **no migration**

Before this, no inbound webhook existed, so Twilio accepted every reply to a
broadcast and discarded it — no record, no response. The parent who texted "Ava
can't make practice" got silence and reasonably assumed they'd been heard.

Now: an auto-reply pointing at the parent portal. **Nothing is stored**, which is
what the outgoing message claims; `SmsAutoReplyTest` fails if the handler ever
gains a database write.

- **Wiring is automatic now** — saving a number in Club Profile → Messaging sets
  its Twilio `SmsUrl`. Non-fatal if it fails, but surfaced in the settings UI,
  because the failure mode is silent: families text into a void and nothing
  records it.
- **⚠️ Club 51's number `+1 785 465 4221` (`PNf40df1…`) was configured at 19:24,
  BEFORE the wiring code existed**, so its `sms_url` was empty — verified against
  the Twilio API. Set by hand via the Twilio REST API after deploying v456. Any
  number saved before v456 has the same gap; re-saving it in the UI now fixes it.
  A re-save *before* v456 was a no-op, since the deployed backend had no wiring code.
- Live behaviour verified against production: an ordinary reply gets the pointer,
  a bare `STOP` gets empty TwiML, and "can we stop by the field at 6?" still gets
  a normal reply.

Durable rules (STOP/HELP must get silence; the reply must stay one GSM-7 segment)
are in `CLAUDE.md`.

### Chat retention policies — migration 059 applied to Neon
migration **059_chat_retention_policy.sql** applied to Neon 2026-07-30 · backend-only · seeds 2 rows

Chat had no retention rule at all: `scripts/retention-check.php` covered health records, consents
and audit entries, so chat was a permanent record with nothing that ever aged it out. Seeds
`chat_messages_removed` (90 days) and `chat_messages` (1095 days), **both `auto_delete = FALSE`**
like all five existing policies. Nothing deletes anything until someone runs `--purge` against an
armed policy, and none are armed.

Done **before** admin moderation removal (Phase 2) at Maggie's request. `chat_messages_removed` is
consequently inert — nothing writes `chat_messages.deleted_at` yet, so it reports zero rows. That is
correct rather than broken, and it means the rule is in place the day removal ships.

**Prod state discovered — `chat_read_receipts.last_read_message_id` is a NO ACTION FK onto
`chat_messages`.** A naive age-based purge raises SQLSTATE 23503 and fails entirely. Rehearsed
against live Neon inside a rolled-back transaction: planted a read receipt, ran the naive delete,
got the 23503; then ran the same delete behind the plan's `before` statements and it succeeded
(`UPDATE 1` receipts, `UPDATE 11` participant watermarks, `DELETE 38`), then ROLLBACK — verified
38 messages / 0 receipts still present afterwards. The table is empty and nothing writes it (it
predates `conversations`), which is precisely the hazard: the purge would have passed every test
and failed the first time it mattered.

Retention rules moved to `lib/retention_plans.php` so they are unit-testable — `retention-check.php`
connects to Neon at load, so a test requiring it would have hit production. The runner now wraps
each purge in a transaction and writes the `audit_log` entry **inside** it, so rows and their audit
record commit together. `ChatRetentionPlanTest` (11 tests) locks the refusals; full PHP suite 373
tests green after the extraction.
### Calendar practice counts were hardcoded to zero in effect
`a1be39a` · **Netlify deploy `a1be39a` ready** · frontend-only · **no backend, no migration**

`TeamCalendarView` carried a `practices` state array from before practices moved into
`calendar_events`. Nothing ever called `setPractices`, so it was permanently `[]` — and two pieces
of UI read it and nothing else:

- the practices **stat tile** (always visible, every view) rendered **0**
- the **Schedule view's list** rendered **"No upcoming practices scheduled"**

Both sat beside a month grid showing those same practices correctly, because the grid reads
`events`, where practices arrive as `type='practice'`. **Live Neon at the time: 337 practice
events, 18 upcoming** (334/18 for club 32). Confidently wrong numbers beside correct data — worse
than blank, and invisible to tests because an empty array renders a plausible `0` and a plausible
empty state.

Removed the dead array, the `Practice` interface, `CalendarDay.practices`, and the day-cell loops
over it — the month grid's "+N more" counted its length too, so that was also wrong whenever it
mattered. Both readers now derive from `events.filter(e => e.type === 'practice')`.

Three things beyond the deletion:

- **The team-filter predicate was inlined four times with three different behaviours.** "Total
  Events" omitted the `team_name` fallback and under-counted events carrying a name but no `teams`
  array. Now one `eventMatchesTeamFilter`.
- **The tile is now scoped to the visible month and relabelled "Practices This Month."** A lifetime
  count (334 for club 32) next to "This Month" and "Upcoming" on a month calendar wasn't a useful
  number. Maggie's call.
- **Date bucketing compares ISO strings** and splits `YYYY-MM-DD` rather than going through
  `new Date(...)`. A bare date parses as UTC midnight, which is the previous day in every US
  timezone; it would have dropped today's practices from "upcoming" and mis-bucketed the 1st of
  each month. The same trap bit the new test fixtures first, which had used `toISOString()`.

Tests appended to the existing `TeamCalendarView` suite and verified to fail with `practiceEvents`
forced empty — i.e. against the old behaviour — so they assert on known counts rather than on the
absence of a crash.

### Frontend lint ratchet — the build catches things again
`e3d75c1` · **Netlify deploy `e3d75c1` ready** · frontend-only · **no backend, no migration**

The build had been silently toothless. `react-scripts` promotes warnings to errors under `CI=true`,
but 116 had accumulated, so `netlify.toml` has always run `CI=false` — which promotes nothing. Two
production bugs shipped through that gap on this day alone (the `data.stats` contract mismatch and
the `cl.team_id` phantom column, both below).

**Step 1 of 2, no behavioral risk:** removed 51 unused variables, imports and dead locals, and
replaced an `href="#"` preview link with a `<button>` (jsx-a11y/anchor-is-valid). Unused useState
*values* became `const [, setX]` rather than being deleted — the setter is still live.

**Step 2 is deliberately not done:** the 74 remaining `react-hooks/exhaustive-deps`. Each is a
possible stale closure needing an individual judgment call; there is no bulk fix and `--fix` won't
touch them.

**The ratchet:** `npm run lint:ci` fails above a `--max-warnings` ceiling, wired into the Netlify
build ahead of the existing `CI=false` build. Source files only — test files carry ~191 separate
`testing-library/*` and `import/first` errors the production build never linted, and folding them
in would bury the number that matters. It reaches **74** vs the build's 64 because eslint lints
files outside the bundle's module graph; that is deliberate, an unreferenced component is exactly
where rot goes unnoticed. Verified it bites: passes at 74, exits 1 at 73.

⚠️ **`main` is shared, so a failing lint blocks everyone's deploy.** The unblock is to fix the
warning, not to raise the ceiling. Rules in `frontend/LINTING.md`, pointer in `CLAUDE.md`.

**One real bug surfaced** — left alone in this commit, **fixed later the same day** (see the
calendar entry above):
`TeamCalendarView` never calls `setPractices`, so the `practices` array is permanently `[]`. It is
vestigial state from before practices moved into `calendar_events` — the grid gets them as events
with `type='practice'` — but two pieces of UI still read the dead array and nothing else:

- the **"Total Practices" stat tile** (always visible, every view) always renders **0**
- the **Schedule view's upcoming list** always renders **"No upcoming practices scheduled"**

Live data as of 2026-07-30: **337 practice events exist, 18 of them upcoming** (334/18 for club 32).
So club 32 sees "0 Total Practices" and an empty schedule list while its month grid shows them
correctly. The fix is to derive both from `events.filter(e => e.type === 'practice')` and delete
the `practices` state, but that changes rendered numbers, so it wants its own commit.

**Correction to an earlier claim in this entry's commit message:** `MakePaymentPage`'s unused
`setPaymentMethods` was also called a latent bug. It is not. The code comment at
`MakePaymentPage.tsx:86` says saved payment methods are deliberately not fetched until the Stripe
Phase 5 endpoint ships, and the selector is hidden while the array is empty. That one is working as
designed.

Test suite identical either side of the sweep: 10 pre-existing failing suites, 33 failing /
286 passing. `tsc --noEmit` clean.

### Send button on the SMS template library
`a3fe673` · **Netlify deploy `a3fe673` ready** · frontend-only · **no backend, no migration**

Parity with the email template editor, which has had a Send button since the July template work.
The SMS library previously had no way to send a template — you opened SMS Compose separately and
re-picked it from the dropdown.

`SmsCompose` gained a `preselectedTemplate` prop mirroring `EmailCompose`'s. It sets the message
body **directly** rather than selecting a template id and waiting on the picker's fetch, so the body
is present on first paint and survives the list returning empty (unsaved or scoped-out template) —
that ordering is the whole reason the prop exists and is pinned by a deliberately synchronous
assertion in `SmsCompose.test.tsx`.

**Send is not admin-gated**, unlike the Edit / Duplicate / Delete buttons beside it. Coaches can
send SMS to their own team and may *use* templates; they just cannot create or modify them (Roles &
Permissions in CLAUDE.md). Server-side scope enforcement on the send is unchanged, so the button
grants nothing the compose screen already didn't.

No backend touched, so nothing to push to Heroku. Netlify built from the shared `main` as usual.

**Repo state noted while verifying:** the frontend suite has **10 failing suites / 33 failing tests
that are unrelated to this change** — `App`, `AthleteManagement`, `VenueManagement`,
`TryoutCreationWizard`, `TournamentCreate`, `PaymentCheckout`, `RevenueDashboard`,
`MakePaymentPage`, `PaymentStatusPage`, `useParentAthletes`. Confirmed pre-existing by running the
suite with this change stashed (same 10). Also: `CI=true npx react-scripts build` fails on
accumulated lint warnings; Netlify runs `CI=false npm run build` (netlify.toml), which is why
deploys are unaffected. Neither is blocking, both are unowned.

### Per-club SMS sending numbers — **migration 057 applied to Neon**
**Heroku v451** (from v450) · **Netlify deploy `ccd2705` ready** · deployed ~11:50 PT

Deployed **backend first**, deliberately inverting the usual frontend-first rule. That rule exists
for auth tightening, where the live frontend must already be sending a header the backend starts
demanding — not the case here. Frontend-first would have put the Messaging tab in front of Maggie
before `api/sms-numbers.php` existed on the dyno, so the tab would have errored on first click.
Backend-first was safe *only* because SMS has no real users yet.

- **Schema:** new `sms_phone_numbers` table (109 tables now, was 108) + `communication_log.from_number`.
  Applied ~11:20 PT. Table created **empty on purpose — no backfill.**
- **Prod state found before applying**, which is what justified skipping the backfill:
  `communication_log` had **5** `channel='sms'` rows — all club 32, dated 2026-03-21 and 2026-04-06,
  all to internal test numbers from `email-sms-test-plan.md` — and **0** `channel='sms'` rows in
  `email_suppressions`. No real family has ever been texted by this platform and nobody has opted out.
- **⚠️ SMS now refuses for all 5 clubs** until each sets a number in Club Profile → Messaging.
  This is intended, not a regression: `te_resolve_sms_sender` has no fallback to `TWILIO_FROM_NUMBER`.
  The lesson (why a shared sender is unsafe) is in `CLAUDE.md`.
- **Applied twice.** The first apply had `phone_number NOT NULL`, which contradicts the API's
  support for a Messaging-Service-only sender. Caught by verifying against Neon rather than the
  SQLite fixture. Table was 0 rows and minutes old, so it was `DROP`ped and recreated from the
  corrected file — `communication_log.from_number` survived via `ADD COLUMN IF NOT EXISTS`.
  The committed 057 is the corrected version; anyone who pulled between the two applies should
  re-run it.
- `tests/fixtures/production-schema.json` regenerated from Neon (not hand-edited). That also picked
  up **`conversation_participants.archived_at`**, which was already live from the chat-archive work
  but missing from the snapshot.

### Chat conversation archive (no delete) — migration 058 + chat app v11
`a1e1993` / `50cfe92` · migration **058_chat_conversation_archive.sql** applied to Neon 2026-07-30 ·
chat app `teamselevated-chat` **v11** (subtree split `ccd4f0a`) · Netlify build of the merge to main

`ALTER TABLE conversation_participants ADD COLUMN archived_at TIMESTAMP` plus
`idx_conv_participants_archived (user_id, archived_at)`. Verified after apply: column present and
nullable, index present, **0 rows archived** — purely additive, no existing row touched.

**Order was migration → chat server → frontend, and the first two are load-bearing.** The chat
server's conversation-list query references `cp.archived_at`; that dyno against a database without
the column would have failed the conversation list for every user, not degraded gracefully.
Frontend last because the frontend is the side *adding* calls here — shipping it first puts an
archive button in front of people that the old dyno ignores. (CLAUDE.md's usual frontend-first note
is about *tightening* auth on an existing contract; this is the inverse, same as the jersey-size
entry below.)

**The chat server is a separate Heroku app and deploys by subtree**, since its repo root is the
contents of `chat-server/`: `git subtree split --prefix=chat-server` then push that ref to the
`chat` remote. `git push heroku` does not deploy it and never has. Verified the split
fast-forwarded onto the deployed commit before pushing; post-deploy `/health` 200 and "Database
connected successfully" in the logs.

`tests/fixtures/production-schema.json` updated in the same commit — the snapshot is the
authority `SchemaConformanceTest` checks against, so a migration that doesn't update it puts the
fixture out of step with live.

**Prod state discovered:** `markRead` had been a no-op on every team conversation for as long as
team chats have existed. `ensureTeamConversation()` creates them with no `conversation_participants`
rows, and `markRead` was a bare `UPDATE ... WHERE conversation_id AND user_id` — zero rows. Team
unread badges never cleared. Fixed in the same commit (upsert) because the archive write had to
solve the identical problem.

Durable rules (no user-facing delete in chat; per-user chat state must be UPSERTed) are in
`../CLAUDE.md` → "Chat has archive, and deliberately has NO delete". Design rationale and the
unbuilt Phases 2–3 are in `docs/chat-archive-plan.md`.

### SECURITY — athlete and guardian writes were gated on the read predicate
`2f14a4c` (merged as `d382a8a`) · Heroku **v448** · backend-only · **no migration**

Found while scoping a narrow endpoint for the crew jersey-size feature (below): every write in
`legacy/athletes-gateway.php` and `legacy/guardian-gateway.php` gated on
`AthleteScope::userCanAccessAthlete`, which passes guardians by design.

Four handlers, in ascending order of severity:

- `athletes-gateway` **PUT** — a parent-portal token could rewrite their own child's
  `date_of_birth` (decides age-group eligibility), name and address.
- `athletes-gateway` **DELETE** — soft-delete their own child off every roster.
- `guardian-gateway` **DELETE** — delete the *other* parent's `athlete_guardians` link, ending that
  parent's access with nothing but a missing row to show for it.
- `guardian-gateway` **POST / PUT** — **no scope check at all.** `athlete_id` came straight from the
  request body. Since `isGuardianOfAthlete` matches guardians on **email**, any authenticated user
  (parent, player, volunteer) could attach a guardian row carrying their own address to any athlete
  in any club, become that child's guardian, and read the record **and the health data** via
  `legacy/medical-gateway.php`. A cross-family escalation chain, not a tidiness problem.

**Not exploited — there were no users.** Maggie confirmed the platform had no real families on it
during the window these holes were open, which closes the question without needing a data audit.
Corroborated independently by the SMS entry above: 5 lifetime `channel='sms'` rows, all to internal
test numbers. Treat this as the *last* window in which "no users" is an available answer.

**Why it survived:** nothing reachable by clicking. Every caller is a staff screen (`AthleteForm`,
`GuardianManagement`, `RosterManagement`, `AthleteProfileEnhanced`, `AthletePhotoUpload`). The
missing button was doing the job the access control should have been doing. Durable lesson in
CLAUDE.md under "Reading an athlete and writing one are different permissions."

`staffCanManageAthlete` is `userCanAccessAthlete` minus the guardian branch, and the read predicate
is now *defined in terms of it* so the two cannot drift. Staff standing is byte-for-byte the logic
that already gated the GET — which is the evidence it works in prod, since admins can view athletes
today. `AthleteWriteScopeTest` covers the CA-18 unrostered-athlete path (where a subtle break would
hide) and parses both gateways to assert the write handlers call the strict predicate. That
source-level guard was mutation-tested: reverting one call fails it.

`legacy/medical-gateway.php` writes were left on the read predicate deliberately — a parent
plausibly *is* the authority on their child's allergies. Now an explicit open item, not an accident.

Post-deploy: both gateways still answer 401 unauthenticated.

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

### Parent invites no longer land on the child's account
backend-only · **no migration**

Follow-on from the `defaultpass` cleanup below, and a prerequisite for inviting Central Kansas's
14 uninvited crew. `users.email` is UNIQUE, so an address can only ever have one account — and those
14 addresses were already occupied by their own child's auto-created shell.

`parentInvite_ensureUserAndToken()` reused whatever row it found by email, which meant the parent
would set a password on an account named after their kid (`users.role='player'`, still pointed at by
`athletes.user_id`) — one login for two people. It now **reclaims** instead: detaches the athlete,
renames the row to the guardian, sets `role='parent'`, audits it as
`parent_invite_reclaimed_athlete_shell`.

**Worth recording honestly:** clearing the passwords earlier the same day changed how this failed
rather than causing it. Before, the shell's `defaultpass` hash made the function return
`already_active`, so the invite was *silently never sent* — the same predicate that made the Crew
page show those 14 as active. After, the invite would have gone out and landed on the child's
account. Both wrong; the second is louder. Found by tracing the invite path when asked to explain
what `user_guardians` would fix.

Safety boundary: a row with a password returns `already_active` before any repair runs, so a live
account can never be renamed out from under its owner. Guarded by `ParentInviteReclaimTest`
(6 cases, verified to fail with the reclaim disabled).

**Prod state:** the 16 club-51 guardians whose email maps to a non-their-own account are unchanged
on disk — the repair happens lazily, at invite time, per guardian. 14 are their child's shell; the
other 2 (Katy Ebert, Samantha Archer) are their own coach accounts, which have passwords and are
therefore correctly left alone.

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
