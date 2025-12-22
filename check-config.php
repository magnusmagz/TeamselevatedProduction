<?php
echo "PHP Version: " . phpversion() . "\n";
echo "MySQL Socket: " . ini_get('pdo_mysql.default_socket') . "\n";

// Test connection
try {
    $connection = new PDO(
        "mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=teams_elevated;charset=utf8mb4",
        "root",
        "root"
    );
    echo "Connection successful using socket!\n";
} catch (PDOException $e) {
    echo "Socket connection failed: " . $e->getMessage() . "\n";
}

// Try host connection
try {
    $connection = new PDO(
        "mysql:host=localhost;port=3306;dbname=teams_elevated;charset=utf8mb4",
        "root",
        "root"
    );
    echo "Connection successful using host!\n";
} catch (PDOException $e) {
    echo "Host connection failed: " . $e->getMessage() . "\n";
}
?>