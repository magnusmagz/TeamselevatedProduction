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

## 2026-07-31

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
