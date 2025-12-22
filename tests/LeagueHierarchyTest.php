<?php
/**
 * League Hierarchy System Tests
 *
 * Run with: php tests/LeagueHierarchyTest.php
 *
 * Tests the league hierarchy implementation:
 * - Enhanced JWT generation with organizational context
 * - Context switching
 * - Permission-based access control
 * - League and team management with proper scoping
 */

class LeagueHierarchyTest {
    private $baseUrl;
    private $superAdminToken;
    private $leagueAdminToken;
    private $clubAdminToken;
    private $testLeagueId;
    private $testClubId;
    private $testUserId;

    public function __construct($baseUrl = 'http://localhost:8889') {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Run all tests
     */
    public function runAll() {
        echo "========================================\n";
        echo "   LEAGUE HIERARCHY TEST SUITE         \n";
        echo "========================================\n\n";

        $results = [
            'passed' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // Test suite
        $tests = [
            'testDatabaseMigration',
            'testUserRegistrationWithEnhancedJWT',
            'testCreateLeagueAsSuperAdmin',
            'testCreateClubInLeague',
            'testAssignLeagueAdmin',
            'testLeagueAdminCanAccessLeague',
            'testContextSwitching',
            'testTeamCreationWithClubScoping',
            'testQueryScopingForTeams',
            'testPermissionDeniedForUnauthorized',
            'testLeagueAdminCannotAccessOtherLeagues',
        ];

        foreach ($tests as $test) {
            echo "Running: $test\n";
            try {
                $this->$test();
                echo "  ✓ PASSED\n";
                $results['passed']++;
            } catch (Exception $e) {
                echo "  ✗ FAILED: " . $e->getMessage() . "\n";
                $results['failed']++;
                $results['errors'][] = "$test: " . $e->getMessage();
            }
            echo "\n";
        }

        // Summary
        echo "========================================\n";
        echo "            TEST SUMMARY               \n";
        echo "========================================\n";
        echo "Passed: {$results['passed']}\n";
        echo "Failed: {$results['failed']}\n";

        if (!empty($results['errors'])) {
            echo "\nErrors:\n";
            foreach ($results['errors'] as $error) {
                echo "  - $error\n";
            }
        }

        return $results['failed'] === 0;
    }

    /**
     * Test: Database migration completed successfully
     */
    public function testDatabaseMigration() {
        // Check if leagues table exists and has Legacy League
        $response = $this->directDbQuery("SELECT COUNT(*) as count FROM leagues WHERE name = 'Legacy League'");

        $this->assertTrue($response > 0, 'Legacy League should exist after migration');
    }

    /**
     * Test: User registration returns enhanced JWT with organizational context
     */
    public function testUserRegistrationWithEnhancedJWT() {
        $userData = [
            'email' => 'testuser_' . time() . '@example.com',
            'password' => 'TestPass123',
            'first_name' => 'Test',
            'last_name' => 'User'
        ];

        $response = $this->request('POST', '/api/auth-gateway.php?action=register', $userData);

        $this->assertTrue($response['success'] ?? false, 'Registration should succeed');
        $this->assertNotEmpty($response['token'] ?? null, 'Should return JWT token');
        $this->assertNotEmpty($response['user'] ?? null, 'Should return user object');

        // Check enhanced JWT structure
        $user = $response['user'];
        $this->assertArrayHasKey('system_role', $user, 'User should have system_role');
        $this->assertArrayHasKey('organization', $user, 'User should have organization');
        $this->assertArrayHasKey('roles', $user, 'User should have roles array');
        // Note: New users without roles will have null/empty activeRole - this is expected

        // Store for later tests
        $this->testUserId = $user['id'];

        echo "    → Enhanced JWT structure verified (new user has no roles yet)\n";
    }

    /**
     * Test: Create league as super admin
     */
    public function testCreateLeagueAsSuperAdmin() {
        // First, we need to make a user super admin via direct DB access
        // In real scenario, this would be done through admin interface
        $testEmail = $this->getTestUserEmail();
        $this->directDbQuery("UPDATE users SET system_role = 'super_admin' WHERE email = ?", [$testEmail]);

        // Login as super admin
        $loginResponse = $this->request('POST', '/api/auth-gateway.php?action=login', [
            'email' => $testEmail,
            'password' => 'TestPass123'
        ]);

        $this->superAdminToken = $loginResponse['token'];
        $this->assertTrue($loginResponse['user']['system_role'] === 'super_admin', 'User should be super admin');

        // Create a league
        $leagueData = [
            'name' => 'Test League ' . time(),
            'description' => 'Test league for automated testing',
            'contact_email' => 'league@test.com'
        ];

        $response = $this->authenticatedRequest('POST', '/legacy/leagues-gateway.php', $leagueData, $this->superAdminToken);

        $this->assertTrue($response['success'] ?? false, 'League creation should succeed');
        $this->assertNotEmpty($response['id'] ?? null, 'Should return league ID');

        $this->testLeagueId = $response['id'];
        echo "    → Created league with ID: {$this->testLeagueId}\n";
    }

    /**
     * Test: Create club in league
     */
    public function testCreateClubInLeague() {
        // This would typically use club-profile-gateway.php
        // For now, we'll create directly via DB
        $clubId = $this->directDbQuery(
            "INSERT INTO club_profile (name, description, league_id) VALUES ('Test Club', 'Test club', ?) RETURNING id",
            [$this->testLeagueId]
        );

        $this->assertNotEmpty($clubId, 'Club should be created');
        $this->testClubId = $clubId;

        echo "    → Created club with ID: {$this->testClubId}\n";
    }

    /**
     * Test: Assign league admin role to user
     */
    public function testAssignLeagueAdmin() {
        // Ensure we have a test league
        if (!$this->testLeagueId) {
            throw new Exception('Test league must be created before assigning league admin');
        }

        // Create a new user for league admin role
        $userData = [
            'email' => 'leagueadmin_' . time() . '@example.com',
            'password' => 'TestPass123',
            'first_name' => 'League',
            'last_name' => 'Admin'
        ];

        $response = $this->request('POST', '/api/auth-gateway.php?action=register', $userData);
        $leagueAdminUserId = $response['user']['id'];

        // Verify league exists
        $leagueExists = $this->directDbQuery("SELECT COUNT(*) FROM leagues WHERE id = ?", [$this->testLeagueId]);
        if (!$leagueExists) {
            throw new Exception("League with ID {$this->testLeagueId} does not exist");
        }

        // Assign league admin role via direct DB
        $inserted = $this->directDbQuery(
            "INSERT INTO user_league_access (user_id, league_id, role, active) VALUES (?, ?, 'league_admin', TRUE)",
            [$leagueAdminUserId, $this->testLeagueId]
        );

        $this->assertTrue($inserted > 0, 'League admin role should be assigned');

        // Login as league admin
        $loginResponse = $this->request('POST', '/api/auth-gateway.php?action=login', [
            'email' => $userData['email'],
            'password' => 'TestPass123'
        ]);

        $this->leagueAdminToken = $loginResponse['token'];

        // Verify roles in JWT
        $user = $loginResponse['user'];
        $this->assertTrue(count($user['roles']) > 0, 'User should have roles');

        $hasLeagueAdminRole = false;
        foreach ($user['roles'] as $role) {
            if ($role['role'] === 'league_admin' && $role['scope_id'] == $this->testLeagueId) {
                $hasLeagueAdminRole = true;
                break;
            }
        }
        $this->assertTrue($hasLeagueAdminRole, 'User should have league_admin role');

        echo "    → League admin role assigned and verified\n";
    }

    /**
     * Test: League admin can access their league
     */
    public function testLeagueAdminCanAccessLeague() {
        $response = $this->authenticatedRequest('GET', "/legacy/leagues-gateway.php?id={$this->testLeagueId}", null, $this->leagueAdminToken);

        $this->assertNotEmpty($response['id'] ?? null, 'League admin should be able to view their league');
        $this->assertEquals($this->testLeagueId, $response['id'], 'Should return correct league');

        echo "    → League admin can access their league\n";
    }

    /**
     * Test: Context switching works correctly
     */
    public function testContextSwitching() {
        // Assign club admin role to league admin user
        $leagueAdminUserId = $this->getUserIdFromToken($this->leagueAdminToken);

        $this->directDbQuery(
            "INSERT INTO user_club_access (user_id, club_profile_id, role, active) VALUES (?, ?, 'club_admin', TRUE)",
            [$leagueAdminUserId, $this->testClubId]
        );

        // Switch context from league to club
        $response = $this->authenticatedRequest('POST', '/api/auth-gateway.php?action=switch-context', [
            'scope_id' => $this->testClubId,
            'scope_type' => 'club'
        ], $this->leagueAdminToken);

        $this->assertTrue($response['success'] ?? false, 'Context switch should succeed');
        $this->assertNotEmpty($response['token'] ?? null, 'Should return new token');

        // Verify new active context
        $user = $response['user'];
        $this->assertEquals($this->testClubId, $user['activeRole']['scope_id'] ?? null, 'Active context should be the club');
        $this->assertEquals('club', $user['activeRole']['scope_type'] ?? null, 'Active context type should be club');

        echo "    → Context switching from league to club successful\n";
    }

    /**
     * Test: Team creation with club scoping
     */
    public function testTeamCreationWithClubScoping() {
        // Create a season first
        $seasonId = $this->directDbQuery(
            "INSERT INTO seasons (name, start_date, end_date) VALUES ('Test Season', '2025-01-01', '2025-12-31') RETURNING id"
        );

        // Switch to club context first
        $switchResponse = $this->authenticatedRequest(
            'POST',
            '/api/auth-gateway.php?action=switch-context',
            [
                'scope_id' => $this->testClubId,
                'scope_type' => 'club'
            ],
            $this->leagueAdminToken
        );

        $clubToken = $switchResponse['token'] ?? null;
        $this->assertNotEmpty($clubToken, 'Should receive new token after context switch');

        // Create team as club admin (with active club context)
        $teamData = [
            'name' => 'Test Team ' . time(),
            'age_group' => 'U12',
            'division' => 'Recreational',
            'season_id' => $seasonId
        ];

        $response = $this->authenticatedRequest('POST', '/legacy/teams-gateway.php', $teamData, $clubToken);

        $this->assertTrue($response['success'] ?? false, 'Team creation should succeed: ' . json_encode($response));
        $this->assertNotEmpty($response['id'] ?? null, 'Should return team ID');

        // Verify team has club_id and league_id set
        $teamId = $response['id'];
        $team = $this->directDbQuery("SELECT club_id, league_id FROM teams WHERE id = ?", [$teamId], true);

        $this->assertEquals($this->testClubId, $team['club_id'] ?? null, 'Team should have club_id set');
        $this->assertEquals($this->testLeagueId, $team['league_id'] ?? null, 'Team should have league_id set');

        echo "    → Team created with proper club and league associations\n";
    }

    /**
     * Test: Query scoping - users only see teams from their clubs
     */
    public function testQueryScopingForTeams() {
        // Get teams as league admin
        $response = $this->authenticatedRequest('GET', '/legacy/teams-gateway.php', null, $this->leagueAdminToken);

        $this->assertArrayHasKey('teams', $response, 'Should return teams array');
        $teams = $response['teams'];

        // All teams should belong to clubs in the league
        foreach ($teams as $team) {
            $this->assertEquals($this->testLeagueId, $team['league_id'] ?? null,
                'All teams should belong to the league');
        }

        echo "    → Query scoping works correctly - only accessible teams returned\n";
    }

    /**
     * Test: Permission denied for unauthorized access
     */
    public function testPermissionDeniedForUnauthorized() {
        // Create a regular user without any league/club access
        $userData = [
            'email' => 'regularuser_' . time() . '@example.com',
            'password' => 'TestPass123',
            'first_name' => 'Regular',
            'last_name' => 'User'
        ];

        $response = $this->request('POST', '/api/auth-gateway.php?action=register', $userData);
        $regularUserToken = $response['token'];

        // Try to access the test league
        $response = $this->authenticatedRequest('GET', "/legacy/leagues-gateway.php?id={$this->testLeagueId}", null, $regularUserToken, false);

        // Should either get empty result or 403 error
        $this->assertTrue(
            empty($response) || isset($response['error']),
            'Regular user should not be able to access league they are not part of'
        );

        echo "    → Permission denied for unauthorized user ✓\n";
    }

    /**
     * Test: League admin cannot access other leagues
     */
    public function testLeagueAdminCannotAccessOtherLeagues() {
        // Create another league with unique name
        $uniqueName = 'Other League ' . time();
        $otherLeagueId = $this->directDbQuery(
            "INSERT INTO leagues (name, description) VALUES (?, 'Should not be accessible') RETURNING id",
            [$uniqueName]
        );

        // Try to access as league admin
        $response = $this->authenticatedRequest('GET', "/legacy/leagues-gateway.php?id={$otherLeagueId}", null, $this->leagueAdminToken, false);

        // Should not be in the list or should get error
        $this->assertTrue(
            empty($response) || isset($response['error']),
            'League admin should not access other leagues'
        );

        echo "    → League admin correctly restricted to their league ✓\n";
    }

    /**
     * Helper: Make HTTP request
     */
    private function request($method, $endpoint, $data = null, $expectSuccess = true) {
        $url = $this->baseUrl . $endpoint;

        $options = [
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\n",
                'ignore_errors' => true
            ]
        ];

        if ($data !== null) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            if ($expectSuccess) {
                throw new Exception("HTTP request failed: $method $url");
            }
            return [];
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Helper: Make authenticated HTTP request
     */
    private function authenticatedRequest($method, $endpoint, $data = null, $token = null, $expectSuccess = true) {
        $url = $this->baseUrl . $endpoint;

        $headers = "Content-Type: application/json\r\n";
        if ($token) {
            $headers .= "Authorization: Bearer $token\r\n";
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => $headers,
                'ignore_errors' => true
            ]
        ];

        if ($data !== null) {
            $options['http']['content'] = json_encode($data);
        }

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            if ($expectSuccess) {
                throw new Exception("HTTP request failed: $method $url");
            }
            return [];
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Helper: Direct database query (for setup/verification)
     */
    private function directDbQuery($sql, $params = [], $fetchOne = false) {
        // This requires database credentials
        $host = 'ep-gentle-smoke-adyqtxaa-pooler.c-2.us-east-1.aws.neon.tech';
        $dbname = 'neondb';
        $user = 'neondb_owner';
        $password = 'npg_3Oe0xzCYVGlJ';

        try {
            $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if (stripos($sql, 'SELECT') === 0) {
                if ($fetchOne) {
                    return $stmt->fetch(PDO::FETCH_ASSOC);
                }
                $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
                return $result[0] ?? null;
            } elseif (stripos($sql, 'INSERT') !== false && stripos($sql, 'RETURNING') !== false) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result['id'] ?? null;
            } elseif (stripos($sql, 'UPDATE') === 0 || stripos($sql, 'INSERT') === 0) {
                return $stmt->rowCount();
            }

            return true;
        } catch (PDOException $e) {
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }

    /**
     * Helper: Get test user email
     */
    private function getTestUserEmail() {
        // Get the most recent test user email
        return $this->directDbQuery("SELECT email FROM users WHERE email LIKE 'testuser_%' ORDER BY id DESC LIMIT 1");
    }

    /**
     * Helper: Extract user ID from JWT token
     */
    private function getUserIdFromToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid JWT token');
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        return $payload['user_id'] ?? null;
    }

    /**
     * Helper: Base64 URL decode
     */
    private function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Assertion helpers
     */
    private function assertTrue($condition, $message) {
        if (!$condition) {
            throw new Exception($message);
        }
    }

    private function assertNotEmpty($value, $message) {
        if (empty($value)) {
            throw new Exception($message . " (got empty value)");
        }
    }

    private function assertEquals($expected, $actual, $message) {
        if ($expected != $actual) {
            throw new Exception($message . " (expected: '$expected', got: '$actual')");
        }
    }

    private function assertArrayHasKey($key, $array, $message) {
        if (!isset($array[$key])) {
            throw new Exception($message . " (key '$key' not found)");
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $baseUrl = $argv[1] ?? 'http://localhost:8889';
    echo "Testing against: $baseUrl\n\n";

    $test = new LeagueHierarchyTest($baseUrl);
    $success = $test->runAll();

    exit($success ? 0 : 1);
}
