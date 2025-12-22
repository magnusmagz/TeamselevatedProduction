<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    // Test 1: Simple SELECT
    echo json_encode(['step' => 'Test 1: SELECT']) . "\n";
    $stmt = $connection->prepare("SELECT id FROM users LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    echo json_encode(['result' => $result]) . "\n";

    // Test 2: Check if specific email exists
    echo json_encode(['step' => 'Test 2: Check email exists']) . "\n";
    $stmt = $connection->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['test123@example.com']);
    $result = $stmt->fetch();
    echo json_encode(['result' => $result]) . "\n";

    // Test 3: Try INSERT in a transaction (without SET LOCAL)
    echo json_encode(['step' => 'Test 3: INSERT with transaction (no SET LOCAL)']) . "\n";
    $connection->beginTransaction();

    echo json_encode(['status' => 'Transaction started, now attempting INSERT...']) . "\n";

    $testEmail = 'testuser' . time() . '@example.com';
    echo json_encode(['info' => 'Preparing statement...']) . "\n";
    $stmt = $connection->prepare("
        INSERT INTO users (email, first_name, last_name, system_role)
        VALUES (?, ?, ?, 'user')
        RETURNING id
    ");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . json_encode($connection->errorInfo()));
    }

    echo json_encode(['info' => 'Executing statement...']) . "\n";
    $result = $stmt->execute([$testEmail, 'Test', 'User']);
    if (!$result) {
        throw new Exception('Execute failed: ' . json_encode($stmt->errorInfo()));
    }

    echo json_encode(['info' => 'Fetching result...']) . "\n";
    $userId = $stmt->fetchColumn();

    $connection->rollBack(); // Don't actually save

    echo json_encode([
        'success' => true,
        'message' => 'Insert test successful',
        'user_id' => $userId,
        'test_email' => $testEmail
    ]) . "\n";

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
