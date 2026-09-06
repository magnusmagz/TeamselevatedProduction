<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards against the single most common defect in this codebase: SQL that
 * references a column or table the database does not have.
 *
 * Postgres raises 42703 / 42P01, a try/catch logs it, the endpoint returns a
 * healthy-looking response, and the feature silently does nothing. That pattern
 * produced, in one day: emergency contacts never saving, all athlete medical info
 * being discarded, the athlete profile's team list, tryout offers never adding
 * anyone to a team, recipient search 500s, and every open/click/delivered event
 * being dropped.
 *
 * tests/fixtures/production-schema.json is a snapshot of the live Neon schema
 * (information_schema.columns). If this test fails after a legitimate migration,
 * refresh the fixture — a failure means code and schema disagree, and one of them
 * is wrong.
 */
class SchemaConformanceTest extends TestCase
{
    private static array $schema;

    /** Directories containing runtime SQL worth checking. */
    private const SCAN_DIRS = ['api', 'legacy', 'registration', 'controllers', 'services', 'lib', 'models'];

    /**
     * Files exempt from the scan, with the reason. Everything here is dead code
     * confirmed to have no callers — it is excluded rather than fixed so the test
     * stays green without pretending the code is correct.
     */
    private const KNOWN_DEAD = [
        'controllers/EmailController.php' => 'MySQL-era orphan: GROUP_CONCAT, zero callers',
        'models/Coach.php'                => 'writes guest_player_games / roster_change_log, neither table exists',
        'api/practices.php'               => 'writes to `events` (now calendar_events); no frontend callers',
    ];

    /**
     * Columns that a WRITTEN migration adds but that are not in Neon yet.
     *
     * Migrations in this repo are applied to Neon BY HAND, and the fixture is a
     * snapshot of what is actually live — so between committing a migration and
     * running it there is a window where correct code names a column this test
     * cannot find. Refreshing the fixture early would be worse than this list: it
     * would assert the column exists in production when it does not, which is the
     * exact lie that let `MergeFieldService` query a table nobody had.
     *
     * ⚠️ This is not a suppression list. Every entry is checked by
     * testPendingMigrationEntriesAreStillPending() below, which fails when the
     * migration has landed in the fixture — so an entry cannot outlive the window
     * it was added for. Delete the entry in the same commit as the fixture refresh.
     *
     * Nothing may be added here for a column that has no migration file.
     */
    private const PENDING_MIGRATION = [
        // Add an entry only for a migration that is written but not yet applied;
        // the self-check fails the moment it lands in the fixture, so an entry
        // cannot outlive its window. Delete it in the same commit as the fixture refresh.
        // Nothing pending as of 2026-09-03 (089/091/092/093 applied).
    ];

    /**
     * Whole TABLES a written migration creates that are not in Neon yet — the
     * table-level twin of the list above, with the same window and the same
     * self-check (testPendingTablesAreStillPending).
     */
    private const PENDING_MIGRATION_TABLES = [
        // Add an entry only for a migration that is written but not yet applied;
        // the self-check fails the moment it lands in the fixture, so an entry
        // cannot outlive its window. Delete it in the same commit as the fixture refresh.
        // 095 written 2026-09-06 (referee feedback, slice 8.6); not yet applied to Neon.
        'referee_feedback' => '095_referee_feedback.sql',
    ];

    /** Is this column merely waiting on a migration that is already written? */
    private static function isPendingMigration(string $table, string $col): bool
    {
        return array_key_exists("$table.$col", self::PENDING_MIGRATION);
    }

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../fixtures/production-schema.json';
        self::$schema = json_decode(file_get_contents($path), true);
        if (!is_array(self::$schema) || count(self::$schema) < 50) {
            self::fail('production-schema.json missing or implausible');
        }
    }

    /**
     * Remove SQL (`--`) and PHP (`//`, `/* *​/`) comments so a pattern search sees
     * only executable code. Without this, a comment that names the defect it fixed
     * reads as the defect itself.
     */
    private static function stripComments(string $src): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', $src);      // block comments
        $src = preg_replace('/^[ \t]*--.*$/m', '', $src);    // SQL line comments
        $src = preg_replace('/^[ \t]*\/\/.*$/m', '', $src);  // PHP line comments
        return $src;
    }

    /** Every runtime PHP file under SCAN_DIRS, minus the known-dead list. */
    private function phpFiles(): array
    {
        $root = realpath(__DIR__ . '/../..');
        $out = [];
        foreach (self::SCAN_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') continue;
                $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
                if (array_key_exists($rel, self::KNOWN_DEAD)) continue;
                $out[$rel] = $f->getPathname();
            }
        }
        return $out;
    }

    /**
     * INSERT INTO <table> (col, col, ...) — the highest-signal pattern, and the
     * one behind most of the bugs above.
     */
    public function testInsertColumnsAllExist(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $rel => $path) {
            $src = file_get_contents($path);
            if (!preg_match_all(
                '/INSERT\s+INTO\s+([a-zA-Z_][\w]*)\s*\(([^;)]*?)\)\s*(?:VALUES|SELECT)/is',
                $src, $matches, PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $m) {
                $table = $m[1];
                if (!isset(self::$schema[$table])) {
                    if (array_key_exists($table, self::PENDING_MIGRATION_TABLES)) { continue; }
                    $violations[] = "$rel: INSERT INTO `$table` — table does not exist";
                    continue;
                }
                foreach (explode(',', $m[2]) as $raw) {
                    $col = trim($raw, " \t\n\r\"`");
                    // Skip SQL comment lines and anything that isn't a bare identifier.
                    if ($col === '' || !preg_match('/^[a-zA-Z_][\w]*$/', $col)) continue;
                    if (!in_array($col, self::$schema[$table], true)
                        && !self::isPendingMigration($table, $col)) {
                        $violations[] = "$rel: $table.$col does not exist";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "SQL references columns/tables the database does not have:\n  " .
            implode("\n  ", array_unique($violations)));
    }

    /** UPDATE <table> SET col = ... */
    public function testUpdateColumnsAllExist(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $rel => $path) {
            $src = file_get_contents($path);
            if (!preg_match_all(
                '/UPDATE\s+([a-zA-Z_][\w]*)\s+SET\s+(.*?)(?:\bWHERE\b|\bRETURNING\b|["\';])/is',
                $src, $matches, PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $m) {
                $table = $m[1];
                if (!isset(self::$schema[$table])) {
                    if (array_key_exists($table, self::PENDING_MIGRATION_TABLES)) { continue; }
                    $violations[] = "$rel: UPDATE `$table` — table does not exist";
                    continue;
                }
                if (!preg_match_all('/([a-zA-Z_][\w]*)\s*=/', $m[2], $cols)) continue;
                foreach ($cols[1] as $col) {
                    if (!in_array($col, self::$schema[$table], true)
                        && !self::isPendingMigration($table, $col)) {
                        $violations[] = "$rel: $table.$col does not exist (UPDATE)";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            "UPDATE statements reference columns the database does not have:\n  " .
            implode("\n  ", array_unique($violations)));
    }

    /**
     * Specific defects fixed on 2026-07-29. Each of these shipped to production
     * and silently broke a user-facing feature; this is the "must never come back"
     * list, and it is cheaper to read than a schema diff.
     */
    public function testKnownRegressionsDoNotReturn(): void
    {
        $root = realpath(__DIR__ . '/../..');
        $banned = [
            'registration/tryouts-api.php'      => ['/INSERT\s+INTO\s+team_members\s*\([^)]*\bjoined_date\b/is' => 'team_members.join_date, not joined_date — tryout offers never added the athlete'],
            'controllers/AuthController.php'    => ['/INSERT\s+INTO\s+users\s*\(\s*email\s*,\s*password\s*,/is' => 'users.password_hash, not password'],
            'register-coach-api.php'            => ['/INSERT\s+INTO\s+users\s*\(\s*email\s*,\s*password\s*,/is' => 'users.password_hash, not password'],
            'api/athletes-profile.php'          => [
                '/\bt\.team_name\b/' => 'teams.name, not team_name',
                '/\bt\.sport\b/'     => 'teams has no sport column',
                '/\bFIELD\s*\(/i'    => 'FIELD() is MySQL; Postgres needs CASE',
            ],
            'legacy/medical-gateway.php'        => ['/\bathlete_medical\b(?![\s\S]{0,200}CREATE)/' => null], // table now exists; presence is fine
        ];

        foreach ($banned as $rel => $patterns) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) continue;
            // Strip comments first. The comments explaining each of these fixes
            // necessarily quote the banned pattern ("FIELD() is MySQL...", "not
            // joined_date"), so matching raw source makes the test fail on its own
            // documentation.
            $src = self::stripComments(file_get_contents($path));
            foreach ($patterns as $re => $why) {
                if ($why === null) continue;
                $this->assertSame(0, preg_match($re, $src), "$rel regressed: $why");
            }
        }
    }

    /** The schema fixture must cover the tables these fixes depend on. */
    /**
     * Every PENDING_MIGRATION entry must still be pending, and must be real.
     *
     * Three ways an entry can be wrong, and each is a failure:
     *   - the migration file it names is gone, so nothing will ever add the column;
     *   - the migration does not actually mention the column, so the entry is a
     *     typo or a guess and the real SQL is unguarded;
     *   - the column IS in the fixture now, meaning the migration was applied and
     *     the entry is silently exempting a live column from every future check.
     *
     * The last one is the point. Without it this list becomes the place bad SQL
     * goes to be forgotten, which is what KNOWN_BROKEN already warns about.
     */
    public function testPendingMigrationEntriesAreStillPending(): void
    {
        $dir = __DIR__ . '/../../database/migrations/';

        // An empty list is the normal state; count it so PHPUnit does not mark this risky.
        $this->addToAssertionCount(1);

        foreach (self::PENDING_MIGRATION as $qualified => $migration) {
            [$table, $col] = explode('.', $qualified, 2);

            $this->assertFileExists($dir . $migration,
                "PENDING_MIGRATION names $migration for $qualified, but that migration does not exist.");

            $this->assertStringContainsString($col, file_get_contents($dir . $migration),
                "$migration does not mention `$col` — the PENDING_MIGRATION entry for $qualified is wrong, " .
                'so the real SQL is going unchecked.');

            $this->assertNotContains($col, self::$schema[$table] ?? [],
                "$qualified is in the schema fixture now, so $migration has been applied. " .
                'Delete its PENDING_MIGRATION entry — leaving it exempts a live column from this test forever.');
        }
    }

    /** The table-level twin of the check above. Same three failure modes. */
    public function testPendingTablesAreStillPending(): void
    {
        $dir = __DIR__ . '/../../database/migrations/';
        $this->addToAssertionCount(1);

        foreach (self::PENDING_MIGRATION_TABLES as $table => $migration) {
            $this->assertFileExists($dir . $migration,
                "PENDING_MIGRATION_TABLES names $migration for `$table`, but that migration does not exist.");

            $this->assertStringContainsString($table, file_get_contents($dir . $migration),
                "$migration does not mention `$table` — the entry is wrong, so the real SQL is unchecked.");

            $this->assertArrayNotHasKey($table, self::$schema,
                "`$table` is in the schema fixture now, so $migration has been applied. " .
                'Delete its PENDING_MIGRATION_TABLES entry.');
        }
    }

    public function testFixtureCoversCriticalTables(): void
    {
        foreach (['athlete_medical', 'emergency_contacts', 'athlete_guardians', 'team_members',
                  'users', 'teams', 'consent_records', 'audit_log', 'communication_log', 'email_events'] as $t) {
            $this->assertArrayHasKey($t, self::$schema, "schema fixture is missing $t");
        }
        // Columns whose absence caused a real outage today.
        $this->assertContains('join_date', self::$schema['team_members']);
        $this->assertContains('password_hash', self::$schema['users']);
        $this->assertContains('name', self::$schema['emergency_contacts']);
        $this->assertContains('secondary_phone', self::$schema['emergency_contacts']);
        $this->assertNotContains('contact_id', self::$schema['communication_log']);
        $this->assertNotContains('sport', self::$schema['teams']);
        // Terms acceptance must be storable — the signup form has always sent
        // tos_accepted and nothing recorded it (migration 053).
        $this->assertContains('tos_accepted_at', self::$schema['users']);
    }

    /**
     * Silent-save guard: a field the signup form sends must be read by the
     * endpoint. This specific one was discarded for months, leaving no record
     * that any user accepted the Terms.
     */
    public function testSignupRecordsTermsAcceptance(): void
    {
        $auth = file_get_contents(realpath(__DIR__ . '/../..') . '/api/auth-gateway.php');
        $this->assertMatchesRegularExpression('/tos_accepted/', $auth,
            'auth-gateway must read the tos_accepted the signup form sends');
        $this->assertMatchesRegularExpression('/tos_accepted_at/', $auth,
            'acceptance must be persisted, not merely validated');
    }
}
