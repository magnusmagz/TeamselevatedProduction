<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AthleteScope.php';
require_once __DIR__ . '/../lib/athlete_writes.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Require a valid token for ALL methods on this admin/coach gateway. Athlete
// records (incl. guardian PII) must never be readable or mutable without auth.
$auth = AuthMiddleware::requireAuth();

/**
 * Does the requester have any admin/coach standing that lets them create
 * athletes? (super_admin, any club_admin, or a coach of any team.)
 */
function requesterCanCreateAthletes(PDO $pdo, AuthMiddleware $auth): bool {
    if ($auth->isSuperAdmin()) {
        return true;
    }
    if (!empty(AthleteScope::clubAdminClubIds($auth))) {
        return true;
    }
    $uid = (int) $auth->getUserId();
    return $uid > 0 && !empty(AthleteScope::coachTeamIdsForUser($pdo, $uid));
}

/**
 * Resolve which club a newly created athlete belongs to, so athletes.club_id is
 * stamped at creation. Without it, a team-less athlete is invisible to club
 * admins (AthleteScope derives club membership from team_members OR
 * athletes.club_id — the write-side half of CA-18).
 *
 * Order: an explicit club_id the requester is allowed to use (super admin: any;
 * club admin: one of their clubs) → the requester's sole admin club → the sole
 * club of the teams they coach → null (visible to super admins only, same as
 * the pre-fix behavior).
 */
function te_resolve_create_club_id(PDO $pdo, AuthMiddleware $auth, $requested): ?int {
    $requested = is_numeric($requested) ? (int) $requested : null;

    if ($auth->isSuperAdmin()) {
        return $requested;
    }

    $adminClubIds = AthleteScope::clubAdminClubIds($auth);
    if ($requested !== null && in_array($requested, $adminClubIds, true)) {
        return $requested;
    }
    if (count($adminClubIds) === 1) {
        return $adminClubIds[0];
    }

    $uid = (int) $auth->getUserId();
    if ($uid > 0) {
        $teamIds = AthleteScope::coachTeamIdsForUser($pdo, $uid);
        if (!empty($teamIds)) {
            $ph = implode(',', array_fill(0, count($teamIds), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT club_id FROM teams WHERE id IN ($ph) AND club_id IS NOT NULL");
            $stmt->execute(array_values($teamIds));
            $clubs = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($clubs) === 1) {
                return (int) $clubs[0];
            }
        }
    }

    return null;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Get specific athlete by ID with guardian data
                $id = (int)$_GET['id'];

                // Scope enforcement: club admin of athlete's club, a coach of
                // one of the athlete's teams, or a guardian of the athlete.
                if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $id)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Access denied']);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT a.*, u.email
                    FROM athletes a
                    LEFT JOIN users u ON u.id = a.user_id AND u.role = 'player'
                    WHERE a.id = ?
                ");
                $stmt->execute([$id]);
                $athlete = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($athlete) {
                    // Get guardian information from athlete_guardians and guardians tables
                    $guardianStmt = $pdo->prepare("
                        SELECT ag.id as id,
                               g.first_name,
                               g.last_name,
                               g.email,
                               g.mobile_phone,
                               g.work_phone,
                               ag.relationship AS relationship_type,
                               ag.is_primary AS is_primary_contact,
                               ag.can_pickup,
                               ag.emergency_contact
                        FROM athlete_guardians ag
                        JOIN guardians g ON ag.guardian_id = g.id
                        WHERE ag.athlete_id = ?
                        ORDER BY ag.is_primary DESC
                    ");
                    $guardianStmt->execute([$id]);
                    $guardians = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

                    $athlete['guardians'] = $guardians;
                    echo json_encode($athlete);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Athlete not found']);
                }
            } else {
                // Get all athletes — scoped to the requester. Super admin sees
                // all; a club admin sees their club's athletes; a coach sees
                // only athletes on teams they coach; a guardian sees only their
                // own athletes. Searching for an out-of-scope athlete therefore
                // returns nothing (COACH-13 / COACH-14).
                $filter = AthleteScope::accessibleAthleteFilter($pdo, $auth, 'a.id');

                $stmt = $pdo->prepare("
                    SELECT a.id, a.first_name, a.middle_initial, a.last_name, a.preferred_name,
                           a.date_of_birth, a.gender, a.school_name, a.grade_level, a.active_status,
                           a.created_at, u.email,
                           g.first_name as guardian_first_name, g.last_name as guardian_last_name,
                           CONCAT(g.first_name, ' ', g.last_name) as primary_guardian_name,
                           g.email as primary_guardian_email,
                           g.mobile_phone as primary_guardian_phone
                    FROM athletes a
                    LEFT JOIN users u ON u.id = a.user_id AND u.role = 'player'
                    -- One row per athlete: pick a single primary guardian. A plain
                    -- JOIN multiplies rows when an athlete has >1 is_primary guardian
                    -- (two-parent / blended households), which duplicated athlete ids
                    -- and broke the frontend table (duplicate React keys → sort froze).
                    LEFT JOIN LATERAL (
                        SELECT gg.first_name, gg.last_name, gg.email, gg.mobile_phone
                        FROM athlete_guardians ag
                        JOIN guardians gg ON gg.id = ag.guardian_id
                        WHERE ag.athlete_id = a.id AND ag.is_primary = true
                        ORDER BY ag.id
                        LIMIT 1
                    ) g ON true
                    WHERE a.active_status = true
                    {$filter['sql']}
                    ORDER BY a.last_name, a.first_name
                ");
                $stmt->execute($filter['params']);
                $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'athletes' => $athletes]);
            }
            break;

        case 'POST':
            // Create new athlete — admins/coaches only.
            if (!requesterCanCreateAthletes($pdo, $auth)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            $input = json_decode(file_get_contents('php://input'), true);

            $first_name = $input['first_name'] ?? null;
            $last_name = $input['last_name'] ?? null;
            $email = $input['email'] ?? null;
            $middle_initial = $input['middle_initial'] ?? null;
            $preferred_name = $input['preferred_name'] ?? null;
            $date_of_birth = $input['date_of_birth'] ?? null;
            $gender = $input['gender'] ?? null;
            $school_name = $input['school_name'] ?? null;
            $grade_level = $input['grade_level'] ?? null;
            $password = $input['password'] ?? password_hash('defaultpass', PASSWORD_DEFAULT);

            if (!$first_name || !$last_name) {
                throw new Exception('First name and last name are required');
            }

            // Set defaults for required fields if not provided
            if (!$date_of_birth) {
                $date_of_birth = '2000-01-01'; // Default date
            }
            if (!$gender) {
                $gender = 'Male'; // Default gender
            }

            // Stamp the athlete's club so club admins can see team-less athletes
            // in the People -> Athletes list (write-side half of CA-18).
            $input['club_id'] = te_resolve_create_club_id($pdo, $auth, $input['club_id'] ?? null);

            // Create the athlete and (if an email was given) find-or-create its linked
            // player user. See lib/athlete_writes.php: athletes.id is ALWAYS sequence-
            // generated and the user link lives in athletes.user_id — never overloaded
            // onto the primary key (which used to collide: athletes_pkey duplicate).
            $pdo->beginTransaction();
            try {
                $created = te_create_athlete($pdo, $input);
                $pdo->commit();
                echo json_encode(['success' => true, 'athlete_id' => $created['athlete_id'], 'message' => 'Athlete created successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Update athlete
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$id) {
                throw new Exception('Athlete ID is required');
            }

            // Only those who can access the athlete may edit them (club admin of
            // the athlete's club, a coach of one of their teams, or a guardian).
            if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $id)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }

            $pdo->beginTransaction();

            try {
                // Update athlete table
                $athlete_fields = [];
                $athlete_values = [];

                $athlete_mapping = [
                    'first_name' => 'first_name',
                    'middle_initial' => 'middle_initial',
                    'last_name' => 'last_name',
                    'preferred_name' => 'preferred_name',
                    'date_of_birth' => 'date_of_birth',
                    'gender' => 'gender',
                    'school_name' => 'school_name',
                    'grade_level' => 'grade_level',
                    'home_address_line1' => 'home_address_line1',
                    'home_address_line2' => 'home_address_line2',
                    'city' => 'city',
                    'state' => 'state',
                    'zip_code' => 'zip_code',
                    'photo_url' => 'photo_url'
                ];

                foreach ($athlete_mapping as $input_key => $db_field) {
                    if (isset($input[$input_key])) {
                        $athlete_fields[] = "$db_field = ?";
                        $athlete_values[] = $input[$input_key];
                    }
                }

                if (!empty($athlete_fields)) {
                    $athlete_values[] = $id;
                    $stmt = $pdo->prepare("UPDATE athletes SET " . implode(', ', $athlete_fields) . " WHERE id = ?");
                    $stmt->execute($athlete_values);
                }

                // Update user table if email, first_name, or last_name changed
                $user_fields = [];
                $user_values = [];

                $user_mapping = [
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'email' => 'email'
                ];

                foreach ($user_mapping as $input_key => $db_field) {
                    if (isset($input[$input_key])) {
                        $user_fields[] = "$db_field = ?";
                        $user_values[] = $input[$input_key];
                    }
                }

                if (!empty($user_fields)) {
                    // The linked user lives at athletes.user_id (NOT the athlete id).
                    $linkStmt = $pdo->prepare("SELECT user_id FROM athletes WHERE id = ?");
                    $linkStmt->execute([$id]);
                    $linkedUserId = $linkStmt->fetchColumn();
                    if ($linkedUserId) {
                        $user_values[] = $linkedUserId;
                        $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $user_fields) . " WHERE id = ? AND role = 'player'");
                        $stmt->execute($user_values);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Athlete updated successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'DELETE':
            // Delete athlete (soft delete - set active_status to 0)
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

            if (!$id) {
                throw new Exception('Athlete ID is required');
            }

            // Only those who can access the athlete may deactivate them.
            if (!AthleteScope::userCanAccessAthlete($pdo, $auth, $id)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }

            $pdo->beginTransaction();

            try {
                // Soft delete from athletes table (stamp audit timestamp)
                $stmt = $pdo->prepare("UPDATE athletes SET active_status = false, deleted_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Athlete deactivated successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    // Return a clean message for unique-constraint violations instead of raw SQL
    // (e.g. an email already in use); otherwise surface the (validation) message.
    $msg = $e->getMessage();
    if (strpos($msg, '23505') !== false || stripos($msg, 'duplicate key') !== false) {
        http_response_code(409);
        $friendly = (stripos($msg, 'users_email') !== false)
            ? 'That email is already in use by another account.'
            : 'That record already exists.';
        echo json_encode(['error' => $friendly]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $msg]);
    }
}
?>