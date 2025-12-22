<?php
require_once __DIR__ . '/../config/database.php';

class AuthController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);

        $errors = $this->validateRegistration($data);
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['errors' => $errors]);
            return;
        }

        try {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            $sql = "INSERT INTO users (email, password, first_name, last_name, phone, role)
                    VALUES (:email, :password, :first_name, :last_name, :phone, :role)";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':email' => $data['email'],
                ':password' => $hashedPassword,
                ':first_name' => $data['first_name'],
                ':last_name' => $data['last_name'],
                ':phone' => $data['phone'] ?? null,
                ':role' => $data['role'] ?? 'player'
            ]);

            if ($result) {
                http_response_code(201);
                echo json_encode([
                    'id' => $this->db->lastInsertId(),
                    'message' => 'User registered successfully'
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
                echo json_encode(['error' => 'Registration failed']);
            }
        }
    }

    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }

        $sql = "SELECT id, email, password, first_name, last_name, role
                FROM users
                WHERE email = :email AND is_active = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $data['email']]);
        $user = $stmt->fetch();

        if ($user && password_verify($data['password'], $user['password'])) {
            session_start();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];

            unset($user['password']);

            echo json_encode([
                'message' => 'Login successful',
                'user' => $user
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
    }

    public function logout() {
        session_start();
        session_destroy();
        echo json_encode(['message' => 'Logged out successfully']);
    }

    private function validateRegistration($data) {
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

        $validRoles = ['club_manager', 'coach', 'parent', 'player', 'volunteer'];
        if (!empty($data['role']) && !in_array($data['role'], $validRoles)) {
            $errors['role'] = 'Invalid role';
        }

        return $errors;
    }
}