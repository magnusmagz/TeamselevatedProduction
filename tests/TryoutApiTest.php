<?php
/**
 * Tryout API Integration Tests
 *
 * Run with: php tests/TryoutApiTest.php
 *
 * Requires: Test database set up with seed data
 */

class TryoutApiTest {
    private $baseUrl;
    private $testResults = [];
    private $passed = 0;
    private $failed = 0;

    public function __construct($baseUrl = 'http://localhost:8889') {
        $this->baseUrl = $baseUrl;
    }

    private function request($method, $path, $data = null) {
        $url = $this->baseUrl . '/registration/tryouts-api.php?' . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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
            $this->testResults[] = ['pass' => true, 'name' => $testName];
            echo "  ✓ $testName\n";
        } else {
            $this->failed++;
            $this->testResults[] = ['pass' => false, 'name' => $testName, 'details' => $details];
            echo "  ✗ $testName\n";
            if ($details) echo "    Details: $details\n";
        }
    }

    // ============================================
    // TEST: Get Sessions
    // ============================================
    public function testGetSessions() {
        echo "\n[TEST] Get Tryout Sessions\n";

        $response = $this->request('GET', 'path=sessions&program_id=100');

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert(is_array($response['body']), 'Returns array');
        $this->assert(count($response['body']) === 3, 'Returns 3 sessions',
            'Got ' . count($response['body'] ?? []) . ' sessions');

        if (!empty($response['body'])) {
            $session = $response['body'][0];
            $this->assert(isset($session['session_date']), 'Session has date');
            $this->assert(isset($session['start_time']), 'Session has start_time');
        }
    }

    // ============================================
    // TEST: Get Criteria
    // ============================================
    public function testGetCriteria() {
        echo "\n[TEST] Get Evaluation Criteria\n";

        $response = $this->request('GET', 'path=criteria&program_id=100');

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert(is_array($response['body']), 'Returns array');
        $this->assert(count($response['body']) === 4, 'Returns 4 criteria',
            'Got ' . count($response['body'] ?? []) . ' criteria');

        if (!empty($response['body'])) {
            $criterion = $response['body'][0];
            $this->assert(isset($criterion['name']), 'Criterion has name');
            $this->assert(isset($criterion['max_score']), 'Criterion has max_score');
            $this->assert(isset($criterion['weight']), 'Criterion has weight');
        }
    }

    // ============================================
    // TEST: Get Registrations
    // ============================================
    public function testGetRegistrations() {
        echo "\n[TEST] Get Tryout Registrations\n";

        $response = $this->request('GET', 'path=registrations&program_id=100');

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert(is_array($response['body']), 'Returns array');
        $this->assert(count($response['body']) >= 8, 'Returns at least 8 registrations',
            'Got ' . count($response['body'] ?? []) . ' registrations');

        if (!empty($response['body'])) {
            $reg = $response['body'][0];
            $this->assert(isset($reg['first_name']), 'Has athlete first_name');
            $this->assert(isset($reg['last_name']), 'Has athlete last_name');
            $this->assert(isset($reg['tryout_status']), 'Has tryout_status');
        }
    }

    // ============================================
    // TEST: Check-In Athlete
    // ============================================
    public function testCheckIn() {
        echo "\n[TEST] Check-In Athlete\n";

        // Registration 101 should be in 'registered' status
        $response = $this->request('POST', 'path=check-in', [
            'registration_id' => 101
        ]);

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert($response['body']['success'] === true, 'Returns success: true');

        // Verify status changed
        $regResponse = $this->request('GET', 'path=registrations&program_id=100');
        $reg101 = array_filter($regResponse['body'], fn($r) => $r['id'] == 101);
        $reg101 = reset($reg101);
        $this->assert($reg101['tryout_status'] === 'checked_in', 'Status changed to checked_in',
            'Status is: ' . ($reg101['tryout_status'] ?? 'null'));
    }

    // ============================================
    // TEST: Submit Evaluation
    // ============================================
    public function testSubmitEvaluation() {
        echo "\n[TEST] Submit Evaluation\n";

        // Get criteria IDs first
        $criteriaResponse = $this->request('GET', 'path=criteria&program_id=100');
        $criteriaIds = array_map(fn($c) => $c['id'], $criteriaResponse['body']);

        // Submit evaluation for registration 102 (checked_in)
        $scores = [];
        foreach ($criteriaIds as $id) {
            $scores[$id] = rand(3, 5); // Random scores 3-5
        }

        $response = $this->request('POST', 'path=evaluate', [
            'registration_id' => 102,
            'evaluator_id' => 2,
            'session_id' => 1,
            'scores' => $scores,
            'notes' => 'Test evaluation from automated test'
        ]);

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert($response['body']['success'] === true, 'Returns success: true');
        $this->assert(isset($response['body']['overall_score']), 'Returns overall_score');
        $this->assert($response['body']['overall_score'] > 0, 'Overall score > 0',
            'Score: ' . ($response['body']['overall_score'] ?? 'null'));
    }

    // ============================================
    // TEST: Get Rankings
    // ============================================
    public function testGetRankings() {
        echo "\n[TEST] Get Rankings\n";

        $response = $this->request('GET', 'path=rankings&program_id=100');

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert(is_array($response['body']), 'Returns array');

        if (!empty($response['body'])) {
            $ranking = $response['body'][0];
            $this->assert(isset($ranking['first_name']), 'Has athlete name');
            $this->assert(isset($ranking['evaluation_count']), 'Has evaluation_count');
            $this->assert(isset($ranking['avg_score']), 'Has avg_score');

            // Verify rankings are sorted by score (descending)
            $scores = array_column($response['body'], 'avg_score');
            $sortedScores = $scores;
            rsort($sortedScores);
            $this->assert($scores === $sortedScores, 'Rankings sorted by score desc');
        }
    }

    // ============================================
    // TEST: Send Offers
    // ============================================
    public function testSendOffers() {
        echo "\n[TEST] Send Offers\n";

        $response = $this->request('POST', 'path=send-offers', [
            'offers' => [
                ['registration_id' => 105, 'offer_type' => 'roster', 'team_id' => 1],
                ['registration_id' => 106, 'offer_type' => 'waitlist']
            ]
        ]);

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert($response['body']['success'] === true, 'Returns success: true');
        $this->assert($response['body']['count'] === 2, 'Sent 2 offers');
    }

    // ============================================
    // TEST: Get Offers
    // ============================================
    public function testGetOffers() {
        echo "\n[TEST] Get Offers\n";

        $response = $this->request('GET', 'path=offers&program_id=100');

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert(is_array($response['body']), 'Returns array');
        $this->assert(count($response['body']) >= 2, 'Has at least 2 offers',
            'Got ' . count($response['body'] ?? []) . ' offers');

        if (!empty($response['body'])) {
            $offer = $response['body'][0];
            $this->assert(isset($offer['offer_type']), 'Has offer_type');
            $this->assert(isset($offer['first_name']), 'Has athlete name');
        }
    }

    // ============================================
    // TEST: Update Offer Response
    // ============================================
    public function testUpdateOfferResponse() {
        echo "\n[TEST] Update Offer Response\n";

        // Get offers to find one to update
        $offersResponse = $this->request('GET', 'path=offers&program_id=100');
        $rosterOffer = array_filter($offersResponse['body'], fn($o) => $o['offer_type'] === 'roster');
        $rosterOffer = reset($rosterOffer);

        if ($rosterOffer) {
            $response = $this->request('POST', 'path=update-offer', [
                'offer_id' => $rosterOffer['id'],
                'response' => 'accepted'
            ]);

            $this->assert($response['status'] === 200, 'Returns 200 status');
            $this->assert($response['body']['success'] === true, 'Returns success: true');
        } else {
            $this->assert(false, 'Found roster offer to update');
        }
    }

    // ============================================
    // TEST: Create Tryout
    // ============================================
    public function testCreateTryout() {
        echo "\n[TEST] Create Tryout\n";

        $response = $this->request('POST', 'path=create', [
            'club_id' => 1,
            'name' => 'Test Tryout ' . time(),
            'description' => 'Created by automated test',
            'min_age' => 8,
            'max_age' => 10,
            'status' => 'draft',
            'sessions' => [
                ['session_date' => '2026-04-01', 'start_time' => '10:00', 'end_time' => '12:00', 'location' => 'Test Field']
            ],
            'criteria' => [
                ['name' => 'Test Skill 1', 'max_score' => 5, 'weight' => 1.0, 'display_order' => 0],
                ['name' => 'Test Skill 2', 'max_score' => 5, 'weight' => 1.0, 'display_order' => 1]
            ]
        ]);

        $this->assert($response['status'] === 200, 'Returns 200 status');
        $this->assert($response['body']['success'] === true, 'Returns success: true');
        $this->assert(isset($response['body']['id']), 'Returns tryout id');
        $this->assert(isset($response['body']['embed_code']), 'Returns embed_code');
        $this->assert(strpos($response['body']['embed_code'], 'TRY') === 0, 'Embed code starts with TRY');
    }

    // ============================================
    // TEST: Add/Update/Delete Criteria
    // ============================================
    public function testCriteriaCRUD() {
        echo "\n[TEST] Criteria CRUD Operations\n";

        // Add single criterion
        $response = $this->request('POST', 'path=criteria', [
            'program_id' => 100,
            'name' => 'Test Criterion',
            'description' => 'Test',
            'max_score' => 10,
            'weight' => 0.5,
            'display_order' => 99
        ]);

        $this->assert($response['status'] === 200, 'Add criterion: 200 status');
        $this->assert($response['body']['success'] === true, 'Add criterion: success');

        $criterionId = $response['body']['id'] ?? null;

        if ($criterionId) {
            // Update
            $updateResponse = $this->request('PUT', "path=criteria&id=$criterionId", [
                'name' => 'Updated Criterion',
                'description' => 'Updated',
                'max_score' => 5,
                'weight' => 1.0,
                'display_order' => 99
            ]);
            $this->assert($updateResponse['body']['success'] === true, 'Update criterion: success');

            // Delete
            $deleteResponse = $this->request('DELETE', "path=criteria&id=$criterionId");
            $this->assert($deleteResponse['body']['success'] === true, 'Delete criterion: success');
        }
    }

    // ============================================
    // TEST: Add/Update/Delete Session
    // ============================================
    public function testSessionCRUD() {
        echo "\n[TEST] Session CRUD Operations\n";

        // Add session
        $response = $this->request('POST', 'path=sessions', [
            'program_id' => 100,
            'session_date' => '2026-03-20',
            'start_time' => '14:00',
            'end_time' => '17:00',
            'location' => 'Test Location'
        ]);

        $this->assert($response['status'] === 200, 'Add session: 200 status');
        $this->assert($response['body']['success'] === true, 'Add session: success');

        $sessionId = $response['body']['id'] ?? null;

        if ($sessionId) {
            // Update
            $updateResponse = $this->request('PUT', "path=sessions&id=$sessionId", [
                'session_date' => '2026-03-21',
                'start_time' => '10:00',
                'end_time' => '13:00',
                'location' => 'Updated Location'
            ]);
            $this->assert($updateResponse['body']['success'] === true, 'Update session: success');

            // Delete
            $deleteResponse = $this->request('DELETE', "path=sessions&id=$sessionId");
            $this->assert($deleteResponse['body']['success'] === true, 'Delete session: success');
        }
    }

    // ============================================
    // RUN ALL TESTS
    // ============================================
    public function run() {
        echo "============================================\n";
        echo "Tryout API Integration Tests\n";
        echo "============================================\n";
        echo "Base URL: {$this->baseUrl}\n";

        $this->testGetSessions();
        $this->testGetCriteria();
        $this->testGetRegistrations();
        $this->testCheckIn();
        $this->testSubmitEvaluation();
        $this->testGetRankings();
        $this->testSendOffers();
        $this->testGetOffers();
        $this->testUpdateOfferResponse();
        $this->testCreateTryout();
        $this->testCriteriaCRUD();
        $this->testSessionCRUD();

        echo "\n============================================\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n";
        echo "============================================\n";

        return $this->failed === 0;
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0])) {
    $baseUrl = $argv[1] ?? 'http://localhost:8889';
    $test = new TryoutApiTest($baseUrl);
    $success = $test->run();
    exit($success ? 0 : 1);
}
