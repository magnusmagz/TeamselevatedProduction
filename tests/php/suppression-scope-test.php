<?php
/**
 * Tests te_email_suppressed (lib/suppression.php) — the unsubscribe-SCOPE fix.
 *
 * The bug: any suppression row blocked all of a club's email, so unsubscribing
 * from one team silently stopped all club email (CAN-SPAM/UX). The fix scopes it.
 *
 * One transaction, rolled back. Safe on prod Neon.
 * Run: php tests/php/suppression-scope-test.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/suppression.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

$uniq = uniqid('supp_', true);
$club = (int) $pdo->query("SELECT id FROM club_profile ORDER BY id LIMIT 1")->fetchColumn();
$teams = $pdo->query("SELECT id FROM teams WHERE deleted_at IS NULL ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($teams) < 2) { echo "need >=2 teams to test\n"; exit(1); }
[$teamA, $teamB] = array_map('intval', $teams);

$emailTeam  = "team+$uniq@example.invalid";
$emailClub  = "club+$uniq@example.invalid";
$emailNone  = "none+$uniq@example.invalid";

// reason CHECK allows unsubscribe_all/unsubscribe_team/... ; scope CHECK allows club|team only.
$ins = $pdo->prepare(
    "INSERT INTO email_suppressions (club_profile_id, email, channel, reason, scope, team_id)
     VALUES (?, ?, 'email', ?, ?, ?)"
);

$pdo->beginTransaction();
try {
    $ins->execute([$club, $emailTeam, 'unsubscribe_team', 'team', $teamA]); // opted out of team A only
    $ins->execute([$club, $emailClub, 'unsubscribe_all',  'club', null]);   // opted out club-wide

    echo "Team-scoped opt-out (from team A):\n";
    check('does NOT block a club-wide send (the bug)', te_email_suppressed($pdo, $emailTeam, $club, []) === false);
    check('BLOCKS a send targeting team A',            te_email_suppressed($pdo, $emailTeam, $club, [$teamA]) === true);
    check('does NOT block a send targeting only team B', te_email_suppressed($pdo, $emailTeam, $club, [$teamB]) === false);
    check('BLOCKS a broadcast that includes team A',   te_email_suppressed($pdo, $emailTeam, $club, [$teamA, $teamB]) === true);

    echo "Club-wide opt-out:\n";
    check('BLOCKS a club-wide send', te_email_suppressed($pdo, $emailClub, $club, []) === true);
    check('BLOCKS a team send too',  te_email_suppressed($pdo, $emailClub, $club, [$teamB]) === true);

    echo "No opt-out:\n";
    check('never blocked', te_email_suppressed($pdo, $emailNone, $club, [$teamA]) === false);

    $pdo->rollBack();
    echo "\n(rolled back — no rows persisted)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "  FAIL  threw: " . $e->getMessage() . "\n";
    $failures++;
}

echo $failures === 0 ? "\nALL CHECKS PASSED\n" : "\n$failures CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
