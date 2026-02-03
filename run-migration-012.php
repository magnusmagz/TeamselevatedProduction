<?php
/**
 * Run database migration for what_to_bring field
 */

require_once __DIR__ . '/config/database.php';

echo "Running migration: 012_tryout_what_to_bring.sql\n";
echo "================================================\n\n";

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    $migrationFile = __DIR__ . '/database/migrations/012_tryout_what_to_bring.sql';

    if (!file_exists($migrationFile)) {
        die("Error: Migration file not found at $migrationFile\n");
    }

    $sql = file_get_contents($migrationFile);
    $connection->exec($sql);

    echo "Migration completed successfully!\n\n";

    // Verify column was added
    echo "Verifying column:\n";

    $stmt = $connection->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'programs'
        AND column_name = 'what_to_bring'
    ");
    $exists = $stmt->fetchColumn() !== false;

    echo "  - what_to_bring: " . ($exists ? "OK" : "MISSING") . "\n";

    echo "\nDone!\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
