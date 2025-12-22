<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    // Get all users with coach role and their team assignments
    $sql = "SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                COUNT(DISTINCT t.id) as team_count
            FROM users u
            LEFT JOIN teams t ON t.coach_id = u.id AND t.is_active = 1
            WHERE u.role = 'coach' AND u.is_active = 1
            GROUP BY u.id
            ORDER BY u.last_name, u.first_name";

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($coaches);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch coaches']);
}