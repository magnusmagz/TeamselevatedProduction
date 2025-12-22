<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/env.php';

echo "Testing Neon PostgreSQL connection...\n\n";

$host = Env::get('DB_HOST');
$port = Env::get('DB_PORT', 5432);
$dbname = Env::get('DB_NAME');
$username = Env::get('DB_USER');
$password = Env::get('DB_PASSWORD');

echo "Host: $host\n";
echo "Port: $port\n";
echo "Database: $dbname\n";
echo "Username: $username\n\n";

// Try connection without SSL first
try {
    echo "Attempting connection without SSL requirement...\n";
    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s",
        $host,
        $port,
        $dbname
    );
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✓ Connection successful without SSL!\n";
    $pdo = null;
} catch (PDOException $e) {
    echo "✗ Connection failed without SSL: " . $e->getMessage() . "\n\n";
}

// Try with sslmode=require
try {
    echo "Attempting connection with sslmode=require...\n";
    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;sslmode=require",
        $host,
        $port,
        $dbname
    );
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "✓ Connection successful with SSL!\n";
    $result = $pdo->query("SELECT version();")->fetch();
    echo "PostgreSQL version: " . $result['version'] . "\n";
} catch (PDOException $e) {
    echo "✗ Connection failed with SSL: " . $e->getMessage() . "\n";
}
