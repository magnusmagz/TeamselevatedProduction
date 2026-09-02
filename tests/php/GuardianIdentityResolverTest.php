<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/guardian_identity.php';

/**
 * One answer to "which guardian rows belong to this account" — lib/guardian_identity.php.
 *
 * Phase 2 of docs/user-guardians-identity-plan.md. The resolver reads `user_guardians`
 * (migration 072) UNION the old `users.email = guardians.email` comparison, which makes it
 * STRICTLY WIDER than the ten hand-written copies it replaced: every family reachable
 * before is still reachable, plus the ones whose two addresses have drifted apart.
 *
 * The case that was broken, confirmed against the pre-conversion code before this file was
 * written: an account linked in `user_guardians` whose `users.email` differs from its
 * `guardians.email` resolved to NOTHING. `AthleteScope::userCanAccessAthlete` answered
 * false for that parent's own child, and financial-permissions' guardian query returned 0
 * rows, which the parent portal renders as "no athletes are registered to you". That is
 * Allix Boyce (invited @yahoo account, @gmail guardian row) and it is why the link table
 * exists.
 *
 * ⚠️ Do not narrow this to the table alone. 194 guardian emails have no account yet, so
 * the fallback is what carries every family who has not been linked. Phase 4 deletes it,
 * after te_guardian_match_source() has shown the divergence is zero.
 */
class GuardianIdentityResolverTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Column names mirror tests/fixtures/production-schema.json.
        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER,
                source TEXT, confidence TEXT, linked_by INTEGER, created_at TEXT
            );
        ");

        $this->pdo->exec("INSERT INTO users (id, email, first_name, last_name) VALUES
            (1, 'allix@gmail.com',        'Allix', 'Boyce'),   -- linked; address drifted
            (2, 'emilygovier0@gmail.com', 'Emily', 'Govier'),  -- email match, capitalised on the guardian row
            (3, 'both@family.test',       'Both',  'Ways'),    -- linked AND matching by email
            (4, 'coach@club.test',        'Coach', 'Only'),    -- staff, no guardian row anywhere
            (5, '',                       'Blank', 'Address')  -- pathological: no address at all
        ");

        $this->pdo->exec("INSERT INTO guardians (id, email, first_name, last_name) VALUES
            (10, 'allix@yahoo.com',        'Allix', 'Boyce'),
            (11, 'Emilygovier0@gmail.com', 'Emily', 'Govier'),
            (12, 'both@family.test',       'Both',  'Ways'),
            (13, '',                       'Juan',  'Rocha'),
            (14, '',                       'Juan',  'Coca')
        ");

        $this->pdo->exec("INSERT INTO user_guardians (id, user_id, guardian_id, source, confidence) VALUES
            (1, 1, 10, 'admin_link',     'manual'),
            (2, 3, 12, 'backfill_email', 'exact')
        ");

        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name) VALUES
            (100, 'Kid',   'Boyce'),
            (101, 'Child', 'Govier'),
            (102, 'Other', 'Family')
        ");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 100, 10),
            (2, 101, 11)
        ");
    }

    // ---------------------------------------------------------------- (a) the broken case

    /**
     * The whole reason for the link table: the account's address and the guardian row's
     * address disagree. Before this resolver existed, this user reached nothing.
     */
    public function testALinkedAccountResolvesEvenWhenTheEmailsDiffer(): void
    {
        $this->assertSame([10], te_guardian_ids_for_user($this->pdo, 1));
        $this->assertSame([100], te_athlete_ids_for_user($this->pdo, 1));
        $this->assertTrue(te_user_is_guardian_of_athlete($this->pdo, 1, 100));
    }

    /** The old comparison, run against the same fixture, to show what it answers. */
    public function testTheEmailComparisonAloneStillFindsNothingForThatAccount(): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.id FROM guardians g JOIN users u ON u.id = ?
              WHERE LOWER(g.email) = LOWER(u.email)'
        );
        $stmt->execute([1]);

        $this->assertSame(
            [],
            $stmt->fetchAll(PDO::FETCH_COLUMN),
            'If this ever returns a row the fixture has stopped modelling the bug.'
        );
    }

    // ------------------------------------------------------------ (b) nothing is lost

    public function testAnEmailOnlyMatchStillResolves(): void
    {
        $this->assertSame([11], te_guardian_ids_for_user($this->pdo, 2));
        $this->assertSame([101], te_athlete_ids_for_user($this->pdo, 2));
        $this->assertTrue(te_user_is_guardian_of_athlete($this->pdo, 2, 101));
    }

    /**
     * Widening cannot mean "everyone". A guardian of somebody else's child stays out.
     */
    public function testAResolvedGuardianDoesNotReachAnotherFamilysAthlete(): void
    {
        $this->assertFalse(te_user_is_guardian_of_athlete($this->pdo, 1, 101));
        $this->assertFalse(te_user_is_guardian_of_athlete($this->pdo, 2, 100));
        $this->assertNotContains(102, te_athlete_ids_for_user($this->pdo, 1));
    }

    // -------------------------------------------------------------- (c) the union dedupes

    public function testAGuardianFoundByBothBranchesAppearsOnce(): void
    {
        $this->assertSame([12], te_guardian_ids_for_user($this->pdo, 3));

        $source = te_guardian_match_source($this->pdo, 3);
        $this->assertSame([12], $source['link']);
        $this->assertSame([12], $source['email']);
    }

    /**
     * te_guardian_match_source is what phase 4 reads: an id in `email` and not in `link`
     * is a family who would lose access the day the fallback is deleted.
     */
    public function testMatchSourceSeparatesTheTwoBranches(): void
    {
        $linked = te_guardian_match_source($this->pdo, 1);
        $this->assertSame([10], $linked['link']);
        $this->assertSame([], $linked['email'], 'the drifted address matches nothing by email');

        $emailOnly = te_guardian_match_source($this->pdo, 2);
        $this->assertSame([], $emailOnly['link']);
        $this->assertSame(
            [11],
            $emailOnly['email'],
            'email-only: this account still depends on the fallback and must be linked before phase 4'
        );
    }

    // ------------------------------------------------------------- (d) case-insensitive

    public function testTheEmailBranchIsCaseInsensitive(): void
    {
        // users.email 'emilygovier0@gmail.com' vs guardians.email 'Emilygovier0@gmail.com'.
        // One capital letter, and Postgres `=` is case-sensitive on text.
        $this->assertSame([11], te_guardian_ids_for_user($this->pdo, 2));
        $this->assertSame([11], te_guardian_ids_for_email($this->pdo, 'EMILYGOVIER0@GMAIL.COM'));
    }

    // ------------------------------------------------------- (e) an account with no family

    public function testAStaffAccountWithNoGuardianRowResolvesToNothing(): void
    {
        $this->assertSame([], te_guardian_ids_for_user($this->pdo, 4));
        $this->assertSame([], te_athlete_ids_for_user($this->pdo, 4));
        $this->assertFalse(te_user_is_guardian_of_athlete($this->pdo, 4, 100));
        $this->assertSame(['link' => [], 'email' => []], te_guardian_match_source($this->pdo, 4));
    }

    public function testAnUnknownOrMissingUserResolvesToNothing(): void
    {
        $this->assertSame([], te_guardian_ids_for_user($this->pdo, 999));
        $this->assertSame([], te_guardian_ids_for_user($this->pdo, 0));
        $this->assertFalse(te_user_is_guardian_of_athlete($this->pdo, 0, 100));
    }

    /**
     * `guardians.email` is NOT NULL and 24 production rows hold `''`. In SQL `'' = ''` is
     * true, so an account with no address would otherwise collapse into every one of them —
     * Juan Rocha and Juan Coca are both live with no email. The email branch is guarded on
     * the USER's address, which cannot narrow anything for a real account.
     */
    public function testABlankAddressMatchesNoGuardianAtAll(): void
    {
        $this->assertSame([], te_guardian_ids_for_user($this->pdo, 5));
        $this->assertSame([], te_guardian_ids_for_email($this->pdo, ''));
        $this->assertSame([], te_guardian_ids_for_email($this->pdo, '   '));
    }

    // -------------------------------------------------------- resolving from a bare address

    /**
     * Sign-up has no account yet (sibling detection runs before one exists), so the
     * address is resolved directly — and widened through any account holding it.
     */
    public function testAnAddressResolvesGuardiansDirectlyAndThroughALinkedAccount(): void
    {
        $this->assertSame([12], te_guardian_ids_for_email($this->pdo, 'both@family.test'));

        // allix@gmail.com is on no guardian row; it reaches g10 only via the link.
        $this->assertSame([10], te_guardian_ids_for_email($this->pdo, 'allix@gmail.com'));
    }

    // ------------------------------------------------------------------ the IN-clause guard

    /**
     * `IN ()` is a syntax error in Postgres, not an empty result. Every converted site
     * either returns early on an empty list or goes through this helper.
     */
    public function testAnEmptyIdListBecomes1Equals0AndNeverAnEmptyIn(): void
    {
        $clause = te_guardian_ids_in_clause('ag.guardian_id', []);
        $this->assertSame('1=0', $clause['sql']);
        $this->assertSame([], $clause['params']);
        $this->assertStringNotContainsString('IN (', $clause['sql']);

        // And it has to survive a real prepare/execute, not just look right.
        $stmt = $this->pdo->prepare("SELECT ag.athlete_id FROM athlete_guardians ag WHERE {$clause['sql']}");
        $stmt->execute($clause['params']);
        $this->assertSame([], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAPopulatedIdListBindsThroughARealStatement(): void
    {
        $clause = te_guardian_ids_in_clause('ag.guardian_id', [10, 11, 10]);
        $stmt = $this->pdo->prepare("SELECT DISTINCT ag.athlete_id FROM athlete_guardians ag WHERE {$clause['sql']} ORDER BY ag.athlete_id");
        $stmt->execute($clause['params']);

        $this->assertSame([100, 101], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    // --------------------------------------------------------------------------- the scan

    /**
     * Files converted in phase 2. The scan below expects ZERO hand-written
     * account-to-guardian email comparisons in these — the bug was never in the predicate,
     * it was in how many copies of it there were.
     */
    private const CONVERTED = [
        'api/financial-permissions.php',
        'api/invoices.php',
        'api/recipient-search-gateway.php',
        'api/calendar-events-gateway.php',
        'api/sibling-discount.php',
        'api/consent.php',
        'api/documents-gateway.php',
        'lib/AthleteScope.php',
    ];

    /**
     * Deliberately NOT converted in this slice, with the reason. Delete an entry when the
     * file moves to the resolver; never add one to silence a new finding.
     */
    private const DEFERRED = [
        'lib/chat_notification_scope.php' =>
            'Its audience must mirror chat-server/lib/team_scope.js exactly, and the chat '
            . 'server is a separate subtree deploy still on the email match (phase 4.1b). '
            . 'Widening this side first would email someone a link to a 403.',
        'lib/background_check.php' =>
            'A child-safety gate, not an access scope: it reads a volunteer\'s background '
            . 'check off their guardian row. A household link (confidence=household) would '
            . 'let one parent\'s clearance answer for the other, so widening it is a '
            . 'product decision rather than a mechanical conversion.',
    ];

    // Two more files consume the same identity notion and are NOT converted here, but the
    // scan cannot see them because they compare users.email against a guardian's address
    // as a bound value rather than column-to-column:
    //   lib/portal_status.php — Crew page portal state. Moving it flips visible rows for
    //     staff (plan §3), so it ships with that change announced, not inside a sweep.
    //   lib/ParentInvite.php  — invite-time identity repair. Phase 3 writes the link at
    //     accept; converting the lookup first would change which account gets reclaimed.
    // The chat server (chat-server/lib/*.js) carries its own copy and is phase 4.1b.

    public function testNoConvertedFileStillComparesAnAccountEmailToAGuardianEmail(): void
    {
        $offenders = [];

        foreach (self::CONVERTED as $rel) {
            foreach ($this->comparisonLines($rel) as $line) {
                $offenders[] = $line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A converted file compares users.email to guardians.email again. Identity is a "
            . "row in user_guardians now; every one of these must go through "
            . "lib/guardian_identity.php:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The deferral list is only honest while each entry is still true. An entry whose file
     * no longer carries the comparison has been converted — delete it.
     */
    public function testEveryDeferredFileStillActuallyCarriesTheComparison(): void
    {
        foreach (self::DEFERRED as $rel => $reason) {
            $this->assertNotEmpty(
                $this->comparisonLines($rel),
                "$rel no longer compares an account email to a guardian email — remove it "
                . "from DEFERRED. (Reason recorded: $reason)"
            );
        }
    }

    /**
     * The resolver is allowed to hold the comparison, because it is the fallback branch.
     * If this ever finds nothing, phase 4 has happened and the fallback is gone.
     */
    public function testTheResolverIsWhereTheComparisonLives(): void
    {
        $this->assertNotEmpty($this->comparisonLines('lib/guardian_identity.php'));
    }

    /**
     * Lines comparing a guardians.email to a users.email, in either order, with comments
     * stripped — the rule is documented in prose all over this repo and the documentation
     * must not read as the defect (same reason MysqlOnlySqlTest requires a SQL keyword).
     *
     * @return string[] "path:line  text"
     */
    private function comparisonLines(string $relativePath): array
    {
        $path = __DIR__ . '/../../' . $relativePath;
        $this->assertFileExists($path, "$relativePath is listed in this test but does not exist");

        $found = [];
        foreach (file($path) as $i => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')
                || str_starts_with($trimmed, '--')) {
                continue;
            }

            $guardian = '(?:g\d?\.email|guardians\.email)';
            $user     = '(?:u\d?\.email|users\.email)';
            $pattern = '/(?:LOWER\(\s*)?' . $guardian . '\s*\)?\s*=\s*(?:LOWER\(\s*)?' . $user . '/i';
            $reverse = '/(?:LOWER\(\s*)?' . $user . '\s*\)?\s*=\s*(?:LOWER\(\s*)?' . $guardian . '/i';

            if (preg_match($pattern, $line) || preg_match($reverse, $line)) {
                $found[] = $relativePath . ':' . ($i + 1) . '  ' . trim($line);
            }
        }

        return $found;
    }
}
