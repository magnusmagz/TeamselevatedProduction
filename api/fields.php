<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();


require_once '../config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Get all fields with their venue information
    $stmt = $db->prepare("
        SELECT f.id,
               CONCAT(v.name, ' - ', f.name) as name,
               f.venue_id,
               v.name as venue_name,
               f.field_type,
               f.surface_type,
               f.dimensions,
               f.has_lights,
               f.status
        FROM fields f
        JOIN venues v ON f.venue_id = v.id
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