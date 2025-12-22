<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../controllers/AthleteController.php';

session_start();

$controller = new AthleteController();

// Parse the URL to get athlete ID and guardian ID if provided
$pathParts = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$athleteId = $pathParts[0] ?? null;
$guardianId = $pathParts[1] ?? null;

if (!$athleteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Athlete ID required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        // Add new guardian to athlete
        $controller->addGuardian($athleteId);
        break;

    case 'PUT':
        // Update guardian relationship
        if (!$guardianId) {
            http_response_code(400);
            echo json_encode(['error' => 'Guardian ID required']);
            exit;
        }
        $controller->updateGuardianRelationship($athleteId, $guardianId);
        break;

    case 'DELETE':
        // Remove guardian from athlete
        if (!$guardianId) {
            http_response_code(400);
            echo json_encode(['error' => 'Guardian ID required']);
            exit;
        }
        $controller->removeGuardian($athleteId, $guardianId);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}