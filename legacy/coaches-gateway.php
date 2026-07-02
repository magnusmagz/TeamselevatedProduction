<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

            // Verify coach exists
            $stmt = $connection->prepare("SELECT id FROM users WHERE id = ? AND role = 'coach'");
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
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
