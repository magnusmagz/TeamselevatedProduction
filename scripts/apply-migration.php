<?php
/**
 * Apply one migration file to the database this dyno is configured for.
 *
 *   heroku run --no-tty -a teamselevated-backend php scripts/apply-migration.php 084_programs_order_archive.sql
 *
 * Migrations are applied by hand in this project (CLAUDE.md), and until 2026-09-02 "by
 * hand" meant psql or an ad-hoc one-liner — which left no record of what actually ran.
 * This script prints the file, runs it in ONE transaction, and records the outcome in
 * audit_log (action `migration_applied`) so CHANGELOG entries have something to cite.
 *
 * It does not track state; a migration must be idempotent (IF NOT EXISTS) or applied
 * once. It refuses a file it cannot find and never guesses a number.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

$name = $argv[1] ?? '';
if ($name === '' || !preg_match('/^\d{3}_[A-Za-z0-9_]+\.sql$/', $name)) {
    fwrite(STDERR, "usage: php scripts/apply-migration.php NNN_name.sql\n");
    exit(2);
}
$path = __DIR__ . '/../database/migrations/' . $name;
if (!is_file($path)) {
    fwrite(STDERR, "not found: database/migrations/$name\n");
    exit(2);
}

$sql = file_get_contents($path);
echo "=== database/migrations/$name ===\n$sql\n=== applying ===\n";

$pdo = Database::getInstance()->getConnection();
$pdo->beginTransaction();
try {
    $pdo->exec($sql);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

AuditLogger::log($pdo, null, 'migration_applied', 'migration', null, ['file' => $name]);
echo "applied: $name\n";
