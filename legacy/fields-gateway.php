<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Use centralized database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();
} catch (Exception $e) {
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
               f.surface_type,
               f.dimensions,
               f.capacity,
               f.active
        FROM fields f
        JOIN venues v ON f.venue_id = v.id
        WHERE f.active = true
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