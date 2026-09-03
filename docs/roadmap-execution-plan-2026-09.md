# Roadmap Execution Plan — September 2026

Source: `Teams Elevated Roadmap - Untitled.csv` (91 rows, exported 2026-09-02), cross-checked
against the code and the 2026-08-31 scope doc (`docs/club-requests-2026-08-31-scope.md`).
Rows are referenced by their sheet number (R1–R91).

## The three rules every slice follows

1. **A slice is one branch, one PR, one revert.** It ships alone and can be undone alone with
   `git revert` + push. If two changes cannot be reverted independently, they are one slice.
2. **The failing test comes first.** Each slice names the test that reproduces the defect (or pins
   the new behaviour) and it must be seen to FAIL on the pre-change code. Scans beat unit tests
   for "fixed one, missed three" bugs — this repo has had four of those.
3. **Data changes are additive and decoupled from code.** Migrations add tables/columns only, carry
   their reverse SQL in a header comment, and are applied *before* the code that reads them.
   Code that writes a new column does so inside a SAVEPOINT or an `if column exists` guard, so
   reverting the code never requires reverting the migration, and vice versa.

Plus two mechanisms that make rollback cheaper than a deploy:

- **Kill switches** for anything that sends, dispatches, or changes what families see:
  `Env::get('TE_FEATURE_<NAME>', 'on')` on the backend (a Heroku config var flips it in
  seconds, no deploy), or a `club_profile` boolean for per-club rollout. None exist today; the
  first slice that needs one introduces `lib/feature_flags.php` with a scan test that every
  new dispatcher/send path checks one.
- **Deploy order is part of the slice.** Frontend first whenever auth tightens or the contract
  changes; backend first whenever a new column must exist before the UI writes it. Chat server is
  a separate subtree push.

**Baseline to hold** (measured 2026-08-31): PHP 822 pass · chat 151 pass · frontend 428 pass /
33 fail in 10 pre-existing red suites · lint ratchet 74/74 (zero headroom) · next migration **083**.

---

## Completed (as of 2026-09-02 16:20 PT, Heroku v583, chat v26)

| Phase | Slices | Release |
|---|---|---|
| 0 Security | 0.1–0.9 | v559, v561 |
| 1 Test health | 1.1–1.5 | v561, Netlify |
| 2 Silent sends | 2.1–2.4 (dark, switches off), 2.6 | v564, v567, Netlify |
| 3 CKU wins | 3.1 (superseded: no primary guardian, v583), 3.3, 3.4, 3.5, 3.7 | v560, v580, Netlify |
| 4 Identity | 4.1, 4.1b, 4.2, 4.3, 4.4 | v568–v573, chat v26 |
| 5 Storage | 5.0 audit fixes + upload folder refused | v578, v581 |
| 6 Age rule | 6.1–6.3 | v582, migration 088 |
| 8 Coaches | 8.1, 8.2, 8.3 (already built), 8.4 | v574, v577 |
| GOTR G1 | org_units, user_org_access, set-based resolver, super-admin Organizations page (dark) | v586, migration 090 |
| GOTR G2 | cached role context + token diet + context switcher, switches OFF (v588–v590). Worker lane, lists lane (089 indexes, subquery scopes, pagination): shipped 2026-09-03 (v592, 089 applied) | v590, v592 |
| GOTR G3, G4 | compliance model (091) + surfaces (093), dark behind TE_FEATURE_COMPLIANCE / _REMINDERS: merged, AWAITING the Heroku push | pending |
| 2 Silent sends | 2.5 rich signature editor (092) + escaped text signatures (a live injection): merged, AWAITING the Heroku push | pending |
| Decisions | 3, 10, 11c | v579, v580 |

Open: 5.1–5.5 (needs S3), 4.5 (time), 2.5, 8.5, 8.6, Phase 10, GOTR G0–G7, decisions 2 and 13.
Punted/skipped: Phase 7, Phase 9, Spanish, team branding, the larger bets. The phase tables
below keep the per-slice tests and rollback notes and carry ✅ markers on shipped rows.

---

## Phase 0 — Close the open doors (security) — ✅ SHIPPED 2026-09-02, Heroku v559

Not on the sheet as a phase, but every one of these is verified live in production and cheaper
to fix than anything below. All backend-only (no frontend caller exists), so deploy order is
irrelevant and rollback is a single revert. Estimated 2–3 days total.

| # | Slice | Test (must fail first) | Rollback |
|---|---|---|---|
| 0.1 | `JWT::decode()` → `AuthMiddleware::requireAuth()` in `api/invitations-gateway.php`, `api/user-profile.php`, `api/coach-notes.php` | Forged-signature token 401s on all three; **scan** asserts no auth gate outside `auth-gateway`/`super-admin-gateway` calls `decode()` | revert |
| 0.2 | `api/invitations-gateway.php` `create-link` / `send` gate on `te_is_club_admin()` (R-pending) | parent token gets 403; admin of another club gets 403 | revert |
| 0.3 | `controllers/TeamController.php` — delete the routes nothing calls, or authenticate all 15 methods; `assignVolunteer` (`index.php:39`, no auth) gates on background-check status instead of taking it from the request body (`models/Team.php:243`) (R4) | no-token 401 on every route; assign with failed check → 422; body-supplied `background_check_status` ignored | revert |
| 0.4 | `registration/registrations-api.php` PUT/DELETE require staff standing; POST stays public | no-token PUT/DELETE 401; POST still 201 | revert |
| 0.5 | `registration/tryouts-api.php` — authenticate, scope list to coach's age groups (R84) | coach sees only own age groups; club admin sees all | revert |
| 0.6 | `api/payment-reminders.php:242` parenthesise the OR (latent, in stripe worktree) | SQL fixture asserts the filtered row set | revert |
| 0.7 | `MysqlOnlySqlTest` gains `CURDATE(` and scans `models/` | fails on `models/Team.php:230` today | n/a |
| 0.9 | (found by the 1.5 route walk) `/api/athletes`, `/api/coach/players/search`, `/api/seasons` reached by directory shadowing with no auth; gated + scoped, Heroku v561 | `DirectoryShadowedListScopeTest` | revert |
| 0.8 | (found by the R81 hunt) `event-attendance.php` get/save/history and `rsvp-webhook.php?action=status` gate on `te_event_staff_standing()` in `lib/event_standing.php` | `EventStandingTest` | revert |

**Gate to Phase 1:** `scripts/smoke-test.php` green against prod; the forged-token probe from the
scope doc returns 401 on all three endpoints.

---

## Phase 1 — Make the test signal trustworthy (R8, R9, R21) — ✅ 1.1, 1.2, 1.5 SHIPPED 2026-09-02; 1.4 partial (two guards added)

Rollback of anything later depends on CI telling the truth. Runs in parallel with Phase 0.

| # | Slice | Done when |
|---|---|---|
| 1.1 | Triage the 10 red frontend suites: fix or quarantine each with a named reason (`test.skip` + issue ref), never delete | `CI=true npm test` exits 0 |
| 1.2 | RSVP flow phpunit coverage (`calendar-reply-parser.php` + attendance write) using the manual scripts as fixtures | ≥1 test per REPLY path: accept / decline / tentative / unparseable |
| 1.3 | ✅ done on `fix/cku-quick-wins`: 74 → 50 | every later frontend slice has room to add a hook without touching the ratchet |
| 1.4 | Add `TE_*_LIB_ONLY` guards to `legacy/athletes-gateway.php`, `legacy/coaches-gateway.php`, `api/club-users-gateway.php`, `api/invitations-gateway.php` so they are unit-testable | each loads under phpunit without a DB |
| 1.5 | `scripts/smoke-test.php` gains a no-token walk of every `index.php` route (asserts 401) | catches the next TeamController |

No production behaviour changes; nothing to roll back.

---

## Phase 2 — Stop lying to staff (silent failures: R1, R2, R3, R11, R12, R13) — 2.1–2.4, 2.6 SHIPPED 2026-09-02 (sends and dispatcher dark behind TE_FEATURE_* switches, all off; 083 applied); 2.5 signature editor remains

Every item here is a button that reports success and does nothing. Each slice sits behind a kill
switch so a bad template or a send storm is a config-var flip, not a deploy.

| # | Slice | Kill switch | Test | Deploy / rollback |
|---|---|---|---|---|
| 2.1 | Transactional stubs → `lib/Email.php` + `->forClub()`: invoice email, payment receipt, payment reminder, payment-failure notice, registration confirmation (R3, R11). Stripe-touching files are done in the stripe worktree. | `TE_FEATURE_TRANSACTIONAL_EMAIL` | each endpoint asserts `Email::send` invoked with the club From; `EmailSenderTest` branding count; scan: no `DEMO:` string remains in `api/` | backend only; revert or flip switch |
| 2.2 | Tryout "Send offers" → offer email (+SMS if number on file) per family, one `communication_log` row each (R2, R12 first touchpoint) | `TE_FEATURE_TRYOUT_OFFER_EMAIL` | offers endpoint enqueues N sends for N offers; zero sends when switch off; existing `joined_date` acceptance test still green | backend only |
| 2.3 | Migration **083** `broadcast_campaigns.body` / `html_body` (additive; reverse = `DROP COLUMN`) | — | fixture refresh diff is ~2 lines | apply before 2.4; safe to leave if 2.4 reverts |
| 2.4 | Scheduled-send dispatcher as a throttled tick in `workers/queue-worker.php` (copy the chat-notification tick; per-campaign try/catch; rebuilt via `$buildServices()`); then remove the 400 guard at `communications-gateway.php:488` | `TE_FEATURE_SCHEDULED_DISPATCH` | **first:** a throwing campaign does not stop the other queues; due campaign dispatches once; not-yet-due does not; worker reconnect rebuilds the dispatcher | backend; flip switch → campaigns sit as `scheduled`, nothing lost |
| 2.6 | `PaymentCheckout.tsx:485` renders an empty page when the plans response has no `plans` key (found by 1.1); guard + real error state. Stripe worktree. | jest | frontend revert |
| 2.5 | Rich HTML signature editor (R13). Append already ships (`EmailSendService.php:167-183`, `nl2br` of a plain textarea); only the editor is missing. | — | signature HTML round-trips and is sanitised server-side; append test unchanged | frontend + a sanitiser in the send path |

**Staged test before flipping each switch on:** one real send to a Teams Elevated address per
path from prod, per `feedback-ship-to-prod-then-she-tests`.

---

## Phase 3 — CKU quick wins with a known cause — 3.1, 3.3, 3.4, 3.7 SHIPPED 2026-09-02; 3.2 folded into 0.8; 3.5 retest and 3.6 decision remain

Small, independent, mostly frontend. Each is its own PR and revert.

| # | Row | Slice | Test |
|---|---|---|---|
| 3.1 | R78 | ~~Primary guardian does not stick.~~ **SUPERSEDED 2026-09-02 — there is no primary guardian.** Maggie: guardians are equal. The row is closed by removing the concept, not by making the flag stick: every control, badge, writer and query predicate is gone and `athlete_guardians.is_primary` is a legacy column. | `tests/php/NoPrimaryGuardianTest.php` + `frontend/src/crewEquality.test.ts` — scans, because the concept lived in 7 writers, 15 query sites and 4 components |
| 3.2 | R81 | Hide RSVPs from other parents. **The sheet's evidence is wrong**: `calendar-events-gateway.php?action=get` already scopes non-staff to their own family (:315-377). Find the screen where a parent actually sees other families' RSVPs (parent-portal event view / `legacy/events-gateway.php`) and scope that one. | parent token sees own family only on the offending endpoint; coach sees all |
| 3.3 | R89/R90/R91 | Programs: migration **084** `programs.sort_order`, `programs.archived_at` (additive); reorder, archive, collapsible types | gateway list excludes archived by default; order persists; jest for collapse state |
| 3.4 | R88 | Home = overview of teams / athletes / revenue / programs; `/` lands there | existing dashboard tests + route test |
| 3.5 | R64, R5 | Facilities edit + Maps search: **retest only**. Edit goes through `legacy/venues-gateway.php` PUT (auth + ownership + full field set); `api/venues.php` PUT is a stale duplicate nothing calls — delete it. | manual retest, then close the rows; `QueriedTablesExistTest` still green after the delete |
| 3.6 | R75 | Team branding: decision recorded (club branding wins for multi-team users), so remove the dead `context_type=team` path or wire the single-team case | decision first, then a one-line change |
| 3.7 | R85 | Evaluations behind a sort/filter dropdown | jest on sort order |

---

## Phase 4 — Identity: finish `user_guardians` (R6, crew-by-link empty portal) — 4.1, 4.1b, 4.2, 4.3 SHIPPED 2026-09-02 (Heroku v571, chat v26); 4.4 SHIPPED v573 (auth-gateway hook = decision 13); 4.5 waits a week of zero email-only hits

Follows `docs/user-guardians-identity-plan.md` exactly; phases 0–1 are done. Order matters and is
the reverse of the first draft: write links at their source **before** retiring the email match.

| # | Slice | Test | Rollback |
|---|---|---|---|
| 4.1 | `lib/guardian_identity.php` resolver = `user_guardians` UNION email match; convert the 12 call sites | scan: no direct `lower(g.email) = lower(u.email)` outside the resolver; resolver returns a superset of the old match on the fixture | revert; strictly wider than today so nothing loses access |
| 4.2 | Parent-portal empty state ("ask your club admin to connect you") | RTL | frontend revert |
| 4.3 | Club-admin "connect this account to this athlete" tool; writes a link + audit row | admin-only 403 test; audit row present | revert; links written stay valid |
| 4.4 | Write links on invite accept and registration | accept response `linked_athletes > 0` | revert |
| 4.5 | Log email-match-only hits for a week; when zero, delete the fallback (one line) | the log query returns 0 for 7 days | re-add the line |

Chat server sweep is part of 4.1 (separate subtree deploy).

---

## Phase 5 — Durable photo AND document storage (R15, R72, R87) — PROMOTED 2026-09-02: the documents audit found uploads vanish on restart and are public while they exist (decision 14); GOTR G4 depends on it

The urgent half is storage; crop and bulk come after.

| # | Slice | Test | Rollback |
|---|---|---|---|
| 5.0 | ✅ SHIPPED 2026-09-02 (Netlify eb955b0, Heroku v578). Documents coherence fixes from the audit: `expiring`/`for-target` gates, cross-club assignment validation, the coach picker calling a non-existent action, route guards, false-empty on the parent page, dead tree removed | `DocumentsGatewayScopeTest` + jest | revert |
| 5.1 | `lib/PhotoStorage.php` with `local` and `s3` drivers, **plus an authenticated download endpoint** that checks `userCanReadDocument` and streams — `/uploads/` stops being static selected by `PHOTO_STORAGE_DRIVER`. There is exactly one write point today (`api/upload.php:119`) serving athlete, coach, match-card, tournament and document uploads, so this is one file to change. | driver contract test on both; scan: no `move_uploaded_file` outside the lib | flip the var back to `local` |
| 5.2 | One-off migrate script for photos that still exist on disk (most will not) | dry-run count matches | n/a |
| 5.3 | Coach photos on the same path | same test | same |
| 5.4 | Crop/resize client-side; bulk upload by athlete id or jersey number | jest + import strategy test | frontend revert |
| 5.5 | R87 photo at registration (optional, club toggle) | registration test | club flag |

Needs the S3 bucket + IAM key as Heroku config vars before 5.1 ships.

---

## Phase 6 — One age-group rule (R7b, R14, R73) — DECIDED 2026-09-02: 1 August to 31 July, everywhere — 6.1, 6.2 (fixture), 6.3 SHIPPED 2026-09-02 (Heroku v582, migration 088)

Maggie: "The age matrix runs from August 1 to July 31, replacing the previous January 1 to
December 31 calendar birth-year mandate." No per-club setting for now. Then:

| # | Slice | Test |
|---|---|---|
| 6.1 | One PHP rule in `lib/age_rule.php` (1 Aug roll); `AgeEligibilityService` uses it; no migration (per-club cutoff dropped) | PHP + TS tests assert identical answers for the same DOB/date pairs (shared JSON fixture) |
| 6.2 | `utils/ageGroup.ts` reads the club's cutoff from the club profile it already loads | existing `ageGroup.test.ts` + fixture |
| 6.3 | R73 field size: `venues`/fields `field_size` (7v7/9v9/11v11) + age-group→size map; scheduler filters | gateway filter test |

Rollback: defaults equal today's behaviour, so reverting code with the migration in place changes
nothing.

---

## Phase 7 — Payments, next slices (R10, R65, R70) — PUNTED 2026-09-02 (Maggie: clubs are not using discount codes yet)

In the `te-stripe-payments` worktree only. Small first.

| # | Slice | Test |
|---|---|---|
| 7.1 | R65 discount codes: migration **086** `discount_codes` (the orphan table gets a real shape) + apply at checkout | code applies once, expires, respects min amount; `PaymentReportService` shows the discount line |
| 7.2 | R70 public program registration + pay + receipt (reuses 2.1's receipt) | end-to-end fixture with a Stripe test-mode session |
| 7.3 | R10 ACH / saved methods / autopay installments, one at a time, each behind `TE_FEATURE_*` | per the payments dossier's phase pattern |

---

## Phase 8 — Coaches and programs (CKU asks)

| # | Row | Slice |
|---|---|---|
| 8.1 | R66 | ✅ SHIPPED 2026-09-02 (Heroku v574, migration 085) — `program_staff`, `lib/program_scope.php`; calendar upcoming + recipient search widened additively |
| 8.2 | R86 | ✅ SHIPPED 2026-09-02 (Heroku v577, migration 087) — email dark behind `TE_FEATURE_TRYOUT_COACH_INVITE_EMAIL` |
| 8.3 | R80 | ✅ ALREADY BUILT — `users.coaching_background` rendered on the portal athlete page's Coaches card via `api/team-coaches.php`. Sheet was wrong; closed 2026-09-02. |
| 8.4 | R76/R77 | ✅ SHIPPED 2026-09-02 (Heroku v577, migration 086) — Performance tab, IDP goals, season trend; parent read-only |
| 8.5 | R67 | Lineup builder (new feature, spec first) |
| 8.6 | R68 | Referee feedback from the coaches portal |

Each is additive: a new table or a new page, revertable alone.

---

## Phase 9 — Tournament remainder — SKIPPED 2026-09-02 (Maggie; no customer pull)

9.1 R26 schedule export (CSV/ICS, then PDF) + tournament clone → 9.2 R83 bracket options by team count
→ 9.3 R27 match protest + emergency composer → 9.4 R28 referee assignment (tables exist, no API/UI)
→ 9.5 R30 roster freeze + waiver e-sign → 9.6 R29 tournament financials (after Phase 7).

---

## Phase 10 — Communications backlog

R16 SMS→chat mirror · R17 fundraiser SMS receipt · R18 campaign live preview · R19 sponsor blocks in
email · R20 topic-level unsubscribe · R79 carry previous thread · R24 inbound email threading ·
R25 canned responses · R23 unified Messages view (last; it is the layer over everything above).

---

## Parked — needs a decision or a separate discovery, not engineering

- **GOTR rows R44–R63**: PROMOTED 2026-09-02 — a real engagement. National → division → chapter/council
  → location hierarchy, coach/volunteer compliance + background checks visible at council AND division
  level, ~30,000 coaches, 270 locations. Everything today is club/team-based, so this is an
  architecture plan first: `docs/gotr-hierarchy-plan-2026-09.md` (in progress).
- **R69 Spanish**: i18n framework choice + ongoing translation cost. Decide whether it is a
  contract requirement before starting.
- **R33–R37 association / multi-club**, **R42 CRM layer**, **R31/R32 tournament 4–5**, **R43
  competitor gaps**, **R22 Linktree**, **R38 player cards 2–3**, **R39 sponsor tiers**, **R40
  calendar 3–4**, **R41/R74 volunteer post-MVP**, **R71 doc-center attachments**, **R82 umbrella**.
- Untracked in the working tree today: `api/oauth/band-callback.php`, migration `033_archive_club_documents.sql`
  (a number already used), three docs. Decide whether they are a workstream before they ship
  by accident inside someone else's push.

---

## Sequencing summary

```
Week 1   Phase 0 (security)  ‖  Phase 1 (test health)
Week 2   Phase 2.1–2.2 (transactional + tryout email)  ‖  Phase 3 quick wins
Week 3   Phase 2.3–2.4 (scheduled dispatch)  ‖  Phase 4.1–4.2
Week 4   Phase 4.3–4.5  ‖  Phase 5.1–5.3 (photo storage)
Then     6 → 7 → 8 → 9 → 10, each phase gated on the previous phase's smoke test.
```

Migration numbers claimed by this plan: 083 (broadcast body), 084 (programs order/archive),
085 (age cutoff), 086 (discount codes), 087 (program staff). Check every worktree before
creating each.
