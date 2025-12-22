<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Direct database connection
try {
    $connection = new PDO(
        "mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=teams_elevated;charset=utf8mb4",
        "root",
        "root",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Connection failed: ' . $e->getMessage()]));
}

// Get input
$data = json_decode(file_get_contents('php://input'), true);

// Validation
if (!$data || !isset($data['name']) || !isset($data['start_date']) || !isset($data['end_date'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Name, start_date, and end_date are required']);
    exit;
}

// Create season
try {
    $stmt = $connection->prepare("
        INSERT INTO seasons (name, start_date, end_date, created_at)
        VALUES (:name, :start_date, :end_date, NOW())
    ");

    $stmt->execute([
        ':name' => $data['name'],
        ':start_date' => $data['start_date'],
        ':end_date' => $data['end_date']
    ]);

    $seasonId = $connection->lastInsertId();

    // Fetch the created season
    $stmt = $connection->prepare("SELECT * FROM seasons WHERE id = :id");
    $stmt->execute([':id' => $seasonId]);
    $season = $stmt->fetch();

    echo json_encode(['success' => true, 'season' => $season]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create season: ' . $e->getMessage()]);
}
?>