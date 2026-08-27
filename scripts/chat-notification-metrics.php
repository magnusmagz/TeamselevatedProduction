<?php
/**
 * How chat notifications are performing. Read-only.
 *
 *   heroku run php scripts/chat-notification-metrics.php -a teamselevated-backend
 *   ... --days=7
 *
 * Chat notifications deliberately bypass EmailSendService, which is where the
 * tracking pixel and link rewriting live, so none of this appears in Email
 * Reporting and there is nothing in Google Analytics either (there is no
 * analytics on the site at all, verified 2026-08-27). This script is the report.
 *
 * ⚠️ CLICK-THROUGH, not opens. Better on purpose: a pixel measures whether an
 * image loaded, which mail clients increasingly block or preload on the reader's
 * behalf, and it cannot see push at all. This counts a person opening the
 * conversation, once per notification.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

$days = 30;
foreach ($argv as $a) {
    if (str_starts_with($a, '--days=')) { $days = max(1, (int) substr($a, 7)); }
}

$pdo = Database::getInstance()->getConnection();
$since = "NOW() - INTERVAL '{$days} days'";

$line = str_repeat('─', 74);
echo "\nChat notifications — last {$days} days\n{$line}\n";

$totals = $pdo->query("
    SELECT COUNT(*) AS sent,
           COUNT(DISTINCT user_id) AS people,
           COUNT(clicked_at) AS clicked
      FROM chat_notification_state
     WHERE last_notified_at > {$since}
")->fetch(PDO::FETCH_ASSOC);

$sent = (int) $totals['sent'];
$clicked = (int) $totals['clicked'];
printf("  sent        %d to %d people\n", $sent, (int) $totals['people']);
printf("  clicked     %d  (%s)\n\n", $clicked, $sent ? round($clicked / $sent * 100, 1) . '%' : 'n/a');

echo "  By channel\n";
printf("    %-8s %-8s %-8s %s\n", 'channel', 'sent', 'clicked', 'rate');
$rows = $pdo->query("
    SELECT last_notified_channel AS ch, COUNT(*) AS sent, COUNT(clicked_at) AS clicked
      FROM chat_notification_state
     WHERE last_notified_at > {$since}
     GROUP BY 1 ORDER BY 2 DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $s = (int) $r['sent'];
    $c = (int) $r['clicked'];
    printf("    %-8s %-8d %-8d %s\n", $r['ch'] ?? '?', $s, $c, $s ? round($c / $s * 100, 1) . '%' : 'n/a');
}

echo "\n  By club\n";
$rows = $pdo->query("
    SELECT COALESCE(cp.name, '(no club)') AS club,
           COUNT(*) AS sent, COUNT(s.clicked_at) AS clicked,
           COUNT(DISTINCT s.user_id) AS people
      FROM chat_notification_state s
      JOIN conversations c ON c.id = s.conversation_id
      LEFT JOIN club_profile cp ON cp.id = c.club_id
     WHERE s.last_notified_at > {$since}
     GROUP BY 1 ORDER BY 2 DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $s = (int) $r['sent'];
    $c = (int) $r['clicked'];
    printf("    %-28s %-6d sent, %-4d clicked (%s), %d people\n",
        substr($r['club'], 0, 28), $s, $c, $s ? round($c / $s * 100, 1) . '%' : 'n/a', (int) $r['people']);
}

// Reach, which is the number that says whether the feature is doing its job at
// all: how many people could be reached instantly rather than by email.
echo "\n  Reach\n";
printf("    push devices registered   %s\n", $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
printf("    people with a device      %s\n", $pdo->query('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions')->fetchColumn());
printf("    opted out of email        %s\n", $pdo->query('SELECT COUNT(*) FROM chat_notification_prefs WHERE email_enabled = FALSE')->fetchColumn());
printf("    opted out of push         %s\n", $pdo->query('SELECT COUNT(*) FROM chat_notification_prefs WHERE push_enabled = FALSE')->fetchColumn());
printf("    conversations muted       %s\n", $pdo->query('SELECT COUNT(*) FROM conversation_participants WHERE muted = TRUE')->fetchColumn());

echo "\n  Moderation alerts to admins\n";
$rows = $pdo->query("
    SELECT kind, COUNT(*) AS n, COUNT(DISTINCT user_id) AS admins
      FROM chat_moderation_alert_state WHERE sent_at > {$since} GROUP BY 1
")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "    none\n";
}
foreach ($rows as $r) {
    printf("    %-14s %-4d to %d admins\n", $r['kind'], (int) $r['n'], (int) $r['admins']);
}

echo "\n{$line}\n";
echo "  Note: click-through only. There is no open tracking on these emails and\n";
echo "  no analytics on the site, so this script is the whole picture.\n\n";
