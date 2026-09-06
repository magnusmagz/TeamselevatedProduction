<?php

/**
 * The SQLite fixture shared by the G7 tests (streams and intake).
 *
 * The same tree as ComplianceRemindersTest, plus migration 098's two intake
 * tables and an audit_log the rate-limit fallback can count against:
 *
 *   1 national  Girls on the Run   /1/       req 10 SafeSport      required, 365d, all roles
 *   2 division  West               /1/2/     req 11 Concussion     required, no expiry, head_coach
 *   3 council   Kansas             /1/2/3/   -> club 100
 *   4 council   California         /1/2/4/   -> club 101
 *   5 national  Other Org          /5/       -> club 102 (a different tree entirely)
 *   club 100                                  req 13 Parking pass  OPTIONAL, volunteer
 *
 * People: 50 head coach @100, 51 volunteer @100, 52 coach @100, 53 parent @100,
 * 56 coach @101, 57 coach @102 (other tree), 58 coach @100 with no first name.
 */
trait ComplianceG7Fixture
{
    private function g7pdo(bool $withDedupeIndex = true): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT,
                last_login_at TEXT
            );
            CREATE TABLE club_profile (
                id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active BOOLEAN DEFAULT 1,
                revoked_at TEXT
            );
            CREATE TABLE documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, uploaded_by INTEGER, title TEXT
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
                stream_id INTEGER, days_before INTEGER NOT NULL,
                sent_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (credential_id, stream_id, days_before)
            );
            CREATE TABLE compliance_intake_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT, org_unit_id INTEGER NOT NULL,
                name TEXT NOT NULL, key_hash TEXT NOT NULL UNIQUE, key_prefix TEXT NOT NULL,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_used_at TEXT, revoked_at TEXT, revoked_by INTEGER
            );
            CREATE TABLE compliance_intake_unmatched (
                id INTEGER PRIMARY KEY AUTOINCREMENT, org_unit_id INTEGER NOT NULL,
                key_id INTEGER, email TEXT NOT NULL, requirement_key TEXT NOT NULL,
                completed_on TEXT, external_id TEXT, reason TEXT NOT NULL, payload TEXT,
                received_at TEXT DEFAULT CURRENT_TIMESTAMP,
                matched_user_id INTEGER, credential_id INTEGER, matched_by INTEGER, matched_at TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT,
                resource_type TEXT, resource_id INTEGER, ip_address TEXT, user_agent TEXT,
                details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        if ($withDedupeIndex) {
            $pdo->exec(
                'CREATE UNIQUE INDEX ' . TE_COMPLIANCE_REMINDER_DEDUPE_INDEX
                . ' ON compliance_reminder_log (credential_id, days_before) WHERE stream_id IS NULL'
            );
        }

        $pdo->exec("
            INSERT INTO users (id, email, first_name, last_name, last_login_at) VALUES
                (50, 'head@gotr.org', 'Hana', 'Head', '2026-09-01 10:00:00'),
                (51, 'vol@gotr.org', 'Vic', 'Volunteer', '2026-09-01 10:00:00'),
                (52, 'coach@gotr.org', 'Cal', 'Coach', '2026-09-01 10:00:00'),
                (53, 'parent@gotr.org', 'Pat', 'Parent', '2026-09-01 10:00:00'),
                (56, 'cali@gotr.org', 'Cass', 'Cali', '2026-09-01 10:00:00'),
                (57, 'other@elsewhere.org', 'Oli', 'Other', '2026-09-01 10:00:00'),
                (58, 'noname@gotr.org', '', 'Nameless', '2026-09-01 10:00:00'),
                (90, 'admin@gotr.org', 'Ada', 'Admin', '2026-09-01 10:00:00');
            INSERT INTO club_profile (id, name, org_unit_id) VALUES
                (100, 'GOTR Kansas', 3),
                (101, 'GOTR California', 4),
                (102, 'Other Org Club', 5);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (50, 100, 'coach', 1, NULL),
                (51, 100, 'volunteer', 1, NULL),
                (52, 100, 'coach', 1, NULL),
                (53, 100, 'parent', 1, NULL),
                (56, 101, 'coach', 1, NULL),
                (57, 102, 'coach', 1, NULL),
                (58, 100, 'coach', 1, NULL);
            INSERT INTO org_units (id, parent_id, type, name, path, depth) VALUES
                (1, NULL, 'national', 'Girls on the Run', '/1/', 0),
                (2, 1, 'division', 'West', '/1/2/', 1),
                (3, 2, 'council', 'Kansas', '/1/2/3/', 2),
                (4, 2, 'council', 'California', '/1/2/4/', 2),
                (5, NULL, 'national', 'Other Org', '/5/', 0);
            INSERT INTO user_org_access (user_id, org_unit_id, role) VALUES
                (90, 2, 'org_admin');
            INSERT INTO compliance_requirements
                (id, org_unit_id, club_profile_id, kind, name, proof, validity_days, required, active, sort_order) VALUES
                (10, 1, NULL, 'training', 'SafeSport', 'document', 365, 1, 1, 1),
                (11, 2, NULL, 'training', 'Concussion protocol', 'attested_date', NULL, 1, 1, 2),
                (13, NULL, 100, 'custom', 'Council parking pass', 'attested_date', NULL, 0, 1, 4);
            INSERT INTO compliance_requirement_roles (requirement_id, staff_role) VALUES
                (11, 'head_coach'),
                (13, 'volunteer');
            INSERT INTO club_staff_roles (user_id, club_profile_id, staff_role) VALUES
                (50, 100, 'head_coach'),
                (51, 100, 'volunteer');
        ");

        return $pdo;
    }

    /** A verified credential for user $u against requirement $r expiring on $expires. */
    private function g7verified(PDO $pdo, int $user, int $requirement, string $expires, string $status = 'verified'): int
    {
        $pdo->prepare(
            "INSERT INTO person_credentials (user_id, requirement_id, status, completed_at, expires_at, source)
             VALUES (?, ?, ?, '2026-01-01', ?, 'admin')"
        )->execute([$user, $requirement, $status, $expires]);
        return (int) $pdo->lastInsertId();
    }

    /** An authored stream row, active by default. Returns its id. */
    private function g7stream(PDO $pdo, int $requirement, ?int $clubId, ?int $orgUnitId, array $steps, bool $active = true): int
    {
        $pdo->prepare(
            'INSERT INTO compliance_reminder_streams (requirement_id, club_profile_id, org_unit_id, active, steps, created_by)
             VALUES (?, ?, ?, ?, ?, 90)'
        )->execute([$requirement, $clubId, $orgUnitId, $active ? 1 : 0, json_encode($steps)]);
        return (int) $pdo->lastInsertId();
    }

    /** A minimal AuthMiddleware stand-in: a user id, and whether they are a super admin. */
    private function g7auth(int $userId, bool $super = false, array $clubAdminOf = []): object
    {
        return new class($userId, $super, $clubAdminOf) {
            public function __construct(private int $userId, private bool $super, private array $clubs) {}
            public function getUserId(): int { return $this->userId; }
            public function isSuperAdmin(): bool { return $this->super; }
            public function hasRole($role, $scopeId = null, $scopeType = null): bool
            {
                return $role === 'club_admin' && in_array((int) $scopeId, $this->clubs, true);
            }
            public function getActiveContext() { return null; }
        };
    }

    private function g7steps(): array
    {
        return [
            ['days_before' => 60, 'subject' => '{{requirement_name}} expires in {{days_left}} days', 'body' => "Hi {{first_name}}, your {{requirement_name}} for {{club_name}} expires on {{expires_on}}. Renew: {{renewal_url}}", 'channel' => 'email'],
            ['days_before' => 14, 'subject' => 'Two weeks left on {{requirement_name}}', 'body' => "{{first_name}}, {{requirement_name}} expires {{expires_on}}.", 'channel' => 'email'],
            ['days_before' => -7, 'subject' => '{{requirement_name}} expired', 'body' => "{{first_name}}, your {{requirement_name}} expired {{days_left}} days ago. Renew: {{renewal_url}}", 'channel' => 'email'],
        ];
    }
}
