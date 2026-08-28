<?php
/**
 * Dump the live schema in the exact shape of tests/fixtures/production-schema.json.
 *
 *   heroku run --no-tty -a teamselevated-backend php scripts/dump-production-schema.php \
 *     > tests/fixtures/production-schema.json
 *
 * Then ⚠️ CHECK `git diff` ACTUALLY SHOWS YOUR TABLE before committing. On
 * 2026-08-26 a refresh for migration 076 was written and then lost between the
 * write and the commit — the commit carried the pre-refresh file and
 * QueriedTablesExistTest failed against a table that genuinely existed in Neon.
 * This checkout is shared; a refresh can be reverted under you.
 *
 * Exists because the refresh used to be a hand-typed `php -r` one-liner passed
 * through `heroku run`, where shell and PHP quoting fight over every embedded
 * SQL string literal. The SQL here binds its parameter instead of quoting it.
 *
 * --no-tty matters: with a TTY, heroku interleaves spinner frames into stdout
 * and the JSON arrives corrupted.
 *
 * Read-only.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->prepare(
    'SELECT table_name, column_name
       FROM information_schema.columns
      WHERE table_schema = ?
      ORDER BY table_name, ordinal_position'
);
$stmt->execute(['public']);

$schema = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $schema[$row['table_name']][] = $row['column_name'];
}

if (count($schema) < 50) {
    // The fixture is a test oracle. Overwriting it with a truncated dump turns
    // every schema test green against a schema that is not production's.
    fwrite(STDERR, 'REFUSING: only ' . count($schema) . " tables returned — that is not production.\n");
    exit(1);
}

$json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// PHP's JSON_PRETTY_PRINT indents with 4 spaces and offers no way to change it;
// the committed fixture uses 2. Re-indent rather than reformat the fixture,
// because the whole value of this file is that `git diff` shows one new table
// and nothing else. At 4 spaces every refresh is a ~3,900-line diff in which a
// new table is invisible — which is how a refresh got silently lost before.
$json = preg_replace_callback('/^( +)/m', function ($m) {
    return str_repeat(' ', intdiv(strlen($m[1]), 2));
}, $json);

echo $json, "\n";
