<?php
/**
 * Chat moderation review queue.
 *
 * Club admins review reported and auto-flagged chat messages here. Reports and
 * flags share one table (`chat_message_reports`, migration 061) so this is one
 * inbox rather than two.
 *
 * WHY THE QUEUE IS PHP AND THE ACTIONS ARE NOT
 * Chat data is written by the Node chat server; the CRM is PHP. Splitting writes
 * across both would give two places that can soft-delete a message. So this file
 * is READ-mostly: it lists the queue and closes items. The removal itself stays
 * on the chat server's `removeMessage` socket, which is also what broadcasts the
 * tombstone to everyone in the room live — a PHP-side removal would leave open
 * clients showing the message until they reloaded.
 *
 * The admin therefore reviews here, clicks through to the conversation in chat,
 * and removes there.
 *
 * WHAT THIS DELIBERATELY DOES NOT RETURN
 * `message_text` is never selected for a message that is already removed. The
 * whole point of removal is that the text stops being served; a moderation queue
 * that echoes removed content back would be the one hole in it.
 *
 * SCOPE
 * Club-scoped for club admins via AuthMiddleware::canAccessClub. Super admins
 * reach any club, for platform support. Coaches get nothing here at all — they
 * cannot remove messages (lib/moderation.js) and they do not review them.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

function te_mod_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Only club admins and above review. Coaches are deliberately excluded — they
 * cannot remove messages either (lib/moderation.js), and a coach reviewing
 * reports about their own conduct is the case this feature exists for.
 *
 * Standing is checked with hasRole(), NOT the active context. A club admin whose
 * active context happens to be a team would otherwise be refused their own
 * queue. (getActiveContext() also returns an array-or-null since SEC-11, so it is
 * not safe to subscript directly.)
 */
function te_mod_require_admin(AuthMiddleware $auth, ?int $clubId): void
{
    if ($auth->isSuperAdmin()) return;

    $isAdmin = $auth->hasRole('club_admin') || $auth->hasRole('admin') || $auth->hasRole('owner');
    if (!$isAdmin) {
        te_mod_fail(403, 'Only club administrators can review reported messages');
    }
    if ($clubId !== null && !$auth->canAccessClub($clubId)) {
        te_mod_fail(403, 'You do not have access to that club');
    }
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('chat-moderation: DB connection failed: ' . $e->getMessage());
    te_mod_fail(500, 'Database unavailable');
}

$auth   = AuthMiddleware::requireAuth();
$action = $_GET['action'] ?? 'queue';

switch ($action) {

    /**
     * The queue itself. Open items first, high severity first, then oldest —
     * oldest-first within a severity because an item that has been sitting is the
     * one that matters, not the newest arrival.
     */
    case 'queue': {
        $clubId = isset($_GET['club_id']) ? (int) $_GET['club_id'] : null;
        te_mod_require_admin($auth, $clubId);

        $status = $_GET['status'] ?? 'open';
        if (!in_array($status, ['open', 'actioned', 'dismissed', 'all'], true)) {
            te_mod_fail(400, 'Unknown status filter');
        }

        $where  = [];
        $params = [];

        if ($clubId !== null) {
            $where[] = 'r.club_id = :club_id';
            $params[':club_id'] = $clubId;
        } elseif (!$auth->isSuperAdmin()) {
            // No club specified: confine a club admin to the clubs they hold.
            $ids = $auth->getAccessibleClubIds();
            if (!$ids) {
                echo json_encode(['success' => true, 'reports' => [], 'open_count' => 0]);
                exit;
            }
            $in = [];
            foreach (array_values($ids) as $i => $id) {
                $in[] = ':club' . $i;
                $params[':club' . $i] = (int) $id;
            }
            $where[] = 'r.club_id IN (' . implode(',', $in) . ')';
        }

        if ($status !== 'all') {
            $where[] = 'r.status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // message_text is nulled for removed messages — see the header note.
        $sql = "
            SELECT r.id, r.message_id, r.conversation_id, r.club_id,
                   r.source, r.rule, r.severity, r.note, r.status,
                   r.created_at, r.reviewed_at,
                   CASE WHEN m.deleted_at IS NULL THEN m.message_text ELSE NULL END AS message_text,
                   (m.deleted_at IS NOT NULL) AS message_removed,
                   m.sender_name, m.sender_id, m.created_at AS message_created_at,
                   c.type AS conversation_type, c.team_id,
                   t.name AS team_name,
                   ru.first_name AS reporter_first_name, ru.last_name AS reporter_last_name,
                   vu.first_name AS reviewer_first_name, vu.last_name AS reviewer_last_name
            FROM chat_message_reports r
            JOIN chat_messages m ON m.id = r.message_id
            LEFT JOIN conversations c ON c.id = r.conversation_id
            LEFT JOIN teams t ON t.id = c.team_id
            LEFT JOIN users ru ON ru.id = r.reported_by
            LEFT JOIN users vu ON vu.id = r.reviewed_by
            $whereSql
            ORDER BY (r.status = 'open') DESC,
                     CASE r.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                     r.created_at ASC
            LIMIT 200
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Queue health. An unwatched queue is worse than none — an unactioned
        // flag is discoverable evidence that someone was told and did nothing —
        // so the age of the oldest open item is surfaced, not just the count.
        $healthWhere = array_filter($where, fn($w) => strpos($w, 'r.status') === false);
        $healthParams = array_filter(
            $params,
            fn($k) => $k !== ':status',
            ARRAY_FILTER_USE_KEY
        );
        $healthSql = "
            SELECT count(*)::int AS open_count,
                   MIN(created_at) AS oldest_open_at
            FROM chat_message_reports r
            " . ($healthWhere ? 'WHERE ' . implode(' AND ', $healthWhere) . " AND r.status = 'open'"
                              : "WHERE r.status = 'open'");
        $hs = $pdo->prepare($healthSql);
        $hs->execute($healthParams);
        $health = $hs->fetch(PDO::FETCH_ASSOC) ?: ['open_count' => 0, 'oldest_open_at' => null];

        echo json_encode([
            'success'        => true,
            'reports'        => $reports,
            'open_count'     => (int) ($health['open_count'] ?? 0),
            'oldest_open_at' => $health['oldest_open_at'] ?? null,
        ]);
        exit;
    }

    /**
     * Close an item without removing the message. Dismissal is a decision and is
     * recorded as one — who looked, and when — so "nobody reviewed this" and
     * "someone reviewed it and judged it fine" stay distinguishable.
     */
    case 'dismiss':
    case 'actioned': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            te_mod_fail(405, 'POST required');
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $reportId = (int) ($body['report_id'] ?? 0);
        if (!$reportId) te_mod_fail(400, 'report_id is required');

        $stmt = $pdo->prepare('SELECT id, club_id, message_id, status FROM chat_message_reports WHERE id = ?');
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) te_mod_fail(404, 'Report not found');

        te_mod_require_admin($auth, $report['club_id'] !== null ? (int) $report['club_id'] : null);

        $newStatus = $action === 'dismiss' ? 'dismissed' : 'actioned';

        // Only an OPEN report moves, so a second click cannot rewrite who
        // reviewed it or when.
        $upd = $pdo->prepare(
            "UPDATE chat_message_reports
             SET status = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'open'"
        );
        $upd->execute([$newStatus, $auth->getUserId(), $reportId]);

        if ($upd->rowCount() > 0) {
            AuditLogger::log($pdo, $auth->getUserId(), 'chat_report_' . $newStatus,
                'chat_message_reports', $reportId, [
                    'message_id' => (int) $report['message_id'],
                    'club_id'    => $report['club_id'],
                ]);
        }

        echo json_encode(['success' => true, 'status' => $newStatus]);
        exit;
    }

    /**
     * Compliance summary over a date range.
     *
     * This is the artifact a club hands to a board or an insurer, and the reason
     * a buyer cares about any of this. It answers "we were told N times, we
     * looked N times, here is what we did" with numbers rather than assurances.
     *
     * It counts ACTIONS, never content — no message text is aggregated or
     * returned here, so the summary can be shared without carrying the thing
     * that was reported.
     */
    /**
     * Cheap count for the nav badge.
     *
     * Deliberately NOT `summary`, which runs three queries over a 90-day window
     * to build the oversight report. This is polled by every admin's navigation,
     * so it has to stay one indexed count — idx_chat_reports_club_status_created
     * already covers (club_id, status).
     *
     * A flag nobody is told about is the gap this and the email alerts close
     * together: auto-flagging has fired on every message since 2026-07-30 and
     * ChatModeration.tsx is pull-only.
     */
    case 'open-count': {
        $clubId = isset($_GET['club_id']) ? (int) $_GET['club_id'] : null;
        te_mod_require_admin($auth, $clubId);

        $clubFilter = $clubId !== null ? 'AND club_id = :club_id' : '';
        $bind = $clubId !== null ? [':club_id' => $clubId] : [];

        $stmt = $pdo->prepare("
            SELECT count(*)::int AS open_total,
                   count(*) FILTER (WHERE severity = 'high')::int AS open_high
              FROM chat_message_reports
             WHERE status = 'open' $clubFilter
        ");
        $stmt->execute($bind);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['open_total' => 0, 'open_high' => 0];

        echo json_encode([
            'success'    => true,
            'open_total' => (int) $counts['open_total'],
            'open_high'  => (int) $counts['open_high'],
        ]);
        break;
    }

    case 'summary': {
        $clubId = isset($_GET['club_id']) ? (int) $_GET['club_id'] : null;
        te_mod_require_admin($auth, $clubId);

        $days = isset($_GET['days']) ? max(1, min(365, (int) $_GET['days'])) : 90;

        $clubFilter  = $clubId !== null ? 'AND club_id = :club_id' : '';
        $bind = [':days' => $days];
        if ($clubId !== null) $bind[':club_id'] = $clubId;

        $sql = "
            SELECT
              count(*)::int AS reports_total,
              count(*) FILTER (WHERE source = 'user')::int      AS reports_from_members,
              count(*) FILTER (WHERE source = 'auto')::int      AS reports_auto_flagged,
              count(*) FILTER (WHERE severity = 'high')::int    AS reports_high_severity,
              count(*) FILTER (WHERE status = 'open')::int      AS still_open,
              count(*) FILTER (WHERE status = 'actioned')::int  AS handled,
              count(*) FILTER (WHERE status = 'dismissed')::int AS no_action_needed,
              count(*) FILTER (WHERE status <> 'open')::int     AS reviewed
            FROM chat_message_reports
            WHERE created_at >= NOW() - (:days || ' days')::interval $clubFilter
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $reports = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Messages actually removed, and admin reads of conversations they were
        // not part of. The second is the number that shows oversight happened
        // AND that it was accountable.
        $removedSql = "
            SELECT count(*)::int AS messages_removed
            FROM chat_messages m
            LEFT JOIN conversations c ON c.id = m.conversation_id
            WHERE m.deleted_at >= NOW() - (:days || ' days')::interval
            " . ($clubId !== null ? 'AND c.club_id = :club_id' : '');
        $rs = $pdo->prepare($removedSql);
        $rs->execute($bind);
        $removed = $rs->fetch(PDO::FETCH_ASSOC) ?: ['messages_removed' => 0];

        $readsSql = "
            SELECT count(*)::int AS admin_reads,
                   count(DISTINCT user_id)::int AS admins_who_read
            FROM chat_access_log
            WHERE created_at >= NOW() - (:days || ' days')::interval $clubFilter
        ";
        $as = $pdo->prepare($readsSql);
        $as->execute($bind);
        $reads = $as->fetch(PDO::FETCH_ASSOC) ?: ['admin_reads' => 0, 'admins_who_read' => 0];

        echo json_encode([
            'success' => true,
            'days'    => $days,
            'summary' => array_merge(
                array_map('intval', $reports),
                array_map('intval', $removed),
                array_map('intval', $reads)
            ),
        ]);
        exit;
    }

    default:
        te_mod_fail(400, 'Unknown action');
}
