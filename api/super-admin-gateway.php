<?php
/**
 * Super Admin Gateway API
 *
 * Platform-wide administration for super admins only.
 * Allows viewing and managing all clubs, teams, users, and roles.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/JWT.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/impersonation.php';

// Require authentication
$auth = AuthMiddleware::requireAuth();

// Require super admin role
if (!$auth->isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Super admin access required']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

function badRequest($msg) {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'stats':
            handleGetStats($pdo);
            break;

        case 'notification-health':
            handleNotificationHealth($pdo);
            break;

        case 'clubs':
            handleGetClubs($pdo);
            break;

        case 'club-details':
            $clubId = $_GET['id'] ?? null;
            if (!$clubId) {
                throw new Exception('Club ID required');
            }
            handleGetClubDetails($pdo, (int)$clubId);
            break;

        case 'users':
            handleGetUsers($pdo);
            break;

        case 'athletes':
            handleGetAthletes($pdo);
            break;

        case 'user-details':
            $userId = $_GET['id'] ?? null;
            if (!$userId) {
                throw new Exception('User ID required');
            }
            handleGetUserDetails($pdo, (int)$userId);
            break;

        case 'update-system-role':
            if ($method !== 'PUT') {
                throw new Exception('PUT method required');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            handleUpdateSystemRole($pdo, $input, $auth);
            break;

        case 'update-club-role':
            if ($method !== 'PUT') {
                throw new Exception('PUT method required');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            handleUpdateClubRole($pdo, $input);
            break;

        case 'remove-club-access':
            if ($method !== 'DELETE') {
                throw new Exception('DELETE method required');
            }
            $userId = $_GET['user_id'] ?? null;
            $clubId = $_GET['club_id'] ?? null;
            if (!$userId || !$clubId) {
                throw new Exception('user_id and club_id required');
            }
            handleRemoveClubAccess($pdo, (int)$userId, (int)$clubId);
            break;

        case 'create-club':
            if ($method !== 'POST') { throw new Exception('POST method required'); }
            $data = json_decode(file_get_contents('php://input'), true);
            $name = $data['name'] ?? null;
            if (!$name) { badRequest('Club name is required'); }
            $stmt = $pdo->prepare("INSERT INTO club_profile (name, city, state, website, primary_color) VALUES (?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$name, $data['city'] ?? null, $data['state'] ?? null, $data['website'] ?? null, $data['primary_color'] ?? '#12443e']);
            $id = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'update-club':
            if ($method !== 'PUT') { throw new Exception('PUT method required'); }
            $data = json_decode(file_get_contents('php://input'), true);
            $clubId = $data['id'] ?? null;
            if (!$clubId) { badRequest('Club ID required'); }
            $fields = []; $values = [];
            foreach (['name','city','state','website','primary_color'] as $f) {
                if (isset($data[$f])) { $fields[] = "$f = ?"; $values[] = $data[$f]; }
            }
            if (!empty($fields)) {
                $values[] = $clubId;
                $stmt = $pdo->prepare("UPDATE club_profile SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($values);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete-club':
            if ($method !== 'DELETE') { throw new Exception('DELETE method required'); }
            $clubId = $_GET['id'] ?? null;
            if (!$clubId) { badRequest('Club ID required'); }
            $stmt = $pdo->prepare("DELETE FROM club_profile WHERE id = ?");
            $stmt->execute([$clubId]);
            echo json_encode(['success' => true]);
            break;

        case 'create-user':
            if ($method !== 'POST') { throw new Exception('POST method required'); }
            $data = json_decode(file_get_contents('php://input'), true);
            $email = $data['email'] ?? null;
            $firstName = $data['first_name'] ?? null;
            $lastName = $data['last_name'] ?? null;
            if (!$email || !$firstName || !$lastName) { badRequest('email, first_name, last_name required'); }
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) { badRequest('Email already exists'); }
            $password = password_hash($data['password'] ?? 'TempPass123!', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, system_role) VALUES (?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$firstName, $lastName, $email, $password, $data['system_role'] ?? 'user']);
            $id = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'update-user':
            if ($method !== 'PUT') { throw new Exception('PUT method required'); }
            $data = json_decode(file_get_contents('php://input'), true);
            $userId = $data['id'] ?? null;
            if (!$userId) { badRequest('User ID required'); }
            $fields = []; $values = [];
            foreach (['first_name','last_name','email','phone','system_role'] as $f) {
                if (isset($data[$f])) { $fields[] = "$f = ?"; $values[] = $data[$f]; }
            }
            if (!empty($fields)) {
                $values[] = $userId;
                $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($values);
            }
            echo json_encode(['success' => true]);
            break;

        case 'assign-user-to-club':
            if ($method !== 'POST') { throw new Exception('POST method required'); }
            $data = json_decode(file_get_contents('php://input'), true);
            $userId = $data['user_id'] ?? null;
            $clubId = $data['club_id'] ?? null;
            $role = $data['role'] ?? 'coach';
            if (!$userId || !$clubId) { badRequest('user_id and club_id required'); }
            $stmt = $pdo->prepare("SELECT id FROM user_club_access WHERE user_id = ? AND club_profile_id = ?");
            $stmt->execute([$userId, $clubId]);
            if ($stmt->fetch()) { badRequest('User already assigned to this club'); }
            $stmt = $pdo->prepare("INSERT INTO user_club_access (user_id, club_profile_id, role, active) VALUES (?, ?, ?, true)");
            $stmt->execute([$userId, $clubId, $role]);
            echo json_encode(['success' => true]);
            break;

        case 'impersonate':
            if ($method !== 'POST') { throw new Exception('POST method required'); }
            $data = json_decode(file_get_contents('php://input'), true);
            handleImpersonate($pdo, $data, $auth);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Action not found']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get platform-wide statistics
 */
/**
 * How chat notifications are performing, for the internal team.
 *
 * Chat notifications deliberately bypass EmailSendService, which is where the
 * tracking pixel and link rewriting live — so none of this appears in Email
 * Reporting, and there is no analytics on the site either. Without this screen
 * the only way to answer "are notifications reaching anyone" is a database
 * query, which is not a thing a support conversation can wait for.
 *
 * Super-admin only, by the gate at the top of this file. It is deliberately
 * PLATFORM-wide rather than club-scoped: the question it answers is whether the
 * feature works, which is ours rather than any one club's.
 *
 * Read-only. Nothing here can change anything, which is what makes it safe to
 * hand to the whole internal team.
 */
function handleNotificationHealth($pdo) {
    $days = isset($_GET['days']) ? max(1, min(365, (int) $_GET['days'])) : 30;
    $since = "NOW() - INTERVAL '{$days} days'";

    $health = ['days' => $days];

    // ── Delivery ─────────────────────────────────────────────────────────────
    $row = $pdo->query("
        SELECT COUNT(*)::int AS sent,
               COUNT(DISTINCT user_id)::int AS people,
               COUNT(clicked_at)::int AS clicked
          FROM chat_notification_state
         WHERE last_notified_at > {$since}
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $health['totals'] = [
        'sent'    => (int) ($row['sent'] ?? 0),
        'people'  => (int) ($row['people'] ?? 0),
        'clicked' => (int) ($row['clicked'] ?? 0),
    ];

    $health['by_channel'] = $pdo->query("
        SELECT COALESCE(last_notified_channel, 'unknown') AS channel,
               COUNT(*)::int AS sent,
               COUNT(clicked_at)::int AS clicked
          FROM chat_notification_state
         WHERE last_notified_at > {$since}
         GROUP BY 1 ORDER BY 2 DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $health['by_club'] = $pdo->query("
        SELECT COALESCE(cp.name, '(no club)') AS club,
               COUNT(*)::int AS sent,
               COUNT(s.clicked_at)::int AS clicked,
               COUNT(DISTINCT s.user_id)::int AS people
          FROM chat_notification_state s
          JOIN conversations c ON c.id = s.conversation_id
          LEFT JOIN club_profile cp ON cp.id = c.club_id
         WHERE s.last_notified_at > {$since}
         GROUP BY 1 ORDER BY 2 DESC LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Reach ────────────────────────────────────────────────────────────────
    // Whether the feature CAN reach people, which matters more than any rate:
    // a low click rate on a channel nobody has enabled says nothing.
    $health['reach'] = [
        'push_devices'   => (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn(),
        'people_with_push' => (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions')->fetchColumn(),
        'emailable_users'  => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email IS NOT NULL AND email <> ''")->fetchColumn(),
        'opted_out_email'  => (int) $pdo->query('SELECT COUNT(*) FROM chat_notification_prefs WHERE email_enabled = FALSE')->fetchColumn(),
        'opted_out_push'   => (int) $pdo->query('SELECT COUNT(*) FROM chat_notification_prefs WHERE push_enabled = FALSE')->fetchColumn(),
        'muted_conversations' => (int) $pdo->query('SELECT COUNT(*) FROM conversation_participants WHERE muted = TRUE')->fetchColumn(),
    ];

    // ── The dispatcher itself ────────────────────────────────────────────────
    // A silent worker is indistinguishable from a quiet week, so surface when it
    // last did anything. If this is hours old while messages are arriving, the
    // worker is stuck — which is exactly the failure that went unnoticed for
    // weeks before the reconnect fix.
    $health['last_notification_at'] = $pdo->query(
        'SELECT MAX(last_notified_at) FROM chat_notification_state'
    )->fetchColumn() ?: null;

    $health['last_message_at'] = $pdo->query(
        'SELECT MAX(created_at) FROM chat_messages'
    )->fetchColumn() ?: null;

    // ── Moderation ───────────────────────────────────────────────────────────
    $health['moderation'] = [
        'alerts' => $pdo->query("
            SELECT kind, COUNT(*)::int AS n, COUNT(DISTINCT user_id)::int AS admins
              FROM chat_moderation_alert_state WHERE sent_at > {$since} GROUP BY 1
        ")->fetchAll(PDO::FETCH_ASSOC),
        'open_reports' => (int) $pdo->query(
            "SELECT COUNT(*) FROM chat_message_reports WHERE status = 'open'"
        )->fetchColumn(),
        'open_high_severity' => (int) $pdo->query(
            "SELECT COUNT(*) FROM chat_message_reports WHERE status = 'open' AND severity = 'high'"
        )->fetchColumn(),
    ];

    echo json_encode(['success' => true, 'health' => $health]);
}

function handleGetStats($pdo) {
    $stats = [];

    // Total clubs
    $stmt = $pdo->query("SELECT COUNT(*) FROM club_profile");
    $stats['total_clubs'] = (int)$stmt->fetchColumn();

    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $stats['total_users'] = (int)$stmt->fetchColumn();

    // Total teams
    $stmt = $pdo->query("SELECT COUNT(*) FROM teams");
    $stats['total_teams'] = (int)$stmt->fetchColumn();

    // Total athletes
    $stmt = $pdo->query("SELECT COUNT(*) FROM athletes WHERE active_status = true");
    $stats['total_athletes'] = (int)$stmt->fetchColumn();

    // Super admins count
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE system_role = 'super_admin'");
    $stats['super_admins'] = (int)$stmt->fetchColumn();

    // Active club admins
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_club_access WHERE role = 'club_admin' AND active = true");
    $stats['club_admins'] = (int)$stmt->fetchColumn();

    // Active coaches
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_club_access WHERE role = 'coach' AND active = true");
    $stats['coaches'] = (int)$stmt->fetchColumn();

    echo json_encode(['success' => true, 'stats' => $stats]);
}

/**
 * Get all clubs with counts
 */
function handleGetClubs($pdo) {
    $search = $_GET['search'] ?? '';

    $sql = "
        SELECT
            c.id,
            c.name,
            c.created_at,
            (SELECT COUNT(*) FROM user_club_access WHERE club_profile_id = c.id AND role = 'club_admin' AND active = true) as admin_count,
            (SELECT COUNT(*) FROM user_club_access WHERE club_profile_id = c.id AND role = 'coach' AND active = true) as coach_count,
            (SELECT COUNT(*) FROM teams WHERE club_id = c.id) as team_count,
            (SELECT COUNT(*) FROM athletes WHERE club_id = c.id AND active_status = true) as athlete_count
        FROM club_profile c
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $sql .= " AND c.name ILIKE ?";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY c.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clubs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'clubs' => $clubs]);
}

/**
 * Get single club details with teams and users
 */
function handleGetClubDetails($pdo, $clubId) {
    // Get club info
    $stmt = $pdo->prepare("SELECT * FROM club_profile WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        throw new Exception('Club not found');
    }

    // Get teams
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.name,
            t.age_group,
            t.gender,
            (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as athlete_count,
            (SELECT CONCAT(u.first_name, ' ', u.last_name)
             FROM users u
             WHERE u.id = t.primary_coach_id) as coach_name
        FROM teams t
        WHERE t.club_id = ?
        ORDER BY t.name ASC
    ");
    $stmt->execute([$clubId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get users with access to this club
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.system_role,
            uca.role as club_role,
            uca.granted_at
        FROM users u
        JOIN user_club_access uca ON u.id = uca.user_id
        WHERE uca.club_profile_id = ? AND uca.active = true
        ORDER BY
            CASE uca.role
                WHEN 'club_admin' THEN 1
                WHEN 'coach' THEN 2
                WHEN 'parent' THEN 3
                ELSE 4
            END,
            u.first_name ASC
    ");
    $stmt->execute([$clubId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'club' => $club,
        'teams' => $teams,
        'users' => $users
    ]);
}

/**
 * Get all users
 */
function handleGetUsers($pdo) {
    $search = $_GET['search'] ?? '';

    $sql = "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.system_role,
            u.created_at,
            u.last_login_at,
            (SELECT COUNT(*) FROM user_club_access WHERE user_id = u.id AND active = true) as club_count
        FROM users u
        WHERE 1=1
    ";

    $params = [];

    if ($search) {
        $sql .= " AND (u.first_name ILIKE ? OR u.last_name ILIKE ? OR u.email ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
}

/**
 * Get single user details with all club roles
 */
function handleGetUserDetails($pdo, $userId) {
    // Get user info
    $stmt = $pdo->prepare("
        SELECT id, first_name, last_name, email, system_role, created_at, last_login_at
        FROM users WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    // Get all club roles
    $stmt = $pdo->prepare("
        SELECT
            uca.id as access_id,
            uca.role,
            uca.granted_at,
            c.id as club_id,
            c.name as club_name
        FROM user_club_access uca
        JOIN club_profile c ON uca.club_profile_id = c.id
        WHERE uca.user_id = ? AND uca.active = true
        ORDER BY c.name ASC
    ");
    $stmt->execute([$userId]);
    $clubRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'user' => $user,
        'club_roles' => $clubRoles
    ]);
}

/**
 * Update user's system role (grant/revoke super_admin)
 */
function handleUpdateSystemRole($pdo, $input, $auth) {
    $userId = $input['user_id'] ?? null;
    $systemRole = $input['system_role'] ?? null;

    if (!$userId || !$systemRole) {
        throw new Exception('user_id and system_role required');
    }

    if (!in_array($systemRole, ['user', 'super_admin'])) {
        throw new Exception('Invalid system_role. Must be "user" or "super_admin"');
    }

    // Prevent removing your own super admin access
    if ($userId == $auth->getUserId() && $systemRole === 'user') {
        throw new Exception('Cannot remove your own super admin access');
    }

    $stmt = $pdo->prepare("UPDATE users SET system_role = ? WHERE id = ?");
    $stmt->execute([$systemRole, $userId]);

    echo json_encode(['success' => true, 'message' => 'System role updated']);
}

/**
 * Update user's role within a club
 */
function handleUpdateClubRole($pdo, $input) {
    $userId = $input['user_id'] ?? null;
    $clubId = $input['club_id'] ?? null;
    $role = $input['role'] ?? null;

    if (!$userId || !$clubId || !$role) {
        throw new Exception('user_id, club_id, and role required');
    }

    if (!in_array($role, ['club_admin', 'coach', 'parent', 'player'])) {
        throw new Exception('Invalid role');
    }

    $stmt = $pdo->prepare("
        UPDATE user_club_access
        SET role = ?
        WHERE user_id = ? AND club_profile_id = ? AND active = true
    ");
    $stmt->execute([$role, $userId, $clubId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('User club access not found');
    }

    echo json_encode(['success' => true, 'message' => 'Club role updated']);
}

/**
 * Remove user's access to a club
 */
function handleRemoveClubAccess($pdo, $userId, $clubId) {
    $stmt = $pdo->prepare("
        UPDATE user_club_access
        SET active = false, revoked_at = CURRENT_TIMESTAMP
        WHERE user_id = ? AND club_profile_id = ? AND active = true
    ");
    $stmt->execute([$userId, $clubId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('User club access not found');
    }

    echo json_encode(['success' => true, 'message' => 'User removed from club']);
}

/**
 * Get all athletes with club and team info
 */
function handleGetAthletes($pdo) {
    $search = $_GET['search'] ?? '';

    $sql = "
        SELECT
            a.id,
            a.first_name,
            a.last_name,
            a.email,
            a.date_of_birth,
            a.gender,
            a.active_status,
            a.created_at,
            c.id as club_id,
            c.name as club_name,
            (
                SELECT string_agg(t.name, ', ' ORDER BY t.name)
                FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                WHERE tm.athlete_id = a.id
            ) as team_names
        FROM athletes a
        LEFT JOIN club_profile c ON a.club_id = c.id
        WHERE 1=1
          AND a.active_status = true
    ";

    $params = [];

    if ($search) {
        $sql .= " AND (a.first_name ILIKE ? OR a.last_name ILIKE ? OR a.email ILIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY a.last_name ASC, a.first_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'athletes' => $athletes]);
}

/**
 * Start impersonating a user — mint a token that IS that user.
 *
 * The returned token carries the target's user_id, so every downstream
 * authorization check treats the session as the target with no special-casing.
 * See lib/impersonation.php for why that, and not an "acting as" hint, is the
 * shape. The `imp` claim only records who is responsible and when the window
 * closes.
 *
 * Impersonation permits WRITES. That is the point — support cases are usually
 * "this save doesn't work for me", which a read-only view cannot reproduce. The
 * protections are therefore accountability-shaped, not capability-shaped: a
 * one-hour ceiling, a permanent banner, an audit row at start and at stop, and a
 * refusal to impersonate another super admin.
 */
function handleImpersonate($pdo, $data, $auth) {
    // Belt and braces: an impersonated session already fails the isSuperAdmin()
    // gate at the top of this file (roles are re-derived for the TARGET), but
    // chaining impersonations must be impossible for a reason stated out loud
    // rather than as a side effect of another check.
    if ($auth->isImpersonating()) {
        http_response_code(403);
        echo json_encode(['error' => 'Stop the current impersonation before starting another']);
        return;
    }

    $targetId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
    if ($targetId <= 0) {
        badRequest('user_id is required');
    }

    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, system_role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    $adminId = (int) $auth->getUserId();
    $refusal = te_impersonation_refusal($target, $adminId);
    if ($refusal !== null) {
        http_response_code($refusal === 'user_not_found' ? 404 : 403);
        echo json_encode([
            'error' => te_impersonation_refusal_message($refusal),
            'reason' => $refusal,
        ]);
        return;
    }

    $stmt = $pdo->prepare('SELECT email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $adminName = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
    $adminEmail = $admin['email'] ?? ($auth->getPayload()->email ?? null);

    $targetName = trim($target['first_name'] . ' ' . $target['last_name']);
    $claims = array_merge(
        JWT::buildOrganizationalContext($pdo, $target['id'], null, null),
        te_impersonation_claims($adminId, $adminEmail, $adminName)
    );
    $token = JWT::generate($target['id'], $target['email'], $targetName, $claims);
    $payload = JWT::decode($token);

    // Audited BEFORE the token reaches the client, and with the target on the
    // resource columns so "who was signed in as me" is answerable from either end.
    AuditLogger::log($pdo, $adminId, 'impersonation_started', 'user', (int) $target['id'], [
        'target_email' => $target['email'],
        'target_name' => $targetName,
        'expires_at' => $claims['imp']['exp'],
    ]);

    echo json_encode([
        'success' => true,
        'token' => $token,
        'expires_at' => $claims['imp']['exp'],
        'user' => [
            'id' => (int) $target['id'],
            'email' => $target['email'],
            'name' => $targetName,
            'system_role' => $payload->system_role ?? 'user',
            'organization' => [
                'orgId' => $payload->org_id ?? null,
                'orgType' => $payload->org_type ?? null,
                'orgName' => $payload->org_name ?? null,
            ],
            'roles' => $payload->roles ?? [],
            'activeRole' => $payload->active_context ?? null,
            'impersonation' => $claims['imp'],
        ],
    ]);
}
?>
