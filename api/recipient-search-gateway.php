<?php
/**
 * Recipient Search Gateway API
 *
 * Typeahead recipient search with role-based scoping for email/SMS compose.
 * Supports searching athletes, guardians, and coaches/staff.
 */

// Tests require this file for its query helpers. PHP early-binds top-level
// functions, so returning here still defines them while skipping CORS, the
// headers, the request dispatch, and the Neon connect. Never defined in
// production — this must stay above everything with a side effect.
if (defined('TE_RECIPIENT_SEARCH_LIB_ONLY')) {
    require_once __DIR__ . '/../lib/coach_scope.php';
    require_once __DIR__ . '/../lib/guardian_identity.php';
    require_once __DIR__ . '/../lib/program_scope.php';
    return;
}

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/coach_scope.php';
require_once __DIR__ . '/../lib/guardian_identity.php';
require_once __DIR__ . '/../lib/program_scope.php';

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
// getCoachTeamIds now lives in lib/coach_scope.php, required at the top of this file.

// ============================================
// Helper: Check if user is club admin for a club
// ============================================
function isClubAdmin($auth, $clubProfileId) {
    if ($auth->isSuperAdmin()) {
        return true;
    }
    return $auth->hasRole('club_admin', $clubProfileId, 'club');
}

/**
 * Teams this user reaches as a GUARDIAN, within one club.
 *
 * The counterpart to getCoachTeamIds(). Extracted 2026-08-17 so the chat search
 * and the team resolver cannot disagree about what a parent can reach — they
 * previously each had their own copy, and only one of them was reached by a
 * coach-parent.
 */
function te_chat_parent_team_ids(PDO $connection, $userId, $clubProfileId): array {
    $stmt = $connection->prepare("
        SELECT DISTINCT tm.team_id
        FROM users u
        JOIN guardians g ON " . te_guardian_link_sql('u', 'g') . "
        JOIN athlete_guardians ag ON ag.guardian_id = g.id
        JOIN team_members tm ON tm.athlete_id = ag.athlete_id AND tm.status = 'active'
        JOIN teams t ON t.id = tm.team_id AND t.club_id = ? AND t.deleted_at IS NULL
        WHERE u.id = ?
    ");
    $stmt->execute([$clubProfileId, $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ============================================
// Helper: Build team filter SQL + params
// ============================================
/**
 * Athletes this user reaches as PROGRAM staff, within one club.
 *
 * The counterpart to getCoachTeamIds() for camps, clinics and drop-ins, which
 * have registrants and no roster — so team scope correctly answers "no teams"
 * and every scope built on it then answers "nobody".
 *
 * Both halves come from lib/program_scope.php and nowhere else. Club scoping is
 * NOT applied here: every caller's own query already carries `a.club_id = ?`, so
 * an athlete id from another club cannot survive it, and a second club predicate
 * here would be a second thing to keep in step.
 */
function te_search_program_athlete_ids($connection, $userId): array {
    $programIds = te_program_ids_for_user($connection, (int)$userId);
    if (empty($programIds)) {
        return [];
    }

    $athleteIds = [];
    foreach ($programIds as $programId) {
        foreach (te_program_registrant_athlete_ids($connection, $programId) as $athleteId) {
            $athleteIds[$athleteId] = true;
        }
    }
    return array_map('intval', array_keys($athleteIds));
}

/**
 * Build the non-admin scope fragment for one query.
 *
 * $athleteColumn is what makes this ADDITIVE for program staff. When a caller
 * passes it, a coach's reach becomes "the teams I coach OR the athletes
 * registered to the programs I staff" — an OR, so nothing a coach could reach
 * before is removed. Callers with no athlete column in scope (the group list,
 * which selects FROM teams) pass nothing and behave exactly as before.
 *
 * `AND 1=0` when neither holds: array_fill(0, 0, '?') would produce `IN ()`,
 * which is a syntax error rather than an empty result, and an unfiltered query
 * would be a club-wide leak.
 */
function getTeamFilterClause($connection, $auth, $userId, $clubProfileId, $teamColumn = 'tm.team_id', $athleteColumn = null) {
    if (isClubAdmin($auth, $clubProfileId)) {
        return ['sql' => '', 'params' => []];
    }

    $coachTeamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
    $programAthleteIds = $athleteColumn === null
        ? []
        : te_search_program_athlete_ids($connection, $userId);

    if (empty($coachTeamIds) && empty($programAthleteIds)) {
        return ['sql' => "AND 1=0", 'params' => []];
    }

    $branches = [];
    $params = [];

    if (!empty($coachTeamIds)) {
        $branches[] = "{$teamColumn} IN (" . implode(',', array_fill(0, count($coachTeamIds), '?')) . ")";
        $params = array_merge($params, $coachTeamIds);
    }

    if (!empty($programAthleteIds)) {
        $branches[] = "{$athleteColumn} IN (" . implode(',', array_fill(0, count($programAthleteIds), '?')) . ")";
        $params = array_merge($params, $programAthleteIds);
    }

    return [
        'sql' => 'AND (' . implode(' OR ', $branches) . ')',
        'params' => $params
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
    // 'a.id' widens a coach's reach to the athletes registered to programs they
    // staff. Both queries below alias athletes as `a`, so one call serves both.
    $teamFilter = getTeamFilterClause($connection, $auth, $userId, $clubProfileId, 'tm.team_id', 'a.id');
    $results = [];
    $seenKeys = []; // For deduplication by email/phone

    // ----- 1. Search Athletes -----
    $athleteParams = [$clubProfileId, $searchPattern, $searchPattern, $searchPattern, $searchPattern];
    $athleteTeamFilterSql = $teamFilter['sql'];
    $athleteParams = array_merge($athleteParams, $teamFilter['params']);

    // Club membership comes from athletes.club_id, and the roster is a LEFT JOIN
    // used only for the team label. An INNER JOIN here meant a registered athlete
    // who had not been placed on a team yet was invisible to the To field — along
    // with their whole crew — which is exactly the population you most need to
    // reach at season start. The club-wide groups (resolveSpecialGroup) dropped
    // the roster requirement for that reason; this query never got the same fix,
    // so "All" could reach a family that search could not find.
    //
    // Coach scoping still holds: getTeamFilterClause adds `AND tm.team_id IN (...)`,
    // and a NULL team_id from the LEFT JOIN does not satisfy IN, so an unrostered
    // athlete stays invisible to a coach and visible only to club admins.
    $athleteSql = "
        SELECT DISTINCT a.id, a.first_name, a.last_name, a.email, a.phone, 'athlete' as type,
               t.id as team_id, t.name as team_name
        FROM athletes a
        LEFT JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
        LEFT JOIN teams t ON tm.team_id = t.id AND t.deleted_at IS NULL
        WHERE a.club_id = ?
          AND (a.first_name ILIKE ? OR a.last_name ILIKE ? OR a.email ILIKE ? OR a.phone LIKE ?)
          AND a.active_status = true
          AND a.deleted_at IS NULL
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

    // Same change as the athlete query above, and the same reason: a guardian was
    // reachable only through a rostered athlete, so a family that had just
    // registered could not be found by name even though "All" would text them.
    $guardianSql = "
        SELECT DISTINCT g.id, g.first_name, g.last_name, g.email, g.mobile_phone as phone, 'guardian' as type,
               a.id as athlete_id, a.first_name as athlete_first_name, a.last_name as athlete_last_name,
               t.id as team_id, t.name as team_name
        FROM guardians g
        JOIN athlete_guardians ag ON g.id = ag.guardian_id
        JOIN athletes a ON ag.athlete_id = a.id
        LEFT JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
        LEFT JOIN teams t ON tm.team_id = t.id AND t.deleted_at IS NULL
        WHERE a.club_id = ?
          AND (g.first_name ILIKE ? OR g.last_name ILIKE ? OR g.email ILIKE ? OR g.mobile_phone LIKE ?)
          AND a.deleted_at IS NULL
          AND a.active_status = true
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
            'athlete_name' => trim(($guardian['athlete_first_name'] ?? '') . ' ' . ($guardian['athlete_last_name'] ?? '')),
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
    // The picker used to count email addresses whatever you were composing, so
    // an SMS "All Crew (132)" included everyone who had an email but no mobile.
    $channel = $_GET['channel'] ?? 'email';
    if (!in_array($channel, ['email', 'sms'], true)) {
        $channel = 'email';
    }

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

    // Guardians carry mobile_phone; athletes carry phone.
    $aContact = ($channel === 'sms') ? 'a.phone' : 'a.email';
    $gContact = ($channel === 'sms') ? 'g.mobile_phone' : 'g.email';

    $sql = "
        SELECT t.id, t.name, t.age_group,
               COUNT(DISTINCT tm.athlete_id)
                 FILTER (WHERE {$aContact} IS NOT NULL AND trim({$aContact}) <> '') as athlete_count,
               COUNT(DISTINCT g.id)
                 FILTER (WHERE {$gContact} IS NOT NULL AND trim({$gContact}) <> '') as guardian_count,
               COUNT(DISTINCT g.id)
                 FILTER (WHERE {$gContact} IS NULL OR trim({$gContact}) = '') as guardian_missing,
               COUNT(DISTINCT tm.athlete_id)
                 FILTER (WHERE {$aContact} IS NULL OR trim({$aContact}) = '') as athlete_missing
        FROM teams t
        LEFT JOIN team_members tm ON t.id = tm.team_id AND tm.status = 'active'
        LEFT JOIN athletes a ON tm.athlete_id = a.id
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
        $team['missing_contact_count'] =
            (int)$team['athlete_missing'] + (int)$team['guardian_missing'];
        $team['missing_contact_label'] = ($channel === 'sms') ? 'phone number' : 'email address';
        $team['people_count'] =
            $team['athlete_count'] + $team['guardian_count'] + $team['missing_contact_count'];
        unset($team['athlete_missing'], $team['guardian_missing']);
        $team['group_type'] = 'team';
    }
    unset($team);

    // Club-wide special groups. Admin-only on purpose: a coach must never be
    // able to select a group that reaches beyond the teams they coach, which is
    // the whole point of getTeamFilterClause above.
    $groups = $teams;
    if (isClubAdmin($auth, $clubProfileId)) {
        $groups = array_merge(getSpecialGroups($connection, $clubProfileId, $channel), $groups);
    }

    // Programs, offered the same way teams are: a club admin sees every program
    // in the club, a coach sees only the ones they staff. Additive — a coach who
    // staffs nothing gets exactly the list they got before.
    $groups = array_merge($groups, getProgramGroups($connection, $auth, $userId, $clubProfileId, $channel));

    echo json_encode([
        'success' => true,
        'groups' => $groups
    ]);
}

/**
 * Programs offered as selectable groups — "All families in Summer Camp".
 *
 * Membership is the REGISTRATION list, not a roster: that is the whole point of
 * the program axis. A camp has families and no teams, so a group built on
 * team_members would be empty for exactly the programs that need it.
 *
 * Scoping mirrors the team groups above rather than inventing a second rule:
 *   club admin → every program in the club
 *   anyone else → only programs in te_program_ids_for_user(), intersected with
 *                 this club, so a coach can never select a group reaching beyond
 *                 what they staff.
 *
 * Programs with no reachable registrant are omitted. An empty group in a picker
 * is a promise the send cannot keep, and the counts here are channel-aware for
 * the same reason getSpecialGroups()' are.
 */
function getProgramGroups($connection, $auth, $userId, $clubProfileId, $channel = 'email') {
    $isAdmin = isClubAdmin($auth, $clubProfileId);

    $programFilter = '';
    $params = [$clubProfileId, $clubProfileId];
    if (!$isAdmin) {
        $programIds = te_program_ids_for_user($connection, (int)$userId);
        if (empty($programIds)) {
            return [];
        }
        // Guarded above: array_fill(0, 0, '?') is `IN ()`, a syntax error.
        $programFilter = ' AND p.id IN (' . implode(',', array_fill(0, count($programIds), '?')) . ')';
        $params = array_merge($params, $programIds);
    }

    $aContact = ($channel === 'sms') ? 'a.phone' : 'a.email';
    $gContact = ($channel === 'sms') ? 'g.mobile_phone' : 'g.email';

    $sql = "
        SELECT p.id, p.name, p.type,
               COUNT(DISTINCT a.id)
                 FILTER (WHERE {$aContact} IS NOT NULL AND trim({$aContact}) <> '') AS athlete_count,
               COUNT(DISTINCT g.id)
                 FILTER (WHERE {$gContact} IS NOT NULL AND trim({$gContact}) <> '') AS guardian_count,
               COUNT(DISTINCT a.id)
                 FILTER (WHERE {$aContact} IS NULL OR trim({$aContact}) = '') AS athlete_missing,
               COUNT(DISTINCT g.id)
                 FILTER (WHERE {$gContact} IS NULL OR trim({$gContact}) = '') AS guardian_missing
        FROM programs p
        JOIN registrations r ON r.program_id = p.id
             AND r.athlete_id IS NOT NULL
             AND (r.status IS NULL OR LOWER(r.status) <> 'rejected')
        JOIN athletes a ON a.id = r.athlete_id
             AND a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
        LEFT JOIN athlete_guardians ag ON ag.athlete_id = a.id
        LEFT JOIN guardians g ON g.id = ag.guardian_id
        WHERE p.club_id = ?{$programFilter}
        GROUP BY p.id, p.name, p.type
        ORDER BY p.name
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Distinct ADDRESSES, counted separately because reachable + missing !=
    // people: a household sharing one mobile is two people, nobody missing, one
    // message. Deriving this by subtraction would report that household as
    // someone lacking a phone number — the same mistake getSpecialGroups()
    // documents.
    $reachableSql = "
        SELECT program_id, COUNT(DISTINCT addr) AS reachable FROM (
            SELECT r.program_id AS program_id, LOWER(TRIM({$aContact})) AS addr
              FROM registrations r
              JOIN athletes a ON a.id = r.athlete_id
                   AND a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
              JOIN programs p ON p.id = r.program_id
             WHERE p.club_id = ?{$programFilter}
               AND (r.status IS NULL OR LOWER(r.status) <> 'rejected')
               AND {$aContact} IS NOT NULL AND TRIM({$aContact}) <> ''
            UNION ALL
            SELECT r.program_id AS program_id, LOWER(TRIM({$gContact})) AS addr
              FROM registrations r
              JOIN athletes a ON a.id = r.athlete_id
                   AND a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
              JOIN athlete_guardians ag ON ag.athlete_id = a.id
              JOIN guardians g ON g.id = ag.guardian_id
              JOIN programs p ON p.id = r.program_id
             WHERE p.club_id = ?{$programFilter}
               AND (r.status IS NULL OR LOWER(r.status) <> 'rejected')
               AND {$gContact} IS NOT NULL AND TRIM({$gContact}) <> ''
        ) x
        GROUP BY program_id
    ";
    $reachStmt = $connection->prepare($reachableSql);
    $reachStmt->execute(array_merge($params, $params));
    $reachable = [];
    foreach ($reachStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reachable[(int)$r['program_id']] = (int)$r['reachable'];
    }

    $out = [];
    foreach ($rows as $row) {
        $athleteCount = (int)$row['athlete_count'];
        $guardianCount = (int)$row['guardian_count'];
        $missing = (int)$row['athlete_missing'] + (int)$row['guardian_missing'];
        if ($athleteCount + $guardianCount + $missing === 0) {
            continue;
        }
        $out[] = [
            'id' => (int)$row['id'],
            'name' => 'All families in ' . $row['name'],
            'program_name' => $row['name'],
            'age_group' => $row['type'],
            'athlete_count' => $athleteCount,
            'guardian_count' => $guardianCount,
            'missing_contact_count' => $missing,
            'missing_contact_label' => ($channel === 'sms') ? 'phone number' : 'email address',
            'recipient_count' => $reachable[(int)$row['id']] ?? 0,
            'people_count' => $athleteCount + $guardianCount + $missing,
            'group_type' => 'program',
        ];
    }
    return $out;
}

/**
 * The two club-wide groups offered in the compose picker.
 *
 * Membership is deliberately NOT "every club team's roster" — it is club-scoped
 * directly, so athletes and crew who exist in the club but are not yet rostered
 * onto a team are still reached. That is the case a club-wide announcement
 * (season kickoff, registration open) most needs to cover.
 *
 * Counts are CHANNEL-AWARE. They used to be the deduplicated EMAIL count no
 * matter what you were composing, which made the SMS picker actively misleading:
 * "All Crew (132)" counted every guardian with an email address, including the
 * ones with no mobile number who could never receive a text. The resolve step
 * dropped them correctly, so the picker promised 132 and the send reached fewer,
 * with nothing explaining the gap.
 *
 * Each group now reports two numbers for the channel in play:
 *   recipient_count      — how many can actually be reached
 *   missing_contact_count — how many exist but have no address for THIS channel
 *
 * The picker shows both, so the number is self-explaining rather than a promise
 * it cannot keep.
 */
function getSpecialGroups($connection, $clubProfileId, $channel = 'email') {
    // Guardians use mobile_phone for SMS; athletes and staff use phone.
    $gContact = ($channel === 'sms') ? 'g.mobile_phone' : 'g.email';
    $aContact = ($channel === 'sms') ? 'a.phone' : 'a.email';
    $uContact = ($channel === 'sms') ? 'u.phone' : 'u.email';

    // Three numbers, each counted directly, because they are three different
    // things and subtracting one from another gives a wrong answer:
    //
    //   people    — distinct guardians in the group
    //   missing   — distinct guardians with NO address on this channel
    //   reachable — distinct ADDRESSES, i.e. how many messages actually go out
    //
    // reachable + missing != people, and that is correct: a household sharing one
    // mobile is two people, no one missing, one message. Deriving `missing` by
    // subtraction would report that household as someone lacking a phone number.
    $crewSql = "
        SELECT COUNT(DISTINCT g.id) AS people,
               COUNT(DISTINCT g.id)
                 FILTER (WHERE {$gContact} IS NULL OR trim({$gContact}) = '') AS missing,
               COUNT(DISTINCT lower(trim({$gContact})))
                 FILTER (WHERE {$gContact} IS NOT NULL AND trim({$gContact}) <> '') AS reachable
        FROM guardians g
        JOIN athlete_guardians ag ON g.id = ag.guardian_id
        JOIN athletes a ON ag.athlete_id = a.id
        WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
    ";
    $stmt = $connection->prepare($crewSql);
    $stmt->execute([$clubProfileId]);
    $crewRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $crewCount   = (int)$crewRow['reachable'];
    $crewPeople  = (int)$crewRow['people'];
    $crewMissing = (int)$crewRow['missing'];

    $neverSent = (int)portalStatusCount($connection, $clubProfileId, 'invite_never_sent');
    $notSetUp  = (int)portalStatusCount($connection, $clubProfileId, 'invite_sent_not_setup');

    $allSql = "
        SELECT COUNT(*) FROM (
            SELECT DISTINCT lower(trim({$aContact})) AS e
            FROM athletes a
            WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
              AND {$aContact} IS NOT NULL AND trim({$aContact}) <> ''
            UNION
            SELECT DISTINCT lower(trim({$gContact}))
            FROM guardians g
            JOIN athlete_guardians ag ON g.id = ag.guardian_id
            JOIN athletes a2 ON ag.athlete_id = a2.id
            WHERE a2.club_id = ? AND a2.deleted_at IS NULL AND a2.active_status = true
              AND {$gContact} IS NOT NULL AND trim({$gContact}) <> ''
            UNION
            SELECT DISTINCT lower(trim({$uContact}))
            FROM users u
            JOIN user_club_access uca ON u.id = uca.user_id
            WHERE uca.club_profile_id = ? AND uca.active = true
              AND uca.role IN ('coach', 'club_admin')
              AND {$uContact} IS NOT NULL AND trim({$uContact}) <> ''
        ) x
    ";
    $stmt = $connection->prepare($allSql);
    $stmt->execute([$clubProfileId, $clubProfileId, $clubProfileId]);
    $allCount = (int)$stmt->fetchColumn();

    // Same three-way count for "All", across all three populations.
    $allPeopleSql = "
        SELECT
          (SELECT COUNT(*) FROM athletes a
             WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true)
        + (SELECT COUNT(DISTINCT g.id) FROM guardians g
             JOIN athlete_guardians ag ON g.id = ag.guardian_id
             JOIN athletes a2 ON ag.athlete_id = a2.id
             WHERE a2.club_id = ? AND a2.deleted_at IS NULL AND a2.active_status = true)
        + (SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_club_access uca ON u.id = uca.user_id
             WHERE uca.club_profile_id = ? AND uca.active = true
               AND uca.role IN ('coach', 'club_admin')) AS people,
          (SELECT COUNT(*) FROM athletes a
             WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
               AND ({$aContact} IS NULL OR trim({$aContact}) = ''))
        + (SELECT COUNT(DISTINCT g.id) FROM guardians g
             JOIN athlete_guardians ag ON g.id = ag.guardian_id
             JOIN athletes a2 ON ag.athlete_id = a2.id
             WHERE a2.club_id = ? AND a2.deleted_at IS NULL AND a2.active_status = true
               AND ({$gContact} IS NULL OR trim({$gContact}) = ''))
        + (SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_club_access uca ON u.id = uca.user_id
             WHERE uca.club_profile_id = ? AND uca.active = true
               AND uca.role IN ('coach', 'club_admin')
               AND ({$uContact} IS NULL OR trim({$uContact}) = '')) AS missing
    ";
    $stmt = $connection->prepare($allPeopleSql);
    $stmt->execute(array_fill(0, 6, $clubProfileId));
    $allRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $allPeople  = (int)$allRow['people'];
    $allMissing = (int)$allRow['missing'];

    // Coaches and club admins, by user_club_access — the authoritative source for
    // club roles. Revoked access (active = false) is excluded, so someone who has
    // left the club stops appearing the moment their access is revoked.
    $coachSql = "
        SELECT COUNT(DISTINCT u.id) AS people,
               COUNT(DISTINCT u.id)
                 FILTER (WHERE {$uContact} IS NULL OR trim({$uContact}) = '') AS missing,
               COUNT(DISTINCT lower(trim({$uContact})))
                 FILTER (WHERE {$uContact} IS NOT NULL AND trim({$uContact}) <> '') AS reachable
        FROM users u
        JOIN user_club_access uca ON u.id = uca.user_id
        WHERE uca.club_profile_id = ? AND uca.active = true
          AND uca.role IN ('coach', 'club_admin')
    ";
    $stmt = $connection->prepare($coachSql);
    $stmt->execute([$clubProfileId]);
    $coachRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $contactLabel = ($channel === 'sms') ? 'phone number' : 'email address';

    return [
        [
            'id' => 'all',
            'name' => 'All',
            'group_type' => 'special',
            'recipient_count' => $allCount,
            'people_count' => $allPeople,
            'missing_contact_count' => $allMissing,
            'missing_contact_label' => $contactLabel,
            'description' => 'Everyone in the club — athletes, crew, and coaches'
        ],
        [
            'id' => 'all_crew',
            'name' => 'All Crew',
            'group_type' => 'special',
            'recipient_count' => $crewCount,
            'people_count' => $crewPeople,
            'missing_contact_count' => $crewMissing,
            'missing_contact_label' => $contactLabel,
            'description' => 'Every guardian in the club'
        ],
        [
            'id' => 'all_coaches',
            'name' => 'All Coaches',
            'group_type' => 'special',
            'recipient_count' => (int)$coachRow['reachable'],
            'people_count' => (int)$coachRow['people'],
            'missing_contact_count' => (int)$coachRow['missing'],
            'missing_contact_label' => $contactLabel,
            'description' => 'Coaches and club admins — no families'
        ],
        // The two portal groups are defined by email state — an invite has nowhere
        // to go without one — so their counts stay email-based on both channels.
        [
            'id' => 'invite_never_sent',
            'name' => 'Invite Never Sent',
            'group_type' => 'special',
            'recipient_count' => $neverSent,
            'description' => 'Crew with no portal account who have never been sent an invite'
        ],
        [
            'id' => 'invite_sent_not_setup',
            'name' => 'Invite Sent, Not Set Up',
            'group_type' => 'special',
            'recipient_count' => $notSetUp,
            'description' => 'Crew who were sent a portal invite but never set up their account'
        ]
    ];
}

/**
 * SQL predicate for a guardian's parent-portal state, matching the status
 * definitions used by auth-gateway's handleParentPortalStatus:
 *
 *   set up      = a users row for the guardian's email has a password_hash
 *   invited     = a magic_link_tokens row keyed "<email>:parent_invite" exists
 *
 * Both groups below exclude anyone already set up — neither is actionable
 * otherwise. Unlike the athlete-scoped status endpoint, "invited" here does NOT
 * require the token to be unexpired: an invite that lapsed unused is still an
 * invite that was sent and not acted on, and those people are exactly who a
 * follow-up is for. (Their original link is dead, so the message needs to tell
 * them a fresh one is coming.)
 *
 * The two predicates partition the club's crew together with the already-set-up
 * group, so no one is double-counted and no one is missed.
 */
function portalStatusPredicate($status) {
    $setUp = "EXISTS (SELECT 1 FROM users u
                       WHERE lower(u.email) = lower(trim(g.email))
                         AND u.password_hash IS NOT NULL AND u.password_hash <> '')";
    $invited = "EXISTS (SELECT 1 FROM magic_link_tokens t
                         WHERE t.email = lower(trim(g.email)) || ':parent_invite')";

    if ($status === 'invite_never_sent') {
        return "NOT {$setUp} AND NOT {$invited}";
    }
    if ($status === 'invite_sent_not_setup') {
        return "NOT {$setUp} AND {$invited}";
    }
    return '1=1';
}

function portalStatusCount($connection, $clubProfileId, $status) {
    $predicate = portalStatusPredicate($status);
    $sql = "
        SELECT COUNT(DISTINCT lower(trim(g.email)))
        FROM guardians g
        JOIN athlete_guardians ag ON g.id = ag.guardian_id
        JOIN athletes a ON ag.athlete_id = a.id
        WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
          AND g.email IS NOT NULL AND trim(g.email) <> ''
          AND {$predicate}
    ";
    $stmt = $connection->prepare($sql);
    $stmt->execute([$clubProfileId]);
    return (int)$stmt->fetchColumn();
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

    if (!in_array($channel, ['email', 'sms'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Channel must be email or sms']);
        exit();
    }

    // Club-wide special groups ("All", "All Crew") come in as special_group
    // instead of team_ids. Handled before the team_ids requirement below.
    $specialGroup = $data['special_group'] ?? null;
    if ($specialGroup !== null && $specialGroup !== '') {
        if (!isClubAdmin($auth, $clubProfileId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Club-wide groups are available to club admins only']);
            exit();
        }
        $validSpecialGroups = ['all', 'all_crew', 'all_coaches', 'invite_never_sent', 'invite_sent_not_setup'];
        if (!in_array($specialGroup, $validSpecialGroups, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Unknown special_group. Valid values: ' . implode(', ', $validSpecialGroups)
            ]);
            exit();
        }

        $excludeLookup = [];
        foreach ($excludeIds as $exc) {
            $excludeLookup[($exc['type'] ?? '') . ':' . ($exc['id'] ?? 0)] = true;
        }

        resolveSpecialGroup($connection, $clubProfileId, $specialGroup, $channel, $excludeLookup);
        return;
    }

    // Program groups come in as program_ids. Handled before the team_ids
    // requirement below, the same way special_group is — a program group carries
    // no teams at all, which is the entire reason it exists.
    $programIds = $data['program_ids'] ?? [];
    if (!empty($programIds) && is_array($programIds)) {
        $programIds = array_values(array_unique(array_map('intval', $programIds)));
        $programIds = array_values(array_filter($programIds, fn($id) => $id > 0));
        if (empty($programIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'program_ids must contain program ids']);
            exit();
        }

        // Every id is re-checked against THIS club. The ids come from the
        // browser, so a resolve that trusted them would let one club's admin
        // read another club's registration list.
        $ph = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $connection->prepare("SELECT id FROM programs WHERE id IN ({$ph}) AND club_id = ?");
        $stmt->execute(array_merge($programIds, [$clubProfileId]));
        $validProgramIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
        if (count($validProgramIds) !== count($programIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'One or more program_ids do not belong to this club']);
            exit();
        }

        // A non-admin may resolve only programs they actually staff — the same
        // check the team branch makes with getCoachTeamIds(), against the one
        // source of program standing.
        if (!isClubAdmin($auth, $clubProfileId)) {
            $staffed = te_program_ids_for_user($connection, (int)$userId);
            foreach ($programIds as $pid) {
                if (!in_array($pid, $staffed, true)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Access denied to program ID ' . $pid]);
                    exit();
                }
            }
        }

        $excludeLookup = [];
        foreach ($excludeIds as $exc) {
            $excludeLookup[($exc['type'] ?? '') . ':' . ($exc['id'] ?? 0)] = true;
        }

        resolveProgramGroup($connection, $clubProfileId, $programIds, $recipientTypes, $channel, $excludeLookup);
        return;
    }

    if (empty($teamIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'team_ids is required and must not be empty']);
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
              AND a.active_status = true
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
            JOIN athlete_guardians ag ON g.id = ag.guardian_id
            JOIN athletes a ON ag.athlete_id = a.id
            JOIN team_members tm ON a.id = tm.athlete_id AND tm.status = 'active'
            JOIN teams t ON tm.team_id = t.id
            WHERE tm.team_id IN ({$teamPlaceholders}) AND t.club_id = ?
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
                'athlete_name' => trim(($guardian['athlete_first_name'] ?? '') . ' ' . ($guardian['athlete_last_name'] ?? '')),
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

/**
 * Resolve one or more PROGRAM groups to individual recipients.
 *
 * Membership is the registration list — `registrations` → `athletes` — with no
 * team join anywhere, because a camp has no roster. Same response shape as the
 * team and special-group paths: suppressed contacts are RETURNED and flagged,
 * never silently dropped, so compose can warn instead of quietly reaching fewer
 * people than the picker promised.
 *
 * The caller has already verified the programs belong to $clubProfileId and that
 * the requester staffs them (or is a club admin).
 */
function resolveProgramGroup($connection, $clubProfileId, array $programIds, $recipientTypes, $channel, array $excludeLookup) {
    $recipients = [];
    $seenKeys = [];
    $suppressedCount = 0;
    $missingContactCount = 0;

    $ph = implode(',', array_fill(0, count($programIds), '?'));

    // ----- Athletes registered to the programs -----
    if (in_array('athletes', $recipientTypes)) {
        $sql = "
            SELECT DISTINCT a.id, a.first_name, a.last_name, a.email, a.phone, 'athlete' as type,
                   p.id as program_id, p.name as program_name
            FROM athletes a
            JOIN registrations r ON r.athlete_id = a.id
                 AND r.program_id IN ({$ph})
                 AND (r.status IS NULL OR LOWER(r.status) <> 'rejected')
            JOIN programs p ON p.id = r.program_id
            WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute(array_merge($programIds, [$clubProfileId]));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $athlete) {
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
                'team_id' => null,
                'team_name' => null,
                'program_id' => (int)$athlete['program_id'],
                'program_name' => $athlete['program_name'],
                'suppressed' => $suppression['suppressed'],
                'suppression_reason' => $suppression['suppression_reason'],
                'missing_contact' => false
            ];
        }
    }

    // ----- Guardians of those athletes -----
    if (in_array('guardians', $recipientTypes)) {
        $sql = "
            SELECT DISTINCT g.id, g.first_name, g.last_name, g.email, g.mobile_phone as phone, 'guardian' as type,
                   a.id as athlete_id, a.first_name as athlete_first_name, a.last_name as athlete_last_name,
                   p.id as program_id, p.name as program_name
            FROM guardians g
            JOIN athlete_guardians ag ON g.id = ag.guardian_id
            JOIN athletes a ON ag.athlete_id = a.id
            JOIN registrations r ON r.athlete_id = a.id
                 AND r.program_id IN ({$ph})
                 AND (r.status IS NULL OR LOWER(r.status) <> 'rejected')
            JOIN programs p ON p.id = r.program_id
            WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute(array_merge($programIds, [$clubProfileId]));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $guardian) {
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
            if ($channel === 'sms' && !$suppression['suppressed']) {
                if (checkGuardianSmsOptOut($connection, $guardian['id'])) {
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
                'athlete_name' => trim(($guardian['athlete_first_name'] ?? '') . ' ' . ($guardian['athlete_last_name'] ?? '')),
                'team_id' => null,
                'team_name' => null,
                'program_id' => (int)$guardian['program_id'],
                'program_name' => $guardian['program_name'],
                'suppressed' => $suppression['suppressed'],
                'suppression_reason' => $suppression['suppression_reason'],
                'missing_contact' => false
            ];
        }
    }

    // ----- Staff assigned to the programs -----
    // Only when the caller asked for coaches, and only from program_staff — a
    // program has no primary_coach_id to fall back on.
    if (in_array('coaches', $recipientTypes) && te_program_staff_table_present($connection)) {
        $sql = "
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone, 'coach' as type,
                   p.id as program_id, p.name as program_name
            FROM program_staff ps
            JOIN users u ON u.id = ps.user_id
            JOIN programs p ON p.id = ps.program_id
            WHERE ps.program_id IN ({$ph})
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute($programIds);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $coach) {
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
                'team_id' => null,
                'team_name' => null,
                'program_id' => (int)$coach['program_id'],
                'program_name' => $coach['program_name'],
                'suppressed' => $suppression['suppressed'],
                'suppression_reason' => $suppression['suppression_reason'],
                'missing_contact' => false
            ];
        }
    }

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

/**
 * Resolve a club-wide special group to individual recipients.
 *
 * Scoped by club membership rather than by team roster, so unrostered athletes
 * and their crew are included — see getSpecialGroups(). Caller has already
 * verified the requester is a club admin for $clubProfileId.
 *
 * Emits the same response shape as the team path: suppressed contacts are
 * RETURNED and flagged, never silently dropped, so the compose UI can warn.
 */
function resolveSpecialGroup($connection, $clubProfileId, $specialGroup, $channel, array $excludeLookup) {
    $recipients = [];
    $seenKeys = [];
    $suppressedCount = 0;
    $missingContactCount = 0;

    // Suppressions are loaded once for the whole club rather than per recipient.
    // The team path calls checkSuppression() inside its loop, which is fine for
    // ~20 people; a club-wide group is 200+ and that becomes 200+ round trips to
    // Neon — slow enough to time the request out.
    $suppressionMap = [];
    $stmt = $connection->prepare("
        SELECT email, phone, channel, reason FROM email_suppressions WHERE club_profile_id = ?
    ");
    $stmt->execute([$clubProfileId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $key = $s['channel'] === 'email'
            ? 'email:' . strtolower(trim((string)$s['email']))
            : 'sms:' . trim((string)$s['phone']);
        if (!isset($suppressionMap[$key])) {
            $suppressionMap[$key] = $s['reason'];
        }
    }

    // Shared per-row handling: exclusions, missing contact, cross-type dedupe by
    // the actual send address, and suppression flagging.
    $add = function (array $row, string $type) use (
        $channel, $excludeLookup, $suppressionMap,
        &$recipients, &$seenKeys, &$suppressedCount, &$missingContactCount
    ) {
        if (isset($excludeLookup["{$type}:{$row['id']}"])) {
            return;
        }

        $contactField = ($channel === 'email') ? $row['email'] : $row['phone'];
        if (empty(trim((string)$contactField))) {
            $missingContactCount++;
            return;
        }

        $dedupeKey = $channel . ':' . strtolower(trim($contactField));
        if (isset($seenKeys[$dedupeKey])) {
            return;
        }
        $seenKeys[$dedupeKey] = true;

        $lookupKey = $channel === 'email'
            ? 'email:' . strtolower(trim((string)$row['email']))
            : 'sms:' . trim((string)$row['phone']);
        $suppression = [
            'suppressed' => isset($suppressionMap[$lookupKey]),
            'suppression_reason' => $suppressionMap[$lookupKey] ?? null,
        ];

        // sms_opt_out comes back on the guardian row, so no extra query per person.
        if ($type === 'guardian' && $channel === 'sms' && !$suppression['suppressed']) {
            $optOut = $row['sms_opt_out'] ?? false;
            if ($optOut === true || $optOut === 1 || $optOut === '1' || $optOut === 't') {
                $suppression['suppressed'] = true;
                $suppression['suppression_reason'] = 'twilio_stop';
            }
        }

        if ($suppression['suppressed']) {
            $suppressedCount++;
        }

        $recipients[] = [
            'id' => (int)$row['id'],
            'type' => $type,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'team_id' => isset($row['team_id']) ? (int)$row['team_id'] : null,
            'team_name' => $row['team_name'] ?? null,
            'suppressed' => $suppression['suppressed'],
            'suppression_reason' => $suppression['suppression_reason'],
            'missing_contact' => false
        ];
    };

    // ----- Crew (guardians) -----
    // Every group except All Coaches is crew-based. The two portal groups
    // additionally require an email: a parent-portal invite has nowhere to go
    // without one, so an emailless guardian is not a "never invited" case to
    // chase, just an incomplete record.
    if ($specialGroup !== 'all_coaches') {
        $crewFilter = '';
        if (in_array($specialGroup, ['invite_never_sent', 'invite_sent_not_setup'], true)) {
            $crewFilter = " AND g.email IS NOT NULL AND trim(g.email) <> ''
                            AND " . portalStatusPredicate($specialGroup);
        }

        $sql = "
            SELECT DISTINCT g.id, g.first_name, g.last_name, g.email, g.mobile_phone AS phone,
                   g.sms_opt_out
            FROM guardians g
            JOIN athlete_guardians ag ON g.id = ag.guardian_id
            JOIN athletes a ON ag.athlete_id = a.id
            WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
            {$crewFilter}
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute([$clubProfileId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add($row, 'guardian');
        }
    }

    if ($specialGroup === 'all') {
        // ----- Athletes -----
        $sql = "
            SELECT a.id, a.first_name, a.last_name, a.email, a.phone,
                   (SELECT t.name
                      FROM team_members tm
                      JOIN teams t ON tm.team_id = t.id
                     WHERE tm.athlete_id = a.id AND tm.status = 'active' AND t.deleted_at IS NULL
                     ORDER BY t.name LIMIT 1) AS team_name
            FROM athletes a
            WHERE a.club_id = ? AND a.deleted_at IS NULL AND a.active_status = true
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute([$clubProfileId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add($row, 'athlete');
        }
    }

    // ----- Coaches and club admins -----
    // Reached by "All" and by "All Coaches" on its own. user_club_access is
    // authoritative for club roles, NOT users.role, and revoked access
    // (active = false) must not receive club messages.
    if ($specialGroup === 'all' || $specialGroup === 'all_coaches') {
        $sql = "
            SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone
            FROM users u
            JOIN user_club_access uca ON u.id = uca.user_id
            WHERE uca.club_profile_id = ? AND uca.active = true
              AND uca.role IN ('coach', 'club_admin')
        ";
        $stmt = $connection->prepare($sql);
        $stmt->execute([$clubProfileId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add($row, 'coach');
        }
    }

    usort($recipients, function ($a, $b) {
        $cmp = strcasecmp((string)$a['last_name'], (string)$b['last_name']);
        return $cmp === 0 ? strcasecmp((string)$a['first_name'], (string)$b['first_name']) : $cmp;
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

    // ─── Standing is ADDITIVE ────────────────────────────────────────────────
    // Someone can be a coach AND a parent, and the two grant different reach.
    // This used to read `$isParent = !$isAdmin && !$isCoach`, so a coach with a
    // child on a team they do NOT coach was treated as coach-only: they could
    // find every coach and admin in the club but not one parent from their own
    // child's team, and could not pick that team as a group.
    //
    // The conversation list already merges both (chat-server getAccessibleTeamIds).
    // This is the same rule applied to who you can find.
    $coachTeamIds  = $isAdmin ? [] : getCoachTeamIds($connection, $userId, $clubProfileId);
    $parentTeamIds = $isAdmin ? [] : te_chat_parent_team_ids($connection, $userId, $clubProfileId);

    // A coach is a coach by ROLE, not by having been given a team yet — see the
    // 2026-08-15 fix. Team assignment decides which FAMILIES they reach.
    $isCoach  = !$isAdmin && ($auth->hasRole('coach', $clubProfileId, 'club') || !empty($coachTeamIds));
    $isParent = !$isAdmin && !empty($parentTeamIds);

    // Every team this person can see, from either hat.
    $visibleTeamIds = array_values(array_unique(array_merge($coachTeamIds, $parentTeamIds)));

    $like = '%' . $q . '%';
    $people = [];
    $teamGroups = [];

    // ─── Who this requester may find ─────────────────────────────────────────
    // One query, with the access branches OR-ed. Each branch is added only when
    // the standing behind it applies, so a pure parent never gains club-wide
    // reach and a team-less coach still finds their colleagues.
    $branches = [];
    $params = [];

    if (!empty($coachTeamIds)) {
        // Coach → the crew of the teams they coach.
        $ph = implode(',', array_fill(0, count($coachTeamIds), '?'));
        $branches[] = "EXISTS (
                SELECT 1 FROM guardians g2
                JOIN athlete_guardians ag2 ON ag2.guardian_id = g2.id
                JOIN team_members tm2 ON tm2.athlete_id = ag2.athlete_id AND tm2.team_id IN ($ph)
                WHERE " . te_guardian_link_sql('u', 'g2') . "
            )";
        $params = array_merge($params, $coachTeamIds);
    }

    if ($isCoach) {
        // Staff → every other coach and club admin. Colleagues, regardless of team.
        $branches[] = "EXISTS (
                SELECT 1 FROM teams t2 WHERE t2.club_id = ? AND t2.primary_coach_id = u.id
            )";
        $params[] = $clubProfileId;
        $branches[] = "EXISTS (
                SELECT 1 FROM team_members tm3
                JOIN teams t3 ON t3.id = tm3.team_id AND t3.club_id = ?
                WHERE tm3.user_id = u.id AND tm3.role IN ('assistant_coach','team_manager') AND tm3.status = 'active'
            )";
        $params[] = $clubProfileId;
        $branches[] = "EXISTS (
                SELECT 1 FROM user_club_access uca_admin
                WHERE uca_admin.user_id = u.id AND uca_admin.club_profile_id = ?
                  AND uca_admin.role IN ('club_admin', 'super_admin')
            )";
        $params[] = $clubProfileId;
    }

    if ($isParent) {
        // Parent → the coaches of their children's teams, and the other families
        // on them. This is the branch a coach-parent was missing.
        $ph = implode(',', array_fill(0, count($parentTeamIds), '?'));
        $branches[] = "EXISTS (
                SELECT 1 FROM teams t4
                LEFT JOIN team_members tm4 ON tm4.team_id = t4.id
                     AND tm4.role IN ('assistant_coach','team_manager') AND tm4.status = 'active'
                WHERE t4.id IN ($ph) AND u.id = COALESCE(tm4.user_id, t4.primary_coach_id)
            )";
        $params = array_merge($params, $parentTeamIds);

        $ph2 = implode(',', array_fill(0, count($parentTeamIds), '?'));
        $branches[] = "EXISTS (
                SELECT 1 FROM guardians g5
                JOIN athlete_guardians ag5 ON ag5.guardian_id = g5.id
                JOIN team_members tm5 ON tm5.athlete_id = ag5.athlete_id
                     AND tm5.team_id IN ($ph2) AND tm5.status = 'active'
                WHERE " . te_guardian_link_sql('u', 'g5') . "
            )";
        $params = array_merge($params, $parentTeamIds);
    }

    // A non-admin with no standing at all reaches nobody. `AND 1=0` rather than
    // an unfiltered query — the same precaution getTeamFilterClause takes.
    $accessFilter = $isAdmin ? '' : ' AND (' . ($branches ? implode(' OR ', $branches) : '1=0') . ')';

    // Teams shared with the requester, shown under the name. Optional in the UI,
    // and previously only present for parents.
    // CAST(...) rather than NULL::text — the Postgres cast syntax is unparseable
    // by the SQLite the tests run against, and this SELECT is exercised there.
    $teamNamesSelect = "CAST(NULL AS text) AS team_names";
    $teamNameParams = [];
    if (!empty($visibleTeamIds)) {
        $ph = implode(',', array_fill(0, count($visibleTeamIds), '?'));
        $teamNamesSelect = "(
            -- No DISTINCT: this selects FROM teams, so each team is already one
            -- row. DISTINCT would also make it unportable — SQLite rejects a
            -- DISTINCT aggregate with a separator, and the tests run on SQLite.
            SELECT STRING_AGG(t6.name, ', ')
            FROM teams t6
            WHERE t6.id IN ($ph)
              AND (
                t6.primary_coach_id = u.id
                OR EXISTS (SELECT 1 FROM team_members tm6 WHERE tm6.team_id = t6.id AND tm6.user_id = u.id
                           AND tm6.role IN ('assistant_coach','team_manager') AND tm6.status = 'active')
                OR EXISTS (SELECT 1 FROM guardians g6
                           JOIN athlete_guardians ag6 ON ag6.guardian_id = g6.id
                           JOIN team_members tm7 ON tm7.athlete_id = ag6.athlete_id AND tm7.team_id = t6.id
                           WHERE " . te_guardian_link_sql('u', 'g6') . ")
              )
        ) AS team_names";
        $teamNameParams = $visibleTeamIds;
    }

    $sql = "
        SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.email,
                        CASE
                            WHEN EXISTS (SELECT 1 FROM teams tt WHERE tt.primary_coach_id = u.id AND tt.club_id = ?) THEN 'coach'
                            WHEN EXISTS (SELECT 1 FROM team_members tmm JOIN teams ttt ON ttt.id = tmm.team_id WHERE tmm.user_id = u.id AND tmm.role IN ('assistant_coach','team_manager') AND ttt.club_id = ?) THEN 'coach'
                            ELSE 'parent'
                        END AS role,
                        $teamNamesSelect
        FROM users u
        WHERE u.id != ?
          AND (LOWER(u.first_name) LIKE LOWER(?) OR LOWER(u.last_name) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?))
          AND EXISTS (
              SELECT 1 FROM user_club_access uca
              WHERE uca.user_id = u.id AND uca.club_profile_id = ?
          )
          $accessFilter
        ORDER BY u.last_name, u.first_name
        LIMIT 50
    ";
    // Order matters and follows the placeholders above: role CASE (x2),
    // team_names, the excluded self, the three LIKEs, the club, then the access
    // branches.
    $stmt = $connection->prepare($sql);
    $stmt->execute(array_merge(
        [$clubProfileId, $clubProfileId],
        $teamNameParams,
        [$userId, $like, $like, $like, $clubProfileId],
        $isAdmin ? [] : $params
    ));
    $people = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Team groups ─────────────────────────────────────────────────────────
    // Admins browse the whole club; everyone else browses the teams they can see
    // from EITHER hat, which is what lets a coach-parent pick their child's team.
    if ($isAdmin) {
        $stmt = $connection->prepare("
            SELECT t.id, t.name, t.age_group
            FROM teams t
            WHERE t.club_id = ? AND t.deleted_at IS NULL
            ORDER BY t.age_group, t.name
        ");
        $stmt->execute([$clubProfileId]);
        $teamGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($visibleTeamIds)) {
        $ph = implode(',', array_fill(0, count($visibleTeamIds), '?'));
        $stmt = $connection->prepare("
            SELECT t.id, t.name, t.age_group
            FROM teams t
            WHERE t.id IN ($ph) AND t.deleted_at IS NULL
            ORDER BY t.age_group, t.name
        ");
        $stmt->execute($visibleTeamIds);
        $teamGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        // UNION, not either/or. This used to fall back to the parent's teams only
        // when the coach list was empty, so a coach whose child plays on a team
        // they do not coach was refused that team — the group-select half of the
        // same bug as the search.
        $allowedIds = array_values(array_unique(array_merge(
            array_map('intval', getCoachTeamIds($connection, $userId, $clubProfileId)),
            te_chat_parent_team_ids($connection, $userId, $clubProfileId)
        )));
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
              JOIN guardians g ON " . te_guardian_link_sql('u2', 'g') . "
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
