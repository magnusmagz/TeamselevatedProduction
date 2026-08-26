<?php
/**
 * Tell club admins that chat has been flagged.
 *
 * Phase 3 of docs/chat-notifications-scope.md, shaped by
 * docs/chat-moderation-plan.md:328 — a weekly digest, PLUS individual alerts for
 * high severity only.
 *
 * ⚠️ **The split is the whole design, not a setting.** Auto-flagging fires on
 * every message and most hits are `external_app` or profanity, which matter in
 * aggregate and not at 2am. Mailing an admin per flag is how admins learn to
 * filter the sender, and then the one that mattered goes unread too. High
 * severity — hate speech, secrecy, off-platform contact — is the set worth
 * interrupting someone for.
 *
 * Nothing has ever told an admin anything: ChatModeration.tsx is pull-only, so
 * since moderation shipped on 2026-07-30 a flag has sat unseen until someone
 * happened to open the page.
 *
 * Sends through lib/Email.php with ->forClub(), for the same reasons as
 * lib/chat_notification_dispatcher.php — EmailSendService would log a campaign
 * row per alert and apply the marketing suppression list to a child-safety
 * notification.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Email.php';

/** How often an admin may receive the routine digest. */
const TE_CHAT_MOD_DIGEST_DAYS = 7;

/**
 * Never look further back than this for un-alerted high-severity reports.
 *
 * Same guard, and same reason, as the lookback in chat_notification_scope.php:
 * on first deploy the marker table is empty, and without a bound every
 * historical flag would be mailed out at once. Wider than the chat window
 * because a missed safety flag stays worth seeing for longer.
 */
const TE_CHAT_MOD_ALERT_LOOKBACK_HOURS = 72;

/**
 * Club admins who should hear about this club.
 *
 * user_club_access is authoritative for roles (CLAUDE.md), and `revoked_at` is
 * checked alongside `active` because the two can disagree and the revocation is
 * the newer fact — the gap lib/JWT.php had to close on 2026-08-04.
 *
 * Coaches are deliberately excluded. Moderation is a club-admin surface
 * (te_mod_require_admin), so alerting a coach would mail them about something
 * they cannot open.
 *
 * @return array<int,array{id:int,email:string,first_name:string}>
 */
function te_chat_club_admins(PDO $pdo, int $clubId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.email, u.first_name
          FROM users u
          JOIN user_club_access uca ON uca.user_id = u.id
         WHERE uca.club_profile_id = ?
           AND uca.active = TRUE
           AND uca.revoked_at IS NULL
           AND uca.role IN ('club_admin', 'admin', 'owner')
           AND u.email IS NOT NULL
           AND u.email <> ''
         ORDER BY u.id
    ");
    $stmt->execute([$clubId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * High-severity, still-open reports no admin has been alerted to yet.
 *
 * @return array<int,array> one entry per (admin, report)
 */
function te_chat_pending_high_severity_alerts(PDO $pdo, array $opts = []): array
{
    $now = isset($opts['now'])
        ? new DateTimeImmutable($opts['now'])
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $hours = (int) ($opts['lookback_hours'] ?? TE_CHAT_MOD_ALERT_LOOKBACK_HOURS);
    $oldest = $now->sub(new DateInterval('PT' . max(1, $hours) . 'H'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT id, club_id, conversation_id, rule, severity, source, created_at
          FROM chat_message_reports
         WHERE severity = 'high'
           AND status = 'open'
           AND club_id IS NOT NULL
           AND created_at > :oldest
         ORDER BY id
    ");
    $stmt->execute([':oldest' => $oldest]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pending = [];
    $adminsByClub = [];

    foreach ($reports as $report) {
        $clubId = (int) $report['club_id'];

        if (!isset($adminsByClub[$clubId])) {
            $adminsByClub[$clubId] = te_chat_club_admins($pdo, $clubId);
        }

        foreach ($adminsByClub[$clubId] as $admin) {
            if (te_chat_admin_already_alerted($pdo, (int) $admin['id'], (int) $report['id'])) {
                continue;
            }
            $pending[] = ['admin' => $admin, 'report' => $report];
        }
    }

    return $pending;
}

/** Has this admin already been mailed about this report? */
function te_chat_admin_already_alerted(PDO $pdo, int $userId, int $reportId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM chat_moderation_alert_state
          WHERE user_id = ? AND report_id = ? AND kind = 'high_severity' LIMIT 1"
    );
    $stmt->execute([$userId, $reportId]);
    return (bool) $stmt->fetchColumn();
}

/** Record that an alert went out. Insert-only; the unique index is the guard. */
function te_chat_record_alert(
    PDO $pdo,
    int $userId,
    ?int $reportId,
    ?int $clubId,
    string $kind,
    ?string $at = null
): void {
    if (!in_array($kind, ['high_severity', 'digest'], true)) {
        throw new InvalidArgumentException("Unknown moderation alert kind: {$kind}");
    }

    $when = $at ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO chat_moderation_alert_state (user_id, report_id, club_id, kind, sent_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $reportId, $clubId, $kind, $when]);
}

/**
 * Admins due a routine digest, with the numbers to put in it.
 *
 * An admin with nothing open is deliberately NOT mailed. A weekly "0 reports"
 * email is the kind of mail people filter, and the filter then catches the week
 * something did happen.
 */
function te_chat_pending_digests(PDO $pdo, array $opts = []): array
{
    $now = isset($opts['now'])
        ? new DateTimeImmutable($opts['now'])
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $days = (int) ($opts['digest_days'] ?? TE_CHAT_MOD_DIGEST_DAYS);
    $cutoff = $now->sub(new DateInterval('P' . max(1, $days) . 'D'))->format('Y-m-d H:i:s');

    $clubs = $pdo->query(
        "SELECT DISTINCT club_id FROM chat_message_reports WHERE status = 'open' AND club_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    $pending = [];

    foreach ($clubs as $clubIdRaw) {
        $clubId = (int) $clubIdRaw;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS open_total,
                   SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) AS open_high
              FROM chat_message_reports
             WHERE club_id = ? AND status = 'open'
        ");
        $stmt->execute([$clubId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['open_total' => 0, 'open_high' => 0];

        if ((int) $counts['open_total'] === 0) {
            continue;
        }

        foreach (te_chat_club_admins($pdo, $clubId) as $admin) {
            $stmt = $pdo->prepare(
                "SELECT MAX(sent_at) FROM chat_moderation_alert_state
                  WHERE user_id = ? AND club_id = ? AND kind = 'digest'"
            );
            $stmt->execute([(int) $admin['id'], $clubId]);
            $last = $stmt->fetchColumn();

            if ($last !== null && $last !== false && (string) $last > $cutoff) {
                continue; // had one recently enough
            }

            $pending[] = [
                'admin'      => $admin,
                'club_id'    => $clubId,
                'open_total' => (int) $counts['open_total'],
                'open_high'  => (int) $counts['open_high'],
            ];
        }
    }

    return $pending;
}

/**
 * Send everything due.
 *
 * ⚠️ Every send is wrapped individually. This runs as a tick in
 * workers/queue-worker.php alongside email, SMS, imports and calendar sync — an
 * uncaught throw stops all four.
 *
 * @return array{alerts_sent:int,digests_sent:int,failed:int,errors:array}
 */
function te_chat_dispatch_moderation_alerts(PDO $pdo, array $opts = []): array
{
    $result = ['alerts_sent' => 0, 'digests_sent' => 0, 'failed' => 0, 'errors' => []];

    $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/');
    $link = $appUrl . '/chat-moderation';

    $mailer = $opts['mailer'] ?? function (array $envelope) use ($pdo): bool {
        $email = (new Email())->forClub($pdo, $envelope['club_id']);
        return (bool) $email->sendModerationAlert(
            $envelope['to'],
            $envelope['recipient_name'],
            $envelope['kind'],
            $envelope['detail'],
            $envelope['link']
        );
    };

    foreach (te_chat_pending_high_severity_alerts($pdo, $opts) as $item) {
        try {
            $ok = $mailer([
                'to'             => $item['admin']['email'],
                'recipient_name' => trim((string) $item['admin']['first_name']) ?: 'there',
                'kind'           => 'high_severity',
                'club_id'        => (int) $item['report']['club_id'],
                'detail'         => [
                    'rule'   => $item['report']['rule'],
                    'source' => $item['report']['source'],
                ],
                'link'           => $link,
            ]);

            if ($ok) {
                te_chat_record_alert(
                    $pdo,
                    (int) $item['admin']['id'],
                    (int) $item['report']['id'],
                    (int) $item['report']['club_id'],
                    'high_severity',
                    $opts['now'] ?? null
                );
                $result['alerts_sent']++;
            } else {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    'admin %d report %d: mailer reported failure',
                    $item['admin']['id'],
                    $item['report']['id']
                );
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = sprintf(
                'admin %d report %d: %s',
                $item['admin']['id'] ?? 0,
                $item['report']['id'] ?? 0,
                $e->getMessage()
            );
            error_log('[ChatModAlert] ' . end($result['errors']));
        }
    }

    foreach (te_chat_pending_digests($pdo, $opts) as $item) {
        try {
            $ok = $mailer([
                'to'             => $item['admin']['email'],
                'recipient_name' => trim((string) $item['admin']['first_name']) ?: 'there',
                'kind'           => 'digest',
                'club_id'        => $item['club_id'],
                'detail'         => [
                    'open_total' => $item['open_total'],
                    'open_high'  => $item['open_high'],
                ],
                'link'           => $link,
            ]);

            if ($ok) {
                te_chat_record_alert(
                    $pdo,
                    (int) $item['admin']['id'],
                    null,
                    $item['club_id'],
                    'digest',
                    $opts['now'] ?? null
                );
                $result['digests_sent']++;
            } else {
                $result['failed']++;
                $result['errors'][] = sprintf('admin %d digest: mailer reported failure', $item['admin']['id']);
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = sprintf('admin %d digest: %s', $item['admin']['id'] ?? 0, $e->getMessage());
            error_log('[ChatModAlert] ' . end($result['errors']));
        }
    }

    return $result;
}
