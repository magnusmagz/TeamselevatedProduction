<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$responses = [];

// Test 1: Try socket connection
try {
    $pdo = new PDO(
        'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=teams_elevated;charset=utf8mb4',
        'root',
        'root'
    );
    $responses['socket_connection'] = 'Success';
} catch (PDOException $e) {
    $responses['socket_connection'] = 'Failed: ' . $e->getMessage();
}

// Test 2: Try TCP connection on port 8889 (MAMP's MySQL port)
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=8889;dbname=teams_elevated;charset=utf8mb4',
        'root',
        'root'
    );
    $responses['tcp_8889_connection'] = 'Success';
} catch (PDOException $e) {
    $responses['tcp_8889_connection'] = 'Failed: ' . $e->getMessage();
}

// Test 3: Try TCP connection on port 3306
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=teams_elevated;charset=utf8mb4',
        'root',
        'root'
    );
    $responses['tcp_3306_connection'] = 'Success';
} catch (PDOException $e) {
    $responses['tcp_3306_connection'] = 'Failed: ' . $e->getMessage();
}

// Test 4: Include the database config file
try {
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance()->getConnection();
    $responses['config_file_connection'] = 'Success';
} catch (Exception $e) {
    $responses['config_file_connection'] = 'Failed: ' . $e->getMessage();
}

echo json_encode($responses, JSON_PRETTY_PRINT);