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

### Made every template's merge tags resolve
**Heroku v443, v445** · commits `e900200`, `1d20b28`

Repointed `MergeFieldService` at `calendar_events` (the `events` table no longer exists), rewrote
456 camelCase tags across 61 templates, added `CONTEXT_PASSTHROUGH_KEYS` for the waitlist URLs, and
rendered the 59 design-only templates that had no `html_output`. Details in CLAUDE.md's pending-work
list and `MergeFieldService::getAvailableFields()`.

---

## Earlier

Entries before 2026-07-30 predate this file. Reconstruct from `git log` plus the dated notes in
`../CLAUDE.md`; the Heroku release history (`heroku releases -a teamselevated-backend`) maps
commits to versions back through the whole project. Migrations 001–055 are all applied to Neon per
CLAUDE.md's parallel-session section, but the individual application dates were not recorded.
