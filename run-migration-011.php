<?php
/**
 * Run database migration for tryout session name field
 */

require_once __DIR__ . '/config/database.php';

echo "Running migration: 011_tryout_session_name.sql\n";
echo "===============================================\n\n";

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    // Read the migration file
    $migrationFile = __DIR__ . '/database/migrations/011_tryout_session_name.sql';

    if (!file_exists($migrationFile)) {
        die("Error: Migration file not found at $migrationFile\n");
    }

    $sql = file_get_contents($migrationFile);

    // Execute the migration
    $connection->exec($sql);

    echo "Migration completed successfully!\n\n";

    // Verify column was added
    echo "Verifying column:\n";

    $stmt = $connection->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'tryout_sessions'
        AND column_name = 'name'
    ");
    $exists = $stmt->fetchColumn() !== false;

    echo "  - name: " . ($exists ? "OK" : "MISSING") . "\n";

    echo "\nDone!\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
