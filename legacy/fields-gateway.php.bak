<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Direct database connection
try {
    $connection = new PDO(
        "mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=teams_elevated;charset=utf8mb4",
        "root",
        "root",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

try {
    // Get all fields with their venue information
    $stmt = $connection->prepare("
        SELECT f.id,
               CONCAT(v.name, ' - ', f.name) as name,
               f.venue_id,
               v.name as venue_name,
               f.field_type,
               f.surface,
               f.size,
               f.lights,
               f.status
        FROM fields f
        JOIN venues v ON f.venue_id = v.id
        WHERE f.status = 'available'
        ORDER BY v.name, f.name
    ");
    $stmt->execute();
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($fields);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>