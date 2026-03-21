# Tournament Module — User Stories

**Date:** March 21, 2026
**Based on:** tournament-module-research.md

---

## Personas

| Persona | Description |
|---------|-------------|
| **Tournament Director** | Club admin who creates and manages the tournament end-to-end |
| **Coach / Team Manager** | Registers their team, manages roster, enters scores on game day |
| **Parent / Spectator** | Views schedules, brackets, and results; receives notifications |
| **Referee Coordinator** | Assigns referees to matches, tracks availability (Phase 2+) |

---

## Phase 1 — MVP

### Tournament Setup

1. **As a tournament director**, I want to create a new tournament with a name, dates, location, and description so that I can begin organizing an event for my club.

2. **As a tournament director**, I want to define divisions within a tournament (e.g. U12 Boys, U14 Girls) with age group, gender, format, game duration, and roster size limits so that teams register into the correct competitive bracket.

3. **As a tournament director**, I want to choose a tournament format per division (round robin, group stage + knockout, single elimination) so that the system generates the correct schedule structure.

4. **As a tournament director**, I want to configure tiebreaker rules per division by ordering criteria (points, head-to-head, goal difference, goals against, etc.) so that standings are calculated according to our tournament's specific rules.

5. **As a tournament director**, I want to set a goal differential cap per division (e.g. max 4 goals counted per game) so that teams are discouraged from running up the score.

6. **As a tournament director**, I want to set registration open/close dates so that teams can only register within the allowed window.

7. **As a tournament director**, I want to define the fields available for my tournament (name, surface type, lighting) so that games can be assigned to specific locations.

### Registration

8. **As a coach**, I want to register my team for a tournament by selecting a division so that my team is entered into the event.

9. **As a tournament director**, I want to review incoming team registrations and accept, reject, or waitlist each application so that I can control which teams participate.

10. **As a tournament director**, I want to set a maximum number of teams per division so that registration automatically closes for a division when it's full (with additional teams going to a waitlist).

11. **As a tournament director**, I want to track payment status (unpaid / paid / refunded) per registration so that I know which teams have settled their entry fees.

12. **As a coach**, I want to see the status of my tournament registration (pending, accepted, waitlisted, rejected) so that I know whether my team is in.

13. **As a coach**, I want to withdraw my team from a tournament before it starts so that my spot can be given to a waitlisted team.

### Group Assignment & Seeding

14. **As a tournament director**, I want to create groups/pools within a division and assign accepted teams to groups so that the group stage can be scheduled.

15. **As a tournament director**, I want to seed teams (manually or randomly) so that the bracket is fair and top teams don't meet in early rounds.

16. **As a tournament director**, I want to drag-and-drop teams between groups so that I can balance pools by strength or geography.

### Schedule Generation

17. **As a tournament director**, I want the system to auto-generate a round-robin schedule for each group so that I don't have to manually create every matchup.

18. **As a tournament director**, I want to assign generated matches to specific fields and time slots so that teams and referees know where and when to show up.

19. **As a tournament director**, I want the system to enforce minimum rest time between a team's matches (configurable, default 2 hours) so that no team plays back-to-back.

20. **As a tournament director**, I want to manually adjust any auto-generated match (change time, field, or swap teams) so that I can handle real-world constraints the algorithm can't anticipate.

21. **As a tournament director**, I want to generate the knockout bracket based on group advancement rules (e.g. top 2 per group advance) with correct cross-matching (A1 vs B2, B1 vs A2) so that the bracket is ready once group play finishes.

### Scoring & Standings

22. **As a tournament director (or designated scorer)**, I want to enter match scores (home score, away score) so that standings update automatically.

23. **As the system**, when a group-stage score is entered I want to automatically recalculate group standings (P/W/D/L/GF/GA/GD/Pts) applying the configured tiebreaker rules so that standings are always current.

24. **As the system**, when all group-stage matches in a division are complete I want to automatically slot the advancing teams into the knockout bracket so that the tournament director doesn't have to do it manually.

25. **As a tournament director**, I want to record penalty kick results for knockout matches that end in a draw so that the correct team advances.

26. **As a coach**, I want to enter scores for my own team's matches (if permitted by the tournament director) so that results can be submitted quickly from the field.

### Public Views (No Login Required)

27. **As a parent/spectator**, I want to view the tournament schedule on a public page (no login) filterable by division, team, field, and date so that I can find my child's games.

28. **As a parent/spectator**, I want to view group standings on a public page showing a standard soccer table (P/W/D/L/GF/GA/GD/Pts) so that I can see how my child's team is doing.

29. **As a parent/spectator**, I want to view the knockout bracket on a public page as a visual bracket diagram that updates as results come in so that I can follow the tournament's progression.

30. **As a parent/spectator**, I want all public views to be mobile-first and responsive so that I can check schedules and results on my phone at the fields.

31. **As a tournament director**, I want to share the public tournament page via a link and QR code so that I can post it at the venue and send it to teams.

### Basic Bracket View

32. **As a parent/spectator**, I want to see placeholder labels in the bracket (e.g. "Winner of Match 5", "1st Group A") before teams are determined so that I understand the bracket structure.

33. **As a parent/spectator**, I want bracket placeholders to be replaced with actual team names as results come in so that I can follow advancement in real time.

---

## Phase 2

### Online Payments

34. **As a coach**, I want to pay my team's tournament entry fee online during registration so that I don't have to mail a check or bring cash.

35. **As a tournament director**, I want to issue refunds to teams that withdraw or are rejected so that I can manage finances within the system.

36. **As a tournament director**, I want to see a financial summary (total revenue, refunds issued, outstanding balances) so that I can track the tournament's financial health.

### Push Notifications

37. **As a parent**, I want to receive a push notification 30 minutes before my child's game with the field location so that I can get there on time.

38. **As a parent**, I want to receive a push notification when my child's game score is posted so that I can follow results even if I'm not at the field.

39. **As a parent**, I want to receive a push notification if my child's game time or field changes so that I don't show up at the wrong place.

### Communication Integration

40. **As a tournament director**, I want to send an email or SMS to all registered teams (or a specific division) using the existing email/SMS module so that I can communicate schedule changes, weather updates, or logistics.

41. **As a tournament director**, I want tournament-related emails to use templates with variables like {{tournament_name}}, {{division}}, {{game_time}}, and {{field}} so that communications are personalized and accurate.

### Referee Management (Basic)

42. **As a referee coordinator**, I want to add referees to the tournament with their name, contact info, and certification level so that I have a pool of available officials.

43. **As a referee coordinator**, I want to assign referees to specific matches (center, AR1, AR2) so that every game is covered.

44. **As a referee coordinator**, I want to see which matches still need referee assignments so that I can ensure full coverage before game day.

45. **As a referee coordinator**, I want to flag referees who have a conflict of interest (e.g. their child plays on one of the teams) so that I can avoid assigning them to those matches.

### Weather & Schedule Adjustments

46. **As a tournament director**, I want to delay all remaining matches by X minutes with a single action so that I can respond to weather delays without editing each match individually.

47. **As a tournament director**, I want to cancel or postpone individual matches and notify affected teams automatically so that everyone gets the update.

48. **As a tournament director**, I want to mark the tournament status (in progress, weather delay, completed, cancelled) so that the public page reflects the current situation.

### Match Events

49. **As a scorer**, I want to record match events (goals, yellow cards, red cards) with the minute and player name so that detailed match records are available.

50. **As a parent/spectator**, I want to see goal scorers and card recipients on the match detail page so that I can follow the game in more detail.

51. **As a tournament director**, I want to generate a Golden Boot report (top scorers across the tournament) so that I can present individual awards.

---

## Phase 3

### Advanced Scheduling

52. **As a tournament director**, I want the schedule optimizer to automatically assign matches to fields and time slots while respecting all constraints (rest time, no conflicts, fair distribution) so that I spend less time manually adjusting the schedule.

53. **As a tournament director**, I want the system to suggest schedule compression options after a weather delay so that I can quickly re-fit remaining matches into available slots.

### Field Maps

54. **As a tournament director**, I want to upload a venue map image and mark field locations on it so that parents can find their way around.

55. **As a parent/spectator**, I want to see an interactive venue map on the public page showing which game is on which field so that I can navigate the tournament venue.

### Live Streaming

56. **As a tournament director**, I want to attach a live stream URL (YouTube, Facebook Live) to any match so that remote family members can watch.

57. **As a parent/spectator**, I want to see a "Watch Live" link on the match detail page when a stream is available so that I can watch from home.

### College Showcase (U15+ Divisions)

58. **As a tournament director**, I want to flag specific divisions as "college showcase" divisions so that player profiles are made visible to college coaches.

59. **As a college coach**, I want to view player profiles (stats, position, graduation year) for athletes in showcase divisions so that I can identify recruiting targets.

60. **As a college coach**, I want to register my attendance at the tournament and indicate which divisions/games I plan to attend so that players and families know college coaches are watching.

### Financial Reporting

61. **As a tournament director**, I want a full financial dashboard showing entry fee revenue, referee costs, facility costs, and net profit/loss so that I can report on tournament finances to club leadership.

62. **As a tournament director**, I want to generate invoices for teams that pay by check so that I have a paper trail for offline payments.

### GotSport Data Exchange

63. **As a tournament director**, I want to export tournament results in a format compatible with GotSport so that I can submit results to my state association if required.

64. **As a tournament director**, I want to import team registration data from GotSport so that teams already registered in the state system don't have to re-enter their information.

---

## Cross-Cutting / Non-Functional

65. **As a club admin**, I want tournament management permissions to follow the existing role model (club admin = full access, coach = own team only) so that access control is consistent with the rest of the CRM.

66. **As a parent with multiple children**, I want to see all my children's tournament games in a single view so that I can plan my day across multiple teams and fields.

67. **As a tournament director**, I want all tournament actions to be logged in the audit trail so that I can review who made changes and when.

68. **As a developer**, I want tournament-related API endpoints to follow the existing `/api/` routing pattern so that the codebase remains consistent.

69. **As a developer**, I want all new tables created via migration files in `/database/migrations/` with sequential numbering so that schema changes are trackable and reproducible.

70. **As a mobile user**, I want all tournament pages to load in under 3 seconds on a 4G connection so that the experience at a busy tournament venue (where cell service is often poor) is acceptable.
