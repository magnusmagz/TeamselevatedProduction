# Consent audit readiness — pick up Monday 2026-08-03

Written 2026-07-31 at the end of the consent workstream, while the detail was fresh.
**Read this before touching consent again**; it exists so the work can be resumed cold.

## The goal, stated plainly

Maggie's actual requirement, in her words: *"we want to give parents a way to give consent —
which we do on registration and in the portal — we need to log these in case we experience an
audit or a complaint."*

Capture and visibility are **done and in production** (2026-07-30 / 07-31 — see CHANGELOG).
What remains is the *defensibility* half. Today, if a parent complains **"I never agreed to
that,"** you can prove **that** they agreed and **when** — but not **to what wording**.

---

## What already exists — do NOT rebuild

| Piece | Where | State |
|---|---|---|
| Capture at sign-up | `lib/consent_capture.php` → `registrations-api.php` | live, `source='registration'` |
| Capture in portal | `ConsentGate` → `api/consent.php?action=record` | live, `source='portal'` |
| Per-athlete read | `consent.php?action=status` | live |
| Per-guardian read | `consent.php?action=list` | live |
| Staff roll-up | `consent.php?action=summary` + Consent column on the athlete list | live |
| Withdrawal **API** | `consent.php?action=revoke` | live, **no caller** |
| Schema | migration 063 — `source`, `guardian_email`, `guardian_name`, nullable `guardian_id` | applied to Neon |

Per record we already store: who (name + email frozen as recorded, plus `guardian_id` when they
had an account), consent type, `consented_at`, `ip_address`, `user_agent`, `consent_version`,
`source`, `email_sent_at` / `email_confirmed_at` (double opt-in), and `revoked_at`.

That is a genuinely strong artifact. The gaps below are what stop it being sufficient on its own.

---

## Gap 1 — the wording is not stored, and it has ALREADY diverged ⚠️ (the one that matters)

Every row stamps `consent_version = '1.0'` (`TE_CONSENT_VERSION` in `lib/consent_capture.php`).
**Nothing stores what "1.0" said.** The statements live as JSX in two files, and nothing forces a
version bump when either is edited.

They are already different. Verbatim, as of 2026-07-31:

**`data_collection`**
- Portal (`ConsentGate.tsx`): "As the parent **or** legal guardian, I consent to the collection and
  storage of my child's personal information as described in the Privacy Policy."
- Registration (`PublicRegistrationForm.tsx`): "As the parent**/**legal guardian, I consent to the
  collection and storage of my child's personal information as described in the Privacy Policy."

**`medical_data`**
- Portal: "…accessible only to authorized staff for safety purposes **— for example** allergies,
  medications and medical conditions."
- Registration: "…accessible only to authorized staff for safety purposes. **Examples of this data
  may include but is not limited to:** allergies, medications and medical conditions."

The second pair is not cosmetic — *"for example"* and *"may include but is not limited to"* claim
different scope, and **both are recorded as v1.0**. The portal wording was introduced on
2026-07-30 when `ConsentGate` was written; the registration wording is the original.

### Fix

Move the statements **server-side as the single source** and have both surfaces render *from* it —
the same trick that keeps the jersey-size list from drifting (`lib/jersey_size.php` ↔
`utils/jerseySize.ts`). Then the version cannot disagree with the text, because there is one copy.

1. `lib/consent_text.php`: versioned statements keyed by `consent_type`, e.g.
   `TE_CONSENT_TEXT['1.1']['medical_data'] = '...'`. Current text becomes **1.1**, not 1.0 —
   1.0 is already ambiguous and must not be reused.
2. New action `consent.php?action=text` returns the current version + statements. `ConsentGate`
   and `PublicRegistrationForm` render from it instead of hardcoding.
3. **Migration 064** (verify the number Monday — `ls database/migrations/ | sort` across every
   worktree; 063 was the last claimed): `ALTER TABLE consent_records ADD COLUMN consent_text TEXT`.
   Store the exact statement shown, per row. Belt and braces with the version: if the version
   table is ever edited, the row still carries what was displayed.
   - Storing the full text, not a hash: a hash proves tampering but cannot *produce* the wording,
     and producing it is the whole requirement.
   - **Backfill:** the ~handful of pre-064 rows cannot be backfilled honestly — we do not know
     which of the two v1.0 texts each saw. Leave `consent_text` NULL and treat NULL as
     "ambiguous, pre-064". Do **not** guess from `source`; the divergence is per-surface but the
     portal text also changed mid-day.
4. A test that fails if any consent statement string appears in a `.tsx` file — that is what stops
   the drift recurring. Mirror `JerseySizeConsistencyTest`.

---

## Gap 2 — the portal promises withdrawal it does not offer

`ConsentGate` tells the parent: *"you can withdraw it at any time from the portal."*
`action=revoke` exists and works. **No UI calls it.** `grep -rn "action=revoke" frontend/src`
returns nothing.

This is the exact silent-promise pattern the rest of this workstream removed, introduced by the
copy written on 2026-07-30. It is also a compliance issue in its own right — withdrawing consent
is meant to be as easy as giving it (GDPR Art. 7(3)).

### Fix
A **"Your consents"** section in the parent portal: what they agreed to, when, which child, whether
confirmed, and a withdraw control wired to `action=revoke`. Doubles as the family's own copy.

Decide at build time — **ask Maggie, do not assume**: what happens after withdrawal? `ConsentGate`
keys on `revoked_at IS NULL`, so revoking currently re-raises the blocking gate on the next load,
which is a loop (withdraw → blocked → withdraw). Options: (a) withdrawal signs them out with the
decline copy, (b) withdrawal notifies the club and leaves the portal usable read-only. Not a code
detail — it decides what withdrawing *means* for the child's participation.

---

## Gap 3 — no tamper-evidence (noted, NOT scheduled)

`consent_records` is an ordinary table. `audit_log` records the grant (`consent_given`) but a later
`UPDATE` to a consent row leaves no trace. Normal for this stage and lower stakes than the above —
recorded so it is a known limitation rather than a surprise. If it is ever wanted: an append-only
mirror, or a DB trigger writing `audit_log` on UPDATE/DELETE.

---

## Verification criteria (what "done" means)

- [ ] Pick one real family and produce, from the database alone: who consented, when, from what IP,
      which child, which consent type, **the exact sentence they were shown**, and whether they
      confirmed by email.
- [ ] Change a consent statement, redeploy, and confirm a NEW record carries the new text and a new
      version while the old record still carries the old text.
- [ ] A parent can withdraw from the portal, and the withdrawal is visible to staff on the athlete
      list within one refresh.
- [ ] The drift test fails if a statement is hardcoded back into a `.tsx`.

## Landmines

- **`consent_version` is currently `'1.0'` for two different texts.** Any scheme that treats
  1.0 as meaningful is building on sand. Start at 1.1.
- `consent.php` takes `CONSENT_VERSION` from `TE_CONSENT_VERSION` — one definition, keep it that way.
- The registration payload's consent flags sit at the **top level**, beside `form_data`, not inside
  it. Pinned by `RegistrationConsentCaptureTest`; do not "tidy" the lookup.
- Registration capture runs in a **SAVEPOINT** so a consent failure cannot roll back a family's
  registration. Any new write on that path must stay inside it.
- The gate clears on `source='portal'`. Do not let a `registration` row satisfy it — the second
  prompt is deliberate (Maggie, 2026-07-31: *"I kinda like the double consent flow"*).
