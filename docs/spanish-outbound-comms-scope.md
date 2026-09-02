# Spanish for Outbound Communications — Scope

Written 2026-09-02 from Maggie's direction and a code inventory. **Slotted for later**; not on
the active build list. Roadmap R69, decision 7.

## The idea, in one paragraph

Every person gets a **language preference** on their profile: parents, coaches, admins. Every
outbound **email and SMS template** can carry a **Spanish version**. At send time, if the
recipient's preference is Spanish and a Spanish version exists, that is what goes out; in
every other case the English version goes out. The portal itself stays English and relies on
browser translation for now (a separate examination of its ~470 strings is in
`docs/parent-portal-spanish-strings.md`).

## What the code inventory found

- **`guardians.preferred_language` already exists in production**, `ENUM('English','Spanish',
  'Other') DEFAULT 'English'` from the MySQL era. Nothing reads or writes it. Right name, right
  table, dead. `users` has no such column.
- **No template has a language today.** `email_templates` has `channel`, `category`, `scope`,
  `cloned_from` — no locale, and no key that says "these two rows are the same template in two
  languages". 12 templates are seeded in migrations (all email); production holds about 142,
  nearly all club-authored. There are no seeded SMS templates.
- **The send paths already know who the recipient is** wherever it matters: broadcasts and
  compose carry `{type, id}` per recipient (athlete / guardian / coach); SMS resolves a guardian
  id per recipient; chat notifications carry a `user_id`; payment reminders, failures and
  receipts loop per guardian; tryout offers carry guardian ids. The transactional templates in
  `lib/Email.php` are PHP heredocs, one method each.
- **Three paths have no identity at send time** and cannot honour a preference without a hint
  carried on the link or token: magic link and password reset (email string only, by design),
  team invitation (invitee has no account yet), donation receipt (public form), and the Twilio
  inbound auto-reply (phone number, composed before the sender is resolved).
- **Household combining**: `send-email` merges two guardians sharing an address into one email.
  If they hold different preferences there is no correct single answer.
- **SMS has a hard constraint**: any accented character (á, ñ, ó) forces UCS-2, where the
  segment limit drops from 160 to 70 characters and cost triples. The 141-character English
  auto-reply becomes three segments in Spanish.

## Design

### 1. Preference
- `users.preferred_language` (new, `en` default) and the existing `guardians.preferred_language`
  normalised to the same two-letter codes (`en`, `es`; `Other` → `en`). One resolver,
  `te_recipient_language($pdo, $type, $id)`: `users` for coaches/admins, `guardians` for
  families, with the guardian-identity resolver bridging the two so a parent's account setting
  and crew record cannot disagree (the account wins; a profile save syncs to the guardian row the
  way `lib/guardian_sync.php` already syncs name and phone).
- Profile UI: one select, "Language for emails and texts: English / Español", on the staff
  profile page and the parent portal settings. The parent portal's own display stays English.

### 2. Template variants
- **Club-authored templates** (`email_templates`): a new nullable `language` column (`en`
  default) and a nullable `variant_of` pointing at the English row. The template library shows
  "Add Spanish version" on any English template; the editor opens with the English design
  pre-loaded so the author translates in place. Merge tags are language-neutral and work
  unchanged. SMS templates the same way, with a live segment counter that warns when the
  Spanish body crosses 70 characters.
- **Code-authored transactional templates** (`lib/Email.php` and the three newer libs): move
  each subject/body pair into a per-language PHP array keyed by template name, English required,
  Spanish optional. The send methods take a `$lang` and fall back to English when the Spanish
  key is absent. The payment renderer and the tryout-offer copy switch are the easiest first
  targets; the ICS calendar invite is the hardest, since one `.ics` is generated per event and
  reused for every recipient, so a Spanish summary means generating per recipient.

### 3. Send-time rule (the fallback)
```
lang = preference(recipient) ?? 'en'
template = variant(template_id, lang) ?? template_id      -- English row is the root
```
Applied in `send-email`, `send-sms`, `send-broadcast`, and in each transactional method that
has an id in hand. Logged on `communication_log` as `language_sent` so reporting can show what
each person actually received. **Household combining**: when two guardians on one address
differ, send English and record `language_conflict` on the log row; do not split into two
emails (that reintroduces the duplicate-send problem household combining exists to stop).

### 4. Out of scope for this piece
- The portal's own strings (see the companion doc).
- Magic link, password reset, team invitation, donation receipt: English until a language hint
  is carried on the token (small, later).
- The Twilio auto-reply: keep the single English constant; a Spanish reply would cost three
  segments per inbound text.

## Slices, when it is scheduled

| # | Slice | Test | Rollback |
|---|---|---|---|
| L1 | Migration: `users.preferred_language`, `email_templates.language` + `variant_of`, `communication_log.language_sent`; normalise the guardian column values (`English`→`en`, `Spanish`→`es`, `Other`→`en`) in a one-off audited script | resolver on SQLite: account beats guardian row, unknown → `en` | additive; drop columns |
| L2 | Profile select on staff profile + parent settings, syncing to the guardian row | jest + `GuardianSyncTest` extension | frontend revert |
| L3 | Template library "Add Spanish version" + editor + SMS segment counter | jest; PHP: variant lookup returns English when no Spanish row | revert; rows stay |
| L4 | Send-time rule in the three gateway paths + `language_sent` logging, behind `TE_FEATURE_LANGUAGE_VARIANTS` | resolution matrix (pref × variant present) all four cells; household conflict logs and sends English | switch off → English only |
| L5 | Transactional libs: payment renderer, tryout offers, invoice/registration, chat digest, parent invite, consent confirmation — one language array each, Spanish copy authored by a human reviewer | render test per template per language: no unresolved tags, no `{{`, button contrast rule | per-template revert |
| L6 | Calendar ICS per-recipient generation (only if a club asks) | ICS parse test | revert |

Rough size: L1–L4 about a week; L5 depends on translation copy more than code.

## Open questions for when this is picked up
1. Who writes the Spanish transactional copy — a bilingual staff member, a translator, or
   machine translation reviewed by a person? The code makes room; it does not supply copy.
2. Should a Spanish preference also flip the **portal** language once the portal is translated,
   or stay a comms-only setting? (The column supports either.)
3. Which club is the pilot? CKU's Spanish-speaking families would be the natural first users.
