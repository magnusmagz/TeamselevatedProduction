<?php
/**
 * Recipient Search Gateway API
 *
 * Typeahead recipient search with role-based scoping for email/SMS compose.
 * Supports searching athletes, guardians, and coaches/staff.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header("Content-Type: application/json; charset=UTF-8");

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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    $auth = AuthMiddleware::requireAuth();
    $userId = $auth->getUserId();

    switch ($action) {
        case 'search':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            handleSearch($connection, $auth, $userId);
            break;

        case 'groups':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            handleGroups($connection, $auth, $userId);
            break;

        case 'resolve-group':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            handleResolveGroup($connection, $auth, $userId);
            break;

        case 'chat-search':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            handleChatSearch($connection, $auth, $userId);
            break;

        case 'chat-resolve-teams':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            handleChatResolveTeams($connection, $auth, $userId);
            break;

        case 'chat-resolve-role':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            handleChatResolveRole($connection, $auth, $userId);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or missing action parameter. Valid actions: search, groups, resolve-group']);
            break;
    }
} catch (Exception $e) {
    error_log("Recipient Search Gateway Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ============================================
// Helper: Get coach team IDs for role scoping
// ============================================
function getCoachTeamIds($connection, $userId, $clubProfileId) {
    $stmt = $connection->prepare("
        SELECT DISTINCT t.id FROM teams t
        LEFT JOIN team_members tm ON t.id = tm.team_id AND tm.user_id = ?
            AND tm.role IN ('assistant_coach','team_manager') AND tm.status = 'active'
        WHERE (t.primary_coach_id = ? OR tm.id IS NOT NULL) AND t.club_id = ?
    ");
    $stmt->execute([$userId, $userId, $clubProfileId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================
// Helper: Check if user is club admin for a club
// ============================================
function isClubAdmin($auth, $clubProfileId) {
    if ($auth->isSuperAdmin()) {
        return true;
    }
    return $auth->hasRole('club_admin', $clubProfileId, 'club');
}

// ============================================
// Helper: Build team filter SQL + params
// ============================================
function getTeamFilterClause($connection, $auth, $userId, $clubProfileId, $teamColumn = 'tm.team_id') {
    if (isClubAdmin($auth, $clubProfileId)) {
        return ['sql' => '', 'params' => []];
    }

    $coachTeamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
    if (empty($coachTeamIds)) {
        return ['sql' => "AND 1=0", 'params' => []];
    }

    $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
    return [
        'sql' => "AND {$teamColumn} IN ({$placeholders})",
        'params' => $coachTeamIds
    ];
}

// ============================================
// Helper: Check suppression status for a recipient
// ============================================
function checkSuppression($connection, $clubProfileId, $email, $phone, $channel) {
    $suppressed = false;
    $suppressionReason = null;

    if ($channel === 'email' && !empty($email)) {
        $stmt = $connection->prepare("
            SELECT reason, scope, team_id FROM email_suppressions
            WHERE club_profile_id = ? AND email = ? AND channel = 'email'
            LIMIT 1
        ");
        $stmt->execute([$clubProfileId, $email]);
        $suppression = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($suppression) {
            $suppressed = true;
            $suppressionReason = $suppression['reason'];
        }
    } elseif ($channel === 'sms' && !empty($phone)) {
        $stmt = $connection->prepare("
            SELECT reason, scope, team_id FROM email_suppressions
            WHERE club_profile_id = ? AND phone = ? AND channel = 'sms'
            LIMIT 1
        ");
        $stmt->execute([$clubProfileId, $phone]);
        $suppression = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($suppression) {
            $suppressed = true;
            $suppressionReason = $suppression['reason'];
        }
    }

    return ['suppressed' => $suppressed, 'suppression_reason' => $suppressionReason];
}

// ============================================
// Helper: Check guardian SMS opt-out
// ============================================
function checkGuardianSmsOptOut($connection, $guardianId) {
    $stmt = $connection->prepare("SELECT sms_opt_out FROM guardians WHERE id = ?");
    $stmt->execute([$guardianId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && $row['sms_opt_out'] === true;
}

// ============================================
// Action: search
// ============================================
function handleSearch($connection, $auth, $userId) {
    $q = trim($_GET['q'] ?? '');
    $clubProfileId = $_GET['club_profile_id'] ?? null;
    $channel = $_GET['channel'] ?? 'email';

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id is required']);
        exit();
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this club']);
        exit();
    }

    if (strlen($q) < 2) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Search term must be at least 2 characters']);
        exit();
    }

    if (!in_array($channel, ['email', 'sms'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Channel must be email or sms']);
        exit();
    }

    $searchPattern = '%' . $q . '%';
    $teamFilter = getTeamFilterClause($connection, $auth, $userId, $clubProfileId);
    $results = [];
    $seenKeys = []; // For deduplication by email/phone

    // ----- 1. Search Athletes -----
    $athleteParams = [$clubProfileId, $searchPattern, $searchPattern, $searchPattern, $searchPattern];
    $athleteTeamFilterSql = $teamFilter['sql'];
    $athleteParams = array_merge($athleteParams, $teamFilter['params']);

    $athleteSql = "
        SELECT DISTINCT a.id, a.first_name, a.last_name, a.email, a.phone, 'athlete' as type,
               t.id as team_id, t.name as team_name
        FROM athletes a
        JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
        JOIN teams t ON tm.team_id = t.id AND t.club_id = ?
        WHERE (a.first_name ILIKE ? OR a.last_name ILIKE ? OR a.email ILIKE ? OR a.phone LIKE ?)
        {$athleteTeamFilterSql}
        LIMIT 20
    ";

    $stmt = $connection->prepare($athleteSql);
    $stmt->execute($athleteParams);
    $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($athletes as $athlete) {
        $contactField = ($channel === 'email') ? $athlete['email'] : $athlete['phone'];

        // Skip if missing required contact field for channel
        if (empty($contactField)) {
            continue;
        }

        $dedupeKey = $channel . ':' . strtolower($contactField);
        if (isset($seenKeys[$dedupeKey])) {
            continue;
        }
        $seenKeys[$dedupeKey] = true;

        $suppression = checkSuppression($connection, $clubProfileId, $athlete['email'], $athlete['phone'], $channel);

        $results[] = [
            'id' => (int)$athlete['id'],
            'type' => 'athlete',
            'first_name' => $athlete['first_name'],
            'last_name' => $athlete['last_name'],
            'email' => $athlete['email'],
            'phone' => $athlete['phone'],
            'team_id' => (int)$athlete['team_id'],
            'team_name' => $athlete['team_name'],
            'suppressed' => $suppression['suppressed'],
            'suppression_reason' => $suppression['suppression_reason'],
            'missing_contact' => false
        ];
    }

    // ----- 2. Search Guardians -----
    $guardianParams = [$clubProfileId, $searchPattern, $searchPattern, $searchPattern, $searchPattern];
    $guardianTeamFilterSql = $teamFilter['sql'];
    $guardianParams = array_merge($guardianParams, $teamFilter['params']);

    $guardianSql = "
        SELECT DISTINCT g.id, g.first_name, g.last_name, g.email, g.mobile_phone as phone, 'guardian' as type,
               a.id as athlete_id, a.first_name as athlete_first_name, a.last_name as athlete_last_name,
               t.id as team_id, t.name as team_name
        FROM guardians g
        JOIN athlete_guardians ag ON g.id = ag.guardian_id AND ag.active_status = TRUE
        JOIN athletes a ON ag.athlete_id = a.id
        JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
        JOIN teams t ON tm.team_id = t.id AND t.club_id = ?
        WHERE (g.first_name ILIKE ? OR g.last_name ILIKE ? OR g.email ILIKE ? OR g.mobile_phone LIKE ?)
          AND ag.receives_communications = TRUE
        {$guardianTeamFilterSql}
        LIMIT 20
    ";

    $stmt = $connection->prepare($guardianSql);
    $stmt->execute($guardianParams);
    $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($guardians as $guardian) {
        $contactField = ($channel === 'email') ? $guardian['email'] : $guardian['phone'];

        if (empty($contactField)) {
            continue;
        }

        $dedupeKey = $channel . ':' . strtolower($contactField);
        if (isset($seenKeys[$dedupeKey])) {
            continue;
        }
        $seenKeys[$dedupeKey] = true;

        $suppression = checkSuppression($connection, $clubProfileId, $guardian['email'], $guardian['phone'], $channel);

        // For SMS, also check guardian sms_opt_out flag
        if ($channel === 'sms' && !$suppression['suppressed']) {
            $smsOptOut = checkGuardianSmsOptOut($connection, $guardian['id']);
            if ($smsOptOut) {
                $suppression['suppressed'] = true;
                $suppression['suppression_reason'] = 'twilio_stop';
            }
        }

        $results[] = [
            'id' => (int)$guardian['id'],
            'type' => 'guardian',
            'first_name' => $guardian['first_name'],
            'last_name' => $guardian['last_name'],
            'email' => $guardian['email'],
            'phone' => $guardian['phone'],
            'athlete_id' => (int)$guardian['athlete_id'],
            'athlete_first_name' => $guardian['athlete_first_name'],
            'athlete_last_name' => $guardian['athlete_last_name'],
            'team_id' => (int)$guardian['team_id'],
            'team_name' => $guardian['team_name'],
            'suppressed' => $suppression['suppressed'],
            'suppression_reason' => $suppression['suppression_reason'],
            'missing_contact' => false
        ];
    }

    // ----- 3. Search Coaches/Staff -----
    $coachParams = [$clubProfileId, $searchPattern, $searchPattern, $searchPattern];

    $coachSql = "
        SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone, 'coach' as type
        FROM users u
        JOIN user_club_access uca ON u.id = uca.user_id AND uca.club_profile_id = ?
        WHERE uca.role IN ('club_admin', 'coach')
        AND uca.active = true
        AND (u.first_name ILIKE ? OR u.last_name ILIKE ? OR u.email ILIKE ?)
        LIMIT 20
    ";

    $stmt = $connection->prepare($coachSql);
    $stmt->execute($coachParams);
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($coaches as $coach) {
        $contactField = ($channel === 'email') ? $coach['email'] : $coach['phone'];

        if (empty($contactField)) {
            continue;
        }

        $dedupeKey = $channel . ':' . strtolower($contactField);
        if (isset($seenKeys[$dedupeKey])) {
            continue;
        }
        $seenKeys[$dedupeKey] = true;

        $suppression = checkSuppression($connection, $clubProfileId, $coach['email'], $coach['phone'], $channel);

        $results[] = [
            'id' => (int)$coach['id'],
            'type' => 'coach',
            'first_name' => $coach['first_name'],
            'last_name' => $coach['last_name'],
            'email' => $coach['email'],
            'phone' => $coach['phone'],
            'suppressed' => $suppression['suppressed'],
            'suppression_reason' => $suppression['suppression_reason'],
            'missing_contact' => false
        ];
    }

    // Sort by last_name, first_name and limit to 20 total
    usort($results, function ($a, $b) {
        $cmp = strcasecmp($a['last_name'], $b['last_name']);
        if ($cmp === 0) {
            return strcasecmp($a['first_name'], $b['first_name']);
        }
        return $cmp;
    });

    $results = array_slice($results, 0, 20);

    echo json_encode([
        'success' => true,
        'results' => $results,
        'total' => count($results)
    ]);
}

// ============================================
// Action: groups
// ============================================
function handleGroups($connection, $auth, $userId) {
    $clubProfileId = $_GET['club_profile_id'] ?? null;

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id is required']);
        exit();
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this club']);
        exit();
    }

    $teamFilter = getTeamFilterClause($connection, $auth, $userId, $clubProfileId, 't.id');
    $params = [$clubProfileId];
    $params = array_merge($params, $teamFilter['params']);

    $sql = "
        SELECT t.id, t.name, t.age_group,
               COUNT(DISTINCT tm.athlete_id) as athlete_count,
               COUNT(DISTINCT g.id) as guardian_count
        FROM teams t
        LEFT JOIN team_members tm ON t.id = tm.team_id AND tm.status = 'active'
        LEFT JOIN athlete_guardians ag ON tm.athlete_id = ag.athlete_id
        LEFT JOIN guardians g ON ag.guardian_id = g.id
        WHERE t.club_id = ? AND t.deleted_at IS NULL
        {$teamFilter['sql']}
        GROUP BY t.id, t.name, t.age_group
        ORDER BY t.name
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast counts to integers
    foreach ($teams as &$team) {
        $team['id'] = (int)$team['id'];
        $team['athlete_count'] = (int)$team['athlete_count'];
        $team['guardian_count'] = (int)$team['guardian_count'];
    }
    unset($team);

    echo json_encode([
        'success' => true,
        'groups' => $teams
    ]);
}

// ============================================
// Action: resolve-group
// ============================================
function handleResolveGroup($connection, $auth, $userId) {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit();
    }

    $clubProfileId = $data['club_profile_id'] ?? null;
    $teamIds = $data['team_ids'] ?? [];
    $recipientTypes = $data['recipient_types'] ?? ['athletes', 'guardians'];
    $excludeIds = $data['exclude_ids'] ?? [];
    $channel = $data['channel'] ?? 'email';

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id is required']);
        exit();
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this club']);
        exit();
    }

    if (empty($teamIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'team_ids is required and must not be empty']);
        exit();
    }

    if (!in_array($channel, ['email', 'sms'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Channel must be email or sms']);
        exit();
    }

    // Validate team access: coaches can only resolve their own teams
    $teamFilter = getTeamFilterClause($connection, $auth, $userId, $clubProfileId);
    if (!isClubAdmin($auth, $clubProfileId)) {
        $coachTeamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
        foreach ($teamIds as $tid) {
            if (!in_array($tid, $coachTeamIds)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied to team ID ' . $tid]);
                exit();
            }
        }
    }

    // Also validate all teams belong to this club
    $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    $stmt = $connection->prepare("SELECT id FROM teams WHERE id IN ({$teamPlaceholders}) AND club_id = ?");
    $stmt->execute(array_merge($teamIds, [$clubProfileId]));
    $validTeamIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($validTeamIds) !== count($teamIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'One or more team_ids do not belong to this club']);
        exit();
    }

    // Build exclude lookup: keyed by "type:id"
    $excludeLookup = [];
    foreach ($excludeIds as $exc) {
        $excType = $exc['type'] ?? '';
        $excId = $exc['id'] ?? 0;
        $excludeLookup["{$excType}:{$excId}"] = true;
    }

    $recipients = [];
    $seenKeys = [];
    $suppressedCount = 0;
    $missingContactCount = 0;

    // ----- Resolve Athletes -----
    if (in_array('athletes', $recipientTypes)) {
        $sql = "
            SELECT DISTINCT a.id, a.first_name, a.last_name, a.email, a.phone, 'athlete' as type,
                   t.id as team_id, t.name as team_name
            FROM athletes a
            JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
            JOIN teams t ON tm.team_id = t.id
            WHERE tm.team_id IN ({$teamPlaceholders}) AND t.club_id = ?
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute(array_merge($teamIds, [$clubProfileId]));
        $athletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($athletes as $athlete) {
            if (isset($excludeLookup["athlete:{$athlete['id']}"])) {
                continue;
            }

            $contactField = ($channel === 'email') ? $athlete['email'] : $athlete['phone'];

            if (empty($contactField)) {
                $missingContactCount++;
                continue;
            }

            $dedupeKey = $channel . ':' . strtolower($contactField);
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;

            $suppression = checkSuppression($connection, $clubProfileId, $athlete['email'], $athlete['phone'], $channel);
            if ($suppression['suppressed']) {
                $suppressedCount++;
            }

            $recipients[] = [
                'id' => (int)$athlete['id'],
                'type' => 'athlete',
                'first_name' => $athlete['first_name'],
                'last_name' => $athlete['last_name'],
                'email' => $athlete['email'],
                'phone' => $athlete['phone'],
                'team_id' => (int)$athlete['team_id'],
                'team_name' => $athlete['team_name'],
                'suppressed' => $suppression['suppressed'],
                'suppression_reason' => $suppression['suppression_reason'],
                'missing_contact' => false
            ];
        }
    }

    // ----- Resolve Guardians -----
    if (in_array('guardians', $recipientTypes)) {
        $sql = "
            SELECT DISTINCT g.id, g.first_name, g.last_name, g.email, g.mobile_phone as phone, 'guardian' as type,
                   a.id as athlete_id, a.first_name as athlete_first_name, a.last_name as athlete_last_name,
                   t.id as team_id, t.name as team_name
            FROM guardians g
            JOIN athlete_guardians ag ON g.id = ag.guardian_id AND ag.active_status = TRUE
            JOIN athletes a ON ag.athlete_id = a.id
            JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
            JOIN teams t ON tm.team_id = t.id
            WHERE tm.team_id IN ({$teamPlaceholders}) AND t.club_id = ?
              AND ag.receives_communications = TRUE
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute(array_merge($teamIds, [$clubProfileId]));
        $guardians = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($guardians as $guardian) {
            if (isset($excludeLookup["guardian:{$guardian['id']}"])) {
                continue;
            }

            $contactField = ($channel === 'email') ? $guardian['email'] : $guardian['phone'];

            if (empty($contactField)) {
                $missingContactCount++;
                continue;
            }

            $dedupeKey = $channel . ':' . strtolower($contactField);
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;

            $suppression = checkSuppression($connection, $clubProfileId, $guardian['email'], $guardian['phone'], $channel);

            // For SMS, also check guardian sms_opt_out flag
            if ($channel === 'sms' && !$suppression['suppressed']) {
                $smsOptOut = checkGuardianSmsOptOut($connection, $guardian['id']);
                if ($smsOptOut) {
                    $suppression['suppressed'] = true;
                    $suppression['suppression_reason'] = 'twilio_stop';
                }
            }

            if ($suppression['suppressed']) {
                $suppressedCount++;
            }

            $recipients[] = [
                'id' => (int)$guardian['id'],
                'type' => 'guardian',
                'first_name' => $guardian['first_name'],
                'last_name' => $guardian['last_name'],
                'email' => $guardian['email'],
                'phone' => $guardian['phone'],
                'athlete_id' => (int)$guardian['athlete_id'],
                'athlete_first_name' => $guardian['athlete_first_name'],
                'athlete_last_name' => $guardian['athlete_last_name'],
                'team_id' => (int)$guardian['team_id'],
                'team_name' => $guardian['team_name'],
                'suppressed' => $suppression['suppressed'],
                'suppression_reason' => $suppression['suppression_reason'],
                'missing_contact' => false
            ];
        }
    }

    // ----- Resolve Coaches -----
    if (in_array('coaches', $recipientTypes)) {
        // Get coaches: primary_coach + team_members with coach roles for the selected teams
        $sql = "
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone, 'coach' as type,
                   t.id as team_id, t.name as team_name
            FROM users u
            JOIN teams t ON t.primary_coach_id = u.id
            WHERE t.id IN ({$teamPlaceholders}) AND t.club_id = ?
            UNION
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone, 'coach' as type,
                   t.id as team_id, t.name as team_name
            FROM users u
            JOIN team_members tm ON u.id = tm.user_id
                AND tm.role IN ('assistant_coach', 'team_manager') AND tm.status = 'active'
            JOIN teams t ON tm.team_id = t.id
            WHERE t.id IN ({$teamPlaceholders}) AND t.club_id = ?
        ";
        $coachParams = array_merge($teamIds, [$clubProfileId], $teamIds, [$clubProfileId]);
        $stmt = $connection->prepare($sql);
        $stmt->execute($coachParams);
        $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($coaches as $coach) {
            if (isset($excludeLookup["coach:{$coach['id']}"])) {
                continue;
            }

            $contactField = ($channel === 'email') ? $coach['email'] : $coach['phone'];

            if (empty($contactField)) {
                $missingContactCount++;
                continue;
            }

            $dedupeKey = $channel . ':' . strtolower($contactField);
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;

            $suppression = checkSuppression($connection, $clubProfileId, $coach['email'], $coach['phone'], $channel);
            if ($suppression['suppressed']) {
                $suppressedCount++;
            }

            $recipients[] = [
                'id' => (int)$coach['id'],
                'type' => 'coach',
                'first_name' => $coach['first_name'],
                'last_name' => $coach['last_name'],
                'email' => $coach['email'],
                'phone' => $coach['phone'],
                'team_id' => (int)$coach['team_id'],
                'team_name' => $coach['team_name'],
                'suppressed' => $suppression['suppressed'],
                'suppression_reason' => $suppression['suppression_reason'],
                'missing_contact' => false
            ];
        }
    }

    // Sort by last_name, first_name
    usort($recipients, function ($a, $b) {
        $cmp = strcasecmp($a['last_name'], $b['last_name']);
        if ($cmp === 0) {
            return strcasecmp($a['first_name'], $b['first_name']);
        }
        return $cmp;
    });

    echo json_encode([
        'success' => true,
        'recipients' => $recipients,
        'total' => count($recipients),
        'suppressed_count' => $suppressedCount,
        'missing_contact_count' => $missingContactCount
    ]);
}

// ============================================
// Action: chat-search
// Returns people (with users.id) and team groups the requester can chat with.
// Scope:
//   super_admin / club_admin → all club members + all club teams as groups
//   coach                    → members of teams they coach + other coaches/admins; their teams as groups
//   parent                   → coaches of teams their athletes are on; no team groups
// ============================================
function handleChatSearch($connection, $auth, $userId) {
    $clubProfileId = $_GET['club_profile_id'] ?? null;
    $q = trim($_GET['q'] ?? '');

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id is required']);
        exit();
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this club']);
        exit();
    }

    $isAdmin = isClubAdmin($auth, $clubProfileId);
    $coachTeamIds = $isAdmin ? [] : getCoachTeamIds($connection, $userId, $clubProfileId);
    $isCoach = !$isAdmin && !empty($coachTeamIds);
    $isParent = !$isAdmin && !$isCoach;

    $like = '%' . $q . '%';
    $people = [];
    $teamGroups = [];

    if ($isParent) {
        // Find teams the requester's athletes are on
        $stmt = $connection->prepare("
            SELECT DISTINCT tm.team_id
            FROM users u
            JOIN guardians g ON g.email = u.email
            JOIN athlete_guardians ag ON ag.guardian_id = g.id
            JOIN team_members tm ON tm.athlete_id = ag.athlete_id AND tm.status = 'active'
            JOIN teams t ON t.id = tm.team_id AND t.club_id = ? AND t.deleted_at IS NULL
            WHERE u.id = ?
        ");
        $stmt->execute([$clubProfileId, $userId]);
        $parentTeamIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($parentTeamIds)) {
            $teamPlaceholders = implode(',', array_fill(0, count($parentTeamIds), '?'));
            // Coaches of those teams: primary_coach_id + assistant_coach/team_manager rows
            $sql = "
                SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.email,
                                'coach' AS role,
                                STRING_AGG(DISTINCT t.name, ', ') AS team_names
                FROM teams t
                LEFT JOIN team_members tm
                  ON tm.team_id = t.id
                 AND tm.role IN ('assistant_coach','team_manager')
                 AND tm.status = 'active'
                JOIN users u ON u.id = COALESCE(tm.user_id, t.primary_coach_id)
                WHERE t.id IN ($teamPlaceholders)
                  AND u.id != ?
                  AND (LOWER(u.first_name) LIKE LOWER(?) OR LOWER(u.last_name) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?))
                GROUP BY u.id, u.first_name, u.last_name, u.email
                ORDER BY u.last_name, u.first_name
            ";
            $params = array_merge($parentTeamIds, [$userId, $like, $like, $like]);
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Admin or coach: search across people they can see
        $teamFilter = '';
        $teamFilterParams = [];
        if ($isCoach) {
            $teamPlaceholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
            // Coach scope: people on their teams (parents via guardian chain) + all coaches/admins in club
            $teamFilter = "
                AND (
                    EXISTS (
                        SELECT 1 FROM guardians g2
                        JOIN athlete_guardians ag2 ON ag2.guardian_id = g2.id
                        JOIN team_members tm2 ON tm2.athlete_id = ag2.athlete_id AND tm2.team_id IN ($teamPlaceholders)
                        WHERE g2.email = u.email
                    )
                    OR EXISTS (
                        SELECT 1 FROM teams t2
                        WHERE t2.club_id = ? AND t2.primary_coach_id = u.id
                    )
                    OR EXISTS (
                        SELECT 1 FROM team_members tm3
                        JOIN teams t3 ON t3.id = tm3.team_id AND t3.club_id = ?
                        WHERE tm3.user_id = u.id AND tm3.role IN ('assistant_coach','team_manager') AND tm3.status = 'active'
                    )
                )
            ";
            $teamFilterParams = array_merge($coachTeamIds, [$clubProfileId, $clubProfileId]);
        }

        $sql = "
            SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.email,
                            CASE
                                WHEN EXISTS (SELECT 1 FROM teams tt WHERE tt.primary_coach_id = u.id AND tt.club_id = ?) THEN 'coach'
                                WHEN EXISTS (SELECT 1 FROM team_members tmm JOIN teams ttt ON ttt.id = tmm.team_id WHERE tmm.user_id = u.id AND tmm.role IN ('assistant_coach','team_manager') AND ttt.club_id = ?) THEN 'coach'
                                ELSE 'parent'
                            END AS role
            FROM users u
            WHERE u.id != ?
              AND (LOWER(u.first_name) LIKE LOWER(?) OR LOWER(u.last_name) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?))
              AND EXISTS (
                  SELECT 1 FROM user_club_access uca
                  WHERE uca.user_id = u.id AND uca.club_profile_id = ?
              )
              $teamFilter
            ORDER BY u.last_name, u.first_name
            LIMIT 50
        ";
        $params = array_merge(
            [$clubProfileId, $clubProfileId, $userId, $like, $like, $like, $clubProfileId],
            $teamFilterParams
        );
        $stmt = $connection->prepare($sql);
        $stmt->execute($params);
        $people = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Team groups (admins see all club teams; coaches see only theirs)
        if ($isAdmin) {
            $stmt = $connection->prepare("
                SELECT t.id, t.name, t.age_group
                FROM teams t
                WHERE t.club_id = ? AND t.deleted_at IS NULL
                ORDER BY t.age_group, t.name
            ");
            $stmt->execute([$clubProfileId]);
            $teamGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($isCoach) {
            $teamPlaceholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
            $stmt = $connection->prepare("
                SELECT t.id, t.name, t.age_group
                FROM teams t
                WHERE t.id IN ($teamPlaceholders) AND t.deleted_at IS NULL
                ORDER BY t.age_group, t.name
            ");
            $stmt->execute($coachTeamIds);
            $teamGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    foreach ($people as &$p) {
        $p['user_id'] = (int)$p['user_id'];
        $p['display_name'] = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    }
    unset($p);
    foreach ($teamGroups as &$t) {
        $t['id'] = (int)$t['id'];
    }
    unset($t);

    // Admin-only: system role groups (All Coaches / All Parents / All Players)
    $roleGroups = [];
    if ($isAdmin) {
        $stmt = $connection->prepare("
            SELECT uca.role, COUNT(DISTINCT uca.user_id) AS count
            FROM user_club_access uca
            WHERE uca.club_profile_id = ?
              AND uca.role IN ('coach', 'parent', 'player')
              AND uca.user_id != ?
            GROUP BY uca.role
        ");
        $stmt->execute([$clubProfileId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $labels = ['coach' => 'All Coaches', 'parent' => 'All Parents', 'player' => 'All Players'];
        foreach ($rows as $r) {
            $roleGroups[] = [
                'role' => $r['role'],
                'label' => $labels[$r['role']] ?? ucfirst($r['role']),
                'count' => (int)$r['count'],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'people' => $people,
        'team_groups' => $teamGroups,
        'role_groups' => $roleGroups,
    ]);
}

// ============================================
// Action: chat-resolve-teams
// Given team_ids, return the deduped users.id list of coaches + guardian-users on those teams.
// Validates team access (admin: all, coach: only their teams, parent: only their athletes' teams).
// ============================================
function handleChatResolveTeams($connection, $auth, $userId) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $clubProfileId = $data['club_profile_id'] ?? null;
    $teamIds = $data['team_ids'] ?? [];

    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id is required']);
        exit();
    }
    if (empty($teamIds) || !is_array($teamIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'team_ids is required']);
        exit();
    }
    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this club']);
        exit();
    }

    $isAdmin = isClubAdmin($auth, $clubProfileId);
    if (!$isAdmin) {
        $coachTeamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
        $allowedIds = $coachTeamIds;
        if (empty($allowedIds)) {
            // Parent: their athletes' teams
            $stmt = $connection->prepare("
                SELECT DISTINCT tm.team_id
                FROM users u
                JOIN guardians g ON g.email = u.email
                JOIN athlete_guardians ag ON ag.guardian_id = g.id
                JOIN team_members tm ON tm.athlete_id = ag.athlete_id AND tm.status = 'active'
                JOIN teams t ON t.id = tm.team_id AND t.club_id = ?
                WHERE u.id = ?
            ");
            $stmt->execute([$clubProfileId, $userId]);
            $allowedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $allowedSet = array_flip(array_map('intval', $allowedIds));
        foreach ($teamIds as $tid) {
            if (!isset($allowedSet[(int)$tid])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied to one or more teams']);
                exit();
            }
        }
    }

    $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    // Coaches + assistant_coach/team_manager users + guardian users on those teams
    $sql = "
        SELECT DISTINCT u.id AS user_id
        FROM (
            SELECT t.primary_coach_id AS user_id FROM teams t WHERE t.id IN ($teamPlaceholders) AND t.primary_coach_id IS NOT NULL
            UNION
            SELECT tm.user_id FROM team_members tm WHERE tm.team_id IN ($teamPlaceholders) AND tm.role IN ('assistant_coach','team_manager') AND tm.status = 'active' AND tm.user_id IS NOT NULL
            UNION
            SELECT u2.id FROM users u2
              JOIN guardians g ON g.email = u2.email
              JOIN athlete_guardians ag ON ag.guardian_id = g.id
              JOIN team_members tm2 ON tm2.athlete_id = ag.athlete_id AND tm2.team_id IN ($teamPlaceholders) AND tm2.status = 'active'
        ) src
        JOIN users u ON u.id = src.user_id
        WHERE u.id != ?
    ";
    $params = array_merge($teamIds, $teamIds, $teamIds, [$userId]);
    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    echo json_encode([
        'success' => true,
        'user_ids' => $userIds,
    ]);
}

// ============================================
// Action: chat-resolve-role
// Admin-only. Given a system role, returns user_ids of all users with that role
// in the club (excluding the requester).
// ============================================
function handleChatResolveRole($connection, $auth, $userId) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $clubProfileId = $data['club_profile_id'] ?? null;
    $role = $data['role'] ?? null;

    if (!$clubProfileId || !$role) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id and role are required']);
        exit();
    }
    if (!in_array($role, ['coach', 'parent', 'player'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'role must be coach, parent, or player']);
        exit();
    }
    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this club']);
        exit();
    }
    if (!isClubAdmin($auth, $clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit();
    }

    $stmt = $connection->prepare("
        SELECT DISTINCT uca.user_id
        FROM user_club_access uca
        WHERE uca.club_profile_id = ?
          AND uca.role = ?
          AND uca.user_id != ?
    ");
    $stmt->execute([$clubProfileId, $role, $userId]);
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    echo json_encode([
        'success' => true,
        'user_ids' => $userIds,
    ]);
}
