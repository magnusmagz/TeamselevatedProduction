<?php
/**
 * Tournament API Integration Tests
 *
 * Run with: php tests/TournamentApiTest.php [baseUrl]
 *
 * Requires: Database migrated with 014_tournament_system.sql
 */

class TournamentApiTest {
    private $baseUrl;
    private $passed = 0;
    private $failed = 0;
    private $adminToken;
    private $coachToken;

    public function __construct($baseUrl = 'http://localhost:8889') {
        $this->baseUrl = rtrim($baseUrl, '/');
        // These tokens should be set via environment or test setup
        $this->adminToken = getenv('TEST_ADMIN_TOKEN') ?: '';
        $this->coachToken = getenv('TEST_COACH_TOKEN') ?: '';
    }

    private function request($method, $endpoint, $data = null, $token = null) {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $headers = ['Content-Type: application/json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true),
            'raw' => $response
        ];
    }

    private function assert($condition, $testName, $details = '') {
        if ($condition) {
            $this->passed++;
            echo "  ✓ $testName\n";
        } else {
            $this->failed++;
            echo "  ✗ $testName\n";
            if ($details) echo "    Details: $details\n";
        }
    }

    // ============================================
    // WI-1: SCAFFOLD TESTS
    // ============================================

    public function testUnauthorizedAccess() {
        echo "\n[TEST] Unauthorized Access\n";

        $response = $this->request('GET', 'api/tournament-gateway.php?action=list');
        $this->assert(
            $response['status'] === 401,
            'GET without token returns 401',
            'Got status: ' . $response['status']
        );
    }

    public function testForbiddenAccess() {
        echo "\n[TEST] Forbidden Access (parent role)\n";

        // This test requires a parent-role token to be set
        if (!$this->adminToken) {
            echo "  ⊘ Skipped (no test tokens configured)\n";
            return;
        }

        // If we don't have a parent token, we just verify the scaffold responds
        $response = $this->request('GET', 'api/tournament-gateway.php?action=list', null, $this->adminToken);
        $this->assert(
            $response['status'] === 200 || $response['status'] === 403,
            'Authenticated request returns 200 or 403 based on role',
            'Got status: ' . $response['status']
        );
    }

    public function testAdminAccess() {
        echo "\n[TEST] Admin Access\n";

        if (!$this->adminToken) {
            echo "  ⊘ Skipped (no admin token configured)\n";
            return;
        }

        $response = $this->request('GET', 'api/tournament-gateway.php?action=list', null, $this->adminToken);
        $this->assert(
            $response['status'] === 200,
            'Admin GET list returns 200',
            'Got status: ' . $response['status']
        );
        $this->assert(
            isset($response['body']['tournaments']),
            'Response contains tournaments array',
            'Body: ' . json_encode($response['body'])
        );
    }

    public function testSportPresetsSeeded() {
        echo "\n[TEST] Sport Presets Seeded\n";

        if (!$this->adminToken) {
            echo "  ⊘ Skipped (no admin token configured)\n";
            return;
        }

        $response = $this->request('GET', 'api/tournament-gateway.php?action=sport-presets&sport=soccer', null, $this->adminToken);
        $this->assert(
            $response['status'] === 200,
            'Sport presets endpoint returns 200',
            'Got status: ' . $response['status']
        );

        $presets = $response['body']['presets'] ?? [];
        $this->assert(
            count($presets) === 6,
            'Soccer has 6 age group presets (U8-U19)',
            'Got ' . count($presets) . ' presets'
        );

        // Verify U10 preset values
        $u10 = null;
        foreach ($presets as $p) {
            if ($p['age_group'] === 'U10') { $u10 = $p; break; }
        }
        $this->assert(
            $u10 !== null,
            'U10 preset exists'
        );
        if ($u10) {
            $this->assert(
                (int)$u10['game_duration_minutes'] === 50,
                'U10 game duration is 50 min',
                'Got: ' . ($u10['game_duration_minutes'] ?? 'null')
            );
            $this->assert(
                (int)$u10['max_players_on_field'] === 7,
                'U10 is 7v7',
                'Got: ' . ($u10['max_players_on_field'] ?? 'null')
            );
        }

        // Verify U8 has rule notes
        $u8 = null;
        foreach ($presets as $p) {
            if ($p['age_group'] === 'U8') { $u8 = $p; break; }
        }
        if ($u8) {
            $ruleNotes = is_string($u8['rule_notes']) ? json_decode($u8['rule_notes'], true) : $u8['rule_notes'];
            $this->assert(
                is_array($ruleNotes) && in_array('No heading', $ruleNotes),
                'U8 rule notes include "No heading"',
                'Got: ' . json_encode($ruleNotes)
            );
        }
    }

    // ============================================
    // PUBLIC ENDPOINT TESTS
    // ============================================

    public function testPublicEndpointNoAuth() {
        echo "\n[TEST] Public Endpoint (no auth required)\n";

        $response = $this->request('GET', 'api/tournament-public-gateway.php?action=tournament-by-slug&slug=nonexistent');
        $this->assert(
            $response['status'] === 404,
            'Public endpoint works without auth (returns 404 for missing tournament)',
            'Got status: ' . $response['status']
        );
    }

    public function testPublicDraftHidden() {
        echo "\n[TEST] Public Draft Tournament Hidden\n";
        // Draft tournaments should not be visible on public endpoints
        // This will be fully testable once WI-2 creates tournaments
        echo "  ⊘ Deferred to WI-9 (needs tournament data)\n";
    }

    // ============================================
    // WI-2: TOURNAMENT CRUD TESTS
    // ============================================

    private $testTournamentId = null;

    public function testCreateTournament() {
        echo "\n[TEST] Create Tournament\n";

        if (!$this->adminToken) {
            echo "  ⊘ Skipped (no admin token)\n";
            return;
        }

        $response = $this->request('POST', 'api/tournament-gateway.php?action=create', [
            'club_id' => 47,
            'name' => 'PHPUnit Spring Classic ' . time(),
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-17',
            'sport' => 'soccer',
            'location_name' => 'Test Park',
            'entry_fee_cents' => 50000,
            'public_url_slug' => 'test-classic-' . time(),
            'contact_name' => 'Test Admin',
            'contact_email' => 'test@example.com',
        ], $this->adminToken);

        $this->assert($response['status'] === 201, 'Create returns 201', 'Got: ' . $response['status']);
        $this->assert(!empty($response['body']['id']), 'Response contains id', json_encode($response['body']));

        if (!empty($response['body']['id'])) {
            $this->testTournamentId = $response['body']['id'];
        }
    }

    public function testCreateTournamentValidation() {
        echo "\n[TEST] Create Tournament Validation\n";

        if (!$this->adminToken) {
            echo "  ⊘ Skipped (no admin token)\n";
            return;
        }

        // Missing required fields
        $response = $this->request('POST', 'api/tournament-gateway.php?action=create', [
            'club_id' => 47,
        ], $this->adminToken);

        $this->assert($response['status'] === 400, 'Missing fields returns 400', 'Got: ' . $response['status']);
    }

    public function testGetTournament() {
        echo "\n[TEST] Get Tournament\n";

        if (!$this->adminToken || !$this->testTournamentId) {
            echo "  ⊘ Skipped (no token or no test tournament)\n";
            return;
        }

        $response = $this->request('GET', 'api/tournament-gateway.php?action=get&id=' . $this->testTournamentId, null, $this->adminToken);

        $this->assert($response['status'] === 200, 'Get returns 200', 'Got: ' . $response['status']);
        $this->assert(!empty($response['body']['name']), 'Response contains name');
        $this->assert(isset($response['body']['divisions']), 'Response contains divisions array');
        $this->assert($response['body']['status'] === 'draft', 'New tournament starts as draft');
    }

    public function testUpdateTournament() {
        echo "\n[TEST] Update Tournament\n";

        if (!$this->adminToken || !$this->testTournamentId) {
            echo "  ⊘ Skipped\n";
            return;
        }

        $response = $this->request('PUT', 'api/tournament-gateway.php?action=update&id=' . $this->testTournamentId, [
            'name' => 'Updated Tournament Name',
            'description' => 'Updated description',
        ], $this->adminToken);

        $this->assert($response['status'] === 200, 'Update returns 200', 'Got: ' . $response['status']);
        $this->assert($response['body']['success'] === true, 'Response success is true');
    }

    public function testStatusTransition() {
        echo "\n[TEST] Status Transition\n";

        if (!$this->adminToken || !$this->testTournamentId) {
            echo "  ⊘ Skipped\n";
            return;
        }

        // draft -> registration_open (valid)
        $response = $this->request('PUT', 'api/tournament-gateway.php?action=update-status&id=' . $this->testTournamentId, [
            'status' => 'registration_open',
        ], $this->adminToken);

        $this->assert($response['status'] === 200, 'Valid transition returns 200', 'Got: ' . $response['status']);
    }

    public function testInvalidStatusTransition() {
        echo "\n[TEST] Invalid Status Transition\n";

        if (!$this->adminToken || !$this->testTournamentId) {
            echo "  ⊘ Skipped\n";
            return;
        }

        // registration_open -> completed (invalid — must go through scheduling, in_progress first)
        $response = $this->request('PUT', 'api/tournament-gateway.php?action=update-status&id=' . $this->testTournamentId, [
            'status' => 'completed',
        ], $this->adminToken);

        $this->assert($response['status'] === 400, 'Invalid transition returns 400', 'Got: ' . $response['status']);
    }

    public function testDeleteTournament() {
        echo "\n[TEST] Delete (Cancel) Tournament\n";

        if (!$this->adminToken || !$this->testTournamentId) {
            echo "  ⊘ Skipped\n";
            return;
        }

        $response = $this->request('DELETE', 'api/tournament-gateway.php?action=delete&id=' . $this->testTournamentId, null, $this->adminToken);

        $this->assert($response['status'] === 200, 'Delete returns 200', 'Got: ' . $response['status']);

        // Verify it's cancelled
        $check = $this->request('GET', 'api/tournament-gateway.php?action=get&id=' . $this->testTournamentId, null, $this->adminToken);
        $this->assert(
            ($check['body']['status'] ?? '') === 'cancelled',
            'Tournament status is now cancelled',
            'Got status: ' . ($check['body']['status'] ?? 'unknown')
        );
    }

    // ============================================
    // RUN ALL
    // ============================================

    public function runAll() {
        echo "========================================\n";
        echo "Tournament API Tests\n";
        echo "Base URL: {$this->baseUrl}\n";
        echo "========================================\n";

        // WI-1: Scaffold
        $this->testUnauthorizedAccess();
        $this->testForbiddenAccess();
        $this->testAdminAccess();
        $this->testSportPresetsSeeded();
        $this->testPublicEndpointNoAuth();
        $this->testPublicDraftHidden();

        // WI-2: Tournament CRUD
        $this->testCreateTournament();
        $this->testCreateTournamentValidation();
        $this->testGetTournament();
        $this->testUpdateTournament();
        $this->testStatusTransition();
        $this->testInvalidStatusTransition();
        $this->testDeleteTournament();

        echo "\n========================================\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n";
        echo "========================================\n";

        return $this->failed === 0;
    }
}

// Run if executed directly
if (php_sapi_name() === 'cli') {
    $baseUrl = $argv[1] ?? 'http://localhost:8889';
    $test = new TournamentApiTest($baseUrl);
    $success = $test->runAll();
    exit($success ? 0 : 1);
}
