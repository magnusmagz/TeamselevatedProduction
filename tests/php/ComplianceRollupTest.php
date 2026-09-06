<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/compliance.php';
require_once __DIR__ . '/../../lib/compliance_rollup.php';
require_once __DIR__ . '/../../lib/compliance_export.php';

/** Auth double — the same two things lib/org_scope.php asks of it. */
class FakeRollupAuth
{
    public function __construct(private int $userId = 0, private bool $superAdmin = false) {}
    public function getUserId(): int { return $this->userId; }
    public function isSuperAdmin(): bool { return $this->superAdmin; }
    public function hasRole(string $role, ?int $scopeId = null, ?string $scopeType = null): bool { return false; }
}

/**
 * Division and national compliance rollups (GOTR G5).
 *
 * Fixture — two divisions, so that "a division admin cannot see a sibling
 * division" is a thing the tree can actually express:
 *
 *   1 national  Girls on the Run   /1/       req 10 SafeSport   required, 365d, all roles
 *   2 division  West               /1/2/     req 11 Concussion  required, no expiry, head_coach
 *   3 council   Kansas             /1/2/3/   -> club 100  (req 13 Parking pass, OPTIONAL, volunteer)
 *   4 council   California         /1/2/4/   -> club 101
 *   5 division  East               /1/5/
 *   6 council   Boston             /1/5/6/   -> club 102
 *   club 103 (CKU) org_unit_id NULL          req 14 — must never appear in any rollup
 *
 * Today is 2026-09-06 throughout.
 *
 *   club 100: Hana (head_coach)  SafeSport verified, expires 09-16  -> compliant, EXPIRING
 *             Vic  (volunteer)   SafeSport verified, expired 09-05; parking pass missing -> EXPIRED + MISSING
 *             Cal  (coach)       nothing on file                    -> MISSING
 *             Pat  (parent)      not staff, not counted
 *   club 101: Bo   (coach)       SafeSport verified, expires 2027-03-25 -> compliant
 *             Cam  (coach)       SafeSport submitted, unreviewed    -> not compliant, not missing
 *             Dee  (volunteer)   SafeSport stored as 'expired'       -> EXPIRED
 *             Fay  (coach)       SafeSport verified, expires 11-15   -> compliant
 *   club 102: Eve  (coach)       nothing on file                    -> MISSING
 *
 * What these tests pin, in order of how much damage the alternative does:
 *
 * - The rollup AGREES with te_compliance_status for every staff member. It is
 *   a set-based query and the per-person function is a PHP loop; two
 *   implementations of "is this person compliant" that drift is how a board
 *   report and a council's own screen disagree about the same coach.
 * - Scope is the descendant set and nothing else — never a sibling division,
 *   never a club with no org unit.
 * - The counts are per PERSON, the way club-status counts them: a person with
 *   two missing certificates is one missing person.
 * - Highest risk first, and a council with no staff is still a row.
 */
class ComplianceRollupTest extends TestCase
{
    private const TODAY = '2026-09-06';

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER);
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER,
                role TEXT, active BOOLEAN DEFAULT 1, revoked_at TEXT
            );
            CREATE TABLE org_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER, type TEXT NOT NULL,
                name TEXT NOT NULL, external_code TEXT, path TEXT NOT NULL, depth INTEGER NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_org_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                org_unit_id INTEGER NOT NULL, role TEXT NOT NULL,
                granted_at TEXT DEFAULT CURRENT_TIMESTAMP, granted_by INTEGER,
                revoked_at TEXT, revoked_by INTEGER, active BOOLEAN DEFAULT 1,
                UNIQUE (user_id, org_unit_id, role)
            );
            CREATE TABLE compliance_requirements (
                id INTEGER PRIMARY KEY AUTOINCREMENT, org_unit_id INTEGER, club_profile_id INTEGER,
                kind TEXT NOT NULL DEFAULT 'custom', name TEXT NOT NULL, description TEXT,
                proof TEXT NOT NULL DEFAULT 'attested_date', proof_url TEXT,
                validity_days INTEGER, required BOOLEAN NOT NULL DEFAULT 1,
                active BOOLEAN NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE compliance_requirement_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER NOT NULL,
                staff_role TEXT NOT NULL, UNIQUE (requirement_id, staff_role)
            );
            CREATE TABLE club_staff_roles (
                user_id INTEGER NOT NULL, club_profile_id INTEGER NOT NULL, staff_role TEXT NOT NULL,
                assigned_by INTEGER, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, club_profile_id, staff_role)
            );
            CREATE TABLE person_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                requirement_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'missing',
                completed_at TEXT, expires_at TEXT, document_id INTEGER,
                submitted_at TEXT, verified_by INTEGER, verified_at TEXT,
                rejection_reason TEXT, source TEXT NOT NULL DEFAULT 'admin', notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, requirement_id)
            );
            CREATE TABLE compliance_reminder_streams (id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER);
            CREATE TABLE compliance_reminder_log (id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER);
        ");
        $pdo->exec("
            INSERT INTO org_units (id, parent_id, type, name, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', '/1/', 0),
                (2, 1, 'division', 'West', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', '/1/2/3/', 2),
                (4, 2, 'council', 'California', '/1/2/4/', 2),
                (5, 1, 'division', 'East', '/1/5/', 1),
                (6, 5, 'council', 'Boston', '/1/5/6/', 2);
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3),
                (101, 'GOTR California', 4),
                (102, 'GOTR Boston', 6),
                (103, 'Central Kansas United', NULL);
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (50, 'hana@gotr.org', 'Hana', 'Head'),
                (51, 'vic@gotr.org', 'Vic', 'Volunteer'),
                (52, 'cal@gotr.org', 'Cal', 'Coach'),
                (53, 'pat@gotr.org', 'Pat', 'Parent'),
                (55, 'bo@gotr.org', 'Bo', 'Bay'),
                (56, 'cam@gotr.org', 'Cam', 'Cali'),
                (57, 'eve@gotr.org', 'Eve', 'East'),
                (58, 'dee@gotr.org', 'Dee', 'Done'),
                (59, 'fay@gotr.org', 'Fay', 'Fine'),
                (60, 'kim@cku.org', 'Kim', 'Kansas');
            INSERT INTO user_club_access (user_id, club_profile_id, role) VALUES
                (50, 100, 'coach'), (51, 100, 'volunteer'), (52, 100, 'coach'), (53, 100, 'parent'),
                (55, 101, 'coach'), (56, 101, 'coach'), (58, 101, 'volunteer'), (59, 101, 'coach'),
                (57, 102, 'coach'),
                (60, 103, 'coach');
            INSERT INTO club_staff_roles (user_id, club_profile_id, staff_role) VALUES
                (50, 100, 'head_coach'), (51, 100, 'volunteer');
            INSERT INTO compliance_requirements
                (id, org_unit_id, club_profile_id, kind, name, proof, validity_days, required, active, sort_order) VALUES
                (10, 1, NULL, 'training', 'SafeSport', 'document', 365, 1, 1, 1),
                (11, 2, NULL, 'training', 'Concussion protocol', 'attested_date', NULL, 1, 1, 2),
                (13, NULL, 100, 'custom', 'Council parking pass', 'attested_date', NULL, 0, 1, 4),
                (14, NULL, 103, 'background_check', 'CKU background check', 'document', 730, 1, 1, 1);
            INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES
                (11, 'head_coach'), (13, 'volunteer');
            INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at, source) VALUES
                (50, 10, 'verified', '2025-09-16', '2026-09-16', 'admin'),
                (50, 11, 'verified', '2026-01-01', NULL, 'admin'),
                (51, 10, 'verified', '2025-09-05', '2026-09-05', 'admin'),
                (55, 10, 'verified', '2026-03-25', '2027-03-25', 'admin'),
                (56, 10, 'submitted', '2026-08-01', '2027-08-01', 'portal'),
                (58, 10, 'expired', '2025-01-01', '2026-01-01', 'admin'),
                (59, 10, 'verified', '2025-11-15', '2026-11-15', 'admin'),
                (60, 14, 'verified', '2026-01-01', '2028-01-01', 'admin');
        ");
        return $pdo;
    }

    /** @return array<int, array> councils keyed by club id */
    private function byClub(array $rollup): array
    {
        $out = [];
        foreach ($rollup['councils'] as $row) {
            $out[$row['club_id']] = $row;
        }
        return $out;
    }

    private function counts(array $row): array
    {
        return [
            'staff_total' => $row['staff_total'], 'compliant' => $row['compliant'],
            'expiring_30' => $row['expiring_30'], 'expired' => $row['expired'], 'missing' => $row['missing'],
        ];
    }

    // ------------------------------------------------------------ the rollup

    public function testADivisionRollsUpItsOwnCouncilsAndNothingElse(): void
    {
        $rollup = te_compliance_rollup($this->pdo(), 2, self::TODAY);
        $clubs = $this->byClub($rollup);

        $this->assertSame([100, 101], $this->sortedClubIds($rollup));
        $this->assertArrayNotHasKey(102, $clubs, 'Boston is under the sibling division');
        $this->assertArrayNotHasKey(103, $clubs, 'a club with no org unit is never in a rollup');

        $this->assertSame(
            ['staff_total' => 3, 'compliant' => 1, 'expiring_30' => 1, 'expired' => 1, 'missing' => 2],
            $this->counts($clubs[100])
        );
        $this->assertSame(
            ['staff_total' => 4, 'compliant' => 2, 'expiring_30' => 0, 'expired' => 1, 'missing' => 0],
            $this->counts($clubs[101]),
            'a submitted-but-unreviewed credential is neither compliant nor missing'
        );
        $this->assertSame(
            ['staff_total' => 7, 'compliant' => 3, 'expiring_30' => 1, 'expired' => 2, 'missing' => 2],
            $this->counts($rollup['total'])
        );
    }

    public function testNationalSeesEveryCouncilAndACouncilSeesItself(): void
    {
        $pdo = $this->pdo();
        $this->assertSame([100, 101, 102], $this->sortedClubIds(te_compliance_rollup($pdo, 1, self::TODAY)));
        $this->assertSame([102], $this->sortedClubIds(te_compliance_rollup($pdo, 5, self::TODAY)));
        $this->assertSame([100], $this->sortedClubIds(te_compliance_rollup($pdo, 3, self::TODAY)));
    }

    private function sortedClubIds(array $rollup): array
    {
        $ids = array_map(static fn (array $r): int => $r['club_id'], $rollup['councils']);
        sort($ids);
        return $ids;
    }

    /**
     * The one that matters. The rollup is a set-based query; te_compliance_status
     * is the per-person loop every other screen renders. If they ever disagree,
     * a council's own page and the division's report say different things about
     * the same coach, and nobody can tell which to believe.
     */
    public function testTheRollupAgreesWithThePerPersonPredicateForEverybody(): void
    {
        $pdo = $this->pdo();
        $clubs = $this->byClub(te_compliance_rollup($pdo, 1, self::TODAY));

        foreach ([100, 101, 102] as $clubId) {
            $expected = ['staff_total' => 0, 'compliant' => 0, 'expiring_30' => 0, 'expired' => 0, 'missing' => 0];
            foreach (te_compliance_club_staff($pdo, $clubId) as $person) {
                $rollup = te_compliance_status($pdo, $person['user_id'], $clubId, self::TODAY)['rollup'];
                $expected['staff_total']++;
                $expected['compliant'] += $rollup['compliant'] ? 1 : 0;
                $expected['expiring_30'] += $rollup['expiring_30'] > 0 ? 1 : 0;
                $expected['expired'] += $rollup['expired'] > 0 ? 1 : 0;
                $expected['missing'] += $rollup['missing'] > 0 ? 1 : 0;
            }
            $this->assertSame($expected, $this->counts($clubs[$clubId]), "club $clubId disagrees with te_compliance_status");
        }
    }

    public function testHighestRiskFirstAndAnEmptyCouncilIsStillARow(): void
    {
        $pdo = $this->pdo();
        // A council with nobody on staff yet.
        $pdo->exec("INSERT INTO org_units (id, parent_id, type, name, path, depth) VALUES (7, 2, 'council', 'Nevada', '/1/2/7/', 2)");
        $pdo->exec("INSERT INTO club_profile (id, name, org_unit_id) VALUES (104, 'GOTR Nevada', 7)");

        $ids = array_map(static fn (array $r): int => $r['club_id'], te_compliance_rollup($pdo, 1, self::TODAY)['councils']);
        // Boston 1/1 non-compliant, Kansas 2/3, California 2/4, Nevada 0/0.
        $this->assertSame([102, 100, 101, 104], $ids);

        $nevada = $this->byClub(te_compliance_rollup($pdo, 1, self::TODAY))[104];
        $this->assertSame(0, $nevada['staff_total']);
        $this->assertNull($nevada['risk_share'], 'no staff is not the same as 0% at risk');
    }

    public function testEveryCouncilRowCarriesItsUnitPathForNesting(): void
    {
        $row = $this->byClub(te_compliance_rollup($this->pdo(), 1, self::TODAY))[100];
        $this->assertSame('GOTR Kansas', $row['club_name']);
        $this->assertSame(3, $row['org_unit_id']);
        $this->assertSame('Kansas', $row['org_unit_name']);
        $this->assertSame('/1/2/3/', $row['org_unit_path']);
    }

    public function testARequirementFilterCountsOnlyThePeopleWhoOweIt(): void
    {
        // Concussion protocol is head_coach only: Hana alone owes it.
        $rollup = te_compliance_rollup($this->pdo(), 2, self::TODAY, 11);
        $clubs = $this->byClub($rollup);
        $this->assertSame(1, $clubs[100]['staff_total']);
        $this->assertSame(1, $clubs[100]['compliant']);
        $this->assertSame(0, $clubs[101]['staff_total'], 'nobody in California owes it');
        $this->assertSame(1, $rollup['total']['staff_total']);
    }

    public function testAnInactiveRequirementDemandsNothing(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('UPDATE compliance_requirements SET active = 0 WHERE id = 10');
        $clubs = $this->byClub(te_compliance_rollup($pdo, 2, self::TODAY));
        $this->assertSame(0, $clubs[100]['expired']);
        $this->assertSame(4, $clubs[101]['compliant'], 'with SafeSport gone every Californian is compliant');
    }

    public function testARevokedStaffRoleIsNotCounted(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("UPDATE user_club_access SET revoked_at = '2026-01-01' WHERE user_id = 57");
        $clubs = $this->byClub(te_compliance_rollup($pdo, 5, self::TODAY));
        $this->assertSame(0, $clubs[102]['staff_total']);
    }

    // ---------------------------------------------------------------- trend

    public function testTheTrendBucketsExpiriesByMonthForSixMonths(): void
    {
        $trend = te_compliance_rollup_trend($this->pdo(), 2, self::TODAY);

        $this->assertSame(
            ['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02'],
            $trend['months']
        );
        $byClub = [];
        foreach ($trend['councils'] as $row) {
            $byClub[$row['club_id']] = $row['by_month'];
        }
        $this->assertSame([1, 0, 0, 0, 0, 0], $byClub[100], 'Hana expires on the 16th; Vic already lapsed');
        $this->assertSame([0, 0, 1, 0, 0, 0], $byClub[101], 'Fay in November; Bo in March is outside the window');
    }

    public function testTheTrendWindowCrossesAYearEnd(): void
    {
        $this->assertSame(
            ['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04'],
            te_compliance_rollup_months('2026-11-30')
        );
    }

    // ------------------------------------------------------------ drill-down

    public function testDrillDownScopeIsTheDescendantSet(): void
    {
        $pdo = $this->pdo();
        $this->assertSame('in', te_compliance_rollup_club_scope($pdo, 2, 100));
        $this->assertSame('out', te_compliance_rollup_club_scope($pdo, 2, 102), 'Boston is under East');
        $this->assertSame('out', te_compliance_rollup_club_scope($pdo, 2, 103), 'CKU is under nothing');
        $this->assertSame('missing', te_compliance_rollup_club_scope($pdo, 2, 999));
        $this->assertSame('in', te_compliance_rollup_club_scope($pdo, 1, 102));
    }

    // ------------------------------------------------------------- standing

    public function testADivisionAdminHasNoStandingAtASiblingDivision(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO user_org_access (user_id, org_unit_id, role) VALUES (70, 2, 'org_admin'), (71, 1, 'org_viewer')");

        $this->assertSame('org_admin', te_user_org_standing($pdo, new FakeRollupAuth(70), 2));
        $this->assertNull(te_user_org_standing($pdo, new FakeRollupAuth(70), 5), 'West has nothing at East');
        $this->assertNull(te_user_org_standing($pdo, new FakeRollupAuth(70), 1), 'and nothing above itself');
        $this->assertSame('org_viewer', te_user_org_standing($pdo, new FakeRollupAuth(71), 5), 'a national viewer reads every division');
    }

    /**
     * The endpoint is procedural, so it is parsed. What is pinned: it
     * authenticates, it checks the kill switch, EVERY view is behind the
     * standing check, an org_viewer is admitted (null is the only refusal),
     * and there is not a single write in the file.
     */
    public function testTheEndpointIsReadOnlyAndGatedOnOrgStanding(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/compliance-rollup.php');

        $auth = strpos($src, 'AuthMiddleware::requireAuth()');
        $this->assertNotFalse($auth, 'the endpoint must authenticate');

        $flag = strpos($src, "if (!te_feature_enabled('COMPLIANCE'))");
        $this->assertNotFalse($flag, 'the kill switch is gone');
        $this->assertLessThan($flag, $auth, 'authenticate first, then check the switch');
        $this->assertStringContainsString("te_feature_disabled_response('COMPLIANCE')", $src);

        $standing = strpos($src, '$standing = te_user_org_standing($pdo, $auth, $orgUnitId)');
        $this->assertNotFalse($standing, 'standing at the org unit is the gate');
        $this->assertLessThan($standing, $flag, 'the switch is checked before standing');
        // null is the refusal. `!== 'org_admin'` here would lock out every viewer,
        // and the viewer role exists for exactly this page.
        $refusal = strpos($src, 'if ($standing === null)');
        $this->assertNotFalse($refusal, 'an org_viewer must be admitted — only NO standing is refused');
        $this->assertLessThan($refusal, $standing);
        $this->assertStringNotContainsString("\$standing !== 'org_admin'", $src);
        $this->assertStringNotContainsString("\$standing === 'org_admin'", $src,
            'nothing here may branch on admin — every view is a read the viewer may make');
        $this->assertStringNotContainsString("te_user_org_standing(\$pdo, \$auth, \$orgUnitId) !== 'org_admin'", $src);

        foreach (['summary', 'trend', 'club'] as $view) {
            $offset = strpos($src, "\$view === '$view'");
            $this->assertNotFalse($offset, "view $view is missing");
            $this->assertLessThan($offset, $standing, "view $view dispatches before the standing check");
        }

        // The drill-down checks the club against the SAME org unit's scope.
        $this->assertStringContainsString('te_compliance_rollup_club_scope($pdo, $orgUnitId, $clubId)', $src);
        $this->assertStringContainsString('te_compliance_club_staff(', $src);
        $this->assertStringContainsString('te_compliance_status(', $src, 'status is reused, never re-derived');

        // Read-only. Nothing in this file may write.
        foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'te_credential_upsert', 'AuditLogger::log'] as $write) {
            $this->assertStringNotContainsString($write, $src, "the rollup endpoint must not $write");
        }
        $this->assertStringNotContainsString("\$_POST", $src);
    }

    // --------------------------------------------------------------- export

    public function testTheOrgExportPutsTheCouncilFirstAndSpansEveryDescendant(): void
    {
        $sheet = te_compliance_export_org_sheet($this->pdo(), 2, '', self::TODAY);

        $this->assertSame('Council', $sheet['headers'][0]);
        $councils = array_unique(array_map(static fn (array $r): string => $r[0], $sheet['rows']));
        sort($councils);
        $this->assertSame(['GOTR California', 'GOTR Kansas'], array_values($councils));
        $this->assertNotContains('GOTR Boston', $councils, 'the sibling division is not in the file');
        $this->assertNotContains('Central Kansas United', $councils);

        // Hana × (SafeSport, Concussion) + Vic × (SafeSport, parking) + Cal × SafeSport + 4 Californians × SafeSport
        $this->assertSame(9, $sheet['total_rows']);
        $this->assertSame(7, $sheet['people']);
        $this->assertSame(0, $sheet['omitted_rows']);
    }

    public function testTheOrgExportCapIsAcrossTheWholeFileAndReported(): void
    {
        $pdo = $this->pdo();
        // 1,200 coaches in California, each owing SafeSport.
        $stmt = $pdo->prepare("INSERT INTO users (id, email, first_name, last_name) VALUES (?, ?, 'Many', 'Coach')");
        $uca = $pdo->prepare("INSERT INTO user_club_access (user_id, club_profile_id, role) VALUES (?, 101, 'coach')");
        for ($i = 1000; $i < 2200; $i++) {
            $stmt->execute([$i, "c$i@gotr.org"]);
            $uca->execute([$i]);
        }

        $sheet = te_compliance_export_org_sheet($pdo, 2, '', self::TODAY);
        $this->assertSame(TE_COMPLIANCE_EXPORT_MAX_ROWS, count($sheet['rows']));
        $this->assertSame(1209, $sheet['total_rows'], 'the count keeps going past the cap');
        $this->assertSame(209, $sheet['omitted_rows']);
        $this->assertStringContainsString('209 of 1209 rows', te_compliance_export_truncation_notice($sheet));
    }

    public function testTheOrgExportHonoursTheFilter(): void
    {
        $sheet = te_compliance_export_org_sheet($this->pdo(), 2, 'expired', self::TODAY);
        // Council, Last name, First name, Email — the council shifts every column right by one.
        $people = array_unique(array_map(static fn (array $r): string => $r[3], $sheet['rows']));
        sort($people);
        $this->assertSame(['dee@gotr.org', 'vic@gotr.org'], array_values($people));
    }

    public function testTheExportEndpointGatesTheOrgBranchOnStanding(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/compliance-export.php');
        $this->assertStringContainsString('te_user_org_standing($pdo, $auth, $orgUnitId) === null', $src,
            'the org branch admits any standing (viewer included) and refuses none');
        $this->assertStringContainsString('te_compliance_export_org_sheet(', $src);
        // The club branch keeps its own, stricter predicate.
        $this->assertStringContainsString('te_compliance_can_admin_club($pdo, $auth, $clubId)', $src);
        $this->assertStringContainsString('\\xEF\\xBB\\xBF', $src, 'the BOM stays');
        $this->assertStringContainsString('X-Compliance-Export-Truncated', $src);
    }
}
