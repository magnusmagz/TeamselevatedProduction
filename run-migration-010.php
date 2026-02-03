<?php
/**
 * Run database migration for tryout session fields
 */

require_once __DIR__ . '/config/database.php';

echo "Running migration: 010_tryout_session_fields.sql\n";
echo "================================================\n\n";

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    // Read the migration file
    $migrationFile = __DIR__ . '/database/migrations/010_tryout_session_fields.sql';

    if (!file_exists($migrationFile)) {
        die("Error: Migration file not found at $migrationFile\n");
    }

    $sql = file_get_contents($migrationFile);

    // Execute the migration
    $connection->exec($sql);

    echo "Migration completed successfully!\n\n";

    // Verify columns were added
    echo "Verifying columns:\n";

    $stmt = $connection->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = 'tryout_sessions'
        AND column_name IN ('is_rain_date', 'age_group', 'gender')
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $expected = ['is_rain_date', 'age_group', 'gender'];
    foreach ($expected as $col) {
        $exists = in_array($col, $columns);
        echo "  - $col: " . ($exists ? "OK" : "MISSING") . "\n";
    }

    echo "\nDone!\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
