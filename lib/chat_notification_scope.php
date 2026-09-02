<?php
/**
 * Who is owed a notification, and for which messages.
 *
 * This is phase 1 of docs/chat-notifications-scope.md and it deliberately sends
 * NOTHING. Email and web push both need exactly this answer, so it is built once
 * and separately — and it is where the risk in the feature actually sits.
 *
 * ─── The bug this file exists to not have ─────────────────────────────────────
 * Unread is normally read off conversation_participants.last_read_message_id.
 * But ensureTeamConversation() creates team conversations with NO participant
 * rows — members reach them through team scope, not membership — and
 * chat-server/server.js:305 falls back to `|| 0` when the row is missing. For a
 * badge that just shows a number that is too high. For notifications it would
 * mean the first dispatcher run emails a parent the ENTIRE history of a team
 * chat they never opened.
 *
 * The guard is the lookback window, not the watermark: no message older than
 * TE_CHAT_NOTIFY_LOOKBACK_MINUTES is ever a candidate, whatever the read state
 * says. So a missing row degrades to "the last hour", never "everything". The
 * watermark still applies on top when it exists; it just cannot be load-bearing.
 *
 * ─── The audience must match who can actually READ the conversation ───────────
 * Otherwise we mail someone a link to a 403. The team branch is a deliberate
 * mirror of chat-server/lib/team_scope.js (COACH_TEAM_IDS_SQL and
 * GUARDIAN_TEAM_IDS_SQL) including its filters and its omissions — the
 * assistant/manager branch checks status = 'active', the guardian branch does
 * not filter athlete status, and neither filters deleted_at. Those choices are
 * documented there. If that file changes, this one changes with it.
 *
 * ⚠️ The guardian branch resolves identity through te_guardian_link_sql() —
 * `user_guardians` links UNION the case-insensitive email match — and NOT by
 * comparing the two email columns inline. chat-server/lib/guardian_identity.js
 * is the port of that same rule and is what actually admits the parent to the
 * conversation. The two are one rule written twice, so they must move together:
 * if chat scope widens and this does not, a family silently stops being told
 * their coach messaged them; if this widens and chat scope does not, we mail
 * them a link to a 403.
 *
 * ⚠️ Club admins are NOT notified about team chats they merely oversee.
 * expandsToWholeClub() lets an admin READ every team conversation in their club,
 * which is correct for oversight and wrong as a mailing list — an admin of 16
 * teams would receive every message sent in the club. Access is not a
 * subscription. An admin who is also a coach or a guardian on that team is
 * notified through those branches, like anyone else.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/guardian_identity.php';

/**
 * How long an EMAIL waits, so an active back-and-forth is never mailed
 * mid-exchange. An email that arrives during a live conversation is noise.
 */
const TE_CHAT_NOTIFY_QUIET_MINUTES = 5;

/**
 * How long a PUSH waits: nothing.
 *
 * Chat has to feel immediate, and anything that reads as a delay reads as
 * broken. Set to 0 with Maggie 2026-08-26 after a 1-minute window still felt
 * slow in testing.
 *
 * ⚠️ **With this at zero the DISPATCH TICK is the floor, not this constant.**
 * The worker notices messages on a timer, so push latency is
 * 0..TE_CHAT_NOTIFY_TICK_SECONDS. Making push faster means shortening that tick,
 * not this. Truly instant needs the chat server to send the push itself at
 * message time rather than a worker noticing afterwards — see the scope doc.
 *
 * Not collapsing bursts is deliberate (Maggie, 2026-08-26): every message
 * alerts, matching iMessage and WhatsApp. That is what `renotify: true` in the
 * service worker is for.
 */
const TE_CHAT_NOTIFY_PUSH_QUIET_MINUTES = 0;

/** Nothing older than this is ever a candidate. See the note above — this is the guard. */
const TE_CHAT_NOTIFY_LOOKBACK_MINUTES = 60;

/**
 * Everyone who can read this conversation and should be told about new messages.
 *
 * @return int[] user ids
 */
function te_chat_conversation_audience(PDO $pdo, int $conversationId): array
{
    $audience = te_chat_conversation_audience_sql($pdo, $conversationId);
    if ($audience === null) {
        return [];
    }

    $stmt = $pdo->prepare($audience['sql']);
    $stmt->execute($audience['params']);

    return te_chat_clean_user_ids($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The same audience, as a SUBQUERY rather than a list of ids.
 *
 * The three state lookups below used to take the materialised audience and
 * rebuild it as `user_id IN (?,?,?,…)` — three times, one bind per person, for
 * every conversation in every tick. A team of 40 families meant 120 binds per
 * conversation to ask three questions. They now embed this instead, so the set
 * is expressed once and the dispatcher's own `foreach` is the only place the
 * audience is materialised at all (it has to be: the per-user decisions below
 * are PHP, not SQL).
 *
 * ⚠️ There is exactly ONE definition of the audience and it is this function.
 * It mirrors chat-server/lib/team_scope.js, filters and omissions included —
 * mailing someone a link to a 403 is the failure mode — and its guardian join
 * is te_guardian_link_sql() verbatim.
 *
 * Returns null when the conversation does not exist, or is a team conversation
 * with no team: "nobody" is the honest answer there, and it is a different
 * answer from an empty result the caller might otherwise widen.
 *
 * @return array{sql:string, params:array<string,int>}|null
 */
function te_chat_conversation_audience_sql(PDO $pdo, int $conversationId): ?array
{
    $stmt = $pdo->prepare('SELECT id, type, team_id FROM conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        return null;
    }

    if (($conversation['type'] ?? '') !== 'team') {
        // DMs and groups take their membership from the participant row, because
        // that is what membership MEANS there. left_at is "I left this group" —
        // six read-side uses treat it that way, so it must exclude here too.
        return [
            'sql' => 'SELECT user_id FROM conversation_participants
              WHERE conversation_id = :aud_conv AND user_id IS NOT NULL AND left_at IS NULL',
            'params' => [':aud_conv' => $conversationId],
        ];
    }

    $teamId = $conversation['team_id'] ?? null;
    if ($teamId === null) {
        // A team conversation with no team cannot resolve an audience. Returning
        // nobody is the honest answer; guessing the club's members is not.
        return null;
    }

    // Mirror of team_scope.js. Written as a UNION of targeted selects rather than
    // a scan over users with EXISTS clauses — the audience is small and known,
    // and the whole users table is not.
    //
    // The guardian join condition is te_guardian_link_sql() verbatim, so this
    // branch admits exactly the people chat-server/lib/team_scope.js admits.
    // Never re-inline the email comparison here.
    $guardianLink = te_guardian_link_sql('u', 'g');

    $sql = "
        SELECT t.primary_coach_id AS user_id
          FROM teams t
         WHERE t.id = :team_a AND t.primary_coach_id IS NOT NULL

        UNION

        SELECT tm.user_id
          FROM team_members tm
         WHERE tm.team_id = :team_b
           AND tm.user_id IS NOT NULL
           AND tm.role IN ('assistant_coach', 'team_manager')
           AND tm.status = 'active'

        UNION

        SELECT u.id AS user_id
          FROM users u
          JOIN guardians g ON {$guardianLink}
          JOIN athlete_guardians ag ON ag.guardian_id = g.id
          JOIN team_members tm ON tm.athlete_id = ag.athlete_id
         WHERE tm.team_id = :team_c
    ";

    return [
        'sql' => $sql,
        'params' => [
            ':team_a' => $teamId,
            ':team_b' => $teamId,
            ':team_c' => $teamId,
        ],
    ];
}

/**
 * Work out who is owed a notification right now.
 *
 * Returns one entry per (user, conversation) — a digest, never one per message.
 * Six messages in a burst produce one entry carrying six ids.
 *
 * @param array $opts now / quiet_minutes / lookback_minutes, all for testing and
 *                    for the caller to tune. Defaults are the confirmed product
 *                    settings.
 * @return array<int,array> each: user_id, conversation_id, message_ids,
 *                          latest_message_id, message_count, email_enabled, push_enabled
 */
function te_chat_pending_notifications(PDO $pdo, array $opts = []): array
{
    $now = isset($opts['now'])
        ? new DateTimeImmutable($opts['now'])
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $quiet = (int) ($opts['quiet_minutes'] ?? TE_CHAT_NOTIFY_QUIET_MINUTES);
    $lookback = (int) ($opts['lookback_minutes'] ?? TE_CHAT_NOTIFY_LOOKBACK_MINUTES);

    // Cutoffs are computed here and bound as parameters rather than written as
    // NOW() - INTERVAL in SQL. That keeps "what counts as recent" testable at an
    // arbitrary clock instead of only in real time.
    $newestEligible = $now->sub(new DateInterval('PT' . max(0, $quiet) . 'M'));
    $oldestEligible = $now->sub(new DateInterval('PT' . max(1, $lookback) . 'M'));

    $fmt = 'Y-m-d H:i:s';

    // Candidate messages: recent enough to matter, old enough not to interrupt an
    // exchange in progress, and not moderated away.
    $stmt = $pdo->prepare("
        SELECT id, conversation_id, sender_id, created_at
          FROM chat_messages
         WHERE deleted_at IS NULL
           AND conversation_id IS NOT NULL
           AND created_at > :oldest
           AND created_at <= :newest
         ORDER BY conversation_id, id
    ");
    $stmt->execute([
        ':oldest' => $oldestEligible->format($fmt),
        ':newest' => $newestEligible->format($fmt),
    ]);

    $byConversation = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byConversation[(int) $row['conversation_id']][] = $row;
    }

    $pending = [];

    foreach ($byConversation as $conversationId => $messages) {
        // Derived ONCE, as SQL, and handed to all three lookups. The ids are
        // materialised only for the per-user loop below, which is PHP work
        // (mute, prefs, watermark) and cannot be expressed as a set operation.
        $audienceSql = te_chat_conversation_audience_sql($pdo, $conversationId);
        if ($audienceSql === null) {
            continue;
        }
        $audience = te_chat_conversation_audience($pdo, $conversationId);
        if (!$audience) {
            continue;
        }

        $state = te_chat_participant_state($pdo, $conversationId, $audienceSql);
        $notified = te_chat_notification_state($pdo, $conversationId, $audienceSql);
        $prefs = te_chat_notification_prefs($pdo, $audienceSql);

        foreach ($audience as $userId) {
            // Muting is per conversation and lives on the participant row. An
            // absent row means "not muted" — the default is on, and for a team
            // chat most people will never have a row at all.
            if (!empty($state[$userId]['muted'])) {
                continue;
            }

            $pref = $prefs[$userId] ?? ['email_enabled' => true, 'push_enabled' => true];
            if (!$pref['email_enabled'] && !$pref['push_enabled']) {
                continue;
            }

            $lastRead = (int) ($state[$userId]['last_read_message_id'] ?? 0);
            $lastNotified = (int) ($notified[$userId] ?? 0);
            $floor = max($lastRead, $lastNotified);

            $owed = [];
            foreach ($messages as $message) {
                $messageId = (int) $message['id'];

                // Never tell someone about their own message.
                if ((int) $message['sender_id'] === $userId) {
                    continue;
                }
                // Already read it, or already been told.
                if ($messageId <= $floor) {
                    continue;
                }
                $owed[] = $messageId;
            }

            if (!$owed) {
                continue;
            }

            $pending[] = [
                'user_id'           => $userId,
                'conversation_id'   => $conversationId,
                'message_ids'       => $owed,
                'latest_message_id' => max($owed),
                'message_count'     => count($owed),
                'email_enabled'     => (bool) $pref['email_enabled'],
                'push_enabled'      => (bool) $pref['push_enabled'],
            ];
        }
    }

    return $pending;
}

/**
 * Record that a person has been told, so the next tick does not tell them again.
 *
 * UPSERT, never UPDATE. Most team-chat users have no participant row and will
 * have no state row either the first time, and a bare UPDATE would hit zero rows
 * and re-send the same digest every minute forever. Same trap markRead fell into
 * on team conversations (chat-server/lib/archive.js).
 */
function te_chat_mark_notified(
    PDO $pdo,
    int $userId,
    int $conversationId,
    int $lastMessageId,
    string $channel,
    ?string $at = null
): void {
    // 'in_app' joined the list with the notification centre (migration 077): a
    // person with no address and no device is still told, in the app, and that
    // has to close the item or the dispatcher re-derives it as owed every tick.
    if (!in_array($channel, ['email', 'push', 'in_app'], true)) {
        throw new InvalidArgumentException("Unknown notification channel: {$channel}");
    }

    $when = $at ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO chat_notification_state
            (user_id, conversation_id, last_notified_message_id, last_notified_at, last_notified_channel)
        VALUES (:user_id, :conversation_id, :message_id, :at, :channel)
        ON CONFLICT (user_id, conversation_id) DO UPDATE SET
            last_notified_message_id = EXCLUDED.last_notified_message_id,
            last_notified_at         = EXCLUDED.last_notified_at,
            last_notified_channel    = EXCLUDED.last_notified_channel,
            updated_at               = EXCLUDED.last_notified_at
    ");

    $stmt->execute([
        ':user_id'         => $userId,
        ':conversation_id' => $conversationId,
        ':message_id'      => $lastMessageId,
        ':at'              => $when,
        ':channel'         => $channel,
    ]);
}

/**
 * Record that someone came back from a notification.
 *
 * ⚠️ FIRST click only — `clicked_at IS NULL` in the WHERE. A person opening the
 * same conversation three times did not respond to three notifications, and
 * counting it that way would quietly inflate every rate built on this.
 *
 * Silently does nothing when there is no matching notification: someone opening
 * a conversation they were never notified about is ordinary use, not an error.
 *
 * @return bool whether this call recorded a click
 */
function te_chat_record_click(
    PDO $pdo,
    int $userId,
    int $conversationId,
    string $channel,
    ?string $at = null
): bool {
    if (!in_array($channel, ['email', 'push', 'in_app'], true)) {
        return false;
    }

    $when = $at ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'UPDATE chat_notification_state
            SET clicked_at = ?, clicked_channel = ?
          WHERE user_id = ? AND conversation_id = ? AND clicked_at IS NULL'
    );
    $stmt->execute([$when, $channel, $userId, $conversationId]);

    return $stmt->rowCount() > 0;
}

/**
 * Read watermark and mute flag, keyed by user id. Absent row is a valid answer.
 *
 * Scoped by the audience SUBQUERY, not by a bound list of its members — see
 * te_chat_conversation_audience_sql(). $audience is passed in rather than
 * re-derived so the three lookups and the dispatcher's loop cannot disagree
 * about who the audience is.
 *
 * @param array{sql:string, params:array} $audience
 */
function te_chat_participant_state(PDO $pdo, int $conversationId, array $audience): array
{
    $stmt = $pdo->prepare(
        "SELECT user_id, last_read_message_id, muted
           FROM conversation_participants
          WHERE conversation_id = :st_conv AND user_id IN ({$audience['sql']})"
    );
    $stmt->execute(array_merge([':st_conv' => $conversationId], $audience['params']));

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['user_id']] = [
            'last_read_message_id' => $row['last_read_message_id'],
            'muted'                => !empty($row['muted']),
        ];
    }
    return $out;
}

/**
 * What each user has already been told about in this conversation.
 *
 * @param array{sql:string, params:array} $audience
 */
function te_chat_notification_state(PDO $pdo, int $conversationId, array $audience): array
{
    $stmt = $pdo->prepare(
        "SELECT user_id, last_notified_message_id
           FROM chat_notification_state
          WHERE conversation_id = :ns_conv AND user_id IN ({$audience['sql']})"
    );
    $stmt->execute(array_merge([':ns_conv' => $conversationId], $audience['params']));

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['user_id']] = (int) $row['last_notified_message_id'];
    }
    return $out;
}

/**
 * Per-user channel preferences. An absent row means the defaults — on — so
 * nothing has to write a row for a user before they can be notified.
 */
function te_chat_notification_prefs(PDO $pdo, array $audience): array
{
    $stmt = $pdo->prepare(
        "SELECT user_id, email_enabled, push_enabled
           FROM chat_notification_prefs
          WHERE user_id IN ({$audience['sql']})"
    );
    $stmt->execute($audience['params']);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['user_id']] = [
            'email_enabled' => te_chat_truthy($row['email_enabled']),
            'push_enabled'  => te_chat_truthy($row['push_enabled']),
        ];
    }
    return $out;
}

/**
 * Postgres hands back native booleans; SQLite (the test fixture) hands back 0/1
 * and some drivers hand back 't'/'f'. Read all three the same way rather than
 * letting the answer depend on the driver.
 */
function te_chat_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    $s = strtolower(trim((string) $value));
    return in_array($s, ['1', 't', 'true', 'yes'], true);
}

/** Positive integers only — same rule, and same reason, as mergeTeamIds(). */
function te_chat_clean_user_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $out[$n] = true;
        }
    }
    return array_keys($out);
}
