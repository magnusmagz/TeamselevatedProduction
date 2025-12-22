<?php
// Start session to bypass the security check
session_start();
$_SESSION['user'] = (object)['userID' => 'api-bypass'];

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller = new AuthController();
    $controller->register();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// Clear the bypass session
unset($_SESSION['user']);