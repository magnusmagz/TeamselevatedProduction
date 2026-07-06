<?php
/**
 * Financial Permissions API
 * Controls access to financial data based on user roles
 *
 * Permission Levels:
 * - league_admin: Full access to all financial data for the league
 * - club_admin: Full access to financial data for their club
 * - treasurer: Full access to financial data (similar to admin)
 * - coach: Can see payment status for athletes on their teams (not amounts)
 * - parent/guardian: Can see their own children's payment details
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/JWT.php';

// Helper to extract JWT token — signature-verified (never trust an unverified payload).
function getJWTPayload() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (empty($authHeader) || !preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        return null;
    }

    // Verify the HMAC/RS256 signature before trusting anything in the token.
    // A forged or tampered token fails verification and is treated as anonymous.
    $verified = JWT::verify($matches[1]);
    if (!$verified) {
        return null;
    }

    // Return the same associative-array shape the rest of this file expects.
    return json_decode(json_encode($verified), true);
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $action = $_GET['action'] ?? 'check';
    $payload = getJWTPayload();

    switch ($action) {
        case 'check':
            // Check permissions for current user
            if (!$payload) {
                echo json_encode([
                    'success' => true,
                    'authenticated' => false,
                    'permissions' => [
                        'can_view_revenue' => false,
                        'can_view_all_payments' => false,
                        'can_view_athlete_payments' => false,
                        'can_send_reminders' => false,
                        'can_process_payments' => false,
                        'can_view_transactions' => false,
                        'can_export_reports' => false,
                        'view_amounts' => false
                    ]
                ]);
                exit;
            }

            $userId = $payload['user_id'] ?? null;
            $userEmail = $payload['email'] ?? '';

            // Query database directly for roles (more reliable than JWT cache)
            $isLeagueAdmin = false;
            $isClubAdmin = false;
            $isTreasurer = false;
            $isCoach = false;
            $isParent = false;

            // Check roles from user_club_access (single source of truth)
            $stmt = $pdo->prepare("
                SELECT role FROM user_club_access
                WHERE user_id = :user_id AND active = TRUE
            ");
            $stmt->execute(['user_id' => $userId]);
            $clubRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($clubRoles as $role) {
                if ($role === 'club_admin') $isClubAdmin = true;
                if ($role === 'treasurer') $isTreasurer = true;
                if ($role === 'coach') $isCoach = true;
                if ($role === 'parent') $isParent = true;
            }

            // Aggregate across ALL guardian rows sharing this email — shared-household support.
            // NOTE: does NOT fix the separate bug where users.email != guardians.email —
            // that requires the Phase 2 user_guardians link table (see project_household_shared_email.md).
            $guardianStmt = $pdo->prepare("
                SELECT g.id, COUNT(ag.athlete_id) as athlete_count
                FROM guardians g
                LEFT JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE g.email = :email
                GROUP BY g.id
            ");
            $guardianStmt->execute(['email' => $userEmail]);
            $guardianRows = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

            $guardianIds = [];
            $totalAthleteCount = 0;
            foreach ($guardianRows as $row) {
                $guardianIds[] = $row['id'];
                $totalAthleteCount += (int) $row['athlete_count'];
            }

            if ($totalAthleteCount > 0) {
                $isParent = true;
            }

            // Build permissions based on role
            $permissions = [
                // Full financial access for admins and treasurer
                'can_view_revenue' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_view_all_payments' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_send_reminders' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_process_payments' => $isLeagueAdmin || $isClubAdmin || $isTreasurer || $isParent,
                'can_view_transactions' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_export_reports' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,
                'can_view_roster_fees' => $isLeagueAdmin || $isClubAdmin || $isTreasurer,

                // Coaches can see roster payment status (not amounts)
                'can_view_athlete_payment_status' => $isLeagueAdmin || $isClubAdmin || $isTreasurer || $isCoach,

                // Everyone can see their own payments
                'can_view_own_payments' => true,

                // Amount visibility
                'view_amounts' => $isLeagueAdmin || $isClubAdmin || $isTreasurer || $isParent
            ];

            // Get athletes this user can view
            $accessibleAthletes = [];

            if ($isParent && !empty($guardianIds)) {
                // Get athletes linked to ANY of the matching guardian rows.
                $placeholders = implode(',', array_fill(0, count($guardianIds), '?'));
                $athleteStmt = $pdo->prepare("
                    SELECT DISTINCT a.id, a.first_name, a.last_name
                    FROM athletes a
                    JOIN athlete_guardians ag ON a.id = ag.athlete_id
                    WHERE ag.guardian_id IN ($placeholders)
                      AND a.active_status = true
                ");
                $athleteStmt->execute($guardianIds);
                $accessibleAthletes = $athleteStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($isCoach) {
                // Get athletes from coach's teams (query is already scoped to this user)
                $teamAthleteStmt = $pdo->prepare("
                    SELECT DISTINCT a.id, a.first_name, a.last_name
                    FROM athletes a
                    JOIN team_members tm ON a.id = tm.athlete_id
                    JOIN teams t ON tm.team_id = t.id
                    JOIN team_coaches tc ON t.id = tc.team_id
                    JOIN coaches c ON tc.coach_id = c.id
                    JOIN users u ON c.email = u.email
                    WHERE u.id = :user_id
                      AND a.active_status = true
                ");
                $teamAthleteStmt->execute(['user_id' => $userId]);
                $coachAthletes = $teamAthleteStmt->fetchAll(PDO::FETCH_ASSOC);
                $accessibleAthletes = array_merge($accessibleAthletes, $coachAthletes);
            }

            // Unique athletes
            $athleteIds = [];
            $uniqueAthletes = [];
            foreach ($accessibleAthletes as $athlete) {
                if (!in_array($athlete['id'], $athleteIds)) {
                    $athleteIds[] = $athlete['id'];
                    $uniqueAthletes[] = $athlete;
                }
            }

            echo json_encode([
                'success' => true,
                'authenticated' => true,
                'user_id' => $userId,
                'roles' => [
                    'is_league_admin' => $isLeagueAdmin,
                    'is_club_admin' => $isClubAdmin,
                    'is_treasurer' => $isTreasurer,
                    'is_coach' => $isCoach,
                    'is_parent' => $isParent
                ],
                'permissions' => $permissions,
                'accessible_athlete_ids' => $athleteIds,
                'accessible_athletes' => $uniqueAthletes
            ]);
            break;

        case 'check-athlete':
            // Check if user can view a specific athlete's financial data
            $athleteId = $_GET['athlete_id'] ?? null;

            if (!$payload || !$athleteId) {
                echo json_encode([
                    'success' => false,
                    'can_view' => false,
                    'can_view_amounts' => false,
                    'message' => 'Missing required parameters'
                ]);
                exit;
            }

            $userId = $payload['user_id'] ?? null;
            $roles = $payload['roles'] ?? [];

            // Check for admin roles
            $isAdmin = false;
            $isCoach = false;
            foreach ($roles as $role) {
                $roleType = $role['role'] ?? '';
                if (in_array($roleType, ['league_admin', 'club_admin', 'treasurer'])) {
                    $isAdmin = true;
                }
                if (in_array($roleType, ['coach', 'head_coach', 'assistant_coach'])) {
                    $isCoach = true;
                }
            }

            if ($isAdmin) {
                echo json_encode([
                    'success' => true,
                    'can_view' => true,
                    'can_view_amounts' => true,
                    'reason' => 'admin'
                ]);
                exit;
            }

            // Check if user is guardian of this athlete
            $guardianCheck = $pdo->prepare("
                SELECT g.id
                FROM guardians g
                JOIN athlete_guardians ag ON g.id = ag.guardian_id
                WHERE g.email = :email AND ag.athlete_id = :athlete_id
            ");
            $guardianCheck->execute([
                'email' => $payload['email'] ?? '',
                'athlete_id' => $athleteId
            ]);

            if ($guardianCheck->fetch()) {
                echo json_encode([
                    'success' => true,
                    'can_view' => true,
                    'can_view_amounts' => true,
                    'reason' => 'guardian'
                ]);
                exit;
            }

            // Check if coach has this athlete on their team
            if ($isCoach) {
                $coachCheck = $pdo->prepare("
                    SELECT tm.athlete_id
                    FROM team_members tm
                    JOIN teams t ON tm.team_id = t.id
                    JOIN team_coaches tc ON t.id = tc.team_id
                    JOIN coaches c ON tc.coach_id = c.id
                    JOIN users u ON c.email = u.email
                    WHERE u.id = :user_id AND tm.athlete_id = :athlete_id
                ");
                $coachCheck->execute([
                    'user_id' => $userId,
                    'athlete_id' => $athleteId
                ]);

                if ($coachCheck->fetch()) {
                    echo json_encode([
                        'success' => true,
                        'can_view' => true,
                        'can_view_amounts' => false, // Coaches see status only
                        'reason' => 'coach'
                    ]);
                    exit;
                }
            }

            // No access
            echo json_encode([
                'success' => true,
                'can_view' => false,
                'can_view_amounts' => false,
                'reason' => 'no_access'
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
