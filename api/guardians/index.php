<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once '../../controllers/AthleteController.php';

    session_start();

    $controller = new AthleteController();
    $method = $_SERVER['REQUEST_METHOD'];

    // Get request body
    $data = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'POST':
            // Expect athlete_id in the POST body
            if (empty($data['athlete_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Athlete ID required']);
                exit;
            }
            $controller->addGuardian($data['athlete_id']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log('Guardian API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
