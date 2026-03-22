# Tournament Module — Human Testing Plan

**Tester:** Club Admin (maggie+ms@4msquared.com, club 32)
**Date:** March 21, 2026
**Environment:** Production (Netlify frontend + Heroku backend + Neon DB)

---

## How to Use This Plan

Work through each section in order. Check the box when the step passes. If something fails, note the actual behavior in the "Notes" column.

---

## 1. Navigation & Access

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 1.1 | Log in as club admin | Dashboard loads | [ ] | |
| 1.2 | Look for "Tournaments" in the top nav bar | Link visible between Fundraisers and Communications | [ ] | |
| 1.3 | Click "Tournaments" | Tournament list page loads with "No tournaments yet" message | [ ] | |
| 1.4 | Verify "Create Tournament" button is visible | Button shown (admin only) | [ ] | |

---

## 2. Create Tournament

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 2.1 | Click "Create Tournament" | Form loads with all sections: Basic Info, Dates, Location, Fees, Contact | [ ] | |
| 2.2 | Try to submit with empty form | Validation errors: "Tournament name is required", "Start date is required", "End date is required" | [ ] | |
| 2.3 | Enter name: "Spring Classic Test" | URL slug auto-generates: "spring-classic-test" | [ ] | |
| 2.4 | Set sport to "Soccer" | Dropdown shows soccer selected | [ ] | |
| 2.5 | Set start date: tomorrow | Date picker works | [ ] | |
| 2.6 | Set end date: day after tomorrow | No validation error | [ ] | |
| 2.7 | Try setting end date BEFORE start date, click submit | Validation error: "End date must be on or after start date" | [ ] | |
| 2.8 | Fix end date, fill in location: "Test Park", "123 Main St", city/state/zip | Fields accept input | [ ] | |
| 2.9 | Set entry fee: 500.00 | Accepted | [ ] | |
| 2.10 | Set max teams per division: 16 | Accepted | [ ] | |
| 2.11 | Fill contact name, email, phone | Fields accept input | [ ] | |
| 2.12 | Click "Create Tournament" | Redirects to tournament detail page | [ ] | |
| 2.13 | Verify tournament shows as "Draft" status | Status badge says "Draft" | [ ] | |

---

## 3. Tournament Detail & Status Transitions

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 3.1 | On detail page, verify Overview tab shows all entered info | Name, dates, location, fee, contact, public link all displayed | [ ] | |
| 3.2 | Click "Edit" button | Edit form loads with all previously entered data pre-filled | [ ] | |
| 3.3 | Change the name, click "Save Changes" | Returns to detail page with updated name | [ ] | |
| 3.4 | Use "Change Status" dropdown, select "Registration Open" | Confirm dialog appears | [ ] | |
| 3.5 | Confirm status change | Status badge changes to "Registration Open" (green) | [ ] | |
| 3.6 | Try changing status to "Completed" (invalid from Registration Open) | Should NOT appear in dropdown — only valid transitions shown | [ ] | |
| 3.7 | Change status to "Registration Closed" | Status updates | [ ] | |
| 3.8 | Go back to tournament list | Tournament appears in list with correct status badge | [ ] | |

---

## 4. Divisions with Sport Presets

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 4.1 | Click into the tournament, go to "Divisions" tab | Empty state: "No divisions yet" | [ ] | |
| 4.2 | Click "Add Division" | Division form loads | [ ] | |
| 4.3 | Select age group "U10" | Form auto-fills: Game Duration=50, Half Duration=25, Max Roster=14, Min Roster=7, Players on Field=7 | [ ] | |
| 4.4 | Verify rule notes chips appear | "No heading", "Build-out line", "Size 4 ball" shown as yellow chips | [ ] | |
| 4.5 | Change format to "Single Elimination" | "Teams per Group" and "Teams Advancing" fields disappear | [ ] | |
| 4.6 | Change format back to "Group Stage + Knockout" | Group fields reappear | [ ] | |
| 4.7 | Verify tiebreaker order shows 7 items starting with "Points" | List of tiebreakers with up/down arrows | [ ] | |
| 4.8 | Click down arrow on "Points" | "Head-to-Head" moves to position 1, "Points" to position 2 | [ ] | |
| 4.9 | Move "Points" back to position 1 | Order restored | [ ] | |
| 4.10 | Remove a rule note chip by clicking X | Chip removed | [ ] | |
| 4.11 | Set gender to "Boys", name auto-fills "U10 Boys" (or similar) | Name populated | [ ] | |
| 4.12 | Set goal differential cap: 4 | Accepted | [ ] | |
| 4.13 | Click "Add Division" | Returns to division list with new division showing | [ ] | |
| 4.14 | Verify division shows: format badge, age group, game duration, players on field | All info displayed | [ ] | |
| 4.15 | Add a second division: U12 Boys, Group + Knockout | Both divisions visible | [ ] | |
| 4.16 | Click "Edit" on first division | Form loads with saved values | [ ] | |
| 4.17 | Change max roster to 16, save | Updates correctly | [ ] | |

---

## 5. Team Registration

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 5.1 | Go to "Registrations" tab | Empty state: "No registrations yet" | [ ] | |
| 5.2 | Click "Register Team" | Registration form loads | [ ] | |
| 5.3 | Division dropdown shows both divisions created in step 4 | Dropdown populated with "U10 Boys" and "U12 Boys" | [ ] | |
| 5.4 | Select "U10 Boys" division | Selected | [ ] | |
| 5.5 | Team dropdown shows teams from your club | Dropdown populated (Ants, Falcons, Rabbits, etc.) | [ ] | |
| 5.6 | Select a team (e.g. "Mighty Mangoes") | Selected | [ ] | |
| 5.7 | Click "Register Team" | Returns to registration list, team shows as "pending" | [ ] | |
| 5.8 | Register 3 more teams in U10 Boys | All 4 show in list | [ ] | |
| 5.9 | Try registering the same team again | Error: "This team is already registered for this tournament" | [ ] | |
| 5.10 | Check "Register a guest team" checkbox | Team dropdown replaced by name/club text fields | [ ] | |
| 5.11 | Enter guest team name: "Visiting FC U10", club: "Visiting FC" | Fields accept input | [ ] | |
| 5.12 | Click "Register Team" | Guest team appears in list with club name shown | [ ] | |
| 5.13 | Click "Accept" on a pending registration | Status changes to "accepted" (green badge) | [ ] | |
| 5.14 | Click "Reject" on another registration | Status changes to "rejected" (red badge) | [ ] | |
| 5.15 | Click status count chips at top (e.g. "pending: 2") | List filters to show only pending registrations | [ ] | |
| 5.16 | Click "Mark Paid" on an accepted team | Prompt for payment reference, enter "Check #123" | [ ] | |
| 5.17 | Verify payment status changes to "paid" (green) | Payment badge updates | [ ] | |
| 5.18 | Accept all remaining teams (need 4 accepted for groups) | All accepted | [ ] | |

---

## 6. Groups & Seeding

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 6.1 | Go to "Groups" tab | Shows U10 Boys division with "No groups created yet" | [ ] | |
| 6.2 | Click "Add Group" | Group A created | [ ] | |
| 6.3 | Click "Add Group" again | Group B created | [ ] | |
| 6.4 | Verify unassigned teams section shows all accepted teams | Yellow section with team chips | [ ] | |
| 6.5 | Use "Move to..." dropdown on a team chip to move to Group A | Team moves to Group A | [ ] | |
| 6.6 | Click "Auto-Assign (Snake Seed)" | All teams distributed across groups | [ ] | |
| 6.7 | Verify groups are roughly equal in size | 2 teams per group (or close) for 4 teams | [ ] | |
| 6.8 | Verify unassigned section is empty or gone | All teams assigned | [ ] | |

---

## 7. Schedule Generation

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 7.1 | Go to "Schedule" tab | Shows U10 Boys with "No matches scheduled" | [ ] | |
| 7.2 | Click "Generate Schedule" | Confirmation dialog appears | [ ] | |
| 7.3 | Confirm | Matches appear grouped by Group A and Group B | [ ] | |
| 7.4 | Verify match count: 4 teams across 2 groups of 2 = 1 match per group = 2 total (or 4 teams in 1 group = 6 matches) | Match count correct for group sizes | [ ] | |
| 7.5 | Each match shows: match number, home team vs away team, time | All info displayed | [ ] | |
| 7.6 | Matches show "vs" (not scores) since unplayed | Correct | [ ] | |

---

## 8. Score Entry & Standings

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 8.1 | Go to "Standings" tab | Shows group tables with all zeros (P/W/D/L/GF/GA/GD/Pts) | [ ] | |
| 8.2 | All teams start at 0 points, position 1 | Correct initial state | [ ] | |
| 8.3 | Go back to "Schedule" tab | Matches visible | [ ] | |
| 8.4 | *(Note: Score entry is on the Schedule tab via match cards — if no inline scoring, go to Bracket tab for knockout scoring. Group stage scoring may need to be done via API for now unless the ScheduleManager has score entry built in.)* | | [ ] | |
| 8.5 | Enter a score (e.g. 3-1) for a group stage match | Score saves, match shows "3 – 1" | [ ] | |
| 8.6 | Go to Standings tab | Standings updated: winning team has 3 pts, losing team has 0 | [ ] | |
| 8.7 | Enter a draw (1-1) for another match | Both teams get 1 point | [ ] | |
| 8.8 | Verify GF, GA, GD columns are correct | Goals for/against/difference match entered scores | [ ] | |
| 8.9 | If goal diff cap is set to 4: enter a 7-0 result | GD should show +4 (not +7) in standings | [ ] | |
| 8.10 | Advancing positions (top N) highlighted in green | Top teams have green row background | [ ] | |

---

## 9. Knockout Bracket

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 9.1 | Go to "Bracket" tab | Shows "No knockout bracket yet" (or bracket if auto-generated) | [ ] | |
| 9.2 | Click "Generate Bracket" | Bracket structure appears with placeholder labels ("1st Group A", "2nd Group B") | [ ] | |
| 9.3 | Click "Slot Group Winners" | Placeholder labels replaced with actual team names from standings | [ ] | |
| 9.4 | Click "Enter Score" on a knockout match | Score inputs appear (two number fields) | [ ] | |
| 9.5 | Enter a score (2-1) and click Save | Match completed, winner shown in bold | [ ] | |
| 9.6 | Winner auto-advances to next round | Next round match shows the winning team name | [ ] | |
| 9.7 | Enter a draw (1-1) in a knockout match | Penalty score inputs appear | [ ] | |
| 9.8 | Enter penalty scores (4-3) and Save | Winner determined by PKs, advances to next round | [ ] | |
| 9.9 | Score the final | Champion determined | [ ] | |

---

## 10. Public Tournament Page

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 10.1 | Copy the public URL from the tournament detail overview (e.g. `/tournament/spring-classic-test`) | URL displayed | [ ] | |
| 10.2 | Open in an incognito/private browser window (no login) | Page loads with tournament header, club logo/name, dates, location | [ ] | |
| 10.3 | If tournament is still "Draft" or "Cancelled" | Should show "Tournament Not Found" (404) | [ ] | |
| 10.4 | Change tournament status to "In Progress" (from admin) | Public page now accessible | [ ] | |
| 10.5 | Schedule tab shows all matches with scores for completed matches | Correct | [ ] | |
| 10.6 | Standings tab shows group tables | Standard soccer table with Pos/Team/P/W/D/L/GF/GA/GD/Pts | [ ] | |
| 10.7 | Bracket tab shows knockout bracket | Visual bracket with team names and scores | [ ] | |
| 10.8 | Division selector buttons switch between divisions | Content updates for selected division | [ ] | |
| 10.9 | **Test on mobile phone** (or Chrome DevTools 375px width) | Cards stack vertically, no horizontal overflow, tables scroll | [ ] | |
| 10.10 | Wait 60 seconds on the page | Data auto-refreshes (if tournament is "In Progress") | [ ] | |

---

## 11. Edge Cases & Permissions

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 11.1 | Log out, log in as a **coach** account | Coach dashboard loads | [ ] | |
| 11.2 | Navigate to /tournaments | Can see tournament list | [ ] | |
| 11.3 | Verify "Create Tournament" button is NOT visible | Hidden for coaches | [ ] | |
| 11.4 | Click into the tournament | Detail page loads (read-only tabs) | [ ] | |
| 11.5 | Verify no Edit, Delete, or status change controls visible | Hidden for coaches | [ ] | |
| 11.6 | Go to Registrations tab, click "Register Team" | Can only see teams the coach is assigned to | [ ] | |
| 11.7 | "Register a guest team" checkbox NOT visible for coaches | Hidden (admin only) | [ ] | |
| 11.8 | Accept/Reject buttons NOT visible on registration list | Hidden for coaches | [ ] | |
| 11.9 | Try deleting a division that has accepted registrations (as admin) | Error: "Cannot delete division with X active registration(s)" | [ ] | |
| 11.10 | Create a second tournament with the same URL slug | Error: "A tournament with this URL slug already exists" | [ ] | |

---

## 12. Cancel & Cleanup

| # | Step | Expected | Pass | Notes |
|---|------|----------|------|-------|
| 12.1 | Go to the test tournament detail page | Detail loads | [ ] | |
| 12.2 | Click "Cancel" button | Confirm dialog: "Cancel [name]? This cannot be undone." | [ ] | |
| 12.3 | Confirm cancellation | Redirects to tournament list, tournament shows "Cancelled" badge | [ ] | |
| 12.4 | Verify cancelled tournament is NOT visible on public page | Returns "Tournament Not Found" | [ ] | |
| 12.5 | Verify no further status changes possible on cancelled tournament | "Change Status" dropdown empty or hidden | [ ] | |

---

## Known Limitations (Phase 1)

These are expected and not bugs:

- **No inline score entry on Schedule tab** — group stage scores may need to be entered via the Bracket tab's score entry pattern (or a future update)
- **No drag-and-drop** for moving teams between groups — uses dropdown instead
- **No QR code generator** yet for public URL
- **Payment is manual tracking only** — no Stripe/online payment
- **No push notifications** for game reminders or results
- **No referee management** — Phase 2
- **No weather delay tools** — Phase 2

---

## Result Summary

| Section | Total Tests | Passed | Failed |
|---------|-----------|--------|--------|
| 1. Navigation | 4 | | |
| 2. Create Tournament | 13 | | |
| 3. Detail & Status | 8 | | |
| 4. Divisions & Presets | 17 | | |
| 5. Registration | 18 | | |
| 6. Groups & Seeding | 8 | | |
| 7. Schedule | 6 | | |
| 8. Scoring & Standings | 10 | | |
| 9. Knockout Bracket | 9 | | |
| 10. Public Page | 10 | | |
| 11. Edge Cases | 10 | | |
| 12. Cancel & Cleanup | 5 | | |
| **TOTAL** | **118** | | |
