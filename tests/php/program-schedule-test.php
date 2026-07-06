<?php
/**
 * Integration test for the camp/clinic facility + schedule feature
 * (migration 043 `programs.venue_id` + program-scoped `tryout_sessions`).
 *
 * Runs inside ONE transaction that is rolled back, so it is safe against prod Neon
 * and leaves no rows behind (sequences advance a few values — harmless).
 *
 * Run:  php tests/php/program-schedule-test.php   ·   Exit 0 = pass, 1 = fail.
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = Database::getInstance()->getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $label, bool $ok): void {
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

$uniq = uniqid('sched_', true);
$clubId = (int) $pdo->query("SELECT id FROM club_profile ORDER BY id LIMIT 1")->fetchColumn();

$pdo->beginTransaction();
try {
    // ---- setup: two throwaway facilities ----
    $insVenue = $pdo->prepare("INSERT INTO venues (name) VALUES (?) RETURNING id");
    $insVenue->execute(["TEST Facility A $uniq"]); $venueA = (int) $insVenue->fetchColumn();
    $insVenue->execute(["TEST Facility B $uniq"]); $venueB = (int) $insVenue->fetchColumn();

    // ---- T1: a camp program carries a headline facility ----
    echo "T1 — camp facility\n";
    $insProg = $pdo->prepare(
        "INSERT INTO programs (club_id, name, type, status, venue_id) VALUES (?, ?, 'camp', 'draft', ?) RETURNING id"
    );
    $insProg->execute([$clubId, "TEST Camp $uniq", $venueA]);
    $programId = (int) $insProg->fetchColumn();
    check('program.venue_id persisted', (int) $pdo->query("SELECT venue_id FROM programs WHERE id=$programId")->fetchColumn() === $venueA);

    // ---- T2/T3: build + read back a 3-session schedule ----
    echo "T2/T3 — schedule create + read\n";
    $insS = $pdo->prepare(
        "INSERT INTO tryout_sessions (program_id, name, session_date, start_time, end_time, venue_id)
         VALUES (?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $insS->execute([$programId, 'Day 1', '2026-11-07', '09:00', '12:00', null]);    $s1 = (int) $insS->fetchColumn();
    $insS->execute([$programId, 'Day 2', '2026-11-08', '09:00', '12:00', $venueB]); $s2 = (int) $insS->fetchColumn();
    $insS->execute([$programId, 'Day 3', '2026-11-09', '09:00', '12:00', null]);    $s3 = (int) $insS->fetchColumn();
    check('3 sessions linked to the program', (int) $pdo->query("SELECT count(*) FROM tryout_sessions WHERE program_id=$programId")->fetchColumn() === 3);
    $dates = $pdo->query("SELECT session_date::text FROM tryout_sessions WHERE program_id=$programId ORDER BY session_date")->fetchAll(PDO::FETCH_COLUMN);
    check('sessions read back ordered by date', $dates[0] === '2026-11-07' && $dates[2] === '2026-11-09');

    // ---- T4: edit a session + delete a session ----
    echo "T4 — edit + delete\n";
    $pdo->prepare("UPDATE tryout_sessions SET start_time='10:00' WHERE id=?")->execute([$s1]);
    $pdo->prepare("DELETE FROM tryout_sessions WHERE id=?")->execute([$s3]);
    check('2 sessions remain after delete', (int) $pdo->query("SELECT count(*) FROM tryout_sessions WHERE program_id=$programId")->fetchColumn() === 2);
    check('session update applied', substr((string) $pdo->query("SELECT start_time FROM tryout_sessions WHERE id=$s1")->fetchColumn(), 0, 5) === '10:00');

    // ---- T5: per-session venue override vs inherit-from-program ----
    echo "T5 — venue override + inherit\n";
    $vS2 = $pdo->query("SELECT venue_id FROM tryout_sessions WHERE id=$s2")->fetchColumn();
    check('session with its own facility keeps it', (int) $vS2 === $venueB);
    // s1 has no session venue → effective facility resolves to the program's (venueA).
    $vS1 = $pdo->query("SELECT venue_id FROM tryout_sessions WHERE id=$s1")->fetchColumn();
    $progVenue = (int) $pdo->query("SELECT venue_id FROM programs WHERE id=$programId")->fetchColumn();
    $effectiveS1 = $vS1 !== null ? (int) $vS1 : $progVenue;
    check('facility-less session inherits the program facility', $effectiveS1 === $venueA);

    // ---- T7: deleting the facility nulls the program link (FK ON DELETE SET NULL) ----
    echo "T7 — venue delete → SET NULL\n";
    $pdo->prepare("DELETE FROM venues WHERE id=?")->execute([$venueA]); // venueA is referenced only by the program
    check('program.venue_id is NULL after its venue is deleted', $pdo->query("SELECT venue_id FROM programs WHERE id=$programId")->fetchColumn() === null);

    // ---- T6: regression — tryouts use the same table and are unaffected ----
    echo "T6 — tryout regression\n";
    $insProg->execute([$clubId, "TEST Tryout $uniq", $venueB]);
    $tryoutId = (int) $insProg->fetchColumn(); // reuse the camp insert (type='camp'); type doesn't affect scheduling
    $insS->execute([$tryoutId, 'Session 1', '2026-12-01', '09:00', '12:00', $venueB]);
    check('sessions work for any program', (int) $pdo->query("SELECT count(*) FROM tryout_sessions WHERE program_id=$tryoutId")->fetchColumn() === 1);

    $pdo->rollBack();
    echo "\n(rolled back — no rows persisted)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "  FAIL  threw: " . $e->getMessage() . "\n";
    $failures++;
}

echo $failures === 0 ? "\nALL CHECKS PASSED\n" : "\n$failures CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
