<?php
// Standalone script outside of source directory
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

// Direct database connection
try {
    $pdo = new PDO(
        "mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=teams_elevated;charset=utf8mb4",
        'root',
        'root',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    $errors = [];
    if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Valid email is required';
    }
    if (empty($data['password']) || strlen($data['password']) < 6) {
        $errors['password'] = 'Password must be at least 6 characters';
    }
    if (empty($data['first_name'])) {
        $errors['first_name'] = 'First name is required';
    }
    if (empty($data['last_name'])) {
        $errors['last_name'] = 'Last name is required';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['errors' => $errors]);
        exit;
    }

    try {
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (email, password, first_name, last_name, phone, role)
                VALUES (:email, :password, :first_name, :last_name, :phone, :role)";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':email' => $data['email'],
            ':password' => $hashedPassword,
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':phone' => $data['phone'] ?? null,
            ':role' => $data['role'] ?? 'coach'
        ]);

        if ($result) {
            http_response_code(201);
            echo json_encode([
                'id' => $pdo->lastInsertId(),
                'message' => 'Coach registered successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Registration failed']);
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            http_response_code(400);
            echo json_encode(['error' => 'Email already exists']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
        }
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}