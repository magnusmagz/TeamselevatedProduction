<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Every action on this gateway requires an authenticated user. Coaches are
// tenant data — never serve them (or let them be mutated) without a valid token
// AND a club scope. getAccessibleClubIds() returns the caller's club IDs, or
// null for super admins (who may see/act across all clubs).
$auth = AuthMiddleware::requireAuth();
$accessibleClubs = $auth->getAccessibleClubIds();

// Authenticated but scoped to no club (and not a super admin): nothing is visible.
$hasNoClubScope = ($accessibleClubs !== null && empty($accessibleClubs));

$action = $_GET['action'] ?? 'available';

try {
    switch ($action) {
        case 'available':
            // Get coaches the caller is allowed to see, scoped to their club(s).
            // A "coach of a club" = has a user_club_access coach role in the club,
            // OR is the primary_coach of a (non-deleted) team in the club. This
            // mirrors how JWT.php derives club-scoped coach roles.
            if ($hasNoClubScope) {
                echo json_encode([]);
                break;
            }

            $params = [];
            if ($accessibleClubs === null) {
                // Super admin: no club filter.
                $teamClubFilter = '';
                $ucaClubFilter = '';
            } else {
                $ph = implode(',', array_fill(0, count($accessibleClubs), '?'));
                $teamClubFilter = "AND t.club_id IN ($ph)";
                $ucaClubFilter  = "AND uca.club_profile_id IN ($ph)";
                // Placeholders appear in this order in the SQL below:
                //   1) teams LEFT JOIN club filter, 2) uca EXISTS club filter.
                $params = array_merge($accessibleClubs, $accessibleClubs);
            }

            $sql = "
                SELECT u.id, u.first_name, u.last_name, u.email,
                       COUNT(DISTINCT t.id) AS team_count
                FROM users u
                LEFT JOIN teams t
                       ON t.primary_coach_id = u.id
                      AND t.deleted_at IS NULL
                      $teamClubFilter
                WHERE (
                    EXISTS (
                        SELECT 1 FROM user_club_access uca
                        WHERE uca.user_id = u.id
                          AND uca.active = true
                          AND uca.role = 'coach'
                          $ucaClubFilter
                    )
                    OR t.id IS NOT NULL
                )
                GROUP BY u.id, u.first_name, u.last_name, u.email
                ORDER BY u.last_name, u.first_name
            ";
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($coaches);
            break;

        case 'create':
            $data = json_decode(file_get_contents("php://input"), true);

            if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
                http_response_code(400);
                echo json_encode(['error' => 'first_name, last_name, and email are required']);
                exit();
            }

            // A new coach must be attached to one of the caller's clubs, otherwise
            // they would be invisible to the (now club-scoped) available list.
            // Prefer the caller's active club context; fall back to an explicit
            // club_id, or their sole club if they only belong to one.
            if ($hasNoClubScope) {
                http_response_code(403);
                echo json_encode(['error' => 'You are not scoped to a club']);
                exit();
            }

            $targetClub = null;
            $activeCtx = $auth->getActiveContext();
            if ($activeCtx && ($activeCtx->scope_type ?? null) === 'club') {
                $targetClub = (int)($activeCtx->scope_id ?? 0);
            } elseif (!empty($data['club_id'])) {
                $targetClub = (int)$data['club_id'];
            } elseif ($accessibleClubs !== null && count($accessibleClubs) === 1) {
                $targetClub = (int)$accessibleClubs[0];
            }

            if (!$targetClub) {
                http_response_code(400);
                echo json_encode(['error' => 'club_id is required to create a coach']);
                exit();
            }

            // Authorize the target club (super admins bypass).
            if ($accessibleClubs !== null &&
                !in_array($targetClub, array_map('intval', $accessibleClubs), true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this club']);
                exit();
            }

            // Check if email already exists
            $stmt = $connection->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Email already exists']);
                exit();
            }

            $connection->beginTransaction();
            try {
                // Create coach account
                $stmt = $connection->prepare("
                    INSERT INTO users (first_name, last_name, email, password_hash, role, created_at)
                    VALUES (?, ?, ?, ?, 'coach', NOW())
                ");
                $hashedPassword = password_hash($data['password'] ?? 'password123', PASSWORD_DEFAULT);
                $stmt->execute([
                    $data['first_name'],
                    $data['last_name'],
                    $data['email'],
                    $hashedPassword
                ]);
                $coachId = $connection->lastInsertId();

                // Grant the coach club-scoped access so they are visible to the club.
                $stmt = $connection->prepare("
                    INSERT INTO user_club_access (user_id, club_profile_id, role, active, granted_at)
                    VALUES (?, ?, 'coach', true, NOW())
                ");
                $stmt->execute([$coachId, $targetClub]);

                $connection->commit();
            } catch (Exception $e) {
                $connection->rollBack();
                throw $e;
            }

            echo json_encode([
                'success' => true,
                'id' => $coachId,
                'message' => 'Coach created successfully'
            ]);
            break;

        case 'update':
            $data = json_decode(file_get_contents("php://input"), true);
            $coachId = $_GET['id'] ?? null;

            if (!$coachId) {
                http_response_code(400);
                echo json_encode(['error' => 'Coach ID is required']);
                exit();
            }

            // Verify the target is a coach by the AUTHORITATIVE definition — a
            // user_club_access 'coach' role or a team's primary coach — NOT
            // users.role, which the 'available' list also ignores. Checking
            // users.role='coach' here 404'd coaches whose role lives only in
            // user_club_access (e.g. club admins who coach, or coaches imported
            // with users.role='parent'). Tenant scope is enforced separately below.
            $stmt = $connection->prepare("
                SELECT 1 FROM users u
                WHERE u.id = ?
                  AND (
                    EXISTS (
                        SELECT 1 FROM user_club_access uca
                        WHERE uca.user_id = u.id AND uca.active = true AND uca.role = 'coach'
                    )
                    OR EXISTS (
                        SELECT 1 FROM teams t
                        WHERE t.primary_coach_id = u.id AND t.deleted_at IS NULL
                    )
                  )
                LIMIT 1
            ");
            $stmt->execute([$coachId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Coach not found']);
                exit();
            }

            // Enforce tenant scope: the caller may only edit a coach who belongs to
            // one of their clubs (via club access or a team they coach). Super
            // admins bypass. Without this, any authenticated user could edit any
            // coach in any org.
            if ($accessibleClubs !== null) {
                if ($hasNoClubScope) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied']);
                    exit();
                }
                $ph = implode(',', array_fill(0, count($accessibleClubs), '?'));
                $chk = $connection->prepare("
                    SELECT 1
                    WHERE EXISTS (
                        SELECT 1 FROM user_club_access uca
                        WHERE uca.user_id = ? AND uca.active = true
                          AND uca.club_profile_id IN ($ph)
                    )
                    OR EXISTS (
                        SELECT 1 FROM teams t
                        WHERE t.primary_coach_id = ? AND t.deleted_at IS NULL
                          AND t.club_id IN ($ph)
                    )
                    LIMIT 1
                ");
                $chk->execute(array_merge(
                    [$coachId], $accessibleClubs,
                    [$coachId], $accessibleClubs
                ));
                if (!$chk->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied to this coach']);
                    exit();
                }
            }

            // Update coach information
            $stmt = $connection->prepare("
                UPDATE users
                SET first_name = ?,
                    last_name = ?,
                    email = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $coachId
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Coach updated successfully'
            ]);
            break;

        // ─── Team staff (assistant coaches / managers) ───────────────────
        // Stored as team_members rows with role assistant_coach|team_manager,
        // which is what the rest of the app reads for team staff access.

        case 'team-staff': {
            $teamId = (int)($_GET['team_id'] ?? 0);
            if (!$teamId) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id is required']);
                exit();
            }
            coachesGw_assertTeamAccess($connection, $accessibleClubs, $teamId);

            $stmt = $connection->prepare("
                SELECT tm.user_id, tm.role, u.first_name, u.last_name, u.email
                FROM team_members tm
                JOIN users u ON u.id = tm.user_id
                WHERE tm.team_id = ?
                  AND tm.role IN ('assistant_coach', 'team_manager')
                  AND (tm.status IS NULL OR tm.status = 'active')
                ORDER BY tm.role, u.last_name, u.first_name
            ");
            $stmt->execute([$teamId]);
            echo json_encode(['success' => true, 'staff' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'assign-staff': {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId = (int)($data['team_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $role   = $data['role'] ?? '';
            if (!$teamId || !$userId || !in_array($role, ['assistant_coach', 'team_manager'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id, user_id and a valid role are required']);
                exit();
            }
            coachesGw_assertTeamAccess($connection, $accessibleClubs, $teamId);

            // Re-activate an existing row rather than duplicating it.
            $chk = $connection->prepare("SELECT id FROM team_members WHERE team_id = ? AND user_id = ? AND role = ? LIMIT 1");
            $chk->execute([$teamId, $userId, $role]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $connection->prepare("UPDATE team_members SET status = 'active', leave_date = NULL WHERE id = ?")
                           ->execute([$existing['id']]);
            } else {
                $connection->prepare("
                    INSERT INTO team_members (team_id, user_id, role, status, join_date)
                    VALUES (?, ?, ?, 'active', CURRENT_DATE)
                ")->execute([$teamId, $userId, $role]);
            }
            echo json_encode(['success' => true]);
            break;
        }

        case 'unassign-staff': {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId = (int)($data['team_id'] ?? 0);
            $userId = (int)($data['user_id'] ?? 0);
            $role   = $data['role'] ?? '';
            if (!$teamId || !$userId || !in_array($role, ['assistant_coach', 'team_manager'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'team_id, user_id and a valid role are required']);
                exit();
            }
            coachesGw_assertTeamAccess($connection, $accessibleClubs, $teamId);

            $connection->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ? AND role = ?")
                       ->execute([$teamId, $userId, $role]);
            echo json_encode(['success' => true]);
            break;
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Tenant guard for team-staff actions: the team must live in one of the
 * caller's clubs (super admins pass $accessibleClubs === null and bypass).
 */
function coachesGw_assertTeamAccess(PDO $connection, $accessibleClubs, int $teamId): void {
    $stmt = $connection->prepare("SELECT club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        http_response_code(404);
        echo json_encode(['error' => 'Team not found']);
        exit();
    }
    if ($accessibleClubs !== null &&
        !in_array((int)$team['club_id'], array_map('intval', $accessibleClubs), true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this team']);
        exit();
    }
}
?>
