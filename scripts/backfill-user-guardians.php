<?php
/**
 * Backfill user_guardians from the email match that identity currently depends on.
 *
 *   php scripts/backfill-user-guardians.php            # dry run, prints the report
 *   php scripts/backfill-user-guardians.php --apply    # writes
 *   php scripts/backfill-user-guardians.php --apply --actor=<users.id>
 *
 * Plan and rationale: docs/user-guardians-identity-plan.md
 *
 * Two rules, and the second one is the point:
 *
 *   1. An account matching EXACTLY ONE guardian by email is linked. No judgement.
 *   2. An account matching MORE THAN ONE is HELD and printed. Six accounts share an
 *      address, and one of them (eli@teamselevated.com) is a staff address sitting on
 *      four unrelated children. Surname difference does not separate that from a real
 *      household — Carmen Haej / Carmen Hawk also spans two surnames and is one family.
 *      Any rule that decides automatically will eventually guess wrong about a child,
 *      and the resulting row would be a durable, audited assertion rather than today's
 *      accident that disappears the moment someone corrects an email.
 *
 * Holding them costs nothing: the email fallback stays live through phase 3, so those
 * accounts keep behaving exactly as they do today until a human decides.
 *
 * Idempotent — ON CONFLICT DO NOTHING. Re-run it immediately before the email fallback
 * is retired, to catch anyone who accepted an invite in the meantime.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/db_actor.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$opts  = getopt('', ['apply', 'actor::']);
$apply = array_key_exists('apply', $opts);
$actor = isset($opts['actor']) ? (int) $opts['actor'] : null;

$pdo = Database::getInstance()->getConnection();

if (to_regclass_missing($pdo)) {
    fwrite(STDERR, "user_guardians does not exist — apply migration 072 first.\n");
    exit(1);
}

function to_regclass_missing(PDO $pdo): bool {
    return $pdo->query("SELECT to_regclass('user_guardians') IS NULL")->fetchColumn() ? true : false;
}

/** Every (user, guardian) pair the email match yields today. */
const MATCH_SQL = "
    SELECT u.id AS user_id, u.email AS user_email,
           u.first_name || ' ' || u.last_name AS user_name,
           g.id AS guardian_id,
           g.first_name || ' ' || g.last_name AS guardian_name
      FROM users u
      JOIN guardians g ON LOWER(g.email) = LOWER(u.email)
     WHERE COALESCE(TRIM(g.email), '') <> ''
     ORDER BY u.id, g.id";

$pairs = $pdo->query(MATCH_SQL)->fetchAll(PDO::FETCH_ASSOC);

$byUser = [];
foreach ($pairs as $p) {
    $byUser[(int) $p['user_id']][] = $p;
}

$link = [];
$held = [];
foreach ($byUser as $userId => $rows) {
    if (count($rows) === 1) {
        $link[] = $rows[0];
    } else {
        $held[$userId] = $rows;
    }
}

/** Athlete ids an account reaches today, via the email match. */
function athletesViaEmail(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT ag.athlete_id
          FROM users u
          JOIN guardians g ON LOWER(g.email) = LOWER(u.email)
          JOIN athlete_guardians ag ON ag.guardian_id = g.id
         WHERE u.id = ?");
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Athlete ids an account would reach through the link rows this run writes. */
function athletesViaLinks(PDO $pdo, array $guardianIds): array {
    if (!$guardianIds) return [];
    $ph = implode(',', array_fill(0, count($guardianIds), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT athlete_id FROM athlete_guardians WHERE guardian_id IN ($ph)");
    $stmt->execute($guardianIds);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

echo "== Backfill user_guardians " . ($apply ? "(APPLYING)" : "(dry run)") . " ==\n\n";

// ── Acceptance test: per-user athlete-set equality, not row count ────────────
// The whole promise of phase 1 is "changes no behaviour". For a linked account that
// means the athletes reachable through its link rows must equal the athletes reachable
// through the email match today. A row count cannot show that.
$mismatch = [];
foreach ($link as $r) {
    $viaEmail = athletesViaEmail($pdo, (int) $r['user_id']);
    $viaLink  = athletesViaLinks($pdo, [(int) $r['guardian_id']]);
    sort($viaEmail); sort($viaLink);
    if ($viaEmail !== $viaLink) {
        $mismatch[] = ['user' => $r, 'email' => $viaEmail, 'link' => $viaLink];
    }
}

printf("Accounts matching exactly one guardian : %d  (will link)\n", count($link));
printf("Accounts matching several guardians    : %d  (HELD — human decision)\n", count($held));
printf("Athlete-set mismatches among linked    : %d\n\n", count($mismatch));

if ($mismatch) {
    echo "!! ATHLETE SET WOULD CHANGE — investigate before applying:\n";
    foreach ($mismatch as $m) {
        printf("   user %s (%s): email=[%s] link=[%s]\n",
            $m['user']['user_id'], $m['user']['user_email'],
            implode(',', $m['email']), implode(',', $m['link']));
    }
    echo "\n";
}

if ($held) {
    echo "HELD for review — shared addresses, not linked by this run:\n";
    foreach ($held as $userId => $rows) {
        printf("   user %-4s %-28s -> %s\n", $userId, $rows[0]['user_email'],
            implode(' | ', array_map(
                fn($r) => $r['guardian_name'] . ' (g' . $r['guardian_id'] . ')', $rows)));
    }
    echo "   These keep working unchanged via the email fallback until resolved.\n\n";
}

// Accounts holding a parent role that no guardian row matches — they are already
// broken today and this run does not change that; listing them is the point.
$orphans = $pdo->query("
    SELECT u.id, u.first_name || ' ' || u.last_name AS name, u.email
      FROM users u
      JOIN user_club_access c ON c.user_id = u.id AND c.role = 'parent'
                             AND c.active AND c.revoked_at IS NULL
     WHERE NOT EXISTS (SELECT 1 FROM guardians g WHERE LOWER(g.email) = LOWER(u.email))
     ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);

if ($orphans) {
    echo "Parent-role accounts with NO guardian row (already broken, unchanged here):\n";
    foreach ($orphans as $o) {
        printf("   user %-4s %-22s %s\n", $o['id'], $o['name'], $o['email']);
    }
    echo "\n";
}

if (!$apply) {
    echo "Dry run — nothing written. Re-run with --apply.\n";
    exit(count($mismatch) ? 1 : 0);
}

if ($mismatch) {
    fwrite(STDERR, "Refusing to apply: the athlete set would change for " . count($mismatch) . " account(s).\n");
    exit(1);
}

// Attribute the run, so 173 audit rows read as one operator action rather than 173
// unexplained out-of-band changes — which is the signal migration 070/072 exists for.
te_db_set_actor($pdo, $actor);

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("
        INSERT INTO user_guardians (user_id, guardian_id, source, confidence, linked_by)
        VALUES (?, ?, 'backfill_email', 'exact', ?)
        ON CONFLICT (user_id, guardian_id) DO NOTHING");

    $written = 0;
    foreach ($link as $r) {
        $ins->execute([(int) $r['user_id'], (int) $r['guardian_id'], $actor]);
        $written += $ins->rowCount();
    }
    $pdo->commit();
    echo "Wrote {$written} link rows (" . (count($link) - $written) . " already present).\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ROLLED BACK: " . $e->getMessage() . "\n");
    exit(1);
}
