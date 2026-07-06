<?php
/**
 * Integration test for te_create_athlete() — the athlete-create path in
 * legacy/athletes-gateway.php.
 *
 * Guards the two production crashes fixed in migration 041 + lib/athlete_writes.php:
 *   A. new email  -> creates a linked player user, athlete id is independent of user id
 *   B. dup email  -> REUSES the existing user (no users_email_key crash)
 *   C. link to a user whose id equals an existing athlete PK -> NO athletes_pkey crash
 *      (this is exactly the screenshot-2 failure)
 *
 * Everything runs inside ONE transaction that is rolled back, so it is safe to run
 * against production Neon and leaves no rows behind. (Sequences advance a few values;
 * that is by design and harmless.)
 *
 * Run:  php tests/php/athlete-create-test.php
 * Exit: 0 = all passed, 1 = a check failed.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/athlete_writes.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

$uniq = uniqid('te_test_', true);
$emailA = "a+$uniq@example.invalid";

$pdo->beginTransaction();
try {
    // ---- A: new email creates a linked user; athlete id decoupled from user id ----
    echo "Scenario A — new email\n";
    $a = te_create_athlete($pdo, ['first_name' => 'Test', 'last_name' => 'AthleteA', 'email' => $emailA]);
    check('athlete_id generated', $a['athlete_id'] > 0);
    check('user_id generated', $a['user_id'] > 0);
    check('athlete id != user id (decoupled)', $a['athlete_id'] !== $a['user_id']);
    $row = $pdo->query("SELECT user_id FROM athletes WHERE id = {$a['athlete_id']}")->fetch(PDO::FETCH_ASSOC);
    check('athlete.user_id links to the user', (int)$row['user_id'] === $a['user_id']);
    $ucount = $pdo->prepare('SELECT count(*) FROM users WHERE email = ?');
    $ucount->execute([$emailA]);
    check('exactly one user for the email', (int)$ucount->fetchColumn() === 1);

    // ---- B: reusing the same email must NOT create a second user (users_email_key) ----
    echo "Scenario B — duplicate email reused\n";
    $b = te_create_athlete($pdo, ['first_name' => 'Test', 'last_name' => 'AthleteB', 'email' => $emailA]);
    check('same email reuses the same user', $b['user_id'] === $a['user_id']);
    check('still exactly one user for the email', (function () use ($pdo, $emailA) {
        $s = $pdo->prepare('SELECT count(*) FROM users WHERE email = ?');
        $s->execute([$emailA]);
        return (int)$s->fetchColumn() === 1;
    })());
    check('second athlete is a distinct row', $b['athlete_id'] !== $a['athlete_id']);

    // ---- C: link to a user whose id == an existing athlete PK must NOT collide ----
    echo "Scenario C — regression: user id already used as an athlete PK\n";
    $coupled = $pdo->query(
        "SELECT a.id AS athlete_id, u.email
           FROM athletes a JOIN users u ON u.id = a.id AND u.role = 'player'
          WHERE u.email IS NOT NULL LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$coupled) {
        echo "  SKIP  no historically-coupled athlete found (migration 041 backfill empty)\n";
    } else {
        // Under the OLD code this INSERTed athletes.id = coupled user id -> duplicate PK.
        $c = te_create_athlete($pdo, ['first_name' => 'Test', 'last_name' => 'AthleteC', 'email' => $coupled['email']]);
        check('create succeeded (no athletes_pkey collision)', $c['athlete_id'] > 0);
        check('reused the existing user', $c['user_id'] === (int)$coupled['athlete_id']);
        check('new athlete got a fresh id, not the user id', $c['athlete_id'] !== (int)$coupled['athlete_id']);
    }

    $pdo->rollBack();
    echo "\n(rolled back — no rows persisted)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "  FAIL  threw: " . $e->getMessage() . "\n";
    $failures++;
}

echo $failures === 0 ? "\nALL CHECKS PASSED\n" : "\n$failures CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
