<?php
/**
 * Payment Items API
 * Get payment items for a program
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $program_id = $_GET['program_id'] ?? null;

    if (!$program_id) {
        echo json_encode(['error' => 'program_id is required']);
        exit;
    }

    $query = "
        SELECT
            pi.*,
            p.name as program_name
        FROM payment_items pi
        JOIN programs p ON pi.program_id = p.id
        WHERE pi.program_id = :program_id
        AND pi.active = true
        ORDER BY
            CASE pi.item_type
                WHEN 'registration' THEN 1
                WHEN 'dues' THEN 2
                WHEN 'uniform' THEN 3
                WHEN 'tournament' THEN 4
                WHEN 'merchandise' THEN 5
                ELSE 6
            END,
            pi.name
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute(['program_id' => $program_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'items' => $items
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch payment items',
        'message' => $e->getMessage()
    ]);
}
