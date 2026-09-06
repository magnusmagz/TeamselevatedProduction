<?php
/**
 * Coach ↔ team assignment from the COACH's row (Coaches page, 2026-09-06).
 *
 *   GET  ?action=list&user_id=N&club_id=N   the coach's teams (with role) +
 *                                            the club's active teams for the picker
 *   POST ?action=assign    { user_id, team_id, role }   role: head_coach |
 *                                                       assistant_coach | team_manager
 *   POST ?action=unassign  { user_id, team_id }
 *
 * Every action is club-admin only (`te_is_club_admin()` of the team's club;
 * super admin passes). All the logic — and every rule that bites — lives in
 * lib/coach_teams.php so CoachTeamsTest can run it against SQLite. This file
 * authenticates, parses, dispatches and emits. Nothing else.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/coach_teams.php';

$auth = AuthMiddleware::requireAuth();
$pdo = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function coachTeams_emit(array $result): void
{
    http_response_code($result['status']);
    echo json_encode($result['body']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            if ($method !== 'GET') {
                coachTeams_emit(coachTeams_fail(405, 'Method not allowed', 'method_not_allowed'));
            }
            coachTeams_emit(coachTeams_list(
                $pdo,
                $auth,
                (int) ($_GET['user_id'] ?? 0),
                (int) ($_GET['club_id'] ?? 0)
            ));
            break;

        case 'assign':
        case 'unassign':
            if ($method !== 'POST') {
                coachTeams_emit(coachTeams_fail(405, 'Method not allowed', 'method_not_allowed'));
            }
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            coachTeams_emit(
                $action === 'assign'
                    ? coachTeams_assign($pdo, $auth, $body)
                    : coachTeams_unassign($pdo, $auth, $body)
            );
            break;

        default:
            coachTeams_emit(coachTeams_fail(400, 'Unknown action', 'bad_action'));
    }
} catch (Throwable $e) {
    error_log('coach-teams: ' . $e->getMessage());
    coachTeams_emit(coachTeams_fail(500, 'Something went wrong', 'server_error'));
}
