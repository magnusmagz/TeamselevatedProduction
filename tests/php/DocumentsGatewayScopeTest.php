<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/document_scope.php';

/**
 * documents-gateway.php — who may read a document list, and whose targets a
 * document may be assigned to.
 *
 * Three findings, all of the same family this codebase has hit repeatedly: the
 * predicate was never wrong, WHICH predicate got called was.
 *
 *  1. `action=expiring` gated on `canAccessClub()` — club MEMBERSHIP, which a
 *     `parent` row satisfies. Any parent passing their own club_id enumerated
 *     every expiring document in the club, titles and `file_path` URLs
 *     included. Same substitution as `handleClubParents` (lib/club_standing.php).
 *  2. `action=for-target` gated on the same thing, so any signed-in club member
 *     could walk target ids and read the document set of any team, athlete or
 *     coach in their club.
 *  3. `te_document_insert_assignments()` was handed the document's `$clubId` and never used
 *     it, so an assignment could name another club's team, athlete or user —
 *     and `listDocumentsForCascade` matches assignments by target id, so that
 *     club's families would then be served the document.
 *
 * The source assertions are as important as the functional one: a scope bug of
 * this shape is invisible in behaviour tests that only ever run an authorised
 * caller.
 */
class DocumentsGatewayScopeTest extends TestCase
{
    private const GATEWAY = __DIR__ . '/../../api/documents-gateway.php';
    private const SCOPE_LIB = __DIR__ . '/../../lib/document_scope.php';

    private function source(): string
    {
        return file_get_contents(self::GATEWAY);
    }

    /** The body of one top-level function, for call-site-scoped assertions. */
    private function fn(string $file, string $name): string
    {
        $src = file_get_contents($file);
        $at = strpos($src, "function $name(");
        $this->assertNotFalse($at, "$name not found in $file");
        $next = strpos($src, "\nfunction ", $at + 1);
        return $next === false ? substr($src, $at) : substr($src, $at, $next - $at);
    }

    /** A handler in the gateway. */
    private function handler(string $name): string
    {
        return $this->fn(self::GATEWAY, $name);
    }

    /** A predicate in lib/document_scope.php. */
    private function predicate(string $name): string
    {
        return $this->fn(self::SCOPE_LIB, $name);
    }

    // ------------------------------------------------------------------
    // 1. action=expiring
    // ------------------------------------------------------------------

    /** THE REGRESSION. A club-wide expiring list is club-wide STAFF data. */
    public function testExpiringGatesOnClubStaffAndNotOnMereMembership(): void
    {
        $handler = $this->handler('handleExpiring');

        $this->assertMatchesRegularExpression(
            '/te_is_club_staff\s*\(\s*\$auth\s*,\s*\$clubId\s*\)/',
            $handler,
            'the expiring dashboard must require staff standing'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$auth\s*->\s*canAccessClub\s*\(/',
            $handler,
            'canAccessClub is club membership; a parent satisfies it'
        );
    }

    /** The predicate has to actually be loaded, not just named. */
    public function testClubStandingIsRequired(): void
    {
        $this->assertStringContainsString("lib/document_scope.php", $this->source());
        $this->assertStringContainsString("lib/club_standing.php", file_get_contents(self::SCOPE_LIB));
        $this->assertTrue(function_exists('te_is_club_staff'));
        $this->assertTrue(function_exists('te_is_club_admin'));
    }

    // ------------------------------------------------------------------
    // 2. action=for-target
    // ------------------------------------------------------------------

    public function testForTargetGatesOnItsPerTargetPredicate(): void
    {
        $handler = $this->handler('handleForTarget');

        $this->assertMatchesRegularExpression(
            '/te_document_user_can_read_target_docs\s*\(\s*\$conn\s*,\s*\$auth\s*,\s*\$type\s*,\s*\$tid\s*,\s*\$clubId\s*\)/',
            $handler,
            'listing a target\'s documents takes the single-document read predicate'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$auth\s*->\s*canAccessClub\s*\(/',
            $handler
        );
    }

    /**
     * The predicate must branch per target_type. A single blanket answer here
     * is how the endpoint was wrong in the first place.
     */
    public function testTargetReadPredicateAnswersPerTargetType(): void
    {
        $predicate = $this->predicate('te_document_user_can_read_target_docs');

        $this->assertStringContainsString('te_is_club_admin($auth, $clubId)', $predicate);
        foreach (['club', 'user', 'athlete', 'team'] as $type) {
            $this->assertStringContainsString(
                "\$type === '$type'",
                $predicate,
                "target_type $type needs its own branch"
            );
        }
        $this->assertStringContainsString('te_document_user_can_read_athlete_docs(', $predicate);
        $this->assertStringContainsString('te_user_is_guardian_of_athlete(', $predicate);
        $this->assertStringContainsString('$tid === $userId', $predicate);
    }

    /** No live canAccessClub call may survive on any read path in this file. */
    public function testNoReadHandlerGatesOnMereClubMembership(): void
    {
        foreach (['handleForTarget', 'handleExpiring'] as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/\$auth\s*->\s*canAccessClub\s*\(/',
                $this->handler($name),
                "$name must not gate on club membership"
            );
        }
    }

    // ------------------------------------------------------------------
    // 3. te_document_insert_assignments uses the $clubId it is handed
    // ------------------------------------------------------------------

    /**
     * The parameter was there the whole time and the function never read it.
     * Assert it reaches a query, not merely that the name appears.
     */
    public function testInsertAssignmentsUsesClubIdInAQuery(): void
    {
        $fn = $this->predicate('te_document_insert_assignments');
        $this->assertStringContainsString('$clubId', $fn, 'the club must be used, not merely accepted');
        $this->assertStringContainsString('te_document_target_is_in_club(', $fn);
        $this->assertStringContainsString('DocumentTargetScopeException', $fn);

        $check = $this->predicate('te_document_target_is_in_club');
        $this->assertMatchesRegularExpression(
            '/execute\(\[[^\]]*\$clubId/',
            $check,
            '$clubId must be bound into the target-validation queries'
        );
        $this->assertStringContainsString('FROM teams WHERE id = ? AND club_id = ?', $check);
        $this->assertStringContainsString('AthleteScope::athleteClubIds(', $check);
        $this->assertStringContainsString('FROM user_club_access', $check);
    }

    /** Both write paths must translate the refusal into a 422, not a 500. */
    public function testBothAssignmentWritersAnswer422OnAForeignTarget(): void
    {
        foreach (['handleCreate', 'handleAssign'] as $name) {
            $handler = $this->handler($name);
            $this->assertStringContainsString(
                'catch (DocumentTargetScopeException',
                $handler,
                "$name must catch the scope refusal"
            );
            $this->assertStringContainsString('http_response_code(422)', $handler);
            $this->assertStringContainsString('foreign_targets', $handler);
        }
    }

    // ------------------------------------------------------------------
    // Functional: the cross-club refusal, against real SQL
    // ------------------------------------------------------------------

    /**
     * Club 51 owns document 900. Team 1 and athlete 10 and user 400 are club
     * 51's; team 2, athlete 20 and user 500 belong to club 32.
     */
    private function fixture(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE documents (id INTEGER PRIMARY KEY, club_profile_id INTEGER);
            CREATE TABLE document_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER, target_type TEXT, target_id INTEGER, assigned_by INTEGER,
                UNIQUE (document_id, target_type, target_id)
            );
            CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER, deleted_at TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER, revoked_at TEXT);
        ");
        $pdo->exec("INSERT INTO documents (id, club_profile_id) VALUES (900, 51)");
        $pdo->exec("INSERT INTO teams (id, club_id, primary_coach_id, deleted_at) VALUES
            (1, 51, 161, NULL), (2, 32, 999, NULL)");
        $pdo->exec("INSERT INTO athletes (id, club_id, deleted_at) VALUES (10, 51, NULL), (20, 32, NULL)");
        $pdo->exec("INSERT INTO user_club_access (id, user_id, club_profile_id, role, active, revoked_at) VALUES
            (1, 400, 51, 'coach', 1, NULL),
            (2, 500, 32, 'coach', 1, NULL),
            (3, 600, 51, 'coach', 1, '2026-07-08')");
        return $pdo;
    }

    private function countAssignments(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM document_assignments")->fetchColumn();
    }

    public function testTargetsInsideTheClubAreInserted(): void
    {
        $pdo = $this->fixture();
        te_document_insert_assignments($pdo, 900, [
            ['target_type' => 'club', 'target_id' => 51],
            ['target_type' => 'team', 'target_id' => 1],
            ['target_type' => 'athlete', 'target_id' => 10],
            ['target_type' => 'user', 'target_id' => 400],
        ], 1, 51);

        $this->assertSame(4, $this->countAssignments($pdo));
    }

    /**
     * THE REGRESSION, functionally. Every foreign target type is refused, and
     * the refusal names the ids so the caller can see which ones.
     */
    public function testEachForeignTargetTypeIsRefusedByName(): void
    {
        $cases = [
            'club'    => ['target_type' => 'club', 'target_id' => 32],
            'team'    => ['target_type' => 'team', 'target_id' => 2],
            'athlete' => ['target_type' => 'athlete', 'target_id' => 20],
            'user'    => ['target_type' => 'user', 'target_id' => 500],
        ];

        foreach ($cases as $type => $target) {
            $pdo = $this->fixture();
            try {
                te_document_insert_assignments($pdo, 900, [$target], 1, 51);
                $this->fail("a foreign $type target must be refused");
            } catch (DocumentTargetScopeException $e) {
                $this->assertSame(["$type {$target['target_id']}"], $e->foreignTargets);
                $this->assertStringContainsString("$type {$target['target_id']}", $e->getMessage());
            }
            $this->assertSame(0, $this->countAssignments($pdo));
        }
    }

    /**
     * The batch is refused WHOLE. A partially-applied assignment set is harder
     * to notice than a rejection — the admin sees the document save and only
     * later finds a target missing.
     */
    public function testAValidTargetInTheSameBatchIsNotWritten(): void
    {
        $pdo = $this->fixture();
        try {
            te_document_insert_assignments($pdo, 900, [
                ['target_type' => 'team', 'target_id' => 1],   // legitimate
                ['target_type' => 'team', 'target_id' => 2],   // club 32's
            ], 1, 51);
            $this->fail('the batch must be refused');
        } catch (DocumentTargetScopeException $e) {
            $this->assertSame(['team 2'], $e->foreignTargets);
        }
        $this->assertSame(0, $this->countAssignments($pdo), 'nothing may be written');
    }

    /**
     * A revoked role is not standing. `active` and `revoked_at` can disagree and
     * the revocation is the newer fact (lib/JWT.php had the same bug).
     */
    public function testARevokedRoleDoesNotPutAUserInTheClub(): void
    {
        $pdo = $this->fixture();
        $this->assertFalse(te_document_target_is_in_club($pdo, 'user', 600, 51));
        $this->assertTrue(te_document_target_is_in_club($pdo, 'user', 400, 51));
    }

    /**
     * An athlete registered to the club but not yet on a team is still in the
     * club — that is why the check reads `AthleteScope::athleteClubIds`, which
     * unions `athletes.club_id` with the team-derived clubs, rather than team
     * membership alone.
     */
    public function testAnAthleteWithNoTeamIsStillInTheirClub(): void
    {
        $pdo = $this->fixture();
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM team_members WHERE athlete_id = 10")->fetchColumn());
        $this->assertTrue(te_document_target_is_in_club($pdo, 'athlete', 10, 51));
    }

    /** A target that does not exist at all is foreign, not silently accepted. */
    public function testAnUnknownTargetIsRefused(): void
    {
        $pdo = $this->fixture();
        foreach ([['team', 777], ['athlete', 777], ['user', 777]] as [$type, $id]) {
            $this->assertFalse(te_document_target_is_in_club($pdo, $type, $id, 51), "$type $id");
        }
    }
}
