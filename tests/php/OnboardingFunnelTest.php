<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/onboarding_funnel.php';

/**
 * The national onboarding funnel (GOTR G6): per descendant council, how many
 * coach accounts exist, were invited, accepted the invite (`used_at`), have
 * signed in (the portal_status evidence order — audit login_success, then
 * users.last_login_at), and are compliant (te_compliance_status, reused).
 *
 * Fixture, under division West (2):
 *   Kansas (3, code KS)      -> club 100
 *   California (4, code CA)  -> club 101
 * and outside it:
 *   Boston (6, under East)   -> club 102   must never appear in West's rollup
 *   CKU                      -> club 103   no org unit, never in any rollup
 */
class OnboardingFunnelTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT,
                password_hash TEXT, last_login_at TEXT
            );
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER);
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                active BOOLEAN DEFAULT 1, revoked_at TEXT
            );
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, expires_at TEXT, used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, details TEXT, created_at TEXT
            );
            CREATE TABLE org_units (
                id INTEGER PRIMARY KEY, parent_id INTEGER, type TEXT NOT NULL, name TEXT NOT NULL,
                external_code TEXT, path TEXT NOT NULL, depth INTEGER NOT NULL
            );
            CREATE TABLE user_org_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, org_unit_id INTEGER, role TEXT,
                active BOOLEAN DEFAULT 1, revoked_at TEXT
            );
            INSERT INTO org_units (id, parent_id, type, name, external_code, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', 'GOTR', '/1/', 0),
                (2, 1, 'division', 'West', 'WEST', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', 'KS', '/1/2/3/', 2),
                (4, 2, 'council', 'California', 'CA', '/1/2/4/', 2),
                (5, 1, 'division', 'East', 'EAST', '/1/5/', 1),
                (6, 5, 'council', 'Boston', 'BOS', '/1/5/6/', 2);
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3), (101, 'GOTR California', 4), (102, 'GOTR Boston', 6),
                (103, 'Central Kansas United', NULL);
            INSERT INTO users (id, email, first_name, last_name, password_hash, last_login_at) VALUES
                (10, 'k1@gotr.org', 'K', 'One', 'h', NULL),          -- accepted + audit login
                (11, 'K2@gotr.org', 'K', 'Two', NULL, NULL),         -- invited, not accepted (case differs from token)
                (12, 'k3@gotr.org', 'K', 'Three', NULL, NULL),       -- account only
                (13, 'k4@gotr.org', 'K', 'Four', 'h', '2026-08-01'), -- never invited (password123 era), signed in via last_login_at
                (20, 'c1@gotr.org', 'C', 'One', NULL, NULL),         -- california, invited
                (30, 'b1@gotr.org', 'B', 'One', 'h', '2026-08-01'),  -- boston: must not count
                (40, 'x@cku.org', 'X', 'Cku', 'h', '2026-08-01'),    -- CKU
                (50, 'p@gotr.org', 'P', 'Parent', 'h', '2026-08-01');-- a parent in Kansas, not a coach
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (10, 100, 'coach', 1, NULL), (11, 100, 'coach', 1, NULL), (12, 100, 'coach', 1, NULL),
                (13, 100, 'coach', 1, NULL), (20, 101, 'coach', 1, NULL), (30, 102, 'coach', 1, NULL),
                (40, 103, 'coach', 1, NULL), (50, 100, 'parent', 1, NULL),
                (12, 101, 'coach', 0, '2026-08-01');                 -- revoked elsewhere: not a california account
            INSERT INTO magic_link_tokens (email, token, expires_at, used_at, created_at) VALUES
                ('k1@gotr.org:coach_invite', 't1', '2099-01-01', '2026-08-02 10:00:00', '2026-08-01'),
                ('k2@gotr.org:coach_invite', 't2', '2099-01-01', NULL, '2026-08-01'),
                ('k2@gotr.org:coach_invite', 't2b', '2099-01-01', NULL, '2026-08-03'),  -- re-sent: still ONE person
                ('c1@gotr.org:coach_invite', 't3', '2099-01-01', NULL, '2026-08-01'),
                ('k3@gotr.org:parent_invite', 't4', '2099-01-01', NULL, '2026-08-01');   -- a PARENT invite is not a coach invite
            INSERT INTO audit_log (user_id, action, resource_type, resource_id, created_at) VALUES
                (10, 'login_success', 'users', 10, '2026-08-02 11:00:00');
        ");
    }

    public function testCountsPerDescendantCouncilUnderTheUnit(): void
    {
        $f = te_onboarding_funnel($this->pdo, 2);
        $this->assertTrue($f['available']);

        $byClub = [];
        foreach ($f['councils'] as $row) {
            $byClub[$row['club_id']] = $row;
        }
        $this->assertSame([100, 101], array_keys($byClub), 'West rolls up Kansas and California only');

        $ks = $byClub[100];
        $this->assertSame('GOTR Kansas', $ks['club_name']);
        $this->assertSame('KS', $ks['council_code']);
        $this->assertSame(4, $ks['accounts'], 'coach role rows only — the parent is not counted');
        $this->assertSame(2, $ks['invited'], 'k1 and k2; a re-sent link is still one person; parent invites do not count');
        $this->assertSame(1, $ks['accepted'], 'used_at is the accepted fact');
        $this->assertSame(2, $ks['signed_in'], 'k1 by audit row, k4 by last_login_at');
        $this->assertNull($ks['compliant'], 'no requirements defined — compliance is not applicable, never zero');

        $ca = $byClub[101];
        $this->assertSame(1, $ca['accounts'], 'a revoked access is not an account in that council');
        $this->assertSame(1, $ca['invited']);
        $this->assertSame(0, $ca['accepted']);
        $this->assertSame(0, $ca['signed_in']);
    }

    public function testTotalsSumTheCouncils(): void
    {
        $f = te_onboarding_funnel($this->pdo, 2);
        $this->assertSame(5, $f['totals']['accounts']);
        $this->assertSame(3, $f['totals']['invited']);
        $this->assertSame(1, $f['totals']['accepted']);
        $this->assertSame(2, $f['totals']['signed_in']);
        $this->assertSame('West', $f['org_unit']['name']);
    }

    public function testACouncilItselfReportsOneRow(): void
    {
        $f = te_onboarding_funnel($this->pdo, 3);
        $this->assertCount(1, $f['councils']);
        $this->assertSame(100, $f['councils'][0]['club_id']);
    }

    public function testAnUnknownUnitIsNotAvailable(): void
    {
        $f = te_onboarding_funnel($this->pdo, 999);
        $this->assertFalse($f['available']);
        $this->assertSame([], $f['councils']);
    }

    public function testCompliantReusesTheComplianceStatusRule(): void
    {
        // The 091 objects, and one required requirement on West that applies to coaches.
        $this->pdo->exec("
            CREATE TABLE compliance_requirements (
                id INTEGER PRIMARY KEY AUTOINCREMENT, org_unit_id INTEGER, club_profile_id INTEGER,
                kind TEXT NOT NULL DEFAULT 'custom', name TEXT NOT NULL, description TEXT,
                proof TEXT NOT NULL DEFAULT 'attested_date', proof_url TEXT, validity_days INTEGER,
                required BOOLEAN NOT NULL DEFAULT 1, active BOOLEAN NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0, created_by INTEGER, created_at TEXT, updated_at TEXT
            );
            CREATE TABLE compliance_requirement_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER NOT NULL, staff_role TEXT NOT NULL
            );
            CREATE TABLE club_staff_roles (
                user_id INTEGER NOT NULL, club_profile_id INTEGER NOT NULL, staff_role TEXT NOT NULL,
                assigned_by INTEGER, assigned_at TEXT
            );
            CREATE TABLE person_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, requirement_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'missing', completed_at TEXT, expires_at TEXT, document_id INTEGER,
                submitted_at TEXT, verified_by INTEGER, verified_at TEXT, rejection_reason TEXT,
                source TEXT NOT NULL DEFAULT 'admin', notes TEXT, created_at TEXT, updated_at TEXT
            );
            CREATE TABLE compliance_reminder_streams (id INTEGER PRIMARY KEY AUTOINCREMENT, requirement_id INTEGER);
            CREATE TABLE compliance_reminder_log (id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER);
            INSERT INTO compliance_requirements (id, org_unit_id, name, required, active) VALUES (1, 2, 'Background check', 1, 1);
            INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES (1, 'coach');
            INSERT INTO person_credentials (user_id, requirement_id, status, expires_at) VALUES
                (10, 1, 'verified', '2099-01-01'),
                (11, 1, 'submitted', NULL);
        ");
        $f = te_onboarding_funnel($this->pdo, 2, '2026-09-01');
        $byClub = [];
        foreach ($f['councils'] as $row) {
            $byClub[$row['club_id']] = $row;
        }
        $this->assertSame(1, $byClub[100]['compliant'], 'k1 verified; k2 submitted is not compliant; k3/k4 missing');
        $this->assertSame(0, $byClub[101]['compliant']);
        $this->assertSame(1, $f['totals']['compliant']);
    }

    public function testTheEndpointAuthenticatesAndGatesOnOrgStanding(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/onboarding-funnel.php');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $src);
        $this->assertStringContainsString('te_user_org_standing(', $src);
        $this->assertStringContainsString('te_onboarding_funnel(', $src);
        $this->assertStringNotContainsString('JWT::decode(', $src);
    }
}
