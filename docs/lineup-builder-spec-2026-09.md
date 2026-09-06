# Lineup builder — spec (roadmap 8.5, R67, CKU ask)

Written 2026-09-06. Spec first, per the plan; nothing is built yet. CKU asked for this twice
(platform feedback and coaches-portal feedback). There are zero `lineup` references in the
code today.

## What a coach is asking for, in their words

"Before a game I want to set who starts, where they play, and who is on the bench, from my
phone, and have it next game without starting over."

## What already exists and gets reused

| Need | Already in the product |
|---|---|
| The roster and each player's positions | `team_members` (`positions`, `primary_position`, `jersey_number`, `status`) — the `SOCCER_POSITIONS` list in `RosterManagement.tsx` is the vocabulary |
| Which game | `calendar_events` rows with `type = 'game'`, joined to the team through `calendar_event_teams`; `opponent_name` is on the event |
| How many on the field | `lib/field_size.php`: U8 and under 4v4, U9/U10 7v7, U11/U12 9v9, U13+ 11v11, from the team's `age_group` |
| Who is available | `event_attendance` (`present / absent / late / excused`) and RSVP status; both staff data via `te_event_staff_standing()` |
| Who may edit | `te_event_staff_standing()` — club admin, or a coach of a team ON the event. Same predicate as attendance |
| Injured / suspended players | `team_members.status` (`injured`, `suspended`, `inactive`) |

So the builder is a screen and two tables; every input it needs is already recorded.

## Design

### Data (migration 096, additive, reverse SQL in the header)

`lineups`
- `id`, `club_id`, `team_id` (FK teams), `calendar_event_id` (FK calendar_events, nullable —
  a NULL event is the team's **template** lineup), `name` (text; "Default", "vs Salina"),
  `formation` (text, e.g. `4-3-3`, `2-3-1`; validated against the preset list for the field
  size), `field_size` (`4v4/7v7/9v9/11v11`, copied at creation from the age-group rule so a
  later age-group edit does not silently re-shape a saved lineup), `published_at` (nullable,
  see decision 1), `created_by`, `updated_by`, `created_at`, `updated_at`.
- UNIQUE (`team_id`, `calendar_event_id`) where event is not null; one template per team is
  enforced in code, not by constraint, so a second template can be added later.

`lineup_slots`
- `id`, `lineup_id` (FK, cascade), `athlete_id` (FK athletes), `slot` (text: `GK`, `LB`,
  `CB1`, … from the formation preset, or `BENCH`), `sort_order` (bench order / sub order),
  `captain` (bool), `note` (text, short — "left foot", "first sub for CB").
- UNIQUE (`lineup_id`, `athlete_id`); UNIQUE (`lineup_id`, `slot`) where slot ≠ `BENCH`.

No minutes-played, no substitution log, no live-game clock. See decision 3.

### Formation presets — one list, mirrored (`lib/lineup_formations.php` + `utils/lineupFormations.ts`)

| Field size | Players on field | Presets (first is default) |
|---|---|---|
| 4v4 | 4 | `1-2-1`, `2-2` (no goalkeeper in 4v4 by US Youth Soccer rule — expose as a per-club toggle later if a club asks) |
| 7v7 | 7 (GK + 6) | `2-3-1`, `3-2-1`, `2-1-2-1` |
| 9v9 | 9 (GK + 8) | `3-3-2`, `3-2-3`, `2-3-3`, `3-4-1` |
| 11v11 | 11 (GK + 10) | `4-3-3`, `4-4-2`, `4-2-3-1`, `3-5-2`, `4-1-4-1` |

Each preset expands to named slots with x/y coordinates on a normalised pitch (0–100), so
the frontend draws the same shape at any size and the PDF/print view matches the screen. A
consistency test (`LineupFormationConsistencyTest`) locks the PHP and TS lists together,
like `JerseySizeConsistencyTest`.

### API — `api/lineups.php` (requireAuth; `lib/lineups.php` holds the logic)

| Action | Standing | Notes |
|---|---|---|
| `get?team_id&event_id` | team view standing (`tpg_requireTeamViewAccess`) for staff; **parents 403 unless `published_at` is set** (decision 1) | returns the event lineup, or the template with `is_template: true` so the screen can say "starting from your default" |
| `save` | `te_event_staff_standing()` on the event; for a template, `te_team_roster_staff_standing()` | full replace of slots in one transaction; validates: every athlete is an active roster member, no duplicate athlete, no duplicate field slot, on-field count ≤ field size, formation belongs to the field size. Injured/suspended athletes are allowed on the bench with a warning flag returned, never silently dropped |
| `copy-from?source=template|event_id` | same as save | the "same as last game" button |
| `publish` / `unpublish` | same as save | sets/clears `published_at`; audited |
| `print?event_id` | same as get | HTML view sized for a phone screenshot or A4; no PDF library |

Audit: `lineup_saved`, `lineup_published` through `lib/AuditLogger.php`.

### Screens

**Coach — `/teams/:id/lineup?event=:eventId`** (staff app, mobile-first; this is used pitch-side)
- Pitch on top: formation slots as tappable circles with jersey number and last name; tap a
  slot, tap a player, done. Long-press to clear. Formation picker above the pitch.
- Bench below: the rest of the active roster, sorted by `primary_position` then jersey;
  players marked `absent`/`excused` for this event are greyed with the reason; injured and
  suspended show a badge. Drag is a nice-to-have, tap-to-swap is the requirement.
- Buttons: **Use last game**, **Use default**, **Save as default**, **Save**, **Publish to
  crew** (decision 1), **Print**.
- Entry points: the event modal for a game ("Lineup" button, staff only) and the team page's
  schedule list.

**Admin** — nothing new. Club admins reach the same screen through the same standing.

**Crew** (only if decision 1 is "yes") — read-only pitch view on the parent portal game
detail after `published_at`, showing the child's slot highlighted. No bench order, no notes.

### Tests
- PHP (SQLite): validation matrix (over-count, duplicate athlete, duplicate slot, wrong
  formation for size, non-roster athlete, injured-on-bench warning), standing (parent 403
  unpublished, parent 200 published if decision 1, coach of another team 403), copy-from,
  template fallback; the formation consistency scan.
- Jest: tap-to-place, swap, count guard on the UI, absent player greyed, publish button
  hidden for unpublished-capable roles.
- Smoke test walk: `lineups.php?action=get` with no token → 401.

### Rollback
Frontend revert hides the buttons; the two tables are additive and stay. A kill switch is
not needed — nothing here sends.

### Size
About 4 engineering days: 1 lib + migration + tests, 2 the coach screen, 1 print view, copy
buttons and the crew view if wanted.

## Decisions for Maggie

1. **Can a coach publish the lineup to families?** Recommended **yes, opt-in per game**
   (`published_at`), because a lineup is roster-adjacent data families already see in person,
   and it answers the "is my kid starting" text the coach otherwise gets. Default unpublished.
2. **Is the lineup per game only, or also a template?** Recommended **both** (the NULL-event
   row). Without a template the "start over every week" complaint is not solved.
3. **Minutes played and substitution tracking?** Recommended **not now**. It is a live-game
   tool with a clock and belongs with match reporting; the builder is pre-game. The slot
   table leaves room (a `subbed_in_at` column later is additive).
4. **4v4 goalkeeper**: US Youth Soccer plays no keeper at 4v4; some clubs do. Recommended
   ship without and add a club toggle if asked.
