<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/org_scope.php';

/**
 * Auth double. `getUserId` and `isSuperAdmin` are the only two things
 * lib/org_scope.php asks of it — deliberately, because standing at a tier comes
 * from `user_org_access` rows and never from a claim in a token.
 */
class FakeOrgAuth
{
    public function __construct(private int $userId = 0, private bool $superAdmin = false) {}
    public function getUserId(): int { return $this->userId; }
    public function isSuperAdmin(): bool { return $this->superAdmin; }
}

/**
 * The tier above the club (GOTR G1, migration 090).
 *
 * Fixture — the shape GOTR actually has:
 *
 *   1 national  Girls on the Run        /1/
 *   2 division  West                    /1/2/       -> club 100 (Kansas council)
 *   3 council   Kansas                  /1/2/3/     -> club 100
 *   4 council   California              /1/2/4/     -> club 101
 *   5 division  East                    /1/5/
 *   6 council   Boston                  /1/5/6/     -> club 102
 *   club 103 (CKU) has org_unit_id NULL — a non-GOTR club, which must stay
 *   reachable exactly as it is today and must never appear in an org rollup.
 *
 * What these tests pin, in order of how much damage the alternative does:
 *
 * - The path is maintained on create AND on re-parent, descendants included. A
 *   subtree left holding stale paths does not error; it silently drops out of
 *   its new division's scope and stays inside its old one's.
 * - The descendant-club resolver is a SUBQUERY that binds through a real
 *   prepared statement. Every other scope predicate in this codebase
 *   materialises ids into `IN (?,?,…)`, and a division admin over 30 councils is
 *   an order of magnitude from Postgres's 65,535 bind-parameter ceiling.
 * - Standing inherits DOWN and never UP, and never sideways.
 * - A revoked grant is not a grant, even while `active` still says TRUE — those
 *   two columns can disagree, and lib/JWT.php minted a revoked role for a year
 *   by reading only one of them.
 * - Every function tolerates the tables being absent, because `main` is shared
 *   and this code reaches production days before the migration is applied.
 */
class OrgScopeTest extends TestCase
{
    // ---------------------------------------------------------------- fixture

    /** Tables that exist regardless of migration 090. */
    private function basePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT
            );
            CREATE TABLE club_profile (
                id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active BOOLEAN DEFAULT TRUE,
                revoked_at TEXT
            );
        ");
        $pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (50, 'west@gotr.org', 'Dana', 'West'),
                (51, 'national@gotr.org', 'Nell', 'North'),
                (52, 'former@gotr.org', 'Kim', 'Gone'),
                (53, 'coach@cku.org', 'Sam', 'Coach');
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3),
                (101, 'GOTR California', 4),
                (102, 'GOTR Boston', 6),
                (103, 'Central Kansas United', NULL);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (50, 103, 'coach', 1, NULL),
                (53, 103, 'coach', 1, NULL);
        ");
        return $pdo;
    }

    /** A connection WITH the migration-090 objects. */
    private function migratedPdo(): PDO
    {
        $pdo = $this->basePdo();
        $pdo->exec("
            CREATE TABLE org_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                external_code TEXT,
                path TEXT NOT NULL,
                depth INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_org_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                org_unit_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                granted_at TEXT DEFAULT CURRENT_TIMESTAMP,
                granted_by INTEGER,
                revoked_at TEXT,
                revoked_by INTEGER,
                active BOOLEAN DEFAULT TRUE,
                UNIQUE (user_id, org_unit_id, role)
            );
        ");
        $pdo->exec("
            INSERT INTO org_units (id, parent_id, type, name, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', '/1/', 0),
                (2, 1, 'division', 'West', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', '/1/2/3/', 2),
                (4, 2, 'council', 'California', '/1/2/4/', 2),
                (5, 1, 'division', 'East', '/1/5/', 1),
                (6, 5, 'council', 'Boston', '/1/5/6/', 2);
            INSERT INTO user_org_access (user_id, org_unit_id, role, active, revoked_at) VALUES
                (50, 2, 'org_admin', 1, NULL),
                (51, 1, 'org_viewer', 1, NULL),
                -- active is still TRUE and the revocation is the newer fact.
                (52, 3, 'org_admin', 1, '2026-08-01 09:00:00');
        ");
        return $pdo;
    }

    /** Resolve a subquery to actual club ids through a REAL prepared statement. */
    private function clubIdsFor(PDO $pdo, array $scope): array
    {
        $stmt = $pdo->prepare("SELECT id FROM club_profile WHERE id IN ({$scope['sql']}) ORDER BY id");
        $stmt->execute($scope['params']);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    }

    // ------------------------------------------------------ path maintenance

    public function testCreateBuildsTheMaterialisedPath(): void
    {
        $pdo = $this->migratedPdo();

        $root = te_org_unit_create($pdo, ['name' => 'GOTR Two', 'type' => 'national'], 50);
        $this->assertTrue($root['ok']);
        $this->assertSame('/' . $root['id'] . '/', $root['path']);

        $child = te_org_unit_create(
            $pdo,
            ['name' => 'Midwest', 'type' => 'division', 'parent_id' => $root['id']],
            50
        );
        $this->assertTrue($child['ok']);
        $this->assertSame('/' . $root['id'] . '/' . $child['id'] . '/', $child['path']);

        $row = te_org_unit($pdo, $child['id']);
        $this->assertSame(1, (int) $row['depth'], 'a child of a root is depth 1');
        $this->assertSame(0, (int) te_org_unit($pdo, $root['id'])['depth']);
    }

    public function testCreateRefusesABadTypeAndAnEmptyName(): void
    {
        $pdo = $this->migratedPdo();
        $this->assertSame('bad_type', te_org_unit_create($pdo, ['name' => 'X', 'type' => 'region'])['reason']);
        $this->assertSame('name_required', te_org_unit_create($pdo, ['name' => '  ', 'type' => 'council'])['reason']);
        $this->assertSame(
            'parent_not_found',
            te_org_unit_create($pdo, ['name' => 'X', 'type' => 'council', 'parent_id' => 999])['reason']
        );
    }

    /**
     * The whole subtree is rewritten, not just the node named in the request.
     *
     * West (2) moves under East (5). Its two councils must follow, or they stay
     * scoped to West forever while appearing under East on screen.
     */
    public function testMoveRewritesEveryDescendantPath(): void
    {
        $pdo = $this->migratedPdo();

        $result = te_org_unit_move($pdo, 2, 5);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['moved'], 'both councils were rewritten');

        $this->assertSame('/1/5/2/', te_org_unit($pdo, 2)['path']);
        $this->assertSame('/1/5/2/3/', te_org_unit($pdo, 3)['path']);
        $this->assertSame('/1/5/2/4/', te_org_unit($pdo, 4)['path']);
        $this->assertSame(3, (int) te_org_unit($pdo, 3)['depth'], 'depth follows the path');
        $this->assertSame(5, (int) te_org_unit($pdo, 2)['parent_id']);

        // And the scope follows: East now reaches Kansas and California.
        $this->assertSame(
            [100, 101, 102],
            $this->clubIdsFor($pdo, te_org_descendant_club_ids_sql([5]))
        );
    }

    public function testMoveToTheTopLevelClearsTheParent(): void
    {
        $pdo = $this->migratedPdo();

        $result = te_org_unit_move($pdo, 2, null);
        $this->assertTrue($result['ok']);
        $this->assertSame('/2/', te_org_unit($pdo, 2)['path']);
        $this->assertNull(te_org_unit($pdo, 2)['parent_id']);
        $this->assertSame('/2/3/', te_org_unit($pdo, 3)['path']);
        $this->assertSame(1, (int) te_org_unit($pdo, 3)['depth']);
    }

    /** The only input that can detach a subtree from the tree entirely. */
    public function testMoveUnderItsOwnDescendantIsRefused(): void
    {
        $pdo = $this->migratedPdo();

        $this->assertSame('cycle', te_org_unit_move($pdo, 2, 3)['reason']);
        $this->assertSame('cycle', te_org_unit_move($pdo, 2, 2)['reason']);
        $this->assertSame('/1/2/', te_org_unit($pdo, 2)['path'], 'nothing was written');
        $this->assertSame('/1/2/3/', te_org_unit($pdo, 3)['path']);
    }

    // ---------------------------------------------------- descendant clubs

    /**
     * The resolver is a subquery, and it has to survive being bound.
     *
     * Asserting on the SQL string would pass for a statement Postgres refuses,
     * which is exactly how AccessibleClubIdsTest's key-gap bug survived review —
     * PDO rejects the shape, not the intent.
     */
    public function testDescendantClubSubqueryBindsAndReturnsTheRightClubs(): void
    {
        $pdo = $this->migratedPdo();

        // A division reaches its councils' clubs.
        $this->assertSame([100, 101], $this->clubIdsFor($pdo, te_org_descendant_club_ids_sql([2])));

        // A council reaches its own.
        $this->assertSame([100], $this->clubIdsFor($pdo, te_org_descendant_club_ids_sql([3])));

        // National reaches everything attached to the tree — and NOT club 103,
        // which has no org unit at all.
        $this->assertSame([100, 101, 102], $this->clubIdsFor($pdo, te_org_descendant_club_ids_sql([1])));

        // Two units, overlapping, must not produce duplicates through the join.
        $this->assertSame([100, 101], $this->clubIdsFor($pdo, te_org_descendant_club_ids_sql([2, 3])));
    }

    /**
     * The subquery is bounded by the number of ORG UNITS, never by the number of
     * clubs. That is the entire reason this returns SQL instead of int[].
     */
    public function testBindCountFollowsOrgUnitsNotClubs(): void
    {
        $scope = te_org_descendant_club_ids_sql([1]);
        $this->assertCount(1, $scope['params']);
        $this->assertStringContainsString('club_profile', $scope['sql']);
        $this->assertStringContainsString('org_units', $scope['sql']);
    }

    /** `array_fill(0, 0, '?')` produces `IN ()`, a syntax error, not an empty result. */
    public function testAnEmptyUnitListSelectsNothingRatherThanFailing(): void
    {
        $pdo = $this->migratedPdo();
        $scope = te_org_descendant_club_ids_sql([]);
        $this->assertSame([], $this->clubIdsFor($pdo, $scope));
    }

    // ----------------------------------------------------------- standing

    public function testStandingInheritsDownTheTree(): void
    {
        $pdo = $this->migratedPdo();
        $west = new FakeOrgAuth(50);   // org_admin on division 2

        $this->assertSame('org_admin', te_user_org_standing($pdo, $west, 2), 'on the granted unit');
        $this->assertSame('org_admin', te_user_org_standing($pdo, $west, 3), 'and on its councils');
        $this->assertSame('org_admin', te_user_org_standing($pdo, $west, 4));
    }

    public function testStandingDoesNotInheritUpOrSideways(): void
    {
        $pdo = $this->migratedPdo();
        $west = new FakeOrgAuth(50);

        $this->assertNull(te_user_org_standing($pdo, $west, 1), 'not up to national');
        $this->assertNull(te_user_org_standing($pdo, $west, 5), 'not across to the sibling division');
        $this->assertNull(te_user_org_standing($pdo, $west, 6), 'nor into its councils');
    }

    public function testViewerStandingStaysViewerAllTheWayDown(): void
    {
        $pdo = $this->migratedPdo();
        $national = new FakeOrgAuth(51);

        $this->assertSame('org_viewer', te_user_org_standing($pdo, $national, 1));
        $this->assertSame('org_viewer', te_user_org_standing($pdo, $national, 6));
    }

    public function testASuperAdminIsAdminEverywhere(): void
    {
        $pdo = $this->migratedPdo();
        $this->assertSame('org_admin', te_user_org_standing($pdo, new FakeOrgAuth(999, true), 6));
    }

    /**
     * `active` still reads TRUE on this row. The revocation is the newer fact,
     * and reading only one of the two columns is how lib/JWT.php minted a
     * revoked role for a year.
     */
    public function testARevokedGrantIsIgnored(): void
    {
        $pdo = $this->migratedPdo();
        $former = new FakeOrgAuth(52);

        $this->assertSame([], te_org_units_for_user($pdo, 52));
        $this->assertNull(te_user_org_standing($pdo, $former, 3));
        $this->assertSame([], $this->clubIdsFor($pdo, te_org_scope_club_ids_sql($pdo, $former)));
    }

    public function testGrantRevivesARevokedRowRatherThanDuplicatingIt(): void
    {
        $pdo = $this->migratedPdo();

        $this->assertTrue(te_org_access_grant($pdo, 52, 3, 'org_admin', 50)['ok']);
        $this->assertSame('org_admin', te_user_org_standing($pdo, new FakeOrgAuth(52), 3));

        $count = $pdo->query('SELECT COUNT(*) FROM user_org_access WHERE user_id = 52')->fetchColumn();
        $this->assertSame(1, (int) $count, 'the UNIQUE constraint is respected');

        $this->assertTrue(te_org_access_revoke($pdo, 52, 3, 'org_admin', 50)['ok']);
        $this->assertNull(te_user_org_standing($pdo, new FakeOrgAuth(52), 3));
        $this->assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM user_org_access WHERE user_id = 52')->fetchColumn(),
            'revoking marks the row, it never deletes it'
        );
    }

    public function testGrantRefusesARoleOutsideTheTwo(): void
    {
        $pdo = $this->migratedPdo();
        $this->assertSame('bad_role', te_org_access_grant($pdo, 53, 3, 'club_admin')['reason']);
        $this->assertSame('not_found', te_org_access_grant($pdo, 53, 999, 'org_admin')['reason']);
    }

    // -------------------------------------------------------------- scope

    /**
     * Neither half can be expressed as the other: a division admin reaches 30
     * councils through one org row, and may also coach at one unrelated club.
     */
    public function testScopeUnionsOrgDescendantsWithDirectClubRoles(): void
    {
        $pdo = $this->migratedPdo();

        // User 50 is org_admin on West AND a coach at club 103 (no org unit).
        $this->assertSame(
            [100, 101, 103],
            $this->clubIdsFor($pdo, te_org_scope_club_ids_sql($pdo, new FakeOrgAuth(50)))
        );

        // User 53 has no org standing at all — today's behaviour, unchanged.
        $this->assertSame(
            [103],
            $this->clubIdsFor($pdo, te_org_scope_club_ids_sql($pdo, new FakeOrgAuth(53)))
        );
    }

    public function testSuperAdminScopeIsEveryClub(): void
    {
        $pdo = $this->migratedPdo();
        $this->assertSame(
            [100, 101, 102, 103],
            $this->clubIdsFor($pdo, te_org_scope_club_ids_sql($pdo, new FakeOrgAuth(1, true)))
        );
    }

    public function testAnUnknownUserReachesNothingRatherThanEverything(): void
    {
        $pdo = $this->migratedPdo();
        $this->assertSame([], $this->clubIdsFor($pdo, te_org_scope_club_ids_sql($pdo, new FakeOrgAuth(0))));
    }

    // ------------------------------------------------------------- delete

    public function testDeleteIsRefusedWhileChildrenOrClubsAreAttached(): void
    {
        $pdo = $this->migratedPdo();

        $withChildren = te_org_unit_delete($pdo, 2);
        $this->assertFalse($withChildren['ok']);
        $this->assertSame('not_empty', $withChildren['reason']);
        $this->assertSame(2, $withChildren['children']);

        $withClubs = te_org_unit_delete($pdo, 3);
        $this->assertFalse($withClubs['ok']);
        $this->assertSame('not_empty', $withClubs['reason']);
        $this->assertSame(1, $withClubs['clubs']);

        $this->assertNotNull(te_org_unit($pdo, 2), 'nothing was deleted');
        $this->assertNotNull(te_org_unit($pdo, 3));
    }

    public function testDeleteSucceedsOnceTheUnitIsEmpty(): void
    {
        $pdo = $this->migratedPdo();

        $this->assertTrue(te_org_attach_club($pdo, 100, null)['ok'], 'detach the council first');
        $this->assertTrue(te_org_unit_delete($pdo, 3)['ok']);
        $this->assertNull(te_org_unit($pdo, 3));

        // And club 100 is now reachable only through user_club_access, exactly
        // like every non-GOTR club.
        $this->assertSame([101], $this->clubIdsFor($pdo, te_org_descendant_club_ids_sql([2])));
    }

    public function testAttachRefusesAUnitOrClubThatDoesNotExist(): void
    {
        $pdo = $this->migratedPdo();
        $this->assertSame('not_found', te_org_attach_club($pdo, 100, 999)['reason']);
        $this->assertSame('club_not_found', te_org_attach_club($pdo, 999, 3)['reason']);
    }

    // ---------------------------------------------------- absent tables

    /**
     * Production between the push and the hand-applied migration. `main` is
     * shared, so this window is real and it is measured in days.
     */
    public function testEveryFunctionToleratesTheTablesBeingAbsent(): void
    {
        $pdo = $this->basePdo();

        $this->assertFalse(te_org_tables_present($pdo));
        $this->assertSame([], te_org_unit_tree($pdo));
        $this->assertSame([], te_org_attached_clubs($pdo));
        $this->assertSame([], te_org_access_list($pdo));
        $this->assertSame([], te_org_units_for_user($pdo, 50));
        $this->assertNull(te_org_unit($pdo, 1));
        $this->assertNull(te_user_org_standing($pdo, new FakeOrgAuth(50), 2));

        $this->assertSame('schema', te_org_unit_create($pdo, ['name' => 'X', 'type' => 'council'])['reason']);
        $this->assertSame('schema', te_org_unit_update($pdo, 1, ['name' => 'X'])['reason']);
        $this->assertSame('schema', te_org_unit_move($pdo, 1, null)['reason']);
        $this->assertSame('schema', te_org_unit_delete($pdo, 1)['reason']);
        $this->assertSame('schema', te_org_attach_club($pdo, 100, 1)['reason']);
        $this->assertSame('schema', te_org_access_grant($pdo, 50, 1, 'org_admin')['reason']);
        $this->assertSame('schema', te_org_access_revoke($pdo, 50, 1, 'org_admin')['reason']);
    }

    /**
     * The degraded scope is today's scope, not an empty one. A resolver that
     * answered "no clubs" while the migration was pending would lock every user
     * out of every club-scoped page on the deploy that shipped it.
     */
    public function testWithoutTheTablesTheScopeIsExactlyTodaysClubRoles(): void
    {
        $pdo = $this->basePdo();

        $scope = te_org_scope_club_ids_sql($pdo, new FakeOrgAuth(50));
        $this->assertStringNotContainsString('org_units', $scope['sql']);
        $this->assertSame([103], $this->clubIdsFor($pdo, $scope));
    }

    /**
     * The probe is memoised per CONNECTION, not per process. An id-keyed cache
     * lets a freed object's answer decide a later one's — the reason
     * lib/program_scope.php uses a WeakMap too.
     */
    public function testThePresenceProbeIsPerConnection(): void
    {
        $migrated = $this->migratedPdo();
        $bare = $this->basePdo();

        $this->assertTrue(te_org_tables_present($migrated));
        $this->assertFalse(te_org_tables_present($bare));
        $this->assertTrue(te_org_tables_present($migrated));
    }

    // -------------------------------------------------------- the gateway

    /**
     * Every org action on the super-admin gateway sits behind that file's
     * existing super-admin gate.
     *
     * A procedural gateway cannot be executed by a test, so this parses it. The
     * failure it guards against is not a wrong predicate — it is an action added
     * ABOVE the gate, or a gate that stops being unconditional. index.php
     * performs no authentication of its own; whatever the file does is the whole
     * of the access control.
     */
    public function testEveryOrgActionIsBehindTheSuperAdminGate(): void
    {
        $path = __DIR__ . '/../../api/super-admin-gateway.php';
        $src = file_get_contents($path);

        $gate = strpos($src, 'if (!$auth->isSuperAdmin())');
        $this->assertNotFalse($gate, 'the super-admin gate is gone');

        $switch = strpos($src, 'switch ($action)');
        $this->assertNotFalse($switch);
        $this->assertLessThan($switch, $gate, 'the gate must run before any action dispatches');

        // The gate must be reached unconditionally, i.e. before the first
        // `case` in the file and before any handler definition.
        $firstCase = strpos($src, "case '");
        $this->assertNotFalse($firstCase);
        $this->assertLessThan($firstCase, $gate);

        $actions = [
            'org-units',
            'org-unit-save',
            'org-unit-move',
            'org-unit-delete',
            'org-unit-attach-club',
            'org-unit-detach-club',
            'org-access-grant',
            'org-access-revoke',
        ];
        foreach ($actions as $action) {
            $offset = strpos($src, "case '$action':");
            $this->assertNotFalse($offset, "action $action is missing from the gateway");
            $this->assertLessThan($offset, $gate, "action $action dispatches before the super-admin gate");
        }

        // And each writer is audited: an org tree decides which councils roll up
        // to which division, so who changed it has to stay answerable.
        foreach ([
            'org_unit_created', 'org_unit_updated', 'org_unit_moved', 'org_unit_deleted',
            'org_unit_club_attached', 'org_unit_club_detached',
            'org_access_granted', 'org_access_revoked',
        ] as $auditAction) {
            $this->assertStringContainsString(
                "'$auditAction'",
                $src,
                "no audit row is written for $auditAction"
            );
        }
    }
}
