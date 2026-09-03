<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/compliance.php';
require_once __DIR__ . '/../../lib/background_check.php';

/**
 * Auth double. The compliance predicates ask for the same two things
 * lib/org_scope.php does, plus hasRole for the club branch of te_is_club_admin.
 */
class FakeComplianceAuth
{
    /** @param array<int, string[]> $clubRoles club id => roles */
    public function __construct(
        private int $userId = 0,
        private bool $superAdmin = false,
        private array $clubRoles = []
    ) {}

    public function getUserId(): int { return $this->userId; }
    public function isSuperAdmin(): bool { return $this->superAdmin; }

    public function hasRole(string $role, ?int $scopeId = null, ?string $scopeType = null): bool
    {
        return in_array($role, $this->clubRoles[(int) $scopeId] ?? [], true);
    }
}

/**
 * Person-level compliance (GOTR G3, migration 091).
 *
 * Fixture — a GOTR tree with a non-GOTR club beside it, because the thing most
 * worth pinning is that turning this on changes nothing for a club that is not
 * under an org unit:
 *
 *   1 national  Girls on the Run   /1/       req 10 SafeSport      required, 365d, all roles
 *   2 division  West               /1/2/     req 11 Concussion     required, no expiry, head_coach
 *   3 council   Kansas             /1/2/3/   -> club 100
 *   4 council   California         /1/2/4/   -> club 101, req 12 Mandated reporter
 *   club 100                                  req 13 Parking pass  OPTIONAL, volunteer
 *   club 103 (CKU) org_unit_id NULL           req 14 Background check, 730d, all roles
 *
 * What these tests pin, in order of how much damage the alternative does:
 *
 * - A lapsed background check is NOT cleared. The predicate this replaces read
 *   "any cleared row on any team wins" and had no expiry at all, so a coach
 *   cleared on a team they left was cleared for life.
 * - Inheritance is the ancestor chain and nothing else — never a sibling
 *   council's requirements, never a descendant's.
 * - Only `required` rows decide compliance. Collapsing that would mean a
 *   council could not track a nice-to-have certificate without locking people
 *   out over it.
 * - Expiry is computed at the boundary, including across a leap day.
 * - The sweep is idempotent, because it is going to be a tick in a worker that
 *   restarts.
 * - Every function tolerates the tables being absent: this code reaches
 *   production days before the migration is applied by hand, and one of its
 *   callers is a live child-safety gate.
 */
class ComplianceTest extends TestCase
{
    // ---------------------------------------------------------------- fixture

    /** Tables that exist regardless of migrations 090 and 091. */
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
                club_profile_id INTEGER, role TEXT, active BOOLEAN DEFAULT 1,
                revoked_at TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT,
                background_check_status TEXT
            );
            CREATE TABLE team_volunteers (
                id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, user_id INTEGER,
                background_check_status TEXT, status TEXT
            );
            CREATE TABLE documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uploaded_by INTEGER, title TEXT
            );
        ");
        $pdo->exec("
            INSERT INTO users (id, email, first_name, last_name) VALUES
                (50, 'head@gotr.org', 'Hana', 'Head'),
                (51, 'vol@gotr.org', 'Vic', 'Volunteer'),
                (52, 'coach@gotr.org', 'Cal', 'Coach'),
                (53, 'parent@gotr.org', 'Pat', 'Parent'),
                (60, 'cku@cku.org', 'Kim', 'Kansas');
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3),
                (101, 'GOTR California', 4),
                (103, 'Central Kansas United', NULL);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (50, 100, 'coach', 1, NULL),
                (51, 100, 'volunteer', 1, NULL),
                (52, 100, 'coach', 1, NULL),
                (53, 100, 'parent', 1, NULL),
                (60, 103, 'coach', 1, NULL);
        ");
        return $pdo;
    }

    /** basePdo + the migration-090 objects. */
    private function orgPdo(): PDO
    {
        $pdo = $this->basePdo();
        $pdo->exec("
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
        ");
        $pdo->exec("
            INSERT INTO org_units (id, parent_id, type, name, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', '/1/', 0),
                (2, 1, 'division', 'West', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', '/1/2/3/', 2),
                (4, 2, 'council', 'California', '/1/2/4/', 2);
        ");
        return $pdo;
    }

    /** orgPdo + the migration-091 objects and the requirement fixture. */
    private function pdo(): PDO
    {
        $pdo = $this->orgPdo();
        $pdo->exec("
            CREATE TABLE compliance_requirements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                org_unit_id INTEGER, club_profile_id INTEGER,
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
                user_id INTEGER NOT NULL, club_profile_id INTEGER NOT NULL,
                staff_role TEXT NOT NULL, assigned_by INTEGER,
                assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
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
            CREATE TABLE compliance_reminder_streams (
                id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER NOT NULL,
                org_unit_id INTEGER, club_profile_id INTEGER, active BOOLEAN NOT NULL DEFAULT 0,
                steps TEXT NOT NULL DEFAULT '[]', created_by INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE compliance_reminder_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER NOT NULL,
                stream_id INTEGER NOT NULL, days_before INTEGER NOT NULL,
                sent_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (credential_id, stream_id, days_before)
            );
        ");
        $pdo->exec("
            INSERT INTO compliance_requirements
                (id, org_unit_id, club_profile_id, kind, name, proof, validity_days, required, active, sort_order) VALUES
                (10, 1, NULL, 'training', 'SafeSport', 'document', 365, 1, 1, 1),
                (11, 2, NULL, 'training', 'Concussion protocol', 'attested_date', NULL, 1, 1, 2),
                (12, 4, NULL, 'training', 'CA mandated reporter', 'external_link', NULL, 1, 1, 3),
                (13, NULL, 100, 'custom', 'Council parking pass', 'attested_date', NULL, 0, 1, 4),
                (14, NULL, 103, 'background_check', 'CKU background check', 'document', 730, 1, 1, 1);
            INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES
                (11, 'head_coach'),
                (13, 'volunteer');
            INSERT INTO club_staff_roles (user_id, club_profile_id, staff_role) VALUES
                (50, 100, 'head_coach'),
                (51, 100, 'volunteer');
        ");
        return $pdo;
    }

    private function names(array $requirements): array
    {
        $names = array_map(static fn (array $r): string => (string) $r['name'], $requirements);
        sort($names);
        return $names;
    }

    // ------------------------------------------------------------ inheritance

    /**
     * A club's set is its own rows plus every ANCESTOR org unit's — national,
     * division, council — and nothing from a sibling.
     */
    public function testRequirementsInheritTheWholeAncestorChain(): void
    {
        $pdo = $this->pdo();

        $this->assertSame(
            ['Concussion protocol', 'Council parking pass', 'SafeSport'],
            $this->names(te_compliance_requirements_for_club($pdo, 100)),
            'Kansas inherits national and West and adds its own'
        );

        $this->assertSame(
            ['CA mandated reporter', 'Concussion protocol', 'SafeSport'],
            $this->names(te_compliance_requirements_for_club($pdo, 101))
        );
    }

    /** California's own requirement must never reach Kansas. */
    public function testASiblingCouncilsRequirementIsNotInherited(): void
    {
        $pdo = $this->pdo();
        $this->assertNotContains(
            'CA mandated reporter',
            $this->names(te_compliance_requirements_for_club($pdo, 100))
        );
    }

    /**
     * Every non-GOTR club has org_unit_id NULL, forever. It sees only its own
     * rows — which is the entire reason switching this on cannot change
     * anything for an existing club.
     */
    public function testAClubWithNoOrgUnitGetsOnlyItsOwnRequirements(): void
    {
        $pdo = $this->pdo();
        $this->assertSame(
            ['CKU background check'],
            $this->names(te_compliance_requirements_for_club($pdo, 103))
        );
    }

    /** Deactivating a requirement stops it demanding anything, immediately. */
    public function testAnInactiveRequirementIsNotInherited(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('UPDATE compliance_requirements SET active = 0 WHERE id = 10');
        $this->assertNotContains('SafeSport', $this->names(te_compliance_requirements_for_club($pdo, 100)));
    }

    // --------------------------------------------------------- role filtering

    /**
     * A requirement naming roles applies to those roles; a requirement naming
     * none applies to everyone with any staff role.
     */
    public function testRoleFilteringUsesTheRequirementsRoleSet(): void
    {
        $pdo = $this->pdo();

        $this->assertSame(
            ['Concussion protocol', 'SafeSport'],
            $this->names(te_person_requirements($pdo, 50, 100)),
            'a head coach owes the head-coach requirement and the club-wide one, not the volunteer one'
        );

        $this->assertSame(
            ['Council parking pass', 'SafeSport'],
            $this->names(te_person_requirements($pdo, 51, 100)),
            'a volunteer owes the volunteer requirement, not the head-coach one'
        );
    }

    /**
     * A requirement carries a SET of roles. Coaches and volunteers have
     * overlapping lists and a one-role column could not express it.
     */
    public function testARequirementCanApplyToASetOfRoles(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES (11, 'volunteer')");

        $this->assertContains('Concussion protocol', $this->names(te_person_requirements($pdo, 50, 100)));
        $this->assertContains('Concussion protocol', $this->names(te_person_requirements($pdo, 51, 100)));
    }

    /**
     * With no club_staff_roles row the person is derived from user_club_access,
     * and EVERY role is returned rather than the most privileged one — a
     * coach-admin may owe paperwork for either.
     */
    public function testStaffRolesFallBackToUserClubAccess(): void
    {
        $pdo = $this->pdo();

        $this->assertSame(['coach'], te_compliance_staff_roles($pdo, 52, 100));
        $this->assertSame(['head_coach'], te_compliance_staff_roles($pdo, 50, 100), 'the explicit row wins');

        $pdo->exec("INSERT INTO user_club_access (user_id, club_profile_id, role, active) VALUES (52, 100, 'club_admin', 1)");
        $roles = te_compliance_staff_roles($pdo, 52, 100);
        sort($roles);
        $this->assertSame(['club_admin', 'coach'], $roles);
    }

    /** A parent is not staff, owes nothing, and is not silently "compliant". */
    public function testSomebodyWithNoStaffRoleOwesNothing(): void
    {
        $pdo = $this->pdo();
        $this->assertSame([], te_compliance_staff_roles($pdo, 53, 100));
        $this->assertSame([], te_person_requirements($pdo, 53, 100));
    }

    /** A revoked role is not a role, even while `active` still says TRUE. */
    public function testARevokedRoleGrantsNoStaffStanding(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("UPDATE user_club_access SET revoked_at = '2026-08-01' WHERE user_id = 52");
        $this->assertSame([], te_compliance_staff_roles($pdo, 52, 100));
    }

    // ---------------------------------------------------------------- expiry

    /** completed_at + validity_days, on the day, including across a leap day. */
    public function testExpiryIsComputedOnTheBoundaryDays(): void
    {
        $this->assertSame('2027-01-01', te_compliance_expiry_from('2026-01-01', 365));
        $this->assertSame('2024-12-31', te_compliance_expiry_from('2024-01-01', 365),
            '2024 has a 29 February, so 365 days lands a day earlier in the year');
        $this->assertSame('2028-01-01', te_compliance_expiry_from('2026-01-01', 730));
        $this->assertNull(te_compliance_expiry_from('2026-01-01', null), 'no validity means it never expires');
        $this->assertNull(te_compliance_expiry_from(null, 365), 'nothing completed, nothing to expire');
        $this->assertNull(te_compliance_expiry_from('not a date', 365));
    }

    public function testUpsertStoresTheComputedExpiry(): void
    {
        $pdo = $this->pdo();
        $result = te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 10,
            'status' => 'verified', 'completed_at' => '2026-03-01', 'source' => 'admin',
        ], 99);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['created']);
        $this->assertSame('2027-03-01', $result['expires_at']);

        $row = $pdo->query('SELECT * FROM person_credentials WHERE id = ' . $result['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2027-03-01', $row['expires_at']);
        $this->assertSame('verified', $row['status']);
        $this->assertSame(99, (int) $row['verified_by'], 'the reviewer is the actor, not the subject');
    }

    /** A certificate carrying its own printed expiry is recorded as it stands. */
    public function testAnExplicitExpiryOverridesTheComputedOne(): void
    {
        $pdo = $this->pdo();
        $result = te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 10,
            'status' => 'verified', 'completed_at' => '2026-03-01', 'expires_at' => '2026-09-30',
        ], 99);
        $this->assertSame('2026-09-30', $result['expires_at']);
    }

    /** An upsert is an upsert: the second write updates, it does not duplicate. */
    public function testUpsertUpdatesTheOneRowPerPersonPerRequirement(): void
    {
        $pdo = $this->pdo();
        $first = te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 10, 'status' => 'submitted', 'completed_at' => '2026-03-01',
        ], 50);
        $second = te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 10, 'status' => 'verified',
        ], 99);

        $this->assertSame($first['id'], $second['id']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM person_credentials')->fetchColumn());

        $row = $pdo->query('SELECT * FROM person_credentials')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-03-01', $row['completed_at'], 'an omitted completed_at keeps the stored one');
        $this->assertSame('2027-03-01', $row['expires_at'], 'and the expiry is recomputed from it');
    }

    public function testUpsertRefusesAnUnknownStatusSourceOrRequirement(): void
    {
        $pdo = $this->pdo();
        $this->assertSame('bad_status', te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 10, 'status' => 'approved',
        ])['reason']);
        $this->assertSame('bad_source', te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 10, 'source' => 'fax',
        ])['reason']);
        $this->assertSame('requirement_not_found', te_credential_upsert($pdo, [
            'user_id' => 50, 'requirement_id' => 999,
        ])['reason']);
    }

    // ---------------------------------------------------------------- status

    /**
     * A stored `verified` whose date has passed reads as `expired` BEFORE the
     * sweep runs. A screen that waited for the sweep would tell a coach they
     * were cleared on the morning their certificate lapsed.
     */
    public function testStatusReportsALapsedVerifiedCredentialAsExpired(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at)
                    VALUES (50, 10, 'verified', '2024-01-01', '2025-01-01')");

        $status = te_compliance_status($pdo, 50, 100, '2026-09-02');
        $safesport = null;
        foreach ($status['requirements'] as $row) {
            if ($row['requirement']['name'] === 'SafeSport') {
                $safesport = $row;
            }
        }
        $this->assertNotNull($safesport);
        $this->assertSame('expired', $safesport['status']);
        $this->assertLessThan(0, $safesport['days_to_expiry']);

        $this->assertSame(
            'verified',
            $pdo->query('SELECT status FROM person_credentials')->fetchColumn(),
            'the read reports the truth without writing it — the sweep owns the column'
        );
    }

    public function testDaysToExpiryCountsWholeDaysFromToday(): void
    {
        $this->assertSame(30, te_compliance_days_to('2026-10-02', '2026-09-02'));
        $this->assertSame(0, te_compliance_days_to('2026-09-02', '2026-09-02'));
        $this->assertSame(-1, te_compliance_days_to('2026-09-01', '2026-09-02'));
        $this->assertNull(te_compliance_days_to(null, '2026-09-02'));
    }

    /**
     * Only `required` rows decide `compliant`. The optional one still counts in
     * `missing`, because a council wants to see it — it just cannot lock anyone
     * out.
     */
    public function testOnlyRequiredRowsDecideCompliance(): void
    {
        $pdo = $this->pdo();

        // The volunteer owes SafeSport (required) and the parking pass (optional).
        te_credential_upsert($pdo, [
            'user_id' => 51, 'requirement_id' => 10,
            'status' => 'verified', 'completed_at' => '2026-08-01',
        ], 99);

        $status = te_compliance_status($pdo, 51, 100, '2026-09-02');
        $this->assertTrue($status['rollup']['compliant'], 'the missing row is optional');
        $this->assertSame(1, $status['rollup']['missing'], 'and it is still counted as missing');
        $this->assertSame(1, $status['rollup']['required_total']);
        $this->assertSame(2, $status['rollup']['total']);
    }

    public function testAMissingRequiredRowIsNotCompliant(): void
    {
        $pdo = $this->pdo();
        $status = te_compliance_status($pdo, 51, 100, '2026-09-02');
        $this->assertFalse($status['rollup']['compliant']);
        $this->assertSame(2, $status['rollup']['missing']);
    }

    /** Submitted is not compliant — the review step exists for a reason. */
    public function testASubmittedButUnreviewedCredentialIsNotCompliant(): void
    {
        $pdo = $this->pdo();
        te_credential_upsert($pdo, [
            'user_id' => 51, 'requirement_id' => 10,
            'status' => 'submitted', 'completed_at' => '2026-08-01', 'source' => 'portal',
        ], 51);
        $status = te_compliance_status($pdo, 51, 100, '2026-09-02');
        $this->assertFalse($status['rollup']['compliant']);
    }

    public function testExpiringWithin30DaysIsCountedSeparatelyFromExpired(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at)
                    VALUES (51, 10, 'verified', '2025-09-20', '2026-09-20')");

        $rollup = te_compliance_status($pdo, 51, 100, '2026-09-02')['rollup'];
        $this->assertSame(1, $rollup['expiring_30']);
        $this->assertSame(0, $rollup['expired']);
        $this->assertTrue($rollup['compliant'], 'expiring is still valid today');
    }

    // ----------------------------------------------------------------- sweep

    /**
     * The sweep is idempotent by construction — its WHERE selects only rows
     * still `verified` — which is what lets it be a tick in a worker that
     * restarts rather than a scheduler nobody can afford to run twice.
     */
    public function testTheExpireSweepIsIdempotent(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at) VALUES
            (50, 10, 'verified', '2024-01-01', '2025-01-01'),
            (51, 10, 'verified', '2026-08-01', '2099-01-01'),
            (52, 10, 'submitted', '2024-01-01', '2025-01-01')");

        $first = te_compliance_expire_sweep($pdo);
        $this->assertTrue($first['ok']);
        $this->assertSame(1, $first['expired'], 'only the lapsed verified row moves');

        $second = te_compliance_expire_sweep($pdo);
        $this->assertSame(0, $second['expired'], 'a second run changes nothing');

        $this->assertSame('expired', $pdo->query('SELECT status FROM person_credentials WHERE user_id = 50')->fetchColumn());
        $this->assertSame('verified', $pdo->query('SELECT status FROM person_credentials WHERE user_id = 51')->fetchColumn());
        $this->assertSame('submitted', $pdo->query('SELECT status FROM person_credentials WHERE user_id = 52')->fetchColumn(),
            'the sweep never touches a row that was never verified');
    }

    // -------------------------------------------------- the volunteer gate

    /**
     * ⚠️ The reason this whole slice exists. `team_volunteers` says cleared —
     * on a team, with no expiry the old predicate ever read — and the person's
     * own credential has lapsed. The answer must be 'expired', not 'cleared'.
     */
    public function testBackgroundCheckReadsCredentialsFirstAndCanSayExpired(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                    VALUES (7, 60, 'cleared', 'active')");
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at)
                    VALUES (60, 14, 'verified', '2022-01-01', '2024-01-01')");

        $this->assertSame('expired', te_background_check_status($pdo, 60));
        $this->assertFalse(te_background_check_cleared($pdo, 60));
    }

    /** A stored 'expired' answers the same as a lapsed 'verified'. */
    public function testAStoredExpiredCredentialAnswersExpired(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at)
                    VALUES (60, 14, 'expired', '2022-01-01', '2024-01-01')");
        $this->assertSame('expired', te_background_check_status($pdo, 60));
    }

    public function testACurrentCredentialClears(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at)
                    VALUES (60, 14, 'verified', '2026-01-01', '2099-01-01')");
        $this->assertSame('cleared', te_background_check_status($pdo, 60));
        $this->assertTrue(te_background_check_cleared($pdo, 60));
    }

    public function testASubmittedCredentialIsPendingNotCleared(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                    VALUES (7, 60, 'cleared', 'active')");
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, source)
                    VALUES (60, 14, 'submitted', 'portal')");
        $this->assertSame('pending', te_background_check_status($pdo, 60));
    }

    /**
     * A REJECTED credential does not fall through to the stale team_volunteers
     * row. Otherwise marking somebody rejected would leave them cleared through
     * a team they no longer coach — the exact failure this replaces.
     */
    public function testARejectedCredentialDoesNotFallBackToTheOldData(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                    VALUES (7, 60, 'cleared', 'active')");
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, rejection_reason)
                    VALUES (60, 14, 'rejected', 'Not the right document')");
        $this->assertSame('none', te_background_check_status($pdo, 60));
    }

    /** No credential row at all: today's logic, unchanged. */
    public function testWithNoCredentialTheOldLogicStillAnswers(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                    VALUES (7, 60, 'cleared', 'active')");
        $this->assertSame('cleared', te_background_check_status($pdo, 60));

        $pdo->exec('DELETE FROM team_volunteers');
        $this->assertSame('none', te_background_check_status($pdo, 60));

        $pdo->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                    VALUES (7, 60, 'pending', 'active')");
        $this->assertSame('pending', te_background_check_status($pdo, 60));
    }

    /** The guardian branch survives: a parent's check is on their guardian row. */
    public function testTheGuardianBranchStillClears(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO guardians (email, background_check_status) VALUES ('CKU@cku.org', 'cleared')");
        $this->assertSame('cleared', te_background_check_status($pdo, 60),
            'the email join is LOWER() on both sides');
    }

    /**
     * The switch restores the old behaviour in a config flip, not a deploy.
     * This is a live child-safety gate, so the new source of truth needs one.
     */
    public function testTheKillSwitchRestoresTheOldSource(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                    VALUES (7, 60, 'cleared', 'active')");
        $pdo->exec("INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at)
                    VALUES (60, 14, 'verified', '2022-01-01', '2024-01-01')");

        $this->assertSame('expired', te_background_check_status($pdo, 60));

        putenv('TE_FEATURE_COMPLIANCE=off');
        $_ENV['TE_FEATURE_COMPLIANCE'] = 'off';
        try {
            $this->assertSame('cleared', te_background_check_status($pdo, 60));
        } finally {
            putenv('TE_FEATURE_COMPLIANCE');
            unset($_ENV['TE_FEATURE_COMPLIANCE']);
        }
    }

    // ------------------------------------------------------ absent tables

    /**
     * This code reaches production days before migration 091 is applied by
     * hand, and one of its callers is a live child-safety gate. A missing table
     * is 42P01 on Postgres — a hard error that would take that gate down rather
     * than hide a feature nobody is using yet.
     */
    public function testEveryFunctionToleratesTheTablesBeingAbsent(): void
    {
        $bare = $this->basePdo();

        $this->assertFalse(te_compliance_tables_present($bare));
        $this->assertSame([], te_compliance_requirements_for_club($bare, 100));
        $this->assertSame(['coach'], te_compliance_staff_roles($bare, 52, 100),
            'the user_club_access fallback works with no compliance tables at all');
        $this->assertSame([], te_person_requirements($bare, 50, 100));
        $this->assertSame('schema', te_credential_upsert($bare, ['user_id' => 50, 'requirement_id' => 10])['reason']);
        $this->assertSame([], te_compliance_status($bare, 50, 100)['requirements']);
        $this->assertFalse(te_compliance_expire_sweep($bare)['ok']);
        $this->assertSame([100], te_compliance_user_club_ids($bare, 52));

        // And the volunteer gate answers off the old data, exactly as today.
        $bare->exec("INSERT INTO team_volunteers (team_id, user_id, background_check_status, status)
                     VALUES (7, 60, 'cleared', 'active')");
        $this->assertSame('cleared', te_background_check_status($bare, 60));
    }

    /** With 091 applied but 090 not, there is no tree — only the club's own rows. */
    public function testWithNoOrgTreeOnlyTheClubsOwnRequirementsApply(): void
    {
        $pdo = $this->basePdo();
        $pdo->exec("
            CREATE TABLE compliance_requirements (
                id INTEGER PRIMARY KEY AUTOINCREMENT, org_unit_id INTEGER, club_profile_id INTEGER,
                kind TEXT NOT NULL DEFAULT 'custom', name TEXT NOT NULL, description TEXT,
                proof TEXT NOT NULL DEFAULT 'attested_date', proof_url TEXT, validity_days INTEGER,
                required BOOLEAN NOT NULL DEFAULT 1, active BOOLEAN NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0, created_by INTEGER,
                created_at TEXT, updated_at TEXT
            );
            CREATE TABLE compliance_requirement_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER, staff_role TEXT
            );
            CREATE TABLE club_staff_roles (user_id INTEGER, club_profile_id INTEGER, staff_role TEXT);
            CREATE TABLE person_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, requirement_id INTEGER,
                status TEXT, completed_at TEXT, expires_at TEXT, document_id INTEGER,
                submitted_at TEXT, verified_by INTEGER, verified_at TEXT, rejection_reason TEXT,
                source TEXT, notes TEXT, created_at TEXT, updated_at TEXT
            );
            CREATE TABLE compliance_reminder_streams (id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER);
            CREATE TABLE compliance_reminder_log (id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER);
        ");
        $pdo->exec("INSERT INTO compliance_requirements (id, org_unit_id, club_profile_id, name)
                    VALUES (13, NULL, 100, 'Council parking pass'), (10, 1, NULL, 'SafeSport')");

        $this->assertSame(
            ['Council parking pass'],
            $this->names(te_compliance_requirements_for_club($pdo, 100)),
            'without the tree there is nothing to inherit from'
        );
    }

    // ------------------------------------------------------------- standing

    public function testOrgAdminsAdministerTheirCouncilsAndNobodyElses(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO user_org_access (user_id, org_unit_id, role, active) VALUES
            (70, 2, 'org_admin', 1),
            (71, 1, 'org_viewer', 1),
            (72, 4, 'org_admin', 1)");

        $this->assertTrue(te_compliance_can_admin_club($pdo, new FakeComplianceAuth(70), 100),
            'a division admin administers its councils');
        $this->assertFalse(te_compliance_can_admin_club($pdo, new FakeComplianceAuth(71), 100),
            'an org_viewer reads rollups and writes nothing');
        $this->assertFalse(te_compliance_can_admin_club($pdo, new FakeComplianceAuth(72), 100),
            'a sibling council admin has no standing here');
        $this->assertFalse(te_compliance_can_admin_club($pdo, new FakeComplianceAuth(50), 100),
            'a head coach is not a club admin');

        $this->assertTrue(
            te_compliance_can_admin_club($pdo, new FakeComplianceAuth(80, false, [100 => ['club_admin']]), 100)
        );
        $this->assertFalse(
            te_compliance_can_admin_club($pdo, new FakeComplianceAuth(80, false, [100 => ['coach']]), 100),
            'a coach is team-scoped; this is club-wide staff data about other people'
        );
        $this->assertTrue(te_compliance_can_admin_club($pdo, new FakeComplianceAuth(90, true), 103));
    }

    public function testClubStaffRollCallExcludesFamilies(): void
    {
        $pdo = $this->pdo();
        $ids = array_map(static fn (array $r): int => $r['user_id'], te_compliance_club_staff($pdo, 100));
        sort($ids);
        $this->assertSame([50, 51, 52], $ids, 'the parent is not staff');
    }

    // -------------------------------------------------------- the gateway

    /**
     * A procedural gateway cannot be executed by a test, so this parses it.
     *
     * The failure it guards against is not a wrong predicate — it is an action
     * added ABOVE the gate. index.php performs no authentication of its own, so
     * whatever this file does is the whole of the access control.
     */
    public function testEveryGatewayActionIsBehindTheFlagAndTheRightPredicate(): void
    {
        $path = __DIR__ . '/../../api/compliance-gateway.php';
        $src = file_get_contents($path);

        $auth = strpos($src, 'AuthMiddleware::requireAuth()');
        $this->assertNotFalse($auth, 'the gateway must authenticate');

        // The STATEMENT, not the mention of it in the file's docblock.
        $flag = strpos($src, "if (!te_feature_enabled('COMPLIANCE'))");
        $this->assertNotFalse($flag, 'the kill switch is gone');
        $this->assertLessThan($flag, $auth, 'authenticate first, then check the switch');
        $this->assertStringContainsString("te_feature_disabled_response('COMPLIANCE')", $src,
            'a feature that is off must say so, never report success for work it did not do');

        // Every action dispatches AFTER the single flag check.
        foreach ([
            'requirements', 'requirement-save', 'requirement-delete',
            'my-requirements', 'record', 'submit', 'review', 'club-status',
        ] as $action) {
            $offset = strpos($src, "\$action === '$action'");
            $this->assertNotFalse($offset, "action $action is missing from the gateway");
            $this->assertLessThan($offset, $flag, "action $action dispatches before the kill switch");
            $this->assertLessThan($offset, $auth, "action $action dispatches before authentication");
        }

        // The club-wide actions gate on the ADMIN predicate. te_is_club_staff
        // would admit a coach, who is team-scoped, to club-wide staff data about
        // other people's background checks.
        foreach (['requirements', 'record', 'review', 'club-status'] as $action) {
            $this->assertStringContainsString('te_compliance_can_admin_club', $src,
                "$action must gate on the admin predicate");
        }
        $this->assertStringNotContainsString('te_is_club_staff(', $src,
            'te_is_club_staff admits a coach — the wrong predicate for club-wide compliance data');

        // A tier-owned requirement needs org_admin, never org_viewer.
        $this->assertStringContainsString("te_user_org_standing(\$pdo, \$auth, \$orgUnitId) !== 'org_admin'", $src);

        // The two self-service actions take the subject from the token.
        $selfService = substr($src, strpos($src, "\$action === 'submit'"));
        $this->assertStringNotContainsString("\$body['user_id']", $selfService,
            'submit must never take a user_id from the body — it is the only write a non-admin can make');

        // Every write is audited.
        foreach ([
            'compliance_requirement_saved', 'compliance_requirement_deleted',
            'compliance_credential_recorded', 'compliance_credential_verified',
            'compliance_credential_rejected', 'compliance_credential_submitted',
        ] as $auditAction) {
            $this->assertStringContainsString("'$auditAction'", $src, "no audit row is written for $auditAction");
        }
    }

    /** The reverse SQL is in the header, and it drops what the file creates. */
    public function testTheMigrationCarriesItsOwnReverse(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/091_compliance.sql');
        $this->assertStringContainsString('REVERSE SQL', $sql);
        foreach (TE_COMPLIANCE_TABLES as $table) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $table", $sql);
            $this->assertStringContainsString("DROP TABLE IF EXISTS $table;", $sql);
        }
        $this->assertStringContainsString('compliance_requirements_one_owner', $sql,
            'exactly one of org_unit_id / club_profile_id must be a CHECK, not a convention');
    }
}
