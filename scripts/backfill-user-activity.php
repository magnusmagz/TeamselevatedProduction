<?php
/**
 * Seed user_activity_daily from the evidence that already exists, so the first
 * month of usage metrics is not blank. Idempotent (upsert). Read-only on every
 * other table.
 *
 *   heroku run --no-tty -a teamselevated-backend php scripts/backfill-user-activity.php --days=90
 *
 * Evidence, each attributed to the user's CURRENT buckets (history is not
 * re-bucketed — a role granted last week did not exist last month, and there is
 * no record of when it did):
 *   - audit_log rows with a user_id (every audited action is a person doing
 *     something signed in — login_success included)
 *   - chat_messages.sender_id / created_at
 *   - conversation_participants.last_read_at (latest read only; older reads
 *     are not kept, which is exactly why the live ping exists)
 *   - users.last_login_at
 *
 * ⚠️ This is a FLOOR. A parent who only read the calendar in the last 90 days
 * left none of these traces. Rows carry surface = 'backfill' so a chart can
 * tell seeded history from measured history.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/usage_activity.php';

$days = 90;
foreach ($argv as $a) {
    if (str_starts_with($a, '--days=')) { $days = max(1, min(365, (int)substr($a, 7))); }
}
$pdo = Database::getInstance()->getConnection();
if (!te_usage_table_present($pdo)) {
    fwrite(STDERR, "user_activity_daily does not exist — apply migration 103 first\n");
    exit(1);
}
$since = gmdate('Y-m-d', time() - $days * 86400);

$sql = "
    SELECT user_id, activity_date FROM (
        SELECT al.user_id AS user_id, al.created_at::date AS activity_date
          FROM audit_log al WHERE al.user_id IS NOT NULL AND al.created_at >= :s1
        UNION
        SELECT m.sender_id, m.created_at::date FROM chat_messages m
         WHERE m.sender_id IS NOT NULL AND m.created_at >= :s2
        UNION
        SELECT cp.user_id, cp.last_read_at::date FROM conversation_participants cp
         WHERE cp.last_read_at IS NOT NULL AND cp.last_read_at >= :s3
        UNION
        SELECT u.id, u.last_login_at::date FROM users u
         WHERE u.last_login_at IS NOT NULL AND u.last_login_at >= :s4
    ) ev
    ORDER BY user_id, activity_date
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':s1' => $since, ':s2' => $since, ':s3' => $since, ':s4' => $since]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$people = 0; $rows = 0; $noRole = 0; $lastUser = null; $bucketsCache = [];
foreach ($events as $ev) {
    $uid = (int)$ev['user_id'];
    if ($uid !== $lastUser) {
        $people++;
        $lastUser = $uid;
    }
    $written = te_usage_record($pdo, $uid, substr($ev['activity_date'], 0, 10), 'backfill');
    if (!$written) { $noRole++; continue; }
    $rows += count($written);
}
echo "Backfilled {$days} days: {$people} people, " . count($events) . " person-days of evidence, {$rows} (user, club, role, day) rows upserted, {$noRole} person-days skipped (account holds no role).\n";
