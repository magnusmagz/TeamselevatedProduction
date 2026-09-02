# Decisions Pending — September 2026

Non-blocking calls that came out of the roadmap work. Nothing here stops the next slice;
each one changes a product behaviour and is Maggie's to make. When one is decided, record
the answer and date under it, then do the work (or delete the item).

Shareable version: the "Pending Decisions" page (link in memory).

---

## From the 2026-09-02 deploys

### 1. Five athletes have broken primary-crew flags — MOOT 2026-09-02
**Maggie: there is no "primary parent" in Teams Elevated; guardians are equal.** The concept is
being removed from the UI and every writer (branch `fix/remove-primary-guardian`); the column
stays, unread. Nothing needs fixing on these five rows.

_Original item:_
Before today's fix, two writers marked every crew link primary, so four club-32 athletes
(Emily Thompson 151, Grace Garcia 164, Gabriel Miller 165, Olivia Thompson 181) have two
primaries and one club-51 athlete (Bonnie Ziegler 463) has none. The fix stopped new
cases; existing rows were left alone on purpose.
- **Options:** (a) fix each in the Crew modal (one click per athlete); (b) I run a one-off
  that keeps the oldest link as primary and audits it; (c) leave until the families touch it.
- **Recommendation:** (a) for club 32 (test/demo data, four rows) and ask CKU which parent
  is primary for Bonnie Ziegler.
- **Report:** `heroku run --no-tty -a teamselevated-backend php scripts/report-duplicate-primaries.php`
- **If left:** the athlete list shows the oldest link as primary, deterministically, which
  may be the wrong parent.

### 2. Treasurer sees Revenue as "Unavailable" on the new home page
`financial-permissions` grants treasurers `can_view_revenue`, so the tile renders, but
`api/revenue-summary.php` gates on `te_assert_financial_admin` (club_admin only) and 403s.
Pre-existing mismatch, surfaced by the tile.
- **Options:** (a) widen `revenue-summary.php` to treasurer (it is a read); (b) hide the
  tile for treasurers.
- **Recommendation:** (a). The treasurer role exists to see money. Payments worktree.
- **If left:** one live treasurer account (club 51) sees a tile that never loads.

### 3. Coaches can create tryouts — DECIDED 2026-09-02: admin-only
**Maggie:** creating a tryout should be a permission that is enabled; admin-only for now, revisit
with a per-role permission toggle if users need it. Shipped (branch `fix/tryout-create-admin-only`).

_Original item:_
The security fix could have made "Create Tryout" admin-only. Every coach has the button
today and `/program-management` has no role gate, so I matched the live UI (staff).
- **Options:** (a) keep staff; (b) admin-only — one token in `tryouts-api.php`
  (`tryout_requireClubAdminForClub` → `te_is_club_admin`) plus hiding the button.
- **Recommendation:** ask CKU. If coaches run their own age-group tryouts, keep (a).
- **If left:** unchanged from before today.

### 4. Age-group narrowing on the tryout registrations list (R84) — DECIDED 2026-09-02
**Maggie: the age matrix runs 1 August to 31 July, replacing the calendar birth-year rule.**
One rule everywhere (option a); no per-club setting for now. Unblocks R84, R14, R73.

_Original item:_
CKU wants coaches to see only their age groups. The auth/club scoping shipped; the
narrowing did not, because the frontend rolls the season year on Aug 1 and
`AgeEligibilityService.php` uses the tournament start year — filtering on a rule that two
halves disagree about would hide the wrong athletes.
- **Options:** (a) Aug 1 roll everywhere (US youth soccer convention); (b) calendar year
  of the event; (c) per-club setting, default Aug 1 (Phase 6 in the plan).
- **Recommendation:** (c), default Aug 1. Unblocks R84, R14 and R73 together.
- **If left:** coaches see the whole club's tryout list, scoped to their club only.

### 5. Team branding for multi-team users (R75) — DEFERRED 2026-09-02
**Maggie:** we will want team branding because of Canva and socials; leave it in its current state
and revisit when the use case is clearer. Nothing to build or delete now.

_Original item:_
Recorded decision: a coach on multiple teams, or a parent with kids on different teams,
sees club branding. Still open: the single-team case, and whether to delete the dead
`context_type=team` endpoint (`organization-branding.php:111`, no caller).
- **Options:** (a) wire single-team users to team branding, club for everyone else;
  (b) club branding always, delete the team path.
- **Recommendation:** (b) until a club asks. Less code, no inconsistency between a
  parent's two children.
- **If left:** everyone sees club branding, as today. The dead endpoint stays.

### 6. Facilities edit and Google Maps search (R64, R5) — CLOSED 2026-09-02
**Maggie: "facilities is good."** Both rows close; the stale `api/venues.php` PUT now answers 405.

_Original item:_
Code review says both work: edit goes through the legacy venues gateway with the full
field set, and the Maps key and component are in place. Needs one retest in prod.
- **Ask:** open Facilities, edit a saved venue, save; then type an address in the search
  box. Report what happens.
- **Then:** close both rows, or send me the exact behaviour and I open a real bug.
- Also: `api/venues.php` PUT is a stale duplicate nothing calls. I will delete it once
  the retest closes.

---

## Carried from the roadmap plan

### 7. Spanish interface (R69) — DEFERRED 2026-09-02
**Maggie:** still a nice-to-have; browser translation is enough for now. Revisit later.

_Original item:_
Needs an i18n framework choice and a translation cost on every new screen from then on.
- **Ask:** is this a contract requirement for CKU or any prospect? If yes, when.
- **If left:** parked.

### 8. GOTR compliance tracking (R44–R63) — DECIDED 2026-09-02: real engagement
Maggie: a national → division → chapter/council → location hierarchy, compliance and
background-check visibility at council and division level, ~30,000 coaches, 270 locations.
Getting its own architecture plan (`docs/gotr-hierarchy-plan-2026-09.md`). No longer a
decision; tracked in the roadmap plan.

### 9. Chat polls show voter names — RESOLVED 2026-09-02
**Maggie:** the poll creator already chooses anonymous or not. Nothing to change.

_Original item:_
Documented choice (`docs/chat-polls-scope.md`): voters are visible unless the coach
ticks anonymous. If a CKU coach runs "who can make Saturday?" as a poll, families see
each other's answers there, which reads like the RSVP complaint.
- **Ask:** confirm with CKU whether that is the surface they meant. If so, default polls
  to anonymous.
- **If left:** unchanged.

### 10. `evaluate` takes `evaluator_id` from the request body — DECIDED 2026-09-02: from the token
Shipped (branch `fix/evaluator-token-and-venues`).

_Original item:_
Any staff member can file a tryout evaluation attributed to another coach. Now bounded
to authenticated club staff, still a spoof.
- **Recommendation:** take it from the token, as `marked_by` now does for attendance.
  Small; I will fold it into the next tryouts slice unless you say otherwise.

### 11. RSVP email replies: three behaviours found by the new tests (2026-09-02) — 11c DECIDED, 11a/11b WAIT
**Maggie:** do (c) now — 60-day expiry on links minted from today, older links unaffected (shipped).
Hold (a) UID routing and (b) second replies for now.

_Original item:_
Pinned as `_KNOWN_DEFECT` tests in `tests/php/CalendarReplyParserTest.php` and
`RsvpTokenTest.php`; each test says to delete it when the behaviour changes.
- **(a) A reply lands on the newest pending invitation, not the event replied to.** The
  parsed UID is discarded. A family with two open invites who declines the first marks
  the second. Fix: store the event UID on `calendar_event_attendees` (additive column) and
  match on it. Recommend: yes, next calendar slice.
- **(b) An emailed reply can only be given once**; a change of mind gets "No matching
  attendee found", while the one-click link path allows changes. Recommend: allow updates
  from email too, consistent with the link.
- **(c) RSVP links never expire** although the page says they do. Anyone holding a
  forwarded invite email holds a permanent credential for that family on that event.
  Adding a TTL invalidates links in emails already sent. Recommend: 60-day TTL, applied
  to links minted from the change forward, page copy kept.
- **If left:** all three stay as they are; the tests keep documenting them.

### 12. Should "not selected" tryout families get an automated email? — DECIDED 2026-09-02: (a) manual
**Maggie: yes to (a).** The club tells those families itself; the send-offers result reports the held-back count.

_Original item:_
Send-offers now emails roster and waitlist offers (Phase 2, behind
`TE_FEATURE_TRYOUT_OFFER_EMAIL`). Rows marked `not_selected` are recorded but deliberately
NOT emailed by that path; the response reports how many were held back.
- **Options:** (a) keep it manual — the club tells those families itself; (b) send a
  short, kind "not selected this season" email from the same path, with copy you approve.
- **Recommendation:** (a) for CKU's first tryout cycle on the platform; revisit after.
- **If left:** staff see "N not notified (not selected)" in the send-offers result and
  contact those families directly.

### 13. One line in `api/auth-gateway.php` so parent-invite redemption writes the link (2026-09-02)
`auth-gateway.php` is on the do-not-modify list. Registration and the shareable-invite accept now
write `user_guardians` links; the "Invite to portal" / approval flow redeems in
`handleSetParentPassword` and does not. Until this lands those families rely on the email
fallback, which still works.
- **The change:** after the write/commit catch block (~line 911), one guarded call:
  `te_link_guardian_on_accept($db, (int)$user['id'], null, $email)` inside try/catch, never fatal.
- **Recommendation:** approve it as the one exception; it is a call-out, not a change to auth.
- **If left:** phase 4.5 (retiring the email match) cannot complete for invited families.

### 14. Uploaded documents: block the folder now, or stream through an authenticated endpoint? (2026-09-02)
Audit finding: `api/upload.php` writes to the dyno's local disk, which is wiped on every restart
or deploy, and Apache serves `/uploads/` as static files — no login, no ownership check — for
as long as they exist. Any file uploaded through the Club Document Center is gone within a day
and readable by anyone with the URL until then. Most real rows are probably "Paste link"
(Google Drive), which is why nobody noticed.
- **Options:** (a) do Phase 5 durable storage now (S3 bucket + key from you) and serve every
  download through an authenticated endpoint that checks the document predicate; (b) until then,
  block `/uploads/` in `.htaccess` and hide the Upload tab so only links are offered.
- **Recommendation:** (b) this week, (a) next — Phase 5 is also a prerequisite for GOTR uploads.
  Needs the bucket and key from you for (a).
- **If left:** uploads keep silently disappearing and are public while they exist.

### 15. `document_acknowledgments`: build the signing flow or drop the table (2026-09-02)
The table exists in production (signature, IP, expiry) and zero code reads or writes it. Same
pattern as the consent checkbox removed on 07-30: the schema asserts signing and stores none.
`is_required` is a badge that blocks nothing; `expires_at` is a badge with no reminder.
- **Options:** (a) build acknowledge/sign into the GOTR compliance work (G3/G4) as the proof
  path for "attested" requirements, with reminders; (b) drop the table and the badges until then.
- **Recommendation:** (a). It is the coach-side surface the audit found missing, and G4 builds
  it anyway. Until G4 the badge copy should say "required" without implying enforcement.
- **If left:** the club page keeps showing "required" and "expires" labels that do nothing.
