# `user_guardians` — recording identity instead of deriving it

Written 2026-08-18, after the seventh incident caused by the same absence.

## The problem in one sentence

There is no row anywhere saying *this account belongs to this guardian*; the
relationship is re-derived on every request by string-comparing `users.email` against
`guardians.email`, two independently-editable columns in two different tables.

## What it has already cost

| Incident | Date | Mechanism |
|---|---|---|
| Emily Govier — empty parent portal | 2026-08-18 | one capital letter; `=` is case-sensitive |
| Allix Boyce — stuck in staff app | 2026-08-15 | `@yahoo` account vs `@gmail` guardian row |
| Athlete shells holding a parent's email (33 rows, migration 067) | 2026-08-15 | `users.email` UNIQUE ⇒ one address, one person, forever |
| `guardian_sync.php` written | 2026-08-04 | a workaround whose only job is keeping the two strings equal |
| Crew page reporting invites nobody sent | 2026-07-30 | portal status inferred from the email match |
| Jaia recording consent for a stranger's child | 2026-07-31 | consent authorised by email match |
| Coach-parents told "no athletes are registered to you" | 2026-08-03 | derivation re-implemented per call site |

**These fail as product statements, not errors.** No 500, no log line — "no athletes are
registered to you" reads like information. That is why they survive rollouts and surface
only when a family speaks up.

## Measured state (production, 2026-08-18)

```
guardians                                 403   (24 with blank email, 379 with)
distinct guardian emails (lowercased)     373
athlete_guardians links                   444
users                                     243

guardian emails that match a user          179
guardian emails with NO user               194   ← never invited/accepted; no link yet
emails held by >1 guardian (households)      6   (12 guardian rows)

users matching exactly 1 guardian          173
users matching >1 guardian                   6   ← HELD for review, not auto-linked
  (each has exactly one name-matching row, but see §1: name matching is
   not a safe rule here, and one of the six is not a household at all)
users with `parent` role and NO guardian     7
```

The 7 unmatched parent accounts are **5 seed/demo rows** (`@email.com`, never logged in)
plus two real cases: **Nancy De Santiago** (`nancyberenice124@gnail.com` — "gnail" typo)
and **Allix Boyce** (yahoo account, gmail guardian row).

So the data is far cleaner than the bug history suggests. The backfill is not a research
project. 173 accounts link with no judgement at all; 6 shared-email accounts need a
human to look once (§1); 2 real people need their address corrected.

## Table

```sql
CREATE TABLE user_guardians (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id)     ON DELETE CASCADE,
    guardian_id  INTEGER NOT NULL REFERENCES guardians(id) ON DELETE CASCADE,
    -- ^ a guardians(id). NOT the same thing as consent_records.guardian_id,
    --   which is an FK to users(id). See the warning below.
    source       VARCHAR(30) NOT NULL,   -- backfill_email | invite_accept | admin_link | registration
    confidence   VARCHAR(10) NOT NULL,   -- exact | household | manual
    linked_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, guardian_id)
);

-- UNIQUE gives a composite index starting with user_id, which covers the hot path
-- (account -> their guardians). The REVERSE lookup has no index from that, and it is
-- not rare: lib/portal_status.php and handleClubParents both ask "which account belongs
-- to this guardian" for every row of the Crew page.
CREATE INDEX user_guardians_guardian_idx ON user_guardians (guardian_id);
```

Decisions worth defending:

- **Many-to-many, not a column on `guardians`.** A guardian row can legitimately gain an
  account later, and one account can cover two guardian rows in edge cases. A nullable
  `guardians.user_id` would look simpler and would re-create the "which one is it"
  problem the households already demonstrate.
- **`source` and `confidence` are stored, not derived.** When this goes wrong — and the
  first wrong row will be a family seeing another family's child — the only question
  that matters is *how did this link get here*. A backfilled guess and an admin's
  deliberate action must be distinguishable forever.
- **No `active`/soft-delete flag.** Unlinking is rare, consequential, and audited by
  the migration-070-style trigger; a flag invites "temporarily" broken states.
- ⚠️ **`guardian_id` means something different two tables over.**
  `user_guardians.guardian_id` and `athlete_guardians.guardian_id` are `guardians(id)`;
  **`consent_records.guardian_id` is a `users(id)`** — verified against the live FK
  (`consent_records_guardian_id_fkey -> users`). Joining the two by name is a silent
  cross-wiring that would attribute consent to the wrong person. The name is kept for
  consistency with `athlete_guardians`, since `consent_records` is the outlier; the
  defence is this note plus a test that fails on a join between them.
- **`ON DELETE CASCADE` on both sides.** The row asserts a relationship between two
  live records; if either is gone the assertion is meaningless, not historical.

## Backfill rules

> **Corrected 2026-08-18 after checking against production.** The first draft said "link
> only the guardian whose name matches the account". That is wrong and would have
> **removed access**: measured against live data, Elias Ulvi drops from 5 children to 2
> and Carmen Hawk from 2 to 1. Carmen is a real family — guardian 322 is spelled
> "Carmen Haej" and 341 "Carmen Hawk", same address, *different athletes*. Name-only
> linking silently drops the misspelled row's child.
>
> The second pass then went further. "Link every row sharing the email" preserves today's
> behaviour but *enshrines* it — including `eli@teamselevated.com`, which is a staff
> address on four unrelated children (§1 below). So the automated step now covers only
> the cases with no judgement in them, and the six shared-email accounts are held for a
> human. The table stays many-to-many because the genuine households need both rows; the
> name match sets `confidence`, and never decides on its own whether a row is written.

Run as a script, `--dry-run` by default, one transaction, counts printed.

1. Account matches **exactly one** guardian by email (case-insensitive) → link.
   `source='backfill_email'`, `confidence='exact'`. **173 rows. This is the whole
   automated step.**
2. Account matches **more than one** guardian → write **nothing**; print it for review.
   6 accounts, 12 candidate rows. See "Problems this fix could introduce" §1 — one of
   the six (`eli@teamselevated.com`) is not a household at all, and no automated rule
   separates it from the genuine ones without eventually guessing wrong about a child.
   Linking these is a human decision, and the email fallback keeps them working
   unchanged until it is made.
3. Guardian with no matching user → **no row**. 194 emails; they have no account yet.
4. Account with `parent` role and no guardian → **no row**, listed in the report. 7
   accounts, of which 5 are seed rows; the two real ones are Nancy De Santiago
   (`@gnail.com` typo) and Allix Boyce (yahoo account, gmail guardian row).

Expected: **173 rows written, 6 accounts held for review, 0 guessed.**

Acceptance test is per-user set equality, not row count: for every account, the set of
athlete ids reachable through the resolver must equal the set reachable today. For the
173 that is exact. For the 6 held accounts it holds because the email fallback still
answers for them — which is precisely why the fallback cannot be removed until they are
resolved.

Idempotent via `ON CONFLICT (user_id, guardian_id) DO NOTHING`; re-runnable.

Blank-email guardians (24) are **out of scope** — agreed 2026-08-18. They are unlinkable
by construction and unreachable regardless of this work.

## Rollout — additive first, cut over second

**Phase 0 (today, independent).** `chat-server/lib/team_scope.js` and
`participants.js` still join `g.email = u.email` case-sensitively. Same bug as Emily's,
still live, separate Heroku app and subtree deploy. Fix and ship on its own.

**Phase 1.** Migration **072** + backfill script + its own audit trigger (070 covers `athlete_guardians` only, not this table). Writes the table, changes no
behaviour, reads nothing. Fully reversible by dropping the table.

**Phase 2.** Introduce one resolver — `lib/guardian_identity.php`,
`te_guardian_ids_for_user()` — that reads `user_guardians` **UNION** the existing email
match. Strictly wider than today, so nothing can lose access. Convert all call sites to
it. Behaviour is unchanged; the shape is centralised. This is where the tests go.

**Phase 3 — write links at their source.** Invite accept, registration, and the
club-admin connect tool. **This must come BEFORE dropping the fallback**, which the
first draft had backwards: 194 guardian emails have no account yet, so between "drop the
fallback" and "write links on accept" every newly-accepted family would land in an empty
portal. That is the bug this project exists to end, reintroduced by its own rollout.

**Phase 4 — retire the email match.** Log every case where the email branch yields a
guardian the table does not. When that is zero for real accounts for a week, delete the
fallback inside the resolver: one line, one place. `users.email` stops being
load-bearing and the class ends.

## Call sites (12)

PHP: `api/financial-permissions.php` ×2, `lib/AthleteScope.php` ×2, `api/invoices.php`
×2, `api/recipient-search-gateway.php` ×2, `api/calendar-events-gateway.php`,
`api/sibling-discount.php`.
Node: `chat-server/lib/team_scope.js`, `chat-server/lib/participants.js`.

`lib/portal_status.php`, `api/auth-gateway.php` (`handleClubParents`) and
`lib/ParentInvite.php` consume the same identity notion and should move to the resolver
in phase 2 even though they query differently.

⚠️ **`AthleteScope::isGuardianOfAthlete($pdo, string $email, int $athleteId)` takes an
EMAIL, not a user id**, and it is a security predicate — it gates consent recording,
medical edits and jersey writes. Two live callers, both of which already have the user
in hand and can pass an id instead: `api/consent.php:135` (has `$guardianUser`) and
`AthleteScope::userCanAccessAthlete` (has `$auth`). Change the signature deliberately, in
its own commit, with `AthleteWriteScopeTest` and `ConsentRollupTest` green before and
after — do not fold it into a bulk edit.

⚠️ **The chat server cannot share the resolver.** It is a separate Node app deployed by
`git subtree split --prefix=chat-server` to the `chat` remote, so phase 2 gives it its
own SQL copy plus a test in `chat-server/__tests__/`, exactly as `team_scope.js` already
carries its own port of `lib/coach_scope.php`. Two implementations of one rule is the
known cost; an untested third copy is not.

## Two consequences worth stating plainly

**A parent changing their email stops breaking anything.** Today `lib/guardian_sync.php`
exists solely to keep `users.email` and `guardians.email` equal, and a mismatch silently
severs the family. Once the link is a row, an email change is just an email change.
guardian_sync still earns its place — the club needs current contact details — but it
stops being load-bearing for access.

**One guardian may end up with two accounts, and the table permits it.** Allix Boyce had
exactly that (a `@yahoo` invited account and a self-created `@gmail` one).
`UNIQUE (user_id, guardian_id)` constrains the pair, not the guardian, so both can link
to the same guardian row and both work. That is the correct behaviour — better than
today, where one of the two is simply broken — but it is a decision, not an accident, and
staff should be able to see it. Surface it on the Crew page rather than deduplicating
silently.

## What this does NOT fix

- **`users.email` remains UNIQUE.** Two guardians sharing an address still cannot both
  hold accounts. The link table makes that survivable (the second can be linked once
  they have any address) but does not remove the constraint.
- **Blank-email guardians (24)** remain unreachable and unlinkable.
- **It is not a merge tool.** Duplicate guardian rows for one human stay duplicated.

## Problems this fix could introduce

Reviewed 2026-08-18, second pass. These are risks created by the migration itself, not
by the status quo.

### 1. It turns an accidental link into an asserted one — and makes it survive the fix

This is the serious one. Today access via a shared email is *derived*: correct the
guardian's email and the access disappears the same second. After the backfill it is a
**row**, which persists after the email is corrected, and nothing prompts anyone to
remove it.

Live example. `eli@teamselevated.com` is on two guardian rows — Arturo Alvarez (g210)
and Elias Ulvi (g300) — reaching **Alex Morgan, Ava Ulvi, John Spencer, Will Lucero**.
Four surnames. That is a staff address used as the guardian email for unrelated
athletes, not a family. Backfilling "preserve today's behaviour exactly" would write a
durable, audited assertion that Elias Ulvi is the guardian of three other people's
children, and would make it immune to the one repair that works today.

**Surname difference is not the signal.** `carmenlynnhawk@gmail.com` also spans two
surnames (Carmen *Haej* / Carmen *Hawk*) and is plainly one family — both athletes are
Hawks. Any automated rule that separates these two cases is a rule that will eventually
guess wrong about a child.

**So do not automate the judgement:**

- **Auto-link only accounts matching exactly ONE guardian — 173 rows, zero judgement.**
- **Hold all 6 shared-email accounts** (12 candidate rows) and print them as a review
  list for a human to confirm.
- This costs nothing operationally, because the email fallback stays live through phases
  1–3: those 6 keep behaving exactly as they do today until someone decides. They must be
  resolved before phase 4, and six is a tractable list.

Expected backfill is therefore **173 rows, not 185**, plus a 6-line review report.

### 2. Re-run the backfill immediately before retiring the fallback

Anyone who accepts an invite between the phase 1 backfill and phase 3 (links written at
source) has no row, and is carried only by the email fallback. Dropping the fallback in
phase 4 would strand exactly those families. **The last action before phase 4 is a
re-run of the backfill**, which is idempotent and designed for it.

### 3. The Crew page will visibly change

`lib/portal_status.php` and `handleClubParents` currently infer portal state from the
email match, which CLAUDE.md already records as reporting invites nobody sent. Once
status is derived from a real link, some rows will flip. That is the fix working, but it
will look like a regression to whoever is reading the page that morning — say so before
it ships rather than after.

### 4. The backfill fires the audit trigger 173 times with a NULL actor

`user_guardians` gets its own trigger (phase 1). A bulk insert therefore writes 173
`guardian_link_added`-style rows attributed to nobody, which is indistinguishable at a
glance from 173 unexplained out-of-band changes — the exact signal migration 070 exists
to make meaningful. Set `app.user_id` to the operator for the backfill run, or stamp
`source='backfill_email'` into the audit detail so the run is identifiable as one event.

### 5. A staff account can acquire parent standing it should not have

Any account whose email happens to sit on a guardian row gets linked, and the resolver
grants parent scope from it. That is already true today, so phase 1 changes nothing —
but it becomes durable, and `eli@` above is a live instance. The review list in (1) is
the control.

## Risks

- **Phase 2 is the dangerous one** and is deliberately additive: UNION means the worst
  case is unchanged behaviour, never lost access. Do not "simplify" it to table-only
  before phase 3 says the divergence is zero.
- A wrong link row is a **child-data disclosure**, so the backfill refuses ambiguity
  rather than guessing, and every row records how it got there.
- The chat server deploys by `git subtree split --prefix=chat-server` to the `chat`
  remote. `git push heroku` does not ship it and never has.
