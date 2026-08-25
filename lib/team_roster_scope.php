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
