# Decisions Pending — September 2026

Non-blocking calls that came out of the roadmap work. Nothing here stops the next slice;
each one changes a product behaviour and is Maggie's to make. When one is decided, record
the answer and date under it, then do the work (or delete the item).

Shareable version: the "Pending Decisions" page (link in memory).

---

## From the 2026-09-02 deploys

### 1. Five athletes have broken primary-crew flags
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

### 3. Coaches can create tryouts
The security fix could have made "Create Tryout" admin-only. Every coach has the button
today and `/program-management` has no role gate, so I matched the live UI (staff).
- **Options:** (a) keep staff; (b) admin-only — one token in `tryouts-api.php`
  (`tryout_requireClubAdminForClub` → `te_is_club_admin`) plus hiding the button.
- **Recommendation:** ask CKU. If coaches run their own age-group tryouts, keep (a).
- **If left:** unchanged from before today.

### 4. Age-group narrowing on the tryout registrations list (R84)
CKU wants coaches to see only their age groups. The auth/club scoping shipped; the
narrowing did not, because the frontend rolls the season year on Aug 1 and
`AgeEligibilityService.php` uses the tournament start year — filtering on a rule that two
halves disagree about would hide the wrong athletes.
- **Options:** (a) Aug 1 roll everywhere (US youth soccer convention); (b) calendar year
  of the event; (c) per-club setting, default Aug 1 (Phase 6 in the plan).
- **Recommendation:** (c), default Aug 1. Unblocks R84, R14 and R73 together.
- **If left:** coaches see the whole club's tryout list, scoped to their club only.

### 5. Team branding for multi-team users (R75)
Recorded decision: a coach on multiple teams, or a parent with kids on different teams,
sees club branding. Still open: the single-team case, and whether to delete the dead
`context_type=team` endpoint (`organization-branding.php:111`, no caller).
- **Options:** (a) wire single-team users to team branding, club for everyone else;
  (b) club branding always, delete the team path.
- **Recommendation:** (b) until a club asks. Less code, no inconsistency between a
  parent's two children.
- **If left:** everyone sees club branding, as today. The dead endpoint stays.

### 6. Facilities edit and Google Maps search (R64, R5)
Code review says both work: edit goes through the legacy venues gateway with the full
field set, and the Maps key and component are in place. Needs one retest in prod.
- **Ask:** open Facilities, edit a saved venue, save; then type an address in the search
  box. Report what happens.
- **Then:** close both rows, or send me the exact behaviour and I open a real bug.
- Also: `api/venues.php` PUT is a stale duplicate nothing calls. I will delete it once
  the retest closes.

---

## Carried from the roadmap plan

### 7. Spanish interface (R69)
Needs an i18n framework choice and a translation cost on every new screen from then on.
- **Ask:** is this a contract requirement for CKU or any prospect? If yes, when.
- **If left:** parked.

### 8. GOTR compliance tracking (R44–R63)
Twenty user stories for a different customer and a different product shape
(certifications, LMS integration, council hierarchy).
- **Ask:** is this a real engagement? If yes, it gets its own discovery and plan, not
  slices inside the Teams Elevated roadmap.
- **If left:** parked.

### 9. Chat polls show voter names
Documented choice (`docs/chat-polls-scope.md`): voters are visible unless the coach
ticks anonymous. If a CKU coach runs "who can make Saturday?" as a poll, families see
each other's answers there, which reads like the RSVP complaint.
- **Ask:** confirm with CKU whether that is the surface they meant. If so, default polls
  to anonymous.
- **If left:** unchanged.

### 10. `evaluate` takes `evaluator_id` from the request body
Any staff member can file a tryout evaluation attributed to another coach. Now bounded
to authenticated club staff, still a spoof.
- **Recommendation:** take it from the token, as `marked_by` now does for attendance.
  Small; I will fold it into the next tryouts slice unless you say otherwise.
