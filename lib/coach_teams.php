<?php
/**
 * Assign a coach to a team FROM THE COACH'S ROW (Coaches page, 2026-09-06).
 *
 * Until now the only way to put a coach on a team was from the team: the team
 * form's head-coach select (`legacy/teams-gateway.php` PUT sets
 * `teams.primary_coach_id`) and the team page's staff controls
 * (`legacy/coaches-gateway.php?action=assign-staff` writes `team_members`).
 * Maggie: "there's no way to assign a coach to a team from the coach, let's
 * add that." This is that door, and it writes the SAME two places — a coach
 * attached here is exactly as attached as one attached from the team, because
 * `getCoachTeamIds()` / `JWT::loadRoleSet()` read nothing else.
 *
 * Roles, and where each one lives:
 *   head_coach       `teams.primary_coach_id` — one per team, so assigning a
 *                    new head REPLACES the old one. The previous head is
 *                    returned and audited; the UI warns before it asks.
 *   assistant_coach  a `team_members` row (user_id set, athlete_id NULL,
 *   team_manager     status 'active', join_date today). The live CHECK on
 *                    `team_members.role` is player / assistant_coach /
 *                    team_manager — nothing else may be written there.
 *
 * Rules that bite:
 *  - Standing is `te_is_club_admin()` of the TEAM's club (list: of the club
 *    asked about). Never canAccessClub() — a parent row satisfies that.
 *  - The target must hold an ACTIVE, unrevoked coach or club_admin role in
 *    that club. An admin passing an arbitrary user id is 422 not_a_coach —
 *    attaching someone to a team hands them that team's roster and families.
 *  - One staff row per (team, user). The MySQL-era schema declared
 *    UNIQUE(team_id, user_id); whether Neon still enforces it is not something
 *    this code relies on — a role change UPDATEs the existing row, a re-assign
 *    after an unassign re-activates it, and the same role again is a no-op
 *    (`unchanged: true`, no audit row — nothing happened).
 *  - Unassign never DELETEs. `status='inactive'` + `leave_date` — the roster
 *    history is the record of who had access to which families, when.
 *  - Every change calls te_role_cache_invalidate() for the COACH: their derived
 *    'coach' role is in the cached half of the role set, and a coach who cannot
 *    see their new team for five minutes is a support ticket.
 *  - Dates are written as `Y-m-d` strings from PHP, not CURRENT_DATE, so the
 *    day is the server's and the same one the audit row carries.
 *
 * Handlers return ['status' => int, 'body' => array] so CoachTeamsTest runs
 * the real thing against SQLite.
 */

require_once __DIR__ . '/club_standing.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/role_cache.php';

const TE_COACH_TEAM_ROLES = ['head_coach', 'assistant_coach', 'team_manager'];
const TE_COACH_TEAM_STAFF_ROLES = ['assistant_coach', 'team_manager'];
/** user_club_access roles that may be attached to a team as staff. */
const TE_COACH_TEAM_ELIGIBLE_ROLES = ['coach', 'club_admin'];

/** @return array{status:int, body:array} */
function coachTeams_fail(int $status, string $message, string $reason): array
{
    return ['status' => $status, 'body' => ['success' => false, 'error' => $message, 'reason' => $reason]];
}

/** The team's club, or null when it does not exist / is soft-deleted. */
function coachTeams_teamRow(PDO $pdo, int $teamId): ?array
{
    if ($teamId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, club_id, primary_coach_id FROM teams WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** id + display name for a users row, or null. */
function coachTeams_person(PDO $pdo, $userId): ?array
{
    if (!$userId) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([(int) $userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    $name = trim(((string) ($u['first_name'] ?? '')) . ' ' . ((string) ($u['last_name'] ?? '')));
    return ['id' => (int) $u['id'], 'name' => $name !== '' ? $name : (string) $u['email']];
}

/** Does the user hold an active coach / club_admin role in the club? */
function coachTeams_isEligible(PDO $pdo, int $userId, int $clubId): bool
{
    $marks = implode(',', array_fill(0, count(TE_COACH_TEAM_ELIGIBLE_ROLES), '?'));
    $stmt = $pdo->prepare(
        "SELECT 1 FROM user_club_access
          WHERE user_id = ? AND club_profile_id = ?
            AND active = TRUE AND revoked_at IS NULL
            AND role IN ($marks)
          LIMIT 1"
    );
    $stmt->execute(array_merge([$userId, $clubId], TE_COACH_TEAM_ELIGIBLE_ROLES));
    return (bool) $stmt->fetchColumn();
}

/**
 * Gate + resolve for assign / unassign: the team (404), the caller's standing
 * in ITS club (403), and — for assign — the target's eligibility (422).
 * Standing is checked before eligibility so a refused caller learns nothing
 * about who is a coach where.
 *
 * @return array{ok:true, team:array, user_id:int}|array{ok:false, fail:array}
 */
function coachTeams_resolve(PDO $pdo, $auth, array $body, bool $requireEligible): array
{
    $userId = (int) ($body['user_id'] ?? 0);
    $teamId = (int) ($body['team_id'] ?? 0);
    if ($userId <= 0 || $teamId <= 0) {
        return ['ok' => false, 'fail' => coachTeams_fail(400, 'user_id and team_id are required', 'bad_request')];
    }
    $team = coachTeams_teamRow($pdo, $teamId);
    if (!$team) {
        return ['ok' => false, 'fail' => coachTeams_fail(404, 'Team not found', 'team_not_found')];
    }
    if (!te_is_club_admin($auth, (int) $team['club_id'])) {
        return ['ok' => false, 'fail' => coachTeams_fail(403, 'Only club admins can change a team\'s coaches', 'forbidden')];
    }
    if ($requireEligible && !coachTeams_isEligible($pdo, $userId, (int) $team['club_id'])) {
        return ['ok' => false, 'fail' => coachTeams_fail(
            422,
            'That person does not hold a coach role in this club. Add them as a coach first.',
            'not_a_coach'
        )];
    }
    return ['ok' => true, 'team' => $team, 'user_id' => $userId];
}

/**
 * GET ?action=list&user_id&club_id
 *
 * `teams`: every active team in the club the coach is on, with their role on
 * each. `available`: every active team in the club (for the picker), with its
 * current head coach so the UI can warn before replacing one.
 *
 * @return array{status:int, body:array}
 */
function coachTeams_list(PDO $pdo, $auth, int $userId, int $clubId): array
{
    if ($userId <= 0 || $clubId <= 0) {
        return coachTeams_fail(400, 'user_id and club_id are required', 'bad_request');
    }
    if (!te_is_club_admin($auth, $clubId)) {
        return coachTeams_fail(403, 'Only club admins can view a coach\'s team assignments', 'forbidden');
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.name, t.age_group, t.primary_coach_id, p.name AS program_name,
                hc.first_name AS head_first, hc.last_name AS head_last, hc.email AS head_email,
                tm.role AS staff_role
           FROM teams t
           LEFT JOIN programs p ON p.id = t.program_id
           LEFT JOIN users hc ON hc.id = t.primary_coach_id
           LEFT JOIN team_members tm
                  ON tm.team_id = t.id AND tm.user_id = ?
                 AND tm.role IN ('assistant_coach', 'team_manager') AND tm.status = 'active'
          WHERE t.club_id = ? AND t.deleted_at IS NULL
          ORDER BY p.name, t.name, t.id"
    );
    $stmt->execute([$userId, $clubId]);

    $mine = [];
    $available = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $head = null;
        if ($row['primary_coach_id'] !== null) {
            $name = trim(((string) $row['head_first']) . ' ' . ((string) $row['head_last']));
            $head = ['id' => (int) $row['primary_coach_id'], 'name' => $name !== '' ? $name : (string) $row['head_email']];
        }
        $team = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'age_group' => $row['age_group'],
            'program_name' => $row['program_name'],
            'head_coach' => $head,
        ];
        $available[] = $team;

        $role = null;
        if ((int) $row['primary_coach_id'] === $userId) {
            $role = 'head_coach';
        } elseif ($row['staff_role'] !== null) {
            $role = (string) $row['staff_role'];
        }
        if ($role !== null) {
            $mine[] = $team + ['role' => $role];
        }
    }

    return ['status' => 200, 'body' => ['success' => true, 'teams' => $mine, 'available' => $available]];
}

/**
 * POST ?action=assign  { user_id, team_id, role }
 *
 * @return array{status:int, body:array}
 */
function coachTeams_assign(PDO $pdo, $auth, array $body): array
{
    $role = (string) ($body['role'] ?? '');
    if (!in_array($role, TE_COACH_TEAM_ROLES, true)) {
        return coachTeams_fail(400, 'role must be head_coach, assistant_coach or team_manager', 'bad_role');
    }
    $r = coachTeams_resolve($pdo, $auth, $body, true);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $team = $r['team'];
    $teamId = (int) $team['id'];
    $userId = $r['user_id'];
    $today = date('Y-m-d');
    $actorId = (int) $auth->getUserId() ?: null;

    $previousHead = null;
    $changed = false;

    $stmt = $pdo->prepare(
        "SELECT id, role, status FROM team_members
          WHERE team_id = ? AND user_id = ? AND role IN ('assistant_coach', 'team_manager')
          ORDER BY id"
    );
    $stmt->execute([$teamId, $userId]);
    $staffRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($role === 'head_coach') {
        $currentHead = $team['primary_coach_id'] !== null ? (int) $team['primary_coach_id'] : null;
        if ($currentHead !== $userId) {
            $previousHead = coachTeams_person($pdo, $currentHead);
            $pdo->prepare('UPDATE teams SET primary_coach_id = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$userId, $teamId]);
            $changed = true;
        }
        // A head coach is not also listed as their own assistant.
        foreach ($staffRows as $row) {
            if ($row['status'] === 'active') {
                $pdo->prepare("UPDATE team_members SET status = 'inactive', leave_date = ? WHERE id = ?")
                    ->execute([$today, (int) $row['id']]);
                $changed = true;
            }
        }
    } else {
        // Keep ONE staff row per (team, user): update the first, retire extras.
        $keep = $staffRows[0] ?? null;
        if ($keep === null) {
            $pdo->prepare(
                "INSERT INTO team_members (team_id, user_id, athlete_id, role, status, join_date)
                 VALUES (?, ?, NULL, ?, 'active', ?)"
            )->execute([$teamId, $userId, $role, $today]);
            $changed = true;
        } elseif ($keep['role'] !== $role || $keep['status'] !== 'active') {
            $rejoin = $keep['status'] !== 'active';
            $pdo->prepare(
                "UPDATE team_members
                    SET role = ?, status = 'active', leave_date = NULL, join_date = CASE WHEN ? THEN ? ELSE join_date END
                  WHERE id = ?"
            )->execute([$role, $rejoin ? 1 : 0, $today, (int) $keep['id']]);
            $changed = true;
        }
        foreach (array_slice($staffRows, 1) as $extra) {
            if ($extra['status'] === 'active') {
                $pdo->prepare("UPDATE team_members SET status = 'inactive', leave_date = ? WHERE id = ?")
                    ->execute([$today, (int) $extra['id']]);
                $changed = true;
            }
        }
        // Being made assistant on a team you head means you are no longer its head.
        if ($team['primary_coach_id'] !== null && (int) $team['primary_coach_id'] === $userId) {
            $pdo->prepare('UPDATE teams SET primary_coach_id = NULL, updated_at = NOW() WHERE id = ?')->execute([$teamId]);
            $changed = true;
        }
    }

    if ($changed) {
        AuditLogger::log($pdo, $actorId, 'coach_assigned_to_team', 'teams', $teamId, [
            'coach_user_id' => $userId,
            'role' => $role,
            'club_id' => (int) $team['club_id'],
            'previous_head_coach_id' => $previousHead['id'] ?? null,
        ]);
        te_role_cache_invalidate($userId);
        if ($previousHead) {
            te_role_cache_invalidate($previousHead['id']);
        }
    }

    return ['status' => 200, 'body' => [
        'success' => true,
        'unchanged' => !$changed,
        'team_id' => $teamId,
        'team_name' => (string) $team['name'],
        'user_id' => $userId,
        'role' => $role,
        'previous_head_coach' => $previousHead,
    ]];
}

/**
 * POST ?action=unassign  { user_id, team_id }
 * Removes every role the coach holds on the team. 404 when they hold none.
 *
 * @return array{status:int, body:array}
 */
function coachTeams_unassign(PDO $pdo, $auth, array $body): array
{
    $r = coachTeams_resolve($pdo, $auth, $body, false);
    if (!$r['ok']) {
        return $r['fail'];
    }
    $team = $r['team'];
    $teamId = (int) $team['id'];
    $userId = $r['user_id'];
    $today = date('Y-m-d');
    $removed = [];

    if ($team['primary_coach_id'] !== null && (int) $team['primary_coach_id'] === $userId) {
        $pdo->prepare('UPDATE teams SET primary_coach_id = NULL, updated_at = NOW() WHERE id = ?')->execute([$teamId]);
        $removed[] = 'head_coach';
    }

    $stmt = $pdo->prepare(
        "SELECT id, role FROM team_members
          WHERE team_id = ? AND user_id = ? AND role IN ('assistant_coach', 'team_manager') AND status = 'active'
          ORDER BY id"
    );
    $stmt->execute([$teamId, $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pdo->prepare("UPDATE team_members SET status = 'inactive', leave_date = ? WHERE id = ?")
            ->execute([$today, (int) $row['id']]);
        $removed[] = (string) $row['role'];
    }

    if (!$removed) {
        return coachTeams_fail(404, 'That coach is not on this team', 'not_assigned');
    }

    AuditLogger::log($pdo, (int) $auth->getUserId() ?: null, 'coach_unassigned_from_team', 'teams', $teamId, [
        'coach_user_id' => $userId,
        'roles' => array_values(array_unique($removed)),
        'club_id' => (int) $team['club_id'],
    ]);
    te_role_cache_invalidate($userId);

    return ['status' => 200, 'body' => [
        'success' => true,
        'team_id' => $teamId,
        'team_name' => (string) $team['name'],
        'user_id' => $userId,
        'removed_roles' => array_values(array_unique($removed)),
    ]];
}
