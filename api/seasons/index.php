<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../controllers/SeasonController.php';

session_start();

$controller = new SeasonController();

$method = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

// Check for /current endpoint
if ($pathInfo === '/current') {
    $controller->getCurrentSeason();
    exit;
}

switch ($method) {
    case 'GET':
        if (preg_match('/^\/(\d+)$/', $pathInfo, $matches)) {
            // Get specific season - implement if needed
        } else {
            $controller->getSeasons();
        }
        break;

    case 'POST':
        $controller->createSeason();
        break;

    case 'PUT':
        if (preg_match('/^\/(\d+)$/', $pathInfo, $matches)) {
            $controller->updateSeason($matches[1]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Season ID required']);
        }
        break;

    case 'DELETE':
        if (preg_match('/^\/(\d+)$/', $pathInfo, $matches)) {
            $controller->deleteSeason($matches[1]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Season ID required']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}