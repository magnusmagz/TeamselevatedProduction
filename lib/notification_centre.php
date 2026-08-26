<?php
/**
 * The in-app notification centre.
 *
 * Phase 5 of docs/chat-notifications-scope.md. Writes and reads the
 * `notifications` table, which has existed in Neon all along with nothing
 * reading or writing it.
 *
 * WHAT THIS IS FOR
 * Push and email both leave the building and can fail for reasons nobody here
 * can see — a phone that is off, a spam filter, an address that was never
 * right. The centre is the copy that does not: it is the record of what the
 * product tried to tell someone, readable the next time they open the app.
 *
 * ⚠️ **One row per closed notification, never one per attempt.** The dispatcher
 * writes here at the moment an item is closed and records which channel carried
 * it. Writing on every tick instead would stack duplicates for anyone the
 * dispatcher keeps re-deriving as owed.
 */

require_once __DIR__ . '/../config/database.php';

/** Notification types. Kept narrow deliberately — a free-text type is unqueryable. */
const TE_NOTIFY_TYPES = ['chat_message', 'chat_flag', 'chat_flag_digest'];

/**
 * Record something worth telling this person.
 *
 * @param array $data Structured payload (a link, ids). Rendered by the client,
 *                    never interpolated into the message server-side.
 */
function te_notify_create(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $message,
    array $data = [],
    ?string $at = null
): void {
    if (!in_array($type, TE_NOTIFY_TYPES, true)) {
        throw new InvalidArgumentException("Unknown notification type: {$type}");
    }

    $when = $at ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message, data, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $type, $title, $message, json_encode($data), $when]);
}

/**
 * This person's notifications, newest first.
 *
 * Always scoped to one user id, which the caller must take from a verified
 * token. There is deliberately no "all users" branch — nothing legitimate needs
 * one, and its absence is what stops this becoming a way to read someone else's.
 */
function te_notify_list(PDO $pdo, int $userId, array $opts = []): array
{
    $limit = max(1, min(100, (int) ($opts['limit'] ?? 30)));
    $unreadOnly = !empty($opts['unread_only']);

    $sql = 'SELECT id, type, title, message, data, read_at, created_at
              FROM notifications
             WHERE user_id = ?'
         . ($unreadOnly ? ' AND read_at IS NULL' : '')
         . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);

    return array_map(function (array $row) {
        // Decoded here so every caller does not have to, and so a malformed
        // payload degrades to an empty object rather than breaking the list.
        $row['data'] = json_decode((string) $row['data'], true) ?: [];
        $row['read'] = $row['read_at'] !== null;
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** How many are waiting. Drives the bell. */
function te_notify_unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Mark notifications read.
 *
 * Scoped by user_id as well as by id, so passing someone else's notification id
 * silently affects nothing rather than marking their unread as read.
 *
 * @param int[]|null $ids null marks everything unread for this user.
 */
function te_notify_mark_read(PDO $pdo, int $userId, ?array $ids = null, ?string $at = null): int
{
    $when = $at ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($ids === null) {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$when, $userId]);
        return $stmt->rowCount();
    }

    $clean = [];
    foreach ($ids as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $clean[] = $n;
        }
    }
    if (!$clean) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $stmt = $pdo->prepare(
        "UPDATE notifications SET read_at = ?
          WHERE user_id = ? AND read_at IS NULL AND id IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$when, $userId], $clean));
    return $stmt->rowCount();
}
