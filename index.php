<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config/database.php';
require_once 'controllers/TeamController.php';
require_once 'controllers/AuthController.php';
require_once 'controllers/CoachController.php';
require_once 'controllers/AthleteController.php';

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
        '/api/coach/teams' => 'CoachController@myTeams',
        '/api/coach/teams/(\d+)/roster' => 'CoachController@roster',
        '/api/coach/teams/(\d+)/position-report' => 'CoachController@positionReport',
        '/api/coach/teams/(\d+)/jersey-report' => 'CoachController@jerseyReport',
        '/api/coach/teams/(\d+)/players/(\d+)/teams' => 'CoachController@multiTeamComparison',
        '/api/coach/teams/(\d+)/attendance' => 'CoachController@attendance',
        '/api/coach/teams/(\d+)/export' => 'CoachController@exportRoster',
        '/api/coach/players/search' => 'CoachController@searchPlayers',
        '/api/athletes' => 'AthleteController@getAthletes',
        '/api/athletes/(\d+)' => 'AthleteController@getAthlete',
    ],
    'POST' => [
        '/api/teams' => 'TeamController@create',
        '/api/teams/(\d+)/coaches' => 'TeamController@assignCoach',
        '/api/teams/(\d+)/volunteers' => 'TeamController@assignVolunteer',
        '/api/teams/bulk-action' => 'TeamController@bulkAction',
        '/api/auth/login' => 'AuthController@login',
        '/api/auth/register' => 'AuthController@register',
        '/api/coach/teams/(\d+)/roster' => 'CoachController@addPlayer',
        '/api/coach/teams/(\d+)/guest-players' => 'CoachController@addGuestPlayer',
        '/api/coach/teams/(\d+)/attendance' => 'CoachController@attendance',
        '/api/athletes' => 'AthleteController@createAthlete',
        '/api/athletes/(\d+)/guardians' => 'AthleteController@addGuardian',
    ],
    'PUT' => [
        '/api/teams/(\d+)' => 'TeamController@update',
        '/api/coach/teams/(\d+)/roster/(\d+)/positions' => 'CoachController@updatePlayerPositions',
    ],
    'DELETE' => [
        '/api/teams/(\d+)' => 'TeamController@delete',
        '/api/teams/(\d+)/coaches/(\d+)' => 'TeamController@removeCoach',
        '/api/coach/teams/(\d+)/roster/(\d+)' => 'CoachController@removePlayer',
        '/api/athletes/(\d+)/guardians/(\d+)' => 'AthleteController@removeGuardian',
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