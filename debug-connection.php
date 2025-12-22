<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP Version: " . PHP_VERSION . "\n";
echo "Loaded extensions: " . implode(', ', get_loaded_extensions()) . "\n\n";

// Test basic MySQL connection
$host = '127.0.0.1';
$port = '3306';
$dbname = 'teams_elevated';
$username = 'root';
$password = 'root';

echo "Attempting connection to MySQL at {$host}:{$port}\n";

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    echo "DSN: {$dsn}\n";

    $pdo = new PDO($dsn, $username, $password);
    echo "SUCCESS: Connected to database!\n";

    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $count = $stmt->fetchColumn();
    echo "Users table has {$count} records.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
}