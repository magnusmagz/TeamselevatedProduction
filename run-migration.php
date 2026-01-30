<?php
/**
 * Run database migration for fundraiser campaigns
 */

require_once __DIR__ . '/config/database.php';

echo "Running migration: 007_fundraiser_campaigns.sql\n";
echo "================================================\n\n";

try {
    $db = Database::getInstance();
    $connection = $db->getConnection();

    // Read the migration file
    $migrationFile = __DIR__ . '/database/migrations/007_fundraiser_campaigns.sql';

    if (!file_exists($migrationFile)) {
        die("Error: Migration file not found at $migrationFile\n");
    }

    $sql = file_get_contents($migrationFile);

    // Execute the migration
    $connection->exec($sql);

    echo "Migration completed successfully!\n\n";

    // Verify tables were created
    echo "Verifying tables:\n";

    $tables = ['fundraiser_campaigns', 'campaign_donations', 'campaign_updates'];

    foreach ($tables as $table) {
        $stmt = $connection->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
        $exists = $stmt->fetchColumn() > 0;
        echo "  - $table: " . ($exists ? "OK" : "MISSING") . "\n";
    }

    echo "\nDone!\n";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
