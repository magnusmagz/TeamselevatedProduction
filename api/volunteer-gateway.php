<?php
/**
 * Volunteer Management API Gateway
 *
 * Actions:
 *   team-volunteers      GET    — List volunteers for a team
 *   assign-volunteer     POST   — Assign a volunteer to a team (admin/coach)
 *   update-volunteer     PUT    — Update a volunteer record
 *   remove-volunteer     DELETE — Remove a volunteer from a team
 *   team-signups         GET    — List pending signup requests for a team (filter: status, bg_status)
 *   review-signup        PUT    — Approve or reject a signup request
 *   review-signups-bulk  PUT    — Approve or reject many signup requests at once
 *   club-volunteers      GET    — All volunteers across a club (admin only)
 *   compliance           GET    — Compliance dashboard stats (admin only)
 *   self-signup          POST   — Submit a self-signup request (any authed user)
 *   my-assignments       GET    — Current user's volunteer assignments
 *   available-teams      GET    — Teams accepting volunteers
 *   search-users         GET    — Search users to assign as volunteers
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

$auth = AuthMiddleware::requireAuth();

$userId = $auth->getUserId();
$isAdmin = $auth->hasRole('club_admin') || $auth->isSuperAdmin();
$isCoach = $auth->hasRole('coach');

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ============================================
        // TEAM-SCOPED ENDPOINTS (Coach + Admin)
        // ============================================

        case 'team-volunteers':
            // GET ?action=team-volunteers&team_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $teamId = (int)($_GET['team_id'] ?? 0);
            if (!$teamId) { badRequest('team_id is required'); }

            requireTeamAccess($auth, $db, $teamId);

            $sql = "SELECT tv.*, u.first_name, u.last_name, u.email, u.phone,
                        t.name as team_name
                    FROM team_volunteers tv
                    JOIN users u ON tv.user_id = u.id
                    JOIN teams t ON tv.team_id = t.id
                    WHERE tv.team_id = ?
                    ORDER BY tv.status, u.last_name, u.first_name";
            $stmt = $db->prepare($sql);
            $stmt->execute([$teamId]);
            $volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'volunteers' => $volunteers]);
            break;

        case 'create-volunteer':
            // POST ?action=create-volunteer
            // Body: { team_id, first_name, last_name, email, phone?, notes?, start_date?, end_date? }
            // Creates a new user record and assigns them as a volunteer
            if ($method !== 'POST') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $teamId = (int)($data['team_id'] ?? 0);
            $firstName = trim($data['first_name'] ?? '');
            $lastName = trim($data['last_name'] ?? '');
            $email = trim($data['email'] ?? '');

            if (!$teamId || !$firstName || !$lastName || !$email) {
                badRequest('team_id, first_name, last_name, and email are required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                badRequest('Invalid email address');
            }

            requireTeamAccess($auth, $db, $teamId);

            // Get the team's club_id for user_club_access
            $teamStmt = $db->prepare("SELECT club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
            $teamStmt->execute([$teamId]);
            $teamData = $teamStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teamData) { notFound('Team not found'); }
            $clubId = $teamData['club_id'];

            $db->beginTransaction();
            try {
                // Check if user with this email already exists
                $existingStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $existingStmt->execute([strtolower($email)]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $newUserId = $existing['id'];
                } else {
                    // Create new user with minimal info (no password — they can claim the account later)
                    $createStmt = $db->prepare("INSERT INTO users (first_name, last_name, email, phone, created_at) VALUES (?, ?, ?, ?, NOW()) RETURNING id");
                    $createStmt->execute([$firstName, $lastName, strtolower($email), $data['phone'] ?? null]);
                    $newUserId = $createStmt->fetchColumn();
                }

                // Ensure user_club_access row exists
                $ucaCheck = $db->prepare("SELECT id FROM user_club_access WHERE user_id = ? AND club_profile_id = ?");
                $ucaCheck->execute([$newUserId, $clubId]);
                if (!$ucaCheck->fetch()) {
                    $ucaStmt = $db->prepare("INSERT INTO user_club_access (user_id, club_profile_id, role, active, granted_at) VALUES (?, ?, 'volunteer', true, NOW())");
                    $ucaStmt->execute([$newUserId, $clubId]);
                }

                // Check if already a volunteer on this team
                $checkStmt = $db->prepare("SELECT id FROM team_volunteers WHERE team_id = ? AND user_id = ?");
                $checkStmt->execute([$teamId, $newUserId]);
                if ($checkStmt->fetch()) {
                    $db->rollBack();
                    badRequest('This person is already a volunteer on this team');
                }

                // Create volunteer assignment (bg check starts as pending for new volunteers)
                $volStmt = $db->prepare("INSERT INTO team_volunteers
                    (team_id, user_id, volunteer_role, start_date, end_date,
                     background_check_status, notes, assigned_by, status, self_signup)
                    VALUES (?, ?, 'volunteer', ?, ?, 'pending', ?, ?, 'active', false)");
                $volStmt->execute([
                    $teamId,
                    $newUserId,
                    $data['start_date'] ?? date('Y-m-d'),
                    $data['end_date'] ?? null,
                    $data['notes'] ?? null,
                    $userId
                ]);

                $volId = $db->lastInsertId();
                $db->commit();

                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'id' => $volId,
                    'user_id' => $newUserId,
                    'message' => $existing ? 'Existing user assigned as volunteer' : 'New volunteer created and assigned'
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'assign-volunteer':
            // POST ?action=assign-volunteer
            // Body: { team_id, user_id, notes?, start_date?, end_date? }
            if ($method !== 'POST') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $teamId = (int)($data['team_id'] ?? 0);
            $volunteerUserId = (int)($data['user_id'] ?? 0);

            if (!$teamId || !$volunteerUserId) {
                badRequest('team_id and user_id are required');
            }

            requireTeamAccess($auth, $db, $teamId);

            // Enforce the same background-check gate as the self-signup approval
            // path: a volunteer may not be assigned to a team unless their check is
            // cleared. Direct assignment previously recorded the status but still let
            // a non-cleared volunteer onto the team — a child-safety enforcement gap.
            $bgStatus = getUserBackgroundCheckStatus($db, $volunteerUserId);
            if ($bgStatus !== 'cleared') {
                http_response_code(403);
                echo json_encode([
                    'error' => 'Cannot assign: volunteer background check not cleared',
                    'background_check_status' => $bgStatus
                ]);
                exit();
            }

            // Check if already assigned
            $checkStmt = $db->prepare("SELECT id FROM team_volunteers WHERE team_id = ? AND user_id = ?");
            $checkStmt->execute([$teamId, $volunteerUserId]);
            if ($checkStmt->fetch()) {
                badRequest('User is already a volunteer on this team');
            }

            $sql = "INSERT INTO team_volunteers
                        (team_id, user_id, volunteer_role, start_date, end_date,
                         background_check_status, background_check_date, notes,
                         assigned_by, status, self_signup)
                    VALUES (?, ?, 'volunteer', ?, ?, ?, NOW(), ?, ?, 'active', false)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $teamId,
                $volunteerUserId,
                $data['start_date'] ?? date('Y-m-d'),
                $data['end_date'] ?? null,
                $bgStatus,
                $data['notes'] ?? null,
                $userId
            ]);

            $newId = $db->lastInsertId();
            http_response_code(201);
            echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Volunteer assigned successfully']);
            break;

        case 'update-volunteer':
            // PUT ?action=update-volunteer&volunteer_id={id}
            if ($method !== 'PUT') { methodNotAllowed(); }

            $volunteerId = (int)($_GET['volunteer_id'] ?? 0);
            if (!$volunteerId) { badRequest('volunteer_id is required'); }

            $data = json_decode(file_get_contents('php://input'), true);

            // Look up volunteer to verify team access
            $vol = getVolunteerRecord($db, $volunteerId);
            if (!$vol) { notFound('Volunteer record not found'); }

            requireTeamAccess($auth, $db, $vol['team_id']);

            $updates = [];
            $params = [];
            if (isset($data['status'])) {
                if (!in_array($data['status'], ['active', 'inactive'])) {
                    badRequest('Invalid status value');
                }
                $updates[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['notes'])) {
                $updates[] = "notes = ?";
                $params[] = $data['notes'];
            }
            if (isset($data['start_date'])) {
                $updates[] = "start_date = ?";
                $params[] = $data['start_date'];
            }
            if (isset($data['end_date'])) {
                $updates[] = "end_date = ?";
                $params[] = $data['end_date'];
            }
            if (isset($data['background_check_status'])) {
                if (!in_array($data['background_check_status'], ['pending', 'cleared', 'expired'])) {
                    badRequest('Invalid background_check_status value');
                }
                $updates[] = "background_check_status = ?";
                $params[] = $data['background_check_status'];
                $updates[] = "background_check_date = NOW()";
            }

            if (empty($updates)) {
                badRequest('No fields to update');
            }

            $params[] = $volunteerId;
            $sql = "UPDATE team_volunteers SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true, 'message' => 'Volunteer updated successfully']);
            break;

        case 'remove-volunteer':
            // DELETE ?action=remove-volunteer&volunteer_id={id}
            if ($method !== 'DELETE') { methodNotAllowed(); }

            $volunteerId = (int)($_GET['volunteer_id'] ?? 0);
            if (!$volunteerId) { badRequest('volunteer_id is required'); }

            $vol = getVolunteerRecord($db, $volunteerId);
            if (!$vol) { notFound('Volunteer record not found'); }

            requireTeamAccess($auth, $db, $vol['team_id']);

            $stmt = $db->prepare("DELETE FROM team_volunteers WHERE id = ?");
            $stmt->execute([$volunteerId]);

            echo json_encode(['success' => true, 'message' => 'Volunteer removed successfully']);
            break;

        // ============================================
        // SIGNUP REQUEST ENDPOINTS (Coach + Admin)
        // ============================================

        case 'team-signups':
            // GET ?action=team-signups&team_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $teamId = (int)($_GET['team_id'] ?? 0);
            if (!$teamId) { badRequest('team_id is required'); }

            requireTeamAccess($auth, $db, $teamId);

            $statusFilter = $_GET['status'] ?? 'pending';

            $sql = "SELECT vs.*, u.first_name, u.last_name, u.email, u.phone,
                        t.name as team_name
                    FROM volunteer_signups vs
                    JOIN users u ON vs.user_id = u.id
                    JOIN teams t ON vs.team_id = t.id
                    WHERE vs.team_id = ?";
            $params = [$teamId];

            if ($statusFilter !== 'all') {
                $sql .= " AND vs.status = ?";
                $params[] = $statusFilter;
            }

            $sql .= " ORDER BY vs.requested_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $signups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Attach bg check status to each signup (derived per-user, not a column on
            // volunteer_signups, so the bg_status filter is applied here in PHP).
            foreach ($signups as &$signup) {
                $signup['background_check_status'] = getUserBackgroundCheckStatus($db, $signup['user_id']);
            }
            unset($signup);

            // Optional background-check filter
            $bgFilter = $_GET['bg_status'] ?? '';
            if ($bgFilter !== '' && $bgFilter !== 'all') {
                $signups = array_values(array_filter($signups, function ($s) use ($bgFilter) {
                    return ($s['background_check_status'] ?? 'none') === $bgFilter;
                }));
            }

            echo json_encode(['success' => true, 'signups' => $signups]);
            break;

        case 'review-signup':
            // PUT ?action=review-signup&signup_id={id}
            // Body: { decision: 'approved'|'rejected', notes? }
            if ($method !== 'PUT') { methodNotAllowed(); }

            $signupId = (int)($_GET['signup_id'] ?? 0);
            if (!$signupId) { badRequest('signup_id is required'); }

            $data = json_decode(file_get_contents('php://input'), true);
            $decision = $data['decision'] ?? '';

            if (!in_array($decision, ['approved', 'rejected'])) {
                badRequest('decision must be "approved" or "rejected"');
            }

            // Look up signup
            $stmt = $db->prepare("SELECT * FROM volunteer_signups WHERE id = ? AND status = 'pending'");
            $stmt->execute([$signupId]);
            $signup = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$signup) { notFound('Pending signup not found'); }

            requireTeamAccess($auth, $db, $signup['team_id']);

            $db->beginTransaction();
            try {
                $result = applySignupDecision($db, $signup, $decision, $userId, $data['notes'] ?? null);
                if (!$result['ok']) {
                    $db->rollBack();
                    http_response_code(403);
                    echo json_encode([
                        'error' => 'Cannot approve: volunteer background check not cleared',
                        'background_check_status' => $result['background_check_status']
                    ]);
                    exit();
                }

                $db->commit();
                echo json_encode(['success' => true, 'message' => "Signup $decision successfully"]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;

        case 'review-signups-bulk':
            // PUT ?action=review-signups-bulk
            // Body: { decision: 'approved'|'rejected', signup_ids: [int], notes? }
            // Applies the same decision to many pending signups. Each is scope-checked
            // and bg-gated independently; results are reported per id so the caller can
            // surface partial failures (e.g. some approvals blocked by missing bg check).
            if ($method !== 'PUT') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $decision = $data['decision'] ?? '';
            $signupIds = $data['signup_ids'] ?? [];

            if (!in_array($decision, ['approved', 'rejected'])) {
                badRequest('decision must be "approved" or "rejected"');
            }
            if (!is_array($signupIds) || count($signupIds) === 0) {
                badRequest('signup_ids must be a non-empty array');
            }

            $approved = 0;
            $rejected = 0;
            $skipped = [];

            foreach ($signupIds as $rawId) {
                $sid = (int)$rawId;
                if (!$sid) { continue; }

                $stmt = $db->prepare("SELECT * FROM volunteer_signups WHERE id = ? AND status = 'pending'");
                $stmt->execute([$sid]);
                $signup = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$signup) {
                    $skipped[] = ['signup_id' => $sid, 'reason' => 'not_found_or_not_pending'];
                    continue;
                }

                // Scope check per signup; skip (don't abort the batch) if out of scope.
                if (!hasTeamAccess($auth, $db, $signup['team_id'])) {
                    $skipped[] = ['signup_id' => $sid, 'reason' => 'forbidden'];
                    continue;
                }

                $db->beginTransaction();
                try {
                    $result = applySignupDecision($db, $signup, $decision, $userId, $data['notes'] ?? null);
                    if (!$result['ok']) {
                        $db->rollBack();
                        $skipped[] = [
                            'signup_id' => $sid,
                            'reason' => 'background_check_not_cleared',
                            'background_check_status' => $result['background_check_status']
                        ];
                        continue;
                    }
                    $db->commit();
                    if ($decision === 'approved') { $approved++; } else { $rejected++; }
                } catch (Exception $e) {
                    $db->rollBack();
                    $skipped[] = ['signup_id' => $sid, 'reason' => 'error'];
                }
            }

            echo json_encode([
                'success' => true,
                'approved' => $approved,
                'rejected' => $rejected,
                'skipped' => $skipped,
                'message' => sprintf('%d approved, %d rejected, %d skipped', $approved, $rejected, count($skipped))
            ]);
            break;

        // ============================================
        // CLUB-SCOPED ENDPOINTS (Admin Only)
        // ============================================

        case 'club-volunteers':
            // GET ?action=club-volunteers&club_id={id}&status=&bg_status=&team_id=&search=
            if ($method !== 'GET') { methodNotAllowed(); }

            if (!$isAdmin) { forbidden(); }

            $clubId = (int)($_GET['club_id'] ?? 0);
            if (!$clubId) { badRequest('club_id is required'); }

            requireClubAccess($auth, $clubId);

            $sql = "SELECT tv.*, u.first_name, u.last_name, u.email, u.phone,
                        t.name as team_name, t.age_group, t.division
                    FROM team_volunteers tv
                    JOIN users u ON tv.user_id = u.id
                    JOIN teams t ON tv.team_id = t.id
                    WHERE t.club_id = ? AND t.deleted_at IS NULL";
            $params = [$clubId];

            // Filters
            if (!empty($_GET['status'])) {
                $sql .= " AND tv.status = ?";
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['bg_status'])) {
                $sql .= " AND tv.background_check_status = ?";
                $params[] = $_GET['bg_status'];
            }
            if (!empty($_GET['team_id'])) {
                $sql .= " AND tv.team_id = ?";
                $params[] = (int)$_GET['team_id'];
            }
            if (!empty($_GET['search'])) {
                $sql .= " AND (u.first_name ILIKE ? OR u.last_name ILIKE ? OR u.email ILIKE ?)";
                $search = '%' . $_GET['search'] . '%';
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            $sql .= " ORDER BY u.last_name, u.first_name";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'volunteers' => $volunteers]);
            break;

        case 'compliance':
            // GET ?action=compliance&club_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            if (!$isAdmin) { forbidden(); }

            $clubId = (int)($_GET['club_id'] ?? 0);
            if (!$clubId) { badRequest('club_id is required'); }

            requireClubAccess($auth, $clubId);

            // Summary stats
            $summaryStmt = $db->prepare("
                SELECT
                    COUNT(*) as total_volunteers,
                    COUNT(*) FILTER (WHERE tv.background_check_status = 'cleared') as cleared,
                    COUNT(*) FILTER (WHERE tv.background_check_status = 'pending') as pending,
                    COUNT(*) FILTER (WHERE tv.background_check_status = 'expired') as expired,
                    COUNT(*) FILTER (WHERE tv.background_check_date IS NULL) as never_checked,
                    COUNT(*) FILTER (WHERE tv.status = 'active') as active_count
                FROM team_volunteers tv
                JOIN teams t ON tv.team_id = t.id
                WHERE t.club_id = ? AND t.deleted_at IS NULL AND tv.status = 'active'
            ");
            $summaryStmt->execute([$clubId]);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            // Compliance rate
            $total = (int)$summary['active_count'];
            $summary['compliance_rate'] = $total > 0
                ? round(((int)$summary['cleared'] / $total) * 100, 1)
                : 0;

            // Per-team breakdown
            $teamStmt = $db->prepare("
                SELECT
                    t.id as team_id, t.name as team_name, t.age_group, t.division,
                    COUNT(tv.id) as volunteer_count,
                    COUNT(*) FILTER (WHERE tv.background_check_status = 'cleared') as cleared,
                    COUNT(*) FILTER (WHERE tv.background_check_status = 'pending') as pending_bg,
                    COUNT(*) FILTER (WHERE tv.background_check_status = 'expired') as expired_bg
                FROM teams t
                LEFT JOIN team_volunteers tv ON t.id = tv.team_id AND tv.status = 'active'
                WHERE t.club_id = ? AND t.deleted_at IS NULL
                GROUP BY t.id, t.name, t.age_group, t.division
                ORDER BY t.name
            ");
            $teamStmt->execute([$clubId]);
            $teamBreakdown = $teamStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($teamBreakdown as &$team) {
                $cnt = (int)$team['volunteer_count'];
                $team['compliance_rate'] = $cnt > 0
                    ? round(((int)$team['cleared'] / $cnt) * 100, 1)
                    : 100;
            }

            // Needs attention: expired or pending bg checks
            $needsAttentionStmt = $db->prepare("
                SELECT tv.*, u.first_name, u.last_name, u.email,
                       t.name as team_name,
                       EXTRACT(DAY FROM NOW() - tv.background_check_date) as days_since_check
                FROM team_volunteers tv
                JOIN users u ON tv.user_id = u.id
                JOIN teams t ON tv.team_id = t.id
                WHERE t.club_id = ? AND t.deleted_at IS NULL
                    AND tv.status = 'active'
                    AND (tv.background_check_status IN ('expired', 'pending')
                         OR tv.background_check_date IS NULL)
                ORDER BY
                    CASE tv.background_check_status
                        WHEN 'expired' THEN 1
                        WHEN 'pending' THEN 2
                        ELSE 3
                    END,
                    tv.background_check_date ASC NULLS FIRST
            ");
            $needsAttentionStmt->execute([$clubId]);
            $needsAttention = $needsAttentionStmt->fetchAll(PDO::FETCH_ASSOC);

            // Pending signups count
            $pendingSignupsStmt = $db->prepare("
                SELECT COUNT(*) FROM volunteer_signups vs
                JOIN teams t ON vs.team_id = t.id
                WHERE t.club_id = ? AND vs.status = 'pending'
            ");
            $pendingSignupsStmt->execute([$clubId]);
            $summary['pending_signups'] = (int)$pendingSignupsStmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'team_breakdown' => $teamBreakdown,
                'needs_attention' => $needsAttention
            ]);
            break;

        // ============================================
        // USER-SCOPED ENDPOINTS (Any Authenticated User)
        // ============================================

        case 'self-signup':
            // POST ?action=self-signup
            // Body: { team_id, notes? }
            if ($method !== 'POST') { methodNotAllowed(); }

            $data = json_decode(file_get_contents('php://input'), true);
            $teamId = (int)($data['team_id'] ?? 0);
            if (!$teamId) { badRequest('team_id is required'); }

            // Verify team exists and belongs to a club the user can see
            $teamStmt = $db->prepare("SELECT id, club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
            $teamStmt->execute([$teamId]);
            $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) { notFound('Team not found'); }

            // Check if already a volunteer
            $checkStmt = $db->prepare("SELECT id FROM team_volunteers WHERE team_id = ? AND user_id = ?");
            $checkStmt->execute([$teamId, $userId]);
            if ($checkStmt->fetch()) {
                badRequest('You are already a volunteer on this team');
            }

            // Check if already has a pending signup
            $checkStmt = $db->prepare("SELECT id FROM volunteer_signups WHERE team_id = ? AND user_id = ? AND status = 'pending'");
            $checkStmt->execute([$teamId, $userId]);
            if ($checkStmt->fetch()) {
                badRequest('You already have a pending signup request for this team');
            }

            $stmt = $db->prepare("INSERT INTO volunteer_signups (team_id, user_id, notes) VALUES (?, ?, ?)");
            $stmt->execute([$teamId, $userId, $data['notes'] ?? null]);

            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Volunteer signup request submitted']);
            break;

        case 'my-assignments':
            // GET ?action=my-assignments
            if ($method !== 'GET') { methodNotAllowed(); }

            $sql = "SELECT tv.*, t.name as team_name, t.age_group, t.division,
                        s.name as season_name,
                        CONCAT(coach.first_name, ' ', coach.last_name) as coach_name
                    FROM team_volunteers tv
                    JOIN teams t ON tv.team_id = t.id
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users coach ON t.primary_coach_id = coach.id
                    WHERE tv.user_id = ? AND t.deleted_at IS NULL
                    ORDER BY tv.status, t.name";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Also get pending signups
            $pendingStmt = $db->prepare("
                SELECT vs.*, t.name as team_name, t.age_group, t.division
                FROM volunteer_signups vs
                JOIN teams t ON vs.team_id = t.id
                WHERE vs.user_id = ? AND vs.status = 'pending' AND t.deleted_at IS NULL
                ORDER BY vs.requested_at DESC
            ");
            $pendingStmt->execute([$userId]);
            $pendingSignups = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'assignments' => $assignments,
                'pending_signups' => $pendingSignups
            ]);
            break;

        case 'available-teams':
            // GET ?action=available-teams&club_id={id}
            if ($method !== 'GET') { methodNotAllowed(); }

            $clubId = (int)($_GET['club_id'] ?? 0);
            if (!$clubId) { badRequest('club_id is required'); }

            $sql = "SELECT t.id, t.name, t.age_group, t.division,
                        s.name as season_name,
                        CONCAT(coach.first_name, ' ', coach.last_name) as coach_name,
                        (SELECT COUNT(*) FROM team_volunteers tv
                         WHERE tv.team_id = t.id AND tv.status = 'active') as volunteer_count,
                        EXISTS(SELECT 1 FROM team_volunteers tv
                               WHERE tv.team_id = t.id AND tv.user_id = ?) as already_volunteer,
                        EXISTS(SELECT 1 FROM volunteer_signups vs
                               WHERE vs.team_id = t.id AND vs.user_id = ? AND vs.status = 'pending') as pending_signup
                    FROM teams t
                    LEFT JOIN seasons s ON t.season_id = s.id
                    LEFT JOIN users coach ON t.primary_coach_id = coach.id
                    WHERE t.club_id = ? AND t.deleted_at IS NULL
                    ORDER BY t.name";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $userId, $clubId]);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'teams' => $teams]);
            break;

        case 'search-users':
            // GET ?action=search-users&club_id={id}&q={search}
            if ($method !== 'GET') { methodNotAllowed(); }

            if (!$isAdmin && !$isCoach) { forbidden(); }

            $clubId = (int)($_GET['club_id'] ?? 0);
            $query = $_GET['q'] ?? '';

            if (!$clubId) { badRequest('club_id is required'); }
            if (strlen($query) < 2) { badRequest('Search query must be at least 2 characters'); }

            requireClubAccess($auth, $clubId);

            $search = '%' . $query . '%';
            $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone
                    FROM users u
                    JOIN user_club_access uca ON u.id = uca.user_id
                    WHERE uca.club_profile_id = ? AND uca.active = true
                        AND (u.first_name ILIKE ? OR u.last_name ILIKE ? OR u.email ILIKE ?)
                    ORDER BY u.last_name, u.first_name
                    LIMIT 20";
            $stmt = $db->prepare($sql);
            $stmt->execute([$clubId, $search, $search, $search]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Attach bg check status to each user
            foreach ($users as &$user) {
                $user['background_check_status'] = getUserBackgroundCheckStatus($db, $user['id']);
            }

            echo json_encode(['success' => true, 'users' => $users]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
            break;
    }
} catch (PDOException $e) {
    error_log("Volunteer Gateway DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Volunteer Gateway Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred']);
}

// ============================================
// Helper Functions
// ============================================

function methodNotAllowed() {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

function badRequest($message) {
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit();
}

function notFound($message) {
    http_response_code(404);
    echo json_encode(['error' => $message]);
    exit();
}

function forbidden() {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to perform this action']);
    exit();
}

/**
 * Verify user has access to a team (admin to any, coach to own teams).
 * Halts the request (403/404) when access is denied.
 */
function requireTeamAccess($auth, $db, $teamId) {
    if ($auth->isSuperAdmin()) return;

    // Distinguish "team not found" (404) from "no access" (403).
    $stmt = $db->prepare("SELECT club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$teamId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        notFound('Team not found');
    }

    if (!hasTeamAccess($auth, $db, $teamId)) {
        forbidden();
    }
}

/**
 * Non-throwing version of the team-access check. Returns true/false.
 * Used by bulk operations that skip (rather than abort) on a scope miss.
 */
function hasTeamAccess($auth, $db, $teamId) {
    if ($auth->isSuperAdmin()) return true;

    $userId = $auth->getUserId();

    $stmt = $db->prepare("SELECT club_id FROM teams WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        return false;
    }

    // Admin: must belong to the team's club
    if ($auth->hasRole('club_admin')) {
        return $auth->canAccessClub($team['club_id']);
    }

    // Coach: must be assigned to this team
    $stmt = $db->prepare("
        SELECT 1 FROM team_members
        WHERE team_id = ? AND user_id = ? AND role IN ('assistant_coach', 'team_manager')
            AND (leave_date IS NULL OR leave_date > CURRENT_DATE)
        UNION
        SELECT 1 FROM teams
        WHERE id = ? AND primary_coach_id = ?
    ");
    $stmt->execute([$teamId, $userId, $teamId, $userId]);

    return (bool)$stmt->fetch();
}

/**
 * Apply an approve/reject decision to a single (already-loaded, pending) signup.
 * Assumes the caller has opened a transaction AND already verified team access.
 *
 * Returns ['ok' => bool, 'background_check_status' => string].
 * ok=false only when an approval is blocked because the volunteer's background
 * check is not cleared — the caller should roll back in that case.
 *
 * Note: the applicant's original `notes` are only overwritten when a review note
 * is supplied (e.g. a rejection reason); an approve with no note preserves them.
 */
function applySignupDecision($db, array $signup, string $decision, $reviewerId, $reviewNotes = null): array {
    if ($reviewNotes !== null && $reviewNotes !== '') {
        $stmt = $db->prepare("UPDATE volunteer_signups SET status = ?, reviewed_by = ?, reviewed_at = NOW(), notes = ? WHERE id = ?");
        $stmt->execute([$decision, $reviewerId, $reviewNotes, $signup['id']]);
    } else {
        $stmt = $db->prepare("UPDATE volunteer_signups SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$decision, $reviewerId, $signup['id']]);
    }

    if ($decision === 'approved') {
        $bgStatus = getUserBackgroundCheckStatus($db, $signup['user_id']);
        if ($bgStatus !== 'cleared') {
            return ['ok' => false, 'background_check_status' => $bgStatus];
        }

        // Create the volunteer record if not already present
        $checkStmt = $db->prepare("SELECT id FROM team_volunteers WHERE team_id = ? AND user_id = ?");
        $checkStmt->execute([$signup['team_id'], $signup['user_id']]);
        if (!$checkStmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO team_volunteers
                (team_id, user_id, volunteer_role, start_date,
                 background_check_status, background_check_date,
                 assigned_by, status, self_signup)
                VALUES (?, ?, 'volunteer', ?, ?, NOW(), ?, 'active', true)");
            $stmt->execute([
                $signup['team_id'],
                $signup['user_id'],
                date('Y-m-d'),
                $bgStatus,
                $reviewerId
            ]);
        }

        return ['ok' => true, 'background_check_status' => $bgStatus];
    }

    return ['ok' => true, 'background_check_status' => getUserBackgroundCheckStatus($db, $signup['user_id'])];
}

/**
 * Verify user has access to a club
 */
function requireClubAccess($auth, $clubId) {
    if ($auth->isSuperAdmin()) return;

    if (!$auth->canAccessClub($clubId)) {
        forbidden();
    }
}

/**
 * Get volunteer record by ID
 */
function getVolunteerRecord($db, $volunteerId) {
    $stmt = $db->prepare("SELECT * FROM team_volunteers WHERE id = ?");
    $stmt->execute([$volunteerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get a user's background check status.
 * Checks team_volunteers first, then guardians table.
 */
function getUserBackgroundCheckStatus($db, $checkUserId) {
    // Check if they have a cleared status in any volunteer record
    $stmt = $db->prepare("
        SELECT background_check_status FROM team_volunteers
        WHERE user_id = ? AND background_check_status = 'cleared'
        LIMIT 1
    ");
    $stmt->execute([$checkUserId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) return 'cleared';

    // Check guardians table (parents may have bg check tracked there)
    $stmt = $db->prepare("
        SELECT background_check_status FROM guardians g
        JOIN users u ON u.email = g.email
        WHERE u.id = ? AND g.background_check_status = 'cleared'
        LIMIT 1
    ");
    $stmt->execute([$checkUserId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) return 'cleared';

    // Check if pending anywhere
    $stmt = $db->prepare("
        SELECT background_check_status FROM team_volunteers
        WHERE user_id = ? AND background_check_status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$checkUserId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) return 'pending';

    return 'none';
}
