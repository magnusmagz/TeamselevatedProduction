<?php
/**
 * ONE answer to "may this person act on this team's roster as staff".
 *
 * There are two different questions about a team roster and they must not be
 * conflated (same shape as userCanAccessAthlete vs staffCanManageAthlete, and
 * canAccessClub vs te_is_club_admin):
 *
 *   VIEW  — tpg_requireTeamViewAccess() in legacy/team-players-gateway.php.
 *           Deliberately wider: a guardian or player on the team gets in, because
 *           a family needs to see the team their child is on.
 *   STAFF — this predicate. Super admin, club admin of the team's club, or a
 *           coach of THIS team. Gates roster edits and the roster download.
 *
 * The download uses the STAFF one on purpose. A roster export is a bulk file of
 * minors' details (and, in the crew flavour, their families' contact details)
 * that leaves the product entirely once it is on someone's laptop. "Coaches and
 * club admins" was the requirement; the view predicate would have handed the
 * whole team's file to any parent on it.
 *
 * Returns a status rather than a bool so callers can tell a missing team from a
 * refused one and answer 404 vs 403 in their own content type — this file is
 * included by both a JSON gateway and a CSV endpoint.
 */

require_once __DIR__ . '/AthleteScope.php';

const TE_TEAM_ROSTER_OK        = 'ok';
const TE_TEAM_ROSTER_NOT_FOUND = 'not_found';
const TE_TEAM_ROSTER_DENIED    = 'denied';

/**
 * @return string one of TE_TEAM_ROSTER_OK / _NOT_FOUND / _DENIED
 */
function te_team_roster_staff_standing(PDO $pdo, $auth, int $teamId): string
{
    $stmt = $pdo->prepare('SELECT club_id FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return TE_TEAM_ROSTER_NOT_FOUND;
    }

    if ($auth->isSuperAdmin()) {
        return TE_TEAM_ROSTER_OK;
    }

    // Two live teams have a NULL club_id, so the club branch must not be
    // reachable with a NULL scope id — that would ask hasRole() a question it
    // cannot answer correctly.
    $clubId = $row['club_id'] !== null ? (int) $row['club_id'] : null;
    if ($clubId !== null && $auth->hasRole('club_admin', $clubId, 'club')) {
        return TE_TEAM_ROSTER_OK;
    }

    // Coach standing is per TEAM, not per club: a coach administers the teams
    // they are on, not every team their club owns. coachTeamIdsForUser covers
    // primary_coach_id plus active assistant_coach / team_manager rows.
    $coachTeamIds = AthleteScope::coachTeamIdsForUser($pdo, (int) $auth->getUserId());
    if (in_array($teamId, $coachTeamIds, true)) {
        return TE_TEAM_ROSTER_OK;
    }

    return TE_TEAM_ROSTER_DENIED;
}

/**
 * The VIEW twin of the predicate above: may this person SEE this team?
 *
 * Deliberately wider — a guardian or player on the team gets in, because a
 * family needs to see the team their child is on. Staff get in by role;
 * everyone else gets in by having access to at least one athlete on the team,
 * which is exactly the guardian/player case.
 *
 * Lifted out of legacy/team-players-gateway.php (where it was
 * tpg_requireTeamViewAccess, and which still delegates here) so that a second
 * read endpoint can gate on the SAME rule without including a gateway that
 * runs a request when it is required. Re-implementing it in the new caller is
 * exactly the drift te_team_roster_staff_standing() exists to prevent.
 *
 * ⚠️ This is a READ gate. Never gate a mutation on it — same rule as
 * userCanAccessAthlete vs staffCanManageAthlete.
 *
 * @return string one of TE_TEAM_ROSTER_OK / _NOT_FOUND / _DENIED
 */
function te_team_view_standing(PDO $pdo, $auth, int $teamId): string
{
    $stmt = $pdo->prepare('SELECT club_id FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return TE_TEAM_ROSTER_NOT_FOUND;
    }

    if ($auth->isSuperAdmin()) {
        return TE_TEAM_ROSTER_OK;
    }

    // Two live teams have a NULL club_id, so the club branch must not be
    // reachable with a NULL scope id.
    $clubId = $row['club_id'] !== null ? (int) $row['club_id'] : null;
    if ($clubId !== null && $auth->hasRole('club_admin', $clubId, 'club')) {
        return TE_TEAM_ROSTER_OK;
    }

    $coachTeamIds = AthleteScope::coachTeamIdsForUser($pdo, (int) $auth->getUserId());
    if (in_array($teamId, $coachTeamIds, true)) {
        return TE_TEAM_ROSTER_OK;
    }

    // Guardian of, or player on, this team.
    $accessibleIds = AthleteScope::accessibleAthleteIds($pdo, $auth);
    if (!empty($accessibleIds)) {
        // array_fill(0, 0, '?') produces `IN ()`, a syntax error rather than an
        // empty result — the empty case is excluded above.
        $ph = implode(',', array_fill(0, count($accessibleIds), '?'));
        $stmt = $pdo->prepare("
            SELECT 1 FROM team_members
            WHERE team_id = ? AND athlete_id IN ({$ph})
            LIMIT 1
        ");
        $stmt->execute(array_merge([$teamId], array_values($accessibleIds)));
        if ($stmt->fetchColumn()) {
            return TE_TEAM_ROSTER_OK;
        }
    }

    return TE_TEAM_ROSTER_DENIED;
}
