<?php
/**
 * Send the notifications that te_chat_pending_notifications() says are owed.
 *
 * Phase 2 of docs/chat-notifications-scope.md. Email only; web push slots in at
 * the same call site in phase 4, which is why the "already told" marker in
 * chat_notification_state is one watermark shared by both channels rather than
 * one per channel.
 *
 * ⚠️ **Sends through lib/Email.php with ->forClub(), never EmailSendService.**
 * Two reasons, both silent failures:
 *   1. EmailSendService writes a communication_log row per send, so Email
 *      Reporting would fill with chat noise and every campaign metric on that
 *      page would be measuring something else.
 *   2. It applies email_suppressions — which is the club's MARKETING opt-out. A
 *      parent who unsubscribed from club broadcasts would silently stop being
 *      told their coach had messaged them. Suppression must not reach this path;
 *      the opt-out for chat is conversation_participants.muted and
 *      chat_notification_prefs, and nothing else.
 * Pinned by ChatNotificationDispatcherTest.
 *
 * ⚠️ **One conversation failing must not stop the rest.** This runs as a tick
 * inside workers/queue-worker.php, which also drives email, SMS, imports and
 * calendar sync. An uncaught throw here stops all four. Every send is wrapped
 * individually and failures are logged and counted, never rethrown.
 */

require_once __DIR__ . '/chat_notification_scope.php';
require_once __DIR__ . '/chat_push.php';
require_once __DIR__ . '/notification_centre.php';
require_once __DIR__ . '/Email.php';

/**
 * Deliver everything currently owed.
 *
 * @param PDO      $pdo
 * @param array    $opts  passed through to te_chat_pending_notifications, plus
 *                        'mailer' (callable) to substitute the sender in tests
 * @return array{sent:int,pushed:int,in_app:int,failed:int,skipped:int,errors:array}
 */
function te_chat_dispatch_notifications(PDO $pdo, array $opts = []): array
{
    // ── The two channels do NOT share a delay ────────────────────────────────
    //
    // A push is the channel people expect to feel immediate; five minutes late
    // reads as broken. An email arriving mid-conversation is noise. So push is
    // resolved on the short quiet period and email on the long one.
    //
    // Shipped 2026-08-26 resolving both from ONE call, which left push waiting
    // the full email delay — not what was agreed.
    $pushPending = te_chat_pending_notifications($pdo, array_merge($opts, [
        'quiet_minutes' => $opts['push_quiet_minutes'] ?? TE_CHAT_NOTIFY_PUSH_QUIET_MINUTES,
    ]));

    $result = ['sent' => 0, 'pushed' => 0, 'in_app' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

    // The mailer is injectable so the dispatcher can be tested without reaching
    // SendGrid. Production passes nothing and gets the real transactional path.
    $mailer = $opts['mailer'] ?? function (array $envelope) use ($pdo): bool {
        $email = (new Email())->forClub($pdo, $envelope['club_id']);
        return (bool) $email->sendChatDigest(
            $envelope['to'],
            $envelope['recipient_name'],
            $envelope['conversation_label'],
            $envelope['sender_names'],
            $envelope['message_count'],
            $envelope['link']
        );
    };

    $pusher = $opts['pusher'] ?? fn(int $userId, array $payload) => te_push_send_to_user($pdo, $userId, $payload, $opts);

    // ── Pass 1: push, on the SHORT window ────────────────────────────────────
    foreach ($pushPending as $item) {
        if (!$item['push_enabled']) {
            continue;
        }

        try {
            $envelope = te_chat_build_envelope($pdo, $item);
            if ($envelope === null) {
                continue;
            }

            $push = $pusher($item['user_id'], [
                'title' => $envelope['conversation_label'],
                'body'  => $envelope['push_body'],
                'url'   => $envelope['push_link'],
                'tag'   => 'chat-' . $item['conversation_id'],
            ]);

            if (($push['delivered'] ?? 0) > 0) {
                te_chat_record_in_app($pdo, $item, $envelope, $opts['now'] ?? null);
                te_chat_mark_notified(
                    $pdo,
                    $item['user_id'],
                    $item['conversation_id'],
                    $item['latest_message_id'],
                    'push',
                    $opts['now'] ?? null
                );
                $result['pushed']++;
            }
            // Nothing delivered — no device, or every endpoint was dead and has
            // just been pruned. Deliberately NOT marked: the email pass picks it
            // up once the LONGER quiet period has elapsed. The fallback must not
            // fire early just because push failed early.
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = sprintf(
                'push user %d conversation %d: %s',
                $item['user_id'] ?? 0,
                $item['conversation_id'] ?? 0,
                $e->getMessage()
            );
            error_log('[ChatNotify] ' . end($result['errors']));
        }
    }

    // ── Pass 2: email, and the in-app last resort, on the FULL window ────────
    //
    // Re-resolved AFTER the push pass so anything just pushed is already marked
    // and no longer reads as owed. That, not an if/else, is what stops one
    // person getting both.
    $pending = te_chat_pending_notifications($pdo, $opts);

    foreach ($pending as $item) {
        // Per item, not per batch. See the warning above — this tick shares a
        // process with every other queue.
        try {
            $envelope = te_chat_build_envelope($pdo, $item);

            if ($envelope === null) {
                $result['skipped']++;
                continue;
            }

            // Nothing can leave the building for this person — no address, or
            // they turned email off. They are still told, in the app, and that
            // CLOSES the item: leaving it open would make the dispatcher
            // re-derive it as owed on every tick forever.
            if (!$item['email_enabled'] || $envelope['to'] === '') {
                te_chat_record_in_app($pdo, $item, $envelope, $opts['now'] ?? null);
                te_chat_mark_notified(
                    $pdo,
                    $item['user_id'],
                    $item['conversation_id'],
                    $item['latest_message_id'],
                    'in_app',
                    $opts['now'] ?? null
                );
                $result['in_app']++;
                continue;
            }

            $ok = $mailer($envelope);

            if ($ok) {
                te_chat_record_in_app($pdo, $item, $envelope, $opts['now'] ?? null);
                te_chat_mark_notified(
                    $pdo,
                    $item['user_id'],
                    $item['conversation_id'],
                    $item['latest_message_id'],
                    'email',
                    $opts['now'] ?? null
                );
                $result['sent']++;
            } else {
                // Deliberately NOT marked as notified — an unsent digest must be
                // retried on the next tick, and the lookback window bounds how
                // long that can go on for.
                $result['failed']++;
                $result['errors'][] = sprintf(
                    'user %d conversation %d: mailer reported failure',
                    $item['user_id'],
                    $item['conversation_id']
                );
            }
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = sprintf(
                'user %d conversation %d: %s',
                $item['user_id'] ?? 0,
                $item['conversation_id'] ?? 0,
                $e->getMessage()
            );
            error_log('[ChatNotify] ' . end($result['errors']));
        }
    }

    return $result;
}

/**
 * Gather everything one digest needs, or null if it cannot be sent.
 *
 * Returning null (rather than throwing) for a missing address is deliberate:
 * plenty of accounts legitimately have no usable email, and that is a skip, not
 * an error worth logging every minute.
 */
function te_chat_build_envelope(PDO $pdo, array $item): ?array
{
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM users WHERE id = ?');
    $stmt->execute([$item['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    // A missing address is NOT fatal here any more: with web push in the mix a
    // person may be perfectly reachable without one. The email branch checks it.
    $address = trim((string) ($user['email'] ?? ''));

    $stmt = $pdo->prepare('SELECT id, type, team_id, club_id FROM conversations WHERE id = ?');
    $stmt->execute([$item['conversation_id']]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($item['message_ids']), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT sender_name FROM chat_messages WHERE id IN ({$placeholders})"
    );
    $stmt->execute($item['message_ids']);
    $senderNames = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_COLUMN),
        fn($n) => trim((string) $n) !== ''
    ));

    $recipientName = trim((string) ($user['first_name'] ?? ''));
    if ($recipientName === '') {
        $recipientName = 'there';
    }

    $count = (int) $item['message_count'];
    $senderPhrase = $senderNames ? (' from ' . $senderNames[0] . (count($senderNames) > 1 ? ' and others' : '')) : '';

    return [
        'to'                 => $address,
        // Deliberately the same shape as the email: sender and count, no message
        // text. A push notification renders on a lock screen, where the content
        // is MORE exposed than an inbox, not less.
        'push_body'          => $count === 1
            ? 'You have 1 new message' . $senderPhrase . '.'
            : "You have {$count} new messages" . $senderPhrase . '.',
        'recipient_name'     => $recipientName,
        'conversation_label' => te_chat_conversation_label($pdo, $conversation, (int) $item['user_id']),
        'sender_names'       => $senderNames,
        'message_count'      => $item['message_count'],
        'club_id'            => $conversation['club_id'] !== null ? (int) $conversation['club_id'] : null,
        'link'               => te_chat_notification_link($pdo, (int) $item['user_id'], $conversation, 'email'),
        'push_link'          => te_chat_notification_link($pdo, (int) $item['user_id'], $conversation, 'push'),
    ];
}

/** What to call this conversation in a subject line. */
function te_chat_conversation_label(PDO $pdo, array $conversation, int $recipientId): string
{
    if (($conversation['type'] ?? '') === 'team' && !empty($conversation['team_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM teams WHERE id = ?');
        $stmt->execute([$conversation['team_id']]);
        $name = $stmt->fetchColumn();
        if ($name) {
            return (string) $name;
        }
        return 'your team';
    }

    if (($conversation['type'] ?? '') === 'direct') {
        // Name the other person, not "Direct message" — in an inbox the useful
        // part of the subject is who it is from.
        $stmt = $pdo->prepare(
            'SELECT display_name FROM conversation_participants
              WHERE conversation_id = ? AND user_id <> ? AND display_name IS NOT NULL
              ORDER BY id LIMIT 1'
        );
        $stmt->execute([$conversation['id'], $recipientId]);
        $name = $stmt->fetchColumn();
        if ($name) {
            return (string) $name;
        }
        return 'your messages';
    }

    return 'your group';
}

/**
 * Where to send them.
 *
 * Staff and families reach chat through two different surfaces — parents have a
 * route (/parent/chat), staff use the ChatWidget which is only mounted on the
 * staff app — so one URL cannot serve both.
 *
 * Standing is read from user_club_access, which CLAUDE.md is explicit is the
 * authoritative source for roles. It is deliberately NOT derived from the
 * guardian-email chain: that comparison is the fragile one this codebase has
 * been repeatedly bitten by, and here a wrong answer only means a slightly wrong
 * landing page, so the simple authoritative table is the right source.
 *
 * A staff link wins for someone holding both, matching lib/JWT.php's role
 * precedence (club_admin > treasurer > coach > volunteer > parent > player) and
 * ParentRedirect's behaviour of leaving staff on the dashboard.
 */
function te_chat_notification_link(PDO $pdo, int $userId, array $conversation, string $channel = 'email'): string
{
    $conversationId = (int) ($conversation['id'] ?? 0);
    $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:3003'), '/');

    $clubId = $conversation['club_id'] ?? null;
    $isStaff = false;

    if ($clubId !== null) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM user_club_access
              WHERE user_id = ? AND club_profile_id = ?
                AND active = TRUE AND revoked_at IS NULL
                AND role IN ('club_admin', 'coach', 'treasurer', 'volunteer')
              LIMIT 1"
        );
        $stmt->execute([$userId, $clubId]);
        $isStaff = (bool) $stmt->fetchColumn();
    }

    // Deep-link to the CONVERSATION, not just the app.
    //
    // Tapping a notification and landing on the dashboard with the chat still
    // closed is barely better than no link at all — the person has to go find
    // the message they were just told about. Staff chat is a widget rather than
    // a route, so it takes a query parameter that ChatWidget reads on load;
    // the parent portal has a real route and takes one too.
    $path = $isStaff ? '/dashboard' : '/parent/chat';

    if ($conversationId <= 0) {
        return $appUrl . $path;
    }

    // `tec` is what makes the click measurable: the app reports it back, which
    // is how we know whether a notification actually brought anyone in. Chat
    // notifications carry no tracking pixel by design (see the header), so this
    // is the only signal — and unlike a pixel it works for PUSH too, and
    // measures a person acting rather than a mail client loading an image.
    //
    // UTMs are along for the ride. Nothing consumes them today — there is no
    // analytics on the site at all, verified 2026-08-27 — but they cost nothing
    // and mean these clicks are attributable from day one if that changes.
    $params = [
        'chat'         => $conversationId,
        'tec'          => $channel,
        'utm_source'   => 'teams-elevated',
        'utm_medium'   => $channel === 'push' ? 'push' : 'email',
        'utm_campaign' => 'chat-notification',
    ];

    return $appUrl . $path . '?' . http_build_query($params);
}

/**
 * Mirror a delivered notification into the in-app centre.
 *
 * Called only when an item is CLOSED, never per attempt — one row per
 * notification. Writing on every tick would stack duplicates for anyone the
 * dispatcher keeps re-deriving as owed.
 *
 * A failure here must not undo a notification that was genuinely delivered, so
 * it is logged and swallowed: the person has already had their push or their
 * email, and throwing would mark the item unsent and send it a second time.
 */
function te_chat_record_in_app(PDO $pdo, array $item, array $envelope, ?string $now = null): void
{
    try {
        te_notify_create(
            $pdo,
            (int) $item['user_id'],
            'chat_message',
            $envelope['conversation_label'],
            $envelope['push_body'],
            [
                'url'             => $envelope['link'],
                'conversation_id' => (int) $item['conversation_id'],
                'message_count'   => (int) $item['message_count'],
            ],
            $now
        );
    } catch (Throwable $e) {
        error_log('[ChatNotify] in-app record failed: ' . $e->getMessage());
    }
}
