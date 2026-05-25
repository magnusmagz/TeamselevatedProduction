<?php
/**
 * Analytics Gateway API
 *
 * Reporting and analytics for email and SMS communication stats.
 * Supports club-wide and coach-scoped views.
 */

require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    $auth = AuthMiddleware::requireAuth();
    $userId = $auth->getUserId();

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit();
    }

    if (!$action) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Action parameter is required']);
        exit();
    }

    switch ($action) {
        case 'overview':
            handleOverview($connection, $auth, $userId);
            break;

        case 'campaign-performance':
            handleCampaignPerformance($connection, $auth, $userId);
            break;

        case 'per-email-report':
            handlePerEmailReport($connection, $auth, $userId);
            break;

        case 'contact-engagement':
            handleContactEngagement($connection, $auth, $userId);
            break;

        case 'link-analytics':
            handleLinkAnalytics($connection, $auth, $userId);
            break;

        case 'recent-sends':
            handleRecentSends($connection, $auth, $userId);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    error_log("Analytics Gateway Error: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Helper: Get coach's team IDs for scoping
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// Helper: Determine if user is club admin for the given club
// ---------------------------------------------------------------------------
function isClubAdmin($auth, $clubProfileId) {
    return $auth->isSuperAdmin() || $auth->hasRole('club_admin', $clubProfileId, 'club');
}

// ---------------------------------------------------------------------------
// Helper: Build coach-scoping JOIN + WHERE fragments
// Returns ['join' => string, 'where' => string, 'params' => array]
// ---------------------------------------------------------------------------
function buildCoachScope($connection, $auth, $userId, $clubProfileId) {
    if (isClubAdmin($auth, $clubProfileId)) {
        return ['join' => '', 'where' => '', 'params' => []];
    }

    $teamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
    if (empty($teamIds)) {
        return ['join' => '', 'where' => 'AND 1=0', 'params' => []];
    }

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));

    // Coach can see communication_log entries where the recipient (athlete or guardian)
    // is associated with one of the coach's teams.
    $join = "
        LEFT JOIN team_members _scope_tm
            ON cl.athlete_id = _scope_tm.athlete_id AND _scope_tm.team_id IN ($placeholders)
        LEFT JOIN athlete_guardians _scope_ag
            ON cl.recipient_id = _scope_ag.guardian_id
        LEFT JOIN team_members _scope_tm2
            ON _scope_ag.athlete_id = _scope_tm2.athlete_id AND _scope_tm2.team_id IN ($placeholders)
    ";
    $where = "AND (_scope_tm.id IS NOT NULL OR _scope_tm2.id IS NOT NULL)";
    $params = array_merge($teamIds, $teamIds);

    return ['join' => $join, 'where' => $where, 'params' => $params];
}

// ---------------------------------------------------------------------------
// Helper: Validate and return club_profile_id from GET params
// ---------------------------------------------------------------------------
function requireClubProfileId($auth) {
    $clubProfileId = $_GET['club_profile_id'] ?? null;
    if (!$clubProfileId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'club_profile_id is required']);
        exit();
    }

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit();
    }

    return (int)$clubProfileId;
}

// ---------------------------------------------------------------------------
// Helper: Build date filter fragments
// Returns ['where' => string, 'params' => array]
// ---------------------------------------------------------------------------
function buildDateFilter($prefix = 'cl') {
    $where = '';
    $params = [];
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;

    if ($dateFrom) {
        $where .= " AND {$prefix}.created_at >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        // date_to arrives as a bare date (YYYY-MM-DD). A naive `created_at <= ?`
        // coerces it to 00:00:00, silently dropping everything sent on the final
        // day — which made the link-analytics panel (and other panels) look
        // empty because the default range's date_to is *today* (CA-61). Use an
        // exclusive upper bound of the next midnight so the whole final day is
        // included regardless of time-of-day.
        $where .= " AND {$prefix}.created_at < (?::date + interval '1 day')";
        $params[] = $dateTo;
    }

    return ['where' => $where, 'params' => $params];
}

// ===========================================================================
// ACTION: overview
// ===========================================================================
function handleOverview($connection, $auth, $userId) {
    $clubProfileId = requireClubProfileId($auth);
    $scope = buildCoachScope($connection, $auth, $userId, $clubProfileId);
    $dateFilter = buildDateFilter();

    $channelFilter = '';
    $channelParams = [];
    $channel = $_GET['channel'] ?? null;
    if ($channel && in_array($channel, ['email', 'sms'])) {
        $channelFilter = ' AND cl.channel = ?';
        $channelParams[] = $channel;
    }

    $teamFilter = '';
    $teamParams = [];
    $teamId = $_GET['team_id'] ?? null;
    if ($teamId) {
        $teamFilter = ' AND cl.team_id = ?';
        $teamParams[] = (int)$teamId;
    }

    $params = array_merge(
        [$clubProfileId],
        $scope['params'],
        $dateFilter['params'],
        $channelParams,
        $teamParams
    );

    $sql = "
        SELECT
            COUNT(*) as total_sent,
            COUNT(*) FILTER (WHERE cl.channel = 'email') as email_sent,
            COUNT(*) FILTER (WHERE cl.channel = 'sms') as sms_sent,
            COUNT(*) FILTER (WHERE cl.status = 'delivered') as delivered,
            COUNT(*) FILTER (WHERE cl.status = 'failed') as failed,
            COUNT(*) FILTER (WHERE cl.status = 'bounced') as bounced,
            COUNT(*) FILTER (WHERE cl.open_count > 0 AND cl.channel = 'email') as opened,
            COUNT(*) FILTER (WHERE cl.click_count > 0 AND cl.channel = 'email') as clicked,
            COUNT(*) FILTER (WHERE cl.unsubscribed_at IS NOT NULL) as unsubscribed
        FROM communication_log cl
        {$scope['join']}
        WHERE cl.club_profile_id = ? AND cl.status != 'queued'
        {$scope['where']}
        {$dateFilter['where']}
        {$channelFilter}
        {$teamFilter}
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalSent   = (int)$row['total_sent'];
    $emailSent   = (int)$row['email_sent'];
    $smsSent     = (int)$row['sms_sent'];
    $delivered   = (int)$row['delivered'];
    $failed      = (int)$row['failed'];
    $bounced     = (int)$row['bounced'];
    $opened      = (int)$row['opened'];
    $clicked     = (int)$row['clicked'];
    $unsubscribed = (int)$row['unsubscribed'];

    echo json_encode([
        'success'          => true,
        'total_sent'       => $totalSent,
        'email_sent'       => $emailSent,
        'sms_sent'         => $smsSent,
        'delivered'        => $delivered,
        'failed'           => $failed,
        'bounced'          => $bounced,
        'opened'           => $opened,
        'clicked'          => $clicked,
        'unsubscribed'     => $unsubscribed,
        'open_rate'        => $emailSent > 0 ? round($opened / $emailSent * 100, 1) : 0,
        'click_rate'       => $emailSent > 0 ? round($clicked / $emailSent * 100, 1) : 0,
        'bounce_rate'      => $totalSent > 0 ? round($bounced / $totalSent * 100, 1) : 0,
        'unsubscribe_rate' => $totalSent > 0 ? round($unsubscribed / $totalSent * 100, 1) : 0,
    ]);
}

// ===========================================================================
// ACTION: campaign-performance
// ===========================================================================
function handleCampaignPerformance($connection, $auth, $userId) {
    $clubProfileId = requireClubProfileId($auth);
    $scope = buildCoachScope($connection, $auth, $userId, $clubProfileId);
    $dateFilter = buildDateFilter();

    $period = $_GET['period'] ?? 'day';
    if (!in_array($period, ['day', 'week', 'month'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid period. Use day, week, or month.']);
        exit();
    }

    $params = array_merge(
        [$period, $clubProfileId],
        $scope['params'],
        $dateFilter['params']
    );

    $sql = "
        SELECT
            date_trunc(?, cl.created_at) as period,
            COUNT(*) as sent,
            COUNT(*) FILTER (WHERE cl.channel = 'email') as email_sent,
            COUNT(*) FILTER (WHERE cl.channel = 'sms') as sms_sent,
            COUNT(*) FILTER (WHERE cl.status = 'delivered') as delivered,
            COUNT(*) FILTER (WHERE cl.open_count > 0) as opened,
            COUNT(*) FILTER (WHERE cl.click_count > 0) as clicked,
            COUNT(*) FILTER (WHERE cl.status = 'bounced') as bounced
        FROM communication_log cl
        {$scope['join']}
        WHERE cl.club_profile_id = ? AND cl.status != 'queued'
        {$scope['where']}
        {$dateFilter['where']}
        GROUP BY period
        ORDER BY period
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields
    $data = array_map(function ($row) {
        return [
            'period'     => $row['period'],
            'sent'       => (int)$row['sent'],
            'email_sent' => (int)$row['email_sent'],
            'sms_sent'   => (int)$row['sms_sent'],
            'delivered'  => (int)$row['delivered'],
            'opened'     => (int)$row['opened'],
            'clicked'    => (int)$row['clicked'],
            'bounced'    => (int)$row['bounced'],
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'period'  => $period,
        'data'    => $data,
    ]);
}

// ===========================================================================
// ACTION: per-email-report
// ===========================================================================
function handlePerEmailReport($connection, $auth, $userId) {
    $id = $_GET['id'] ?? null;
    $type = $_GET['type'] ?? 'single';

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'id parameter is required']);
        exit();
    }

    if ($type === 'broadcast') {
        handleBroadcastReport($connection, $auth, $userId, (int)$id);
    } else {
        handleSingleReport($connection, $auth, $userId, (int)$id);
    }
}

function handleSingleReport($connection, $auth, $userId, $logId) {
    // Fetch the single log entry
    $stmt = $connection->prepare("
        SELECT cl.*, u.first_name as sender_first, u.last_name as sender_last
        FROM communication_log cl
        JOIN users u ON cl.user_id = u.id
        WHERE cl.id = ?
    ");
    $stmt->execute([$logId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Communication log entry not found']);
        exit();
    }

    // Verify club access
    if (!$auth->canAccessClub($log['club_profile_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit();
    }

    // Coach scoping check
    $clubProfileId = (int)$log['club_profile_id'];
    if (!isClubAdmin($auth, $clubProfileId)) {
        $teamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
        if (empty($teamIds)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $scopeStmt = $connection->prepare("
            SELECT 1 FROM communication_log cl2
            LEFT JOIN team_members tm ON cl2.athlete_id = tm.athlete_id AND tm.team_id IN ($placeholders)
            LEFT JOIN athlete_guardians ag ON cl2.recipient_id = ag.guardian_id
            LEFT JOIN team_members tm2 ON ag.athlete_id = tm2.athlete_id AND tm2.team_id IN ($placeholders)
            WHERE cl2.id = ? AND (tm.id IS NOT NULL OR tm2.id IS NOT NULL)
        ");
        $scopeStmt->execute(array_merge($teamIds, $teamIds, [$logId]));
        if (!$scopeStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit();
        }
    }

    // Fetch webhook / tracking events for this log entry. Events live in
    // email_events (cols: event_type, event_data, occurred_at) — NOT a
    // "communication_events" table, which does not exist. The earlier name
    // threw a PDO exception that bubbled to a 500, so the per-recipient report
    // never rendered (CA-60).
    $evtStmt = $connection->prepare("
        SELECT event_type, occurred_at, event_data
        FROM email_events
        WHERE communication_log_id = ?
        ORDER BY occurred_at ASC
    ");
    $evtStmt->execute([$logId]);
    $events = $evtStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch link clicks for this send
    $linkStmt = $connection->prepare("
        SELECT original_url, click_count, last_clicked_at
        FROM email_links
        WHERE communication_log_id = ?
        ORDER BY click_count DESC
    ");
    $linkStmt->execute([$logId]);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);

    // A single send is exactly one recipient. Build the per-recipient breakdown
    // the frontend expects (name / email / phone / status / opened / clicked
    // plus first-open and first-click timestamps derived from email_events).
    $openedAt  = firstEventTime($events, 'open');
    $clickedAt = firstEventTime($events, 'click');
    $opened    = $openedAt !== null || (int)$log['open_count'] > 0;
    $clicked   = $clickedAt !== null || (int)$log['click_count'] > 0;

    $recipient = [
        'name'       => $log['recipient_name'],
        'email'      => $log['recipient_email'] ?? null,
        'phone'      => $log['recipient_phone'] ?? null,
        'status'     => $log['status'],
        'opened'     => $opened,
        'clicked'    => $clicked,
        'opened_at'  => $openedAt,
        'clicked_at' => $clickedAt,
    ];

    $delivered = $log['status'] === 'delivered' ? 1 : 0;
    $bounced   = $log['status'] === 'bounced' ? 1 : 0;

    echo json_encode([
        'success' => true,
        'report'  => [
            'id'              => (int)$log['id'],
            'type'            => 'single',
            'channel'         => $log['channel'],
            'subject'         => $log['subject'],
            'body'            => $log['body'] ?? null,
            'sender_name'     => trim($log['sender_first'] . ' ' . $log['sender_last']),
            'sent_at'         => $log['sent_at'] ?? $log['created_at'],
            'recipient_count' => 1,
            'total_delivered' => $delivered,
            'total_opened'    => $opened ? 1 : 0,
            'total_clicked'   => $clicked ? 1 : 0,
            'total_bounced'   => $bounced,
            'recipients'      => [$recipient],
            'links'           => $links,
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Helper: first occurred_at for a given event_type, or null if none.
// ---------------------------------------------------------------------------
function firstEventTime(array $events, string $type): ?string {
    foreach ($events as $evt) {
        if (($evt['event_type'] ?? null) === $type) {
            return $evt['occurred_at'];
        }
    }
    return null;
}

function handleBroadcastReport($connection, $auth, $userId, $broadcastCampaignId) {
    // Fetch campaign-level info from the first log entry
    $infoStmt = $connection->prepare("
        SELECT cl.club_profile_id, cl.subject, cl.channel, cl.broadcast_campaign_id,
               cl.created_at, cl.event_id,
               u.first_name as sender_first, u.last_name as sender_last
        FROM communication_log cl
        JOIN users u ON cl.user_id = u.id
        WHERE cl.broadcast_campaign_id = ?
        ORDER BY cl.created_at ASC
        LIMIT 1
    ");
    $infoStmt->execute([$broadcastCampaignId]);
    $campaignInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaignInfo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Broadcast campaign not found']);
        exit();
    }

    $clubProfileId = (int)$campaignInfo['club_profile_id'];

    if (!$auth->canAccessClub($clubProfileId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit();
    }

    // Build coach scope
    $scope = buildCoachScope($connection, $auth, $userId, $clubProfileId);

    // Aggregate stats for the broadcast
    $aggParams = array_merge([$broadcastCampaignId], $scope['params']);
    $aggSql = "
        SELECT
            COUNT(*) as total_recipients,
            COUNT(*) FILTER (WHERE cl.status = 'delivered') as delivered,
            COUNT(*) FILTER (WHERE cl.status = 'failed') as failed,
            COUNT(*) FILTER (WHERE cl.status = 'bounced') as bounced,
            COUNT(*) FILTER (WHERE cl.open_count > 0) as opened,
            COUNT(*) FILTER (WHERE cl.click_count > 0) as clicked,
            COUNT(*) FILTER (WHERE cl.unsubscribed_at IS NOT NULL) as unsubscribed
        FROM communication_log cl
        {$scope['join']}
        WHERE cl.broadcast_campaign_id = ?
        {$scope['where']}
    ";
    $aggStmt = $connection->prepare($aggSql);
    $aggStmt->execute($aggParams);
    $stats = $aggStmt->fetch(PDO::FETCH_ASSOC);

    $totalRecipients = (int)$stats['total_recipients'];
    $delivered       = (int)$stats['delivered'];
    $opened          = (int)$stats['opened'];
    $clicked         = (int)$stats['clicked'];
    $bounced         = (int)$stats['bounced'];
    $failed          = (int)$stats['failed'];
    $unsubscribed    = (int)$stats['unsubscribed'];

    // Per-recipient breakdown
    $recipientParams = array_merge([$broadcastCampaignId], $scope['params']);
    $recipientSql = "
        SELECT cl.id, cl.recipient_id, cl.recipient_name, cl.recipient_email,
               cl.recipient_phone, cl.status, cl.open_count, cl.click_count,
               cl.sent_at, cl.delivered_at, cl.unsubscribed_at,
               u.first_name as sender_first, u.last_name as sender_last
        FROM communication_log cl
        JOIN users u ON cl.user_id = u.id
        {$scope['join']}
        WHERE cl.broadcast_campaign_id = ?
        {$scope['where']}
        ORDER BY cl.recipient_name
    ";
    $recipientStmt = $connection->prepare($recipientSql);
    $recipientStmt->execute($recipientParams);
    $recipientRows = $recipientStmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-recipient open/click timestamps from email_events, keyed by
    // communication_log_id, so the breakdown can show who opened / clicked.
    $logIds = array_column($recipientRows, 'id');
    $openTimes = [];
    $clickTimes = [];
    if (!empty($logIds)) {
        $evtPlaceholders = implode(',', array_fill(0, count($logIds), '?'));
        $evtStmt = $connection->prepare("
            SELECT communication_log_id, event_type, MIN(occurred_at) as first_at
            FROM email_events
            WHERE communication_log_id IN ($evtPlaceholders)
              AND event_type IN ('open', 'click')
            GROUP BY communication_log_id, event_type
        ");
        $evtStmt->execute($logIds);
        foreach ($evtStmt->fetchAll(PDO::FETCH_ASSOC) as $evt) {
            $lid = (int)$evt['communication_log_id'];
            if ($evt['event_type'] === 'open') {
                $openTimes[$lid] = $evt['first_at'];
            } else {
                $clickTimes[$lid] = $evt['first_at'];
            }
        }
    }

    $recipients = array_map(function ($r) use ($openTimes, $clickTimes) {
        $lid = (int)$r['id'];
        $openedAt  = $openTimes[$lid] ?? null;
        $clickedAt = $clickTimes[$lid] ?? null;
        return [
            'name'       => $r['recipient_name'],
            'email'      => $r['recipient_email'] ?? null,
            'phone'      => $r['recipient_phone'] ?? null,
            'status'     => $r['status'],
            'opened'     => $openedAt !== null || (int)$r['open_count'] > 0,
            'clicked'    => $clickedAt !== null || (int)$r['click_count'] > 0,
            'opened_at'  => $openedAt,
            'clicked_at' => $clickedAt,
        ];
    }, $recipientRows);

    // Link click breakdown
    $linkParams = array_merge([$broadcastCampaignId], $scope['params']);
    $linkSql = "
        SELECT el.original_url, SUM(el.click_count) as total_clicks
        FROM email_links el
        JOIN communication_log cl ON el.communication_log_id = cl.id
        {$scope['join']}
        WHERE cl.broadcast_campaign_id = ?
        {$scope['where']}
        GROUP BY el.original_url
        ORDER BY total_clicks DESC
    ";
    $linkStmt = $connection->prepare($linkSql);
    $linkStmt->execute($linkParams);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);

    // Return under a uniform `report` key so the frontend renders broadcast and
    // single sends through the same code path (CA-60).
    echo json_encode([
        'success' => true,
        'report'  => [
            'id'              => $broadcastCampaignId,
            'type'            => 'broadcast',
            'channel'         => $campaignInfo['channel'],
            'subject'         => $campaignInfo['subject'],
            'sender_name'     => trim($campaignInfo['sender_first'] . ' ' . $campaignInfo['sender_last']),
            'sent_at'         => $campaignInfo['created_at'],
            'event_id'        => $campaignInfo['event_id'],
            'recipient_count' => $totalRecipients,
            'total_delivered' => $delivered,
            'total_opened'    => $opened,
            'total_clicked'   => $clicked,
            'total_bounced'   => $bounced,
            'total_failed'    => $failed,
            'total_unsubscribed' => $unsubscribed,
            'open_rate'       => $totalRecipients > 0 ? round($opened / $totalRecipients * 100, 1) : 0,
            'click_rate'      => $totalRecipients > 0 ? round($clicked / $totalRecipients * 100, 1) : 0,
            'bounce_rate'     => $totalRecipients > 0 ? round($bounced / $totalRecipients * 100, 1) : 0,
            'recipients'      => $recipients,
            'links'           => $links,
        ],
    ]);
}

// ===========================================================================
// ACTION: contact-engagement
// ===========================================================================
function handleContactEngagement($connection, $auth, $userId) {
    $clubProfileId = requireClubProfileId($auth);
    $contactType = $_GET['contact_type'] ?? null;
    $contactId = $_GET['contact_id'] ?? null;

    if (!$contactType || !$contactId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'contact_type and contact_id are required']);
        exit();
    }

    if (!in_array($contactType, ['athlete', 'guardian'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'contact_type must be athlete or guardian']);
        exit();
    }

    $contactId = (int)$contactId;

    // Coach scoping: verify this contact is on one of the coach's teams
    if (!isClubAdmin($auth, $clubProfileId)) {
        $teamIds = getCoachTeamIds($connection, $userId, $clubProfileId);
        if (empty($teamIds)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        if ($contactType === 'athlete') {
            $scopeStmt = $connection->prepare("
                SELECT 1 FROM team_members WHERE athlete_id = ? AND team_id IN ($placeholders) LIMIT 1
            ");
            $scopeStmt->execute(array_merge([$contactId], $teamIds));
        } else {
            $scopeStmt = $connection->prepare("
                SELECT 1 FROM athlete_guardians ag
                JOIN team_members tm ON ag.athlete_id = tm.athlete_id
                WHERE ag.guardian_id = ? AND tm.team_id IN ($placeholders) LIMIT 1
            ");
            $scopeStmt->execute(array_merge([$contactId], $teamIds));
        }

        if (!$scopeStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit();
        }
    }

    // Determine filter column based on contact type
    if ($contactType === 'athlete') {
        $filterCol = 'cl.athlete_id';
    } else {
        $filterCol = 'cl.recipient_id';
    }

    $stmt = $connection->prepare("
        SELECT
            COUNT(*) FILTER (WHERE cl.channel = 'email') as total_emails,
            COUNT(*) FILTER (WHERE cl.channel = 'sms') as total_sms,
            COUNT(*) FILTER (WHERE cl.open_count > 0 AND cl.channel = 'email') as opened_emails,
            COUNT(*) FILTER (WHERE cl.click_count > 0 AND cl.channel = 'email') as clicked_emails,
            MAX(cl.created_at) as last_contacted,
            BOOL_OR(cl.unsubscribed_at IS NOT NULL) as has_unsubscribed
        FROM communication_log cl
        WHERE {$filterCol} = ? AND cl.club_profile_id = ? AND cl.status != 'queued'
    ");
    $stmt->execute([$contactId, $clubProfileId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalEmails  = (int)$row['total_emails'];
    $totalSms     = (int)$row['total_sms'];
    $openedEmails = (int)$row['opened_emails'];
    $clickedEmails = (int)$row['clicked_emails'];

    echo json_encode([
        'success'          => true,
        'total_emails'     => $totalEmails,
        'total_sms'        => $totalSms,
        'opened_emails'    => $openedEmails,
        'clicked_emails'   => $clickedEmails,
        'engagement_score' => $totalEmails > 0 ? round($openedEmails / $totalEmails * 100) : 0,
        'last_contacted'   => $row['last_contacted'],
        'unsubscribed'     => (bool)$row['has_unsubscribed'],
    ]);
}

// ===========================================================================
// ACTION: link-analytics
// ===========================================================================
function handleLinkAnalytics($connection, $auth, $userId) {
    $clubProfileId = requireClubProfileId($auth);
    $scope = buildCoachScope($connection, $auth, $userId, $clubProfileId);
    $dateFilter = buildDateFilter();

    $params = array_merge([$clubProfileId], $scope['params'], $dateFilter['params']);

    // Only surface URLs that were actually clicked — a "top clicked links"
    // panel listing zero-click links is noise (CA-61).
    $sql = "
        SELECT el.original_url,
               SUM(el.click_count) as total_clicks,
               COUNT(DISTINCT el.communication_log_id) as emails_containing
        FROM email_links el
        JOIN communication_log cl ON el.communication_log_id = cl.id
        {$scope['join']}
        WHERE cl.club_profile_id = ?
        {$scope['where']}
        {$dateFilter['where']}
        GROUP BY el.original_url
        HAVING SUM(el.click_count) > 0
        ORDER BY total_clicks DESC
        LIMIT 20
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields
    $links = array_map(function ($row) {
        return [
            'original_url'      => $row['original_url'],
            'total_clicks'      => (int)$row['total_clicks'],
            'emails_containing' => (int)$row['emails_containing'],
        ];
    }, $links);

    echo json_encode([
        'success' => true,
        'links'   => $links,
    ]);
}

// ===========================================================================
// ACTION: recent-sends
// ===========================================================================
function handleRecentSends($connection, $auth, $userId) {
    $clubProfileId = requireClubProfileId($auth);
    $scope = buildCoachScope($connection, $auth, $userId, $clubProfileId);

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $channelFilter = '';
    $channelParams = [];
    $channel = $_GET['channel'] ?? null;
    if ($channel && in_array($channel, ['email', 'sms'])) {
        $channelFilter = ' AND cl.channel = ?';
        $channelParams[] = $channel;
    }

    // --- Broadcast campaigns (aggregated) ---
    $broadcastParams = array_merge([$clubProfileId], $scope['params'], $channelParams);
    $broadcastSql = "
        SELECT
            cl.broadcast_campaign_id,
            MIN(cl.subject) as subject,
            MIN(cl.channel) as channel,
            MIN(cl.created_at) as sent_at,
            MIN(u.first_name) as sender_first,
            MIN(u.last_name) as sender_last,
            COUNT(*) as total_recipients,
            COUNT(*) FILTER (WHERE cl.status = 'delivered') as delivered,
            COUNT(*) FILTER (WHERE cl.open_count > 0) as opened,
            COUNT(*) FILTER (WHERE cl.click_count > 0) as clicked,
            COUNT(*) FILTER (WHERE cl.status = 'bounced') as bounced,
            COUNT(*) FILTER (WHERE cl.status = 'failed') as failed
        FROM communication_log cl
        JOIN users u ON cl.user_id = u.id
        {$scope['join']}
        WHERE cl.club_profile_id = ? AND cl.broadcast_campaign_id IS NOT NULL
            AND cl.status != 'queued'
            {$scope['where']}
            {$channelFilter}
        GROUP BY cl.broadcast_campaign_id
    ";

    // --- Individual sends (no broadcast) ---
    $individualParams = array_merge([$clubProfileId], $scope['params'], $channelParams);
    $individualSql = "
        SELECT
            cl.id,
            NULL as broadcast_campaign_id,
            cl.subject,
            cl.channel,
            cl.created_at as sent_at,
            u.first_name as sender_first,
            u.last_name as sender_last,
            1 as total_recipients,
            CASE WHEN cl.status = 'delivered' THEN 1 ELSE 0 END as delivered,
            CASE WHEN cl.open_count > 0 THEN 1 ELSE 0 END as opened,
            CASE WHEN cl.click_count > 0 THEN 1 ELSE 0 END as clicked,
            CASE WHEN cl.status = 'bounced' THEN 1 ELSE 0 END as bounced,
            CASE WHEN cl.status = 'failed' THEN 1 ELSE 0 END as failed,
            cl.recipient_name
        FROM communication_log cl
        JOIN users u ON cl.user_id = u.id
        {$scope['join']}
        WHERE cl.club_profile_id = ? AND cl.broadcast_campaign_id IS NULL
            AND cl.status != 'queued'
            {$scope['where']}
            {$channelFilter}
    ";

    // UNION and paginate
    $unionParams = array_merge($broadcastParams, $individualParams);
    $unionSql = "
        SELECT * FROM (
            SELECT
                broadcast_campaign_id as id,
                'broadcast' as type,
                subject, channel, sent_at,
                sender_first, sender_last,
                total_recipients, delivered, opened, clicked, bounced, failed,
                NULL as recipient_name,
                CASE WHEN total_recipients > 0
                    THEN ROUND(opened::numeric / total_recipients * 100, 1) ELSE 0 END as open_rate,
                CASE WHEN total_recipients > 0
                    THEN ROUND(clicked::numeric / total_recipients * 100, 1) ELSE 0 END as click_rate
            FROM ({$broadcastSql}) broadcasts

            UNION ALL

            SELECT
                id,
                'individual' as type,
                subject, channel, sent_at,
                sender_first, sender_last,
                total_recipients, delivered, opened, clicked, bounced, failed,
                recipient_name,
                CASE WHEN opened > 0 THEN 100.0 ELSE 0 END as open_rate,
                CASE WHEN clicked > 0 THEN 100.0 ELSE 0 END as click_rate
            FROM ({$individualSql}) individuals
        ) combined
        ORDER BY sent_at DESC
        LIMIT ? OFFSET ?
    ";

    $paginationParams = array_merge($unionParams, [$perPage, $offset]);

    $stmt = $connection->prepare($unionSql);
    $stmt->execute($paginationParams);
    $sends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "
        SELECT (
            (SELECT COUNT(DISTINCT cl.broadcast_campaign_id)
             FROM communication_log cl
             {$scope['join']}
             WHERE cl.club_profile_id = ? AND cl.broadcast_campaign_id IS NOT NULL
                 AND cl.status != 'queued'
                 {$scope['where']}
                 {$channelFilter})
            +
            (SELECT COUNT(*)
             FROM communication_log cl
             {$scope['join']}
             WHERE cl.club_profile_id = ? AND cl.broadcast_campaign_id IS NULL
                 AND cl.status != 'queued'
                 {$scope['where']}
                 {$channelFilter})
        ) as total
    ";

    $countParams = array_merge(
        [$clubProfileId], $scope['params'], $channelParams,
        [$clubProfileId], $scope['params'], $channelParams
    );

    $countStmt = $connection->prepare($countSql);
    $countStmt->execute($countParams);
    $totalCount = (int)$countStmt->fetchColumn();

    // Cast numeric fields in results
    $sends = array_map(function ($row) {
        return [
            'id'               => $row['id'],
            'type'             => $row['type'],
            'subject'          => $row['subject'],
            'channel'          => $row['channel'],
            'sent_at'          => $row['sent_at'],
            'sender'           => trim($row['sender_first'] . ' ' . $row['sender_last']),
            'total_recipients' => (int)$row['total_recipients'],
            'delivered'        => (int)$row['delivered'],
            'opened'           => (int)$row['opened'],
            'clicked'          => (int)$row['clicked'],
            'bounced'          => (int)$row['bounced'],
            'failed'           => (int)$row['failed'],
            'recipient_name'   => $row['recipient_name'],
            'open_rate'        => (float)$row['open_rate'],
            'click_rate'       => (float)$row['click_rate'],
        ];
    }, $sends);

    echo json_encode([
        'success'    => true,
        'sends'      => $sends,
        'pagination' => [
            'page'       => $page,
            'per_page'   => $perPage,
            'total'      => $totalCount,
            'total_pages' => (int)ceil($totalCount / $perPage),
        ],
    ]);
}
?>
