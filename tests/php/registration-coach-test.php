<?php
/**
 * Integration test for te_create_coach_registration() — the coach/adult program
 * registration path (migration 044 + lib/registration_writes.php).
 *
 * Guards the invariants that make coaches "not users":
 *   T1  coach registration → athlete_id/guardian_id NULL, registrant_email set,
 *       and NO users row and NO guardians row created
 *   T2  same coach + same program again → already_registered (one row only)
 *   T3  same coach + a different program → allowed
 *   T5  coach email that already exists in users → users table untouched
 *   T6  missing required coach fields → throws, nothing inserted
 *
 * One transaction, rolled back. Safe on prod Neon. Run:
 *   php tests/php/registration-coach-test.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/registration_writes.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

$uniq = uniqid('coach_', true);
$clubId = (int) $pdo->query("SELECT id FROM club_profile ORDER BY id LIMIT 1")->fetchColumn();

$newProgram = function (string $name) use ($pdo, $clubId): array {
    $stmt = $pdo->prepare(
        "INSERT INTO programs (club_id, name, type, status, participant_type)
         VALUES (?, ?, 'clinic', 'published', 'coach') RETURNING id"
    );
    $stmt->execute([$clubId, $name]);
    return ['id' => (int) $stmt->fetchColumn()];
};

$countUsers = fn() => (int) $pdo->query("SELECT count(*) FROM users")->fetchColumn();
$countGuardians = fn() => (int) $pdo->query("SELECT count(*) FROM guardians")->fetchColumn();

$pdo->beginTransaction();
try {
    $program = $newProgram("TEST Coaching Course $uniq");
    $email = "coach+$uniq@example.invalid";
    $form = ['coach_first' => 'Sam', 'coach_last' => 'Coach', 'coach_email' => $email, 'coach_phone' => '555-0100'];

    $usersBefore = $countUsers();
    $guardiansBefore = $countGuardians();

    // ---- T1: coach registration is athlete/guardian/user-free ----
    echo "T1 — coach happy path\n";
    $r1 = te_create_coach_registration($pdo, $program, $form);
    check('registration created', !$r1['already_registered'] && $r1['registration_id'] > 0);
    $row = $pdo->query("SELECT athlete_id, guardian_id, registrant_email FROM registrations WHERE id={$r1['registration_id']}")->fetch(PDO::FETCH_ASSOC);
    check('athlete_id is NULL', $row['athlete_id'] === null);
    check('guardian_id is NULL', $row['guardian_id'] === null);
    check('registrant_email stored', $row['registrant_email'] === $email);
    check('NO users row created', $countUsers() === $usersBefore);
    check('NO guardians row created', $countGuardians() === $guardiansBefore);

    // ---- T2: dedup within the same program ----
    echo "T2 — duplicate in same program blocked\n";
    $r2 = te_create_coach_registration($pdo, $program, $form);
    check('second submit → already_registered', $r2['already_registered'] === true);
    check('points at the first registration', $r2['registration_id'] === $r1['registration_id']);
    $dupCount = (int) $pdo->query("SELECT count(*) FROM registrations WHERE program_id={$program['id']} AND lower(registrant_email)=lower('$email')")->fetchColumn();
    check('still exactly one registration', $dupCount === 1);

    // ---- T3: same coach can register for a different program ----
    echo "T3 — different program allowed\n";
    $program2 = $newProgram("TEST Coaching Course B $uniq");
    $r3 = te_create_coach_registration($pdo, $program2, $form);
    check('registers for the new program', !$r3['already_registered'] && $r3['registration_id'] !== $r1['registration_id']);

    // ---- T5: coach email already used by a users row → users untouched ----
    echo "T5 — no-login invariant\n";
    $existingUserEmail = $pdo->query("SELECT email FROM users WHERE email IS NOT NULL LIMIT 1")->fetchColumn();
    $program3 = $newProgram("TEST Coaching Course C $uniq");
    $usersBefore2 = $countUsers();
    $r5 = te_create_coach_registration($pdo, $program3, [
        'coach_first' => 'Reuse', 'coach_last' => 'Email', 'coach_email' => $existingUserEmail, 'coach_phone' => '555-0199',
    ]);
    check('registration succeeds with an existing user email', !$r5['already_registered'] && $r5['registration_id'] > 0);
    check('users table untouched (no create/link)', $countUsers() === $usersBefore2);

    // ---- T6: validation ----
    echo "T6 — missing required fields throws\n";
    try {
        te_create_coach_registration($pdo, $program, ['coach_first' => 'NoEmail']);
        check('should have thrown on missing email', false);
    } catch (InvalidArgumentException $e) {
        check('throws on missing coach fields', true);
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
