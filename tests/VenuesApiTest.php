<?php
/**
 * Venues API Tests
 *
 * Run with: php tests/VenuesApiTest.php
 *
 * Tests the venues-gateway.php API endpoints for:
 * - GET all venues
 * - GET single venue with fields
 * - POST create venue
 * - PUT update venue
 * - DELETE venue
 */

class VenuesApiTest {
    private $baseUrl;
    private $testVenueId;

    public function __construct($baseUrl = 'http://localhost:8889') {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Run all tests
     */
    public function runAll() {
        echo "========================================\n";
        echo "       VENUES API TEST SUITE           \n";
        echo "========================================\n\n";

        $results = [
            'passed' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // Test suite
        $tests = [
            'testGetAllVenues',
            'testCreateVenue',
            'testCreateVenueWithFields',
            'testGetSingleVenue',
            'testUpdateVenue',
            'testDeleteVenue',
            'testCreateVenueValidation',
            'testFieldStatusValues',
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

        // Cleanup
        $this->cleanup();

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
     * Test: GET all venues returns array
     */
    public function testGetAllVenues() {
        $response = $this->request('GET', '/legacy/venues-gateway.php');

        $this->assertIsArray($response, 'Response should be an array');
        // Response can be empty array, that's fine
    }

    /**
     * Test: Create a basic venue
     */
    public function testCreateVenue() {
        $venueData = [
            'name' => 'Test Venue ' . time(),
            'address' => '123 Test Street',
            'city' => 'Austin',
            'state' => 'TX',
            'zip_code' => '78701',
            'website' => 'https://testvenue.com',
            'map_url' => 'https://maps.google.com/test'
        ];

        $response = $this->request('POST', '/legacy/venues-gateway.php', $venueData);

        $this->assertTrue($response['success'] ?? false, 'Create should return success');
        $this->assertNotEmpty($response['id'] ?? null, 'Create should return venue ID');

        $this->testVenueId = $response['id'];
    }

    /**
     * Test: Create venue with fields
     */
    public function testCreateVenueWithFields() {
        $venueData = [
            'name' => 'Venue With Fields ' . time(),
            'address' => '456 Field Road',
            'city' => 'Dallas',
            'state' => 'TX',
            'zip_code' => '75201',
            'fields' => [
                [
                    'name' => 'Field 1',
                    'field_type' => 'Soccer',
                    'surface_type' => 'Grass',
                    'dimensions' => 'Full',
                    'has_lights' => true,
                    'status' => 'available'
                ],
                [
                    'name' => 'Field 2',
                    'field_type' => 'Multi-purpose',
                    'surface_type' => 'Turf',
                    'dimensions' => 'U12',
                    'has_lights' => false,
                    'status' => 'maintenance'
                ]
            ]
        ];

        $response = $this->request('POST', '/legacy/venues-gateway.php', $venueData);

        $this->assertTrue(!empty($response['success']), 'Create with fields should succeed: ' . json_encode($response));
        $this->assertNotEmpty($response['id'] ?? null, 'Should return venue ID');

        // Verify fields were created
        $venueId = $response['id'];
        $venue = $this->request('GET', "/legacy/venues-gateway.php?id=$venueId");

        $this->assertIsArray($venue['fields'] ?? [], 'Venue should have fields array');
        $this->assertEquals(2, count($venue['fields'] ?? []), 'Should have 2 fields');

        // Cleanup
        $this->request('DELETE', "/legacy/venues-gateway.php?id=$venueId");
    }

    /**
     * Test: GET single venue with fields
     */
    public function testGetSingleVenue() {
        // First create a venue
        $venueData = [
            'name' => 'Single Venue Test ' . time(),
            'address' => '789 Single St',
            'city' => 'Houston',
            'state' => 'TX',
            'zip_code' => '77001',
            'map_url' => 'https://maps.google.com/single',
            'fields' => [
                [
                    'name' => 'Test Field',
                    'field_type' => 'Soccer',
                    'surface_type' => 'Grass',
                    'dimensions' => 'Full',
                    'has_lights' => true,
                    'status' => 'available'
                ]
            ]
        ];

        $createResponse = $this->request('POST', '/legacy/venues-gateway.php', $venueData);
        $venueId = $createResponse['id'];

        // Get the venue
        $venue = $this->request('GET', "/legacy/venues-gateway.php?id=$venueId");

        $this->assertEquals('Single Venue Test ' . time(), substr($venue['name'], 0, 17) . ' ' . time(), 'Name should match');
        $this->assertEquals('789 Single St', $venue['address'], 'Address should match');
        $this->assertEquals('Houston', $venue['city'], 'City should match');
        $this->assertIsArray($venue['fields'], 'Should include fields');
        $this->assertEquals(1, count($venue['fields']), 'Should have 1 field');

        // Cleanup
        $this->request('DELETE', "/legacy/venues-gateway.php?id=$venueId");
    }

    /**
     * Test: Update venue
     */
    public function testUpdateVenue() {
        // Create venue first
        $venueData = [
            'name' => 'Update Test Venue',
            'address' => '100 Original St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip_code' => '78702'
        ];

        $createResponse = $this->request('POST', '/legacy/venues-gateway.php', $venueData);
        $venueId = $createResponse['id'];

        // Update venue
        $updateData = [
            'name' => 'Updated Venue Name',
            'address' => '200 New Address',
            'city' => 'Dallas',
            'state' => 'TX',
            'zip_code' => '75201',
            'map_url' => 'https://maps.google.com/updated',
            'website' => 'https://updated.com',
            'fields' => [
                [
                    'name' => 'New Field',
                    'field_type' => 'Football',
                    'surface_type' => 'Turf',
                    'dimensions' => 'Full',
                    'has_lights' => true,
                    'status' => 'available'
                ]
            ]
        ];

        $updateResponse = $this->request('PUT', "/legacy/venues-gateway.php?id=$venueId", $updateData);

        $this->assertTrue($updateResponse['success'] ?? false, 'Update should succeed');

        // Verify update
        $venue = $this->request('GET', "/legacy/venues-gateway.php?id=$venueId");

        $this->assertEquals('Updated Venue Name', $venue['name'], 'Name should be updated');
        $this->assertEquals('200 New Address', $venue['address'], 'Address should be updated');
        $this->assertEquals('Dallas', $venue['city'], 'City should be updated');
        $this->assertEquals(1, count($venue['fields']), 'Should have 1 field');
        $this->assertEquals('New Field', $venue['fields'][0]['name'], 'Field name should match');

        // Cleanup
        $this->request('DELETE', "/legacy/venues-gateway.php?id=$venueId");
    }

    /**
     * Test: Delete venue
     */
    public function testDeleteVenue() {
        // Create venue
        $venueData = [
            'name' => 'Delete Test Venue ' . time(),
            'address' => '999 Delete St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip_code' => '78703'
        ];

        $createResponse = $this->request('POST', '/legacy/venues-gateway.php', $venueData);
        $this->assertNotEmpty($createResponse['id'] ?? null, 'Create should return venue ID for delete test');
        $venueId = $createResponse['id'];

        // Delete venue
        $deleteResponse = $this->request('DELETE', "/legacy/venues-gateway.php?id=$venueId");

        $this->assertTrue(!empty($deleteResponse['success']), 'Delete should succeed');

        // Verify deletion - venue should return null/false or empty array
        $venue = $this->request('GET', "/legacy/venues-gateway.php?id=$venueId");

        $this->assertTrue(empty($venue) || $venue === false || $venue === null, 'Venue should be deleted');
    }

    /**
     * Test: Venue creation validation (required fields)
     */
    public function testCreateVenueValidation() {
        // Test with minimal required data
        $venueData = [
            'name' => 'Minimal Venue ' . time(),
            'address' => '123 Minimal St'
            // city, state, zip_code are optional
        ];

        $response = $this->request('POST', '/legacy/venues-gateway.php', $venueData);

        // Should succeed even without optional fields
        $this->assertTrue($response['success'] ?? false, 'Should succeed with minimal data');

        // Cleanup
        if (!empty($response['id'])) {
            $this->request('DELETE', "/legacy/venues-gateway.php?id=" . $response['id']);
        }
    }

    /**
     * Test: Field status values are properly stored
     */
    public function testFieldStatusValues() {
        $statuses = ['available', 'maintenance', 'closed'];

        foreach ($statuses as $status) {
            $venueData = [
                'name' => "Status Test - $status - " . time(),
                'address' => '123 Status St',
                'fields' => [
                    [
                        'name' => "Field with $status status",
                        'field_type' => 'Soccer',
                        'surface_type' => 'Grass',
                        'dimensions' => 'Full',
                        'has_lights' => false,
                        'status' => $status
                    ]
                ]
            ];

            $response = $this->request('POST', '/legacy/venues-gateway.php', $venueData);
            $this->assertTrue(!empty($response['success']), "Create with status '$status' should succeed: " . json_encode($response));

            // Verify status was saved correctly
            $venue = $this->request('GET', "/legacy/venues-gateway.php?id=" . $response['id']);
            $this->assertEquals($status, $venue['fields'][0]['status'] ?? '', "Status should be '$status'");

            // Cleanup
            $this->request('DELETE', "/legacy/venues-gateway.php?id=" . $response['id']);

            // Small delay between iterations
            usleep(100000);
        }
    }

    /**
     * Cleanup test data
     */
    private function cleanup() {
        if ($this->testVenueId) {
            $this->request('DELETE', "/legacy/venues-gateway.php?id={$this->testVenueId}");
        }
    }

    /**
     * Make HTTP request to API
     */
    private function request($method, $endpoint, $data = null) {
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
            throw new Exception("HTTP request failed: $method $url");
        }

        return json_decode($response, true);
    }

    /**
     * Assertion helpers
     */
    private function assertTrue($condition, $message) {
        if (!$condition) {
            throw new Exception($message);
        }
    }

    private function assertIsArray($value, $message) {
        if (!is_array($value)) {
            throw new Exception($message . " (got: " . gettype($value) . ")");
        }
    }

    private function assertNotEmpty($value, $message) {
        if (empty($value)) {
            throw new Exception($message);
        }
    }

    private function assertEmpty($value, $message) {
        if (!empty($value)) {
            throw new Exception($message . " (got: " . json_encode($value) . ")");
        }
    }

    private function assertEquals($expected, $actual, $message) {
        if ($expected !== $actual) {
            throw new Exception($message . " (expected: '$expected', got: '$actual')");
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $baseUrl = $argv[1] ?? 'http://localhost:8889';
    echo "Testing against: $baseUrl\n\n";

    $test = new VenuesApiTest($baseUrl);
    $success = $test->runAll();

    exit($success ? 0 : 1);
}
