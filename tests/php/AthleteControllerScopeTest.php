<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteController;

require_once __DIR__ . '/../../controllers/AthleteController.php';

/**
 * Scope-enforcement tests for AthleteController::getAthlete().
 *
 * This is the route handler behind `GET /api/athletes/(\d+)` in index.php, which
 * previously returned the athlete (including the medical record) with NO auth or
 * scope check — the third athlete read path (#25). The other two read paths
 * (api/athletes.php, legacy/athletes-gateway.php) were already locked down using
 * AthleteScope; this test proves the controller now mirrors that behavior:
 *
 *   - an unrelated user gets HTTP 403 "Access denied" and NO athlete payload
 *     (in particular, no medical data leaks);
 *   - a guardian / coach / club admin in scope gets the athlete back.
 *
 * Runs entirely against an in-memory SQLite fixture (same pattern as
 * AthleteScopeTest) — never touches the production Neon database. The
 * controller's PDO is injected and its auth resolution is overridden via the
 * resolveAuth() seam, so no JWT/token is required.
 */
class AthleteControllerScopeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
    }

    private function createSchema(): void
    {
        // Core scope tables (mirror AthleteScopeTest) plus the detail tables
        // getAthlete() reads after the access check passes.
        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT,
                club_id INTEGER,
                primary_coach_id INTEGER,
                deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                user_id INTEGER,
                athlete_id INTEGER,
                role TEXT,
                status TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                last_name TEXT,
                active_status INTEGER DEFAULT 1
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                last_name TEXT,
                email TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER,
                guardian_id INTEGER,
                relationship TEXT,
                is_primary INTEGER
            );
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT
            );
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                guardian_id INTEGER,
                source TEXT,
                confidence TEXT
            );
            CREATE TABLE emergency_contacts (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER,
                priority_order INTEGER
            );
            CREATE TABLE medical_records (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER,
                blood_type TEXT,
                has_asthma INTEGER
            );
            CREATE TABLE allergies (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER
            );
            CREATE TABLE medications (
                id INTEGER PRIMARY KEY,
                athlete_id INTEGER
            );
        ");
    }

    private function seed(): void
    {
        // Athlete 1 -> Team 10 (club 100, primary_coach 50), guardian alice (200)
        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name) VALUES (1, 'Anna', 'Aaron')");
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES (10, 'Team A', 100, 50, NULL)");
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES (1, 10, NULL, 1, 'player', 'active')");
        $this->pdo->exec("INSERT INTO guardians (id, first_name, last_name, email) VALUES (200, 'Alice', 'Aaron', 'alice@family-a.com')");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary) VALUES (1, 1, 200, 'Mother', 1)");

        // Guardian standing is resolved from the ACCOUNT (lib/guardian_identity.php), so
        // the accounts these cases sign in as must exist.
        $this->pdo->exec("INSERT INTO users (id, email) VALUES
            (50, 'coach50@club.test'), (60, 'admin@club.test'),
            (70, 'alice@family-a.com'), (999, 'nobody@example.com')");

        // Sensitive medical record for athlete 1 — must NOT leak to unrelated users.
        $this->pdo->exec("INSERT INTO medical_records (id, athlete_id, blood_type, has_asthma) VALUES (1, 1, 'O+', 1)");
        $this->pdo->exec("INSERT INTO emergency_contacts (id, athlete_id, priority_order) VALUES (1, 1, 1)");
    }

    private function controller(AuthMiddleware $auth): AthleteController
    {
        return new class($this->pdo, $auth) extends AthleteController {
            private AuthMiddleware $injectedAuth;
            public function __construct(PDO $db, AuthMiddleware $auth)
            {
                parent::__construct($db);
                $this->injectedAuth = $auth;
            }
            protected function resolveAuth()
            {
                return $this->injectedAuth;
            }
        };
    }

    /**
     * Capture the JSON body + HTTP status produced by getAthlete().
     */
    private function callGetAthlete(AuthMiddleware $auth, int $athleteId): array
    {
        $controller = $this->controller($auth);
        http_response_code(200);
        ob_start();
        try {
            $controller->getAthlete($athleteId);
        } finally {
            $body = ob_get_clean();
        }
        return [
            'status' => http_response_code(),
            'json' => json_decode($body, true),
        ];
    }

    private function guardian(string $email): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => $email, 'roles' => []]);
    }

    private function clubAdmin(int $clubId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60,
            'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    private function coach(int $userId): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => $userId, 'email' => "coach{$userId}@club.test", 'roles' => []]);
    }

    private function unrelated(): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 999, 'email' => 'nobody@example.com', 'roles' => []]);
    }

    public function testUnrelatedUserGets403AndNoAthleteData(): void
    {
        $result = $this->callGetAthlete($this->unrelated(), 1);

        $this->assertSame(403, $result['status']);
        $this->assertFalse($result['json']['success']);
        $this->assertSame('Access denied', $result['json']['error']);
        // Critically: no athlete payload (and therefore no medical record) is returned.
        $this->assertArrayNotHasKey('athlete', $result['json']);
    }

    public function testGuardianInScopeGetsAthleteWithMedical(): void
    {
        $result = $this->callGetAthlete($this->guardian('alice@family-a.com'), 1);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['json']['success']);
        $this->assertSame(1, (int) $result['json']['athlete']['id']);
        // The detail payload (including medical) is only delivered to authorized users.
        $this->assertSame('O+', $result['json']['athlete']['medical']['blood_type']);
    }

    public function testHeadCoachInScopeGetsAthlete(): void
    {
        $result = $this->callGetAthlete($this->coach(50), 1);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['json']['success']);
        $this->assertSame(1, (int) $result['json']['athlete']['id']);
    }

    public function testClubAdminInScopeGetsAthlete(): void
    {
        $result = $this->callGetAthlete($this->clubAdmin(100), 1);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['json']['success']);
        $this->assertSame(1, (int) $result['json']['athlete']['id']);
    }

    public function testClubAdminOfWrongClubIsDenied(): void
    {
        $result = $this->callGetAthlete($this->clubAdmin(101), 1);

        $this->assertSame(403, $result['status']);
        $this->assertArrayNotHasKey('athlete', $result['json']);
    }
}
