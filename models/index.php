<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:3000');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config/database.php';
require_once 'controllers/TeamController.php';
require_once 'controllers/AuthController.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$routes = [
    'GET' => [
        '/api/teams' => 'TeamController@index',
        '/api/teams/(\d+)' => 'TeamController@show',
        '/api/teams/(\d+)/roster' => 'TeamController@roster',
        '/api/teams/(\d+)/audit-log' => 'TeamController@auditLog',
        '/api/coaches/available' => 'TeamController@availableCoaches',
        '/api/seasons' => 'TeamController@seasons',
        '/api/fields' => 'TeamController@fields',
    ],
    'POST' => [
        '/api/teams' => 'TeamController@create',
        '/api/teams/(\d+)/coaches' => 'TeamController@assignCoach',
        '/api/teams/(\d+)/volunteers' => 'TeamController@assignVolunteer',
        '/api/teams/bulk-action' => 'TeamController@bulkAction',
        '/api/auth/login' => 'AuthController@login',
        '/api/auth/register' => 'AuthController@register',
    ],
    'PUT' => [
        '/api/teams/(\d+)' => 'TeamController@update',
    ],
    'DELETE' => [
        '/api/teams/(\d+)' => 'TeamController@delete',
        '/api/teams/(\d+)/coaches/(\d+)' => 'TeamController@removeCoach',
    ],
];

$routeFound = false;

if (isset($routes[$method])) {
    foreach ($routes[$method] as $route => $handler) {
        $pattern = '#^' . $route . '$#';
        if (preg_match($pattern, $uri, $matches)) {
            array_shift($matches);

            list($controllerName, $methodName) = explode('@', $handler);

            require_once "controllers/$controllerName.php";

            $controller = new $controllerName();

            if (method_exists($controller, $methodName)) {
                call_user_func_array([$controller, $methodName], $matches);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Method not found']);
            }

            $routeFound = true;
            break;
        }
    }
}

if (!$routeFound) {
    http_response_code(404);
    echo json_encode(['error' => 'Route not found']);
}