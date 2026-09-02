<?php

use PHPUnit\Framework\TestCase;

/**
 * Every table named in a FROM or JOIN must exist in production.
 *
 * SchemaConformanceTest checks INSERT and UPDATE, so it never looked at reads —
 * and on 2026-08-03 `api/financial-permissions.php` was found joining
 * `team_coaches` and `coaches`, NEITHER OF WHICH EXISTS. Every request from anyone
 * holding a coach role returned HTTP 500 with SQLSTATE 42P01.
 *
 * It hid for months because the failure looked like a product statement rather
 * than an error: the parent portal reads its athlete list from this endpoint, so a
 * coach who was also a parent was told "no athletes are registered to you" while
 * the Crew and Athletes screens showed the child correctly. Parent-only accounts
 * were fine, which is how it survived a 148-family rollout — the first person to
 * hit it was the first coach who was also a parent.
 *
 * A read that references a missing table cannot be caught by a fixture that only
 * models writes, so this closes the other half.
 */
class QueriedTablesExistTest extends TestCase
{
    private static array $tables;

    private const SCAN_DIRS = ['api', 'legacy', 'registration', 'controllers', 'services', 'lib', 'models'];

    /** Dead code, excluded rather than fixed — mirrors SchemaConformanceTest. */
    private const KNOWN_DEAD = [
        'controllers/EmailController.php' => 'MySQL-era orphan, zero callers',
        'models/Coach.php'                => 'writes tables that do not exist; no callers',
        'api/practices.php'               => 'queries `events` (now calendar_events); no callers',
    ];

    /**
     * Real phantom-table bugs that are LATENT — the query exists but no caller
     * reaches it today, so nobody is being broken by it right now. Verified
     * 2026-08-03 against live Neon; none of these three tables exist.
     *
     * This is deliberately NOT the same list as KNOWN_DEAD. These files are alive;
     * only the specific code path is unreached. Listing them here records the debt
     * without letting it block the test, and without pretending the code is right.
     * Delete an entry when the query is fixed — do not add one to silence a NEW
     * finding, which would defeat the whole test.
     */
    private const KNOWN_BROKEN = [
        'api/calendar-events-gateway.php:team_players' =>
            'handleSendCalendarInvite joins team_players (roster is team_members). The '
            . 'parent portal calls get/rsvp/upcoming only, never this action. Same query '
            . 'also has `u.email != ""` — double quotes are an identifier in Postgres, so '
            . 'it would fail twice over.',
        'api/athletes-profile.php:insurance_policies' =>
            'no frontend caller anywhere in frontend/src',
        'api/athletes-profile.php:athlete_sports' =>
            'no frontend caller anywhere in frontend/src',
    ];

    /**
     * Tables a WRITTEN migration creates that are not in Neon yet.
     *
     * Same window, and the same reasoning, as SchemaConformanceTest's
     * PENDING_MIGRATION: migrations here are applied by hand, so between
     * committing one and running it there is a period where correct code names a
     * table this fixture cannot have. Refreshing the fixture early would assert
     * the table exists in production when it does not — the exact lie that let
     * MergeFieldService query a table nobody had.
     *
     * ⚠️ Not a suppression list. testPendingTablesAreStillPending() below fails
     * the moment the table lands in the fixture, so an entry cannot outlive the
     * window it was added for. Delete it in the same commit as the fixture
     * refresh.
     */
    private const PENDING_MIGRATION_TABLES = [
        'program_staff' => '085_program_staff.sql',
    ];

    /**
     * Names that follow FROM/JOIN but are not tables: CTEs, derived-table aliases
     * and PL/pgSQL keywords. `coaches` and `parents` are CTEs in
     * recipient-search-gateway.php — the real lesson of this test is that the same
     * word can be a legitimate CTE in one file and a phantom table in another, so
     * the check must be per-query, not per-word.
     */
    private const NOT_TABLES = [
        'select', 'lateral', 'unnest', 'values', 'generate_series', 'json_array_elements',
        'jsonb_array_elements', 'jsonb_to_recordset', 'dual', 'only',
        // EXTRACT(YEAR FROM AGE(...)) / EXTRACT(EPOCH FROM NOW()) put a function
        // name straight after FROM.
        'age', 'now', 'current_date', 'current_timestamp', 'localtimestamp',
        'timestamp', 'date', 'interval', 'extract',
        // Postgres system catalogs. `FROM information_schema.columns` matches as
        // the schema name `information_schema`, and the fixture is a snapshot of
        // the application's own tables, so a system catalog can never appear in
        // it. These are always present in any Postgres database — a query against
        // one is the opposite of the defect this test hunts (a reference to a
        // table nobody checked existed). lib/program_ordering.php probes
        // information_schema.columns precisely so it can tolerate migration 084
        // not being applied yet.
        'information_schema', 'pg_catalog',
    ];

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../fixtures/production-schema.json';
        $schema = json_decode(file_get_contents($path), true);
        if (!is_array($schema) || count($schema) < 50) {
            self::fail('production-schema.json missing or implausible');
        }
        self::$tables = array_map('strtolower', array_keys($schema));
    }

    private static function stripComments(string $src): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', $src);
        $src = preg_replace('/^[ \t]*--.*$/m', '', $src);
        $src = preg_replace('/^[ \t]*\/\/.*$/m', '', $src);
        return $src;
    }

    /** CTE names defined anywhere in this file, so `WITH x AS (…) … FROM x` passes. */
    private static function cteNames(string $src): array
    {
        $names = [];
        // WITH a AS (...), b AS (...)
        // NOT `\b(?:WITH|,)` — \b before a comma requires a word character before it,
        // so the second CTE in `), parents AS (` was missed and reported as a
        // phantom table.
        if (preg_match_all('/(?:\bWITH|,)\s+([a-z_][a-z0-9_]*)\s+AS\s*\(/i', $src, $m)) {
            foreach ($m[1] as $n) { $names[strtolower($n)] = true; }
        }
        return $names;
    }

    /**
     * Only string literals that actually contain a SELECT.
     *
     * Scanning raw source treats English as SQL: "unsubscribe from all club emails"
     * and "a note from your coach" both match /FROM\s+(\w+)/ and produced 14 false
     * positives on the first run of this test — enough noise to bury the two real
     * findings. A test that cries wolf gets deleted, so it has to look only where
     * SQL can be.
     */
    private static function sqlLiterals(string $src): array
    {
        $out = [];

        // Heredoc / nowdoc.
        if (preg_match_all('/<<<[\'"]?(\w+)[\'"]?\R(.*?)\R\s*\1\s*[;,)]/s', $src, $m)) {
            foreach ($m[2] as $body) { $out[] = $body; }
        }
        // Double- and single-quoted strings, which may span lines.
        foreach (['/"((?:[^"\\\\]|\\\\.)*)"/s', "/'((?:[^'\\\\]|\\\\.)*)'/s"] as $re) {
            if (preg_match_all($re, $src, $m)) {
                foreach ($m[1] as $body) { $out[] = $body; }
            }
        }

        return array_values(array_filter(
            $out,
            fn($s) => preg_match('/\bSELECT\b/i', $s) && preg_match('/\bFROM\b/i', $s)
        ));
    }

    public function testEveryTableInAFromOrJoinExists(): void
    {
        $root = realpath(__DIR__ . '/../..');
        $problems = [];

        foreach (self::SCAN_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) { continue; }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') { continue; }
                $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
                if (array_key_exists($rel, self::KNOWN_DEAD)) { continue; }

                $src = self::stripComments(file_get_contents($f->getPathname()));
                $ctes = self::cteNames($src);

                foreach (self::sqlLiterals($src) as $sql) {
                    // FROM/JOIN followed by a bare identifier. Skips "(" (subqueries)
                    // and "$" / "{" (interpolated SQL we cannot resolve statically).
                    preg_match_all(
                        '/\b(?:FROM|(?:INNER |LEFT |RIGHT |FULL |CROSS )?JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i',
                        $sql, $m
                    );
                    foreach ($m[1] as $name) {
                        $n = strtolower($name);
                        if (in_array($n, self::NOT_TABLES, true)) { continue; }
                        if (isset($ctes[$n])) { continue; }
                        if (in_array($n, self::$tables, true)) { continue; }
                        if (array_key_exists("{$rel}:{$n}", self::KNOWN_BROKEN)) { continue; }
                        if (array_key_exists($n, self::PENDING_MIGRATION_TABLES)) { continue; }
                        $problems[] = "{$rel}: FROM/JOIN `{$name}` — not a table in production-schema.json";
                    }
                }
            }
        }

        $problems = array_values(array_unique($problems));
        $this->assertSame(
            [],
            $problems,
            "Query references a table that does not exist in production:\n  "
            . implode("\n  ", $problems)
            . "\n\nIf it is a CTE, define it with WITH in the same file. If the file is "
            . "dead, add it to KNOWN_DEAD with the reason."
        );
    }

    /**
     * Every PENDING_MIGRATION_TABLES entry must still be pending, and must be real.
     *
     * The third assertion is the point: without it this list becomes the place a
     * phantom table goes to be forgotten.
     */
    public function testPendingTablesAreStillPending(): void
    {
        $dir = __DIR__ . '/../../database/migrations/';
        $this->addToAssertionCount(1);

        foreach (self::PENDING_MIGRATION_TABLES as $table => $migration) {
            $this->assertFileExists($dir . $migration,
                "PENDING_MIGRATION_TABLES names $migration for `$table`, but that migration does not exist.");

            $this->assertStringContainsString($table, file_get_contents($dir . $migration),
                "$migration does not mention `$table` — the entry is a typo or a guess, " .
                'so the real SQL is going unchecked.');

            $this->assertNotContains($table, self::$tables,
                "`$table` is in the schema fixture now, so $migration has been applied. " .
                'Delete its PENDING_MIGRATION_TABLES entry — leaving it exempts a live table from this test forever.');
        }
    }

    /** The specific regression, named, so the reason survives a refactor. */
    public function testFinancialPermissionsDoesNotJoinThePhantomCoachTables(): void
    {
        $src = self::stripComments(
            file_get_contents(__DIR__ . '/../../api/financial-permissions.php')
        );
        $this->assertStringNotContainsString('team_coaches', $src);
        $this->assertDoesNotMatchRegularExpression('/JOIN\s+coaches\b/i', $src);
    }

    /**
     * Coach team scoping has one implementation. Re-deriving it here is what
     * produced a query against tables nobody had checked existed.
     */
    public function testFinancialPermissionsUsesTheSharedCoachScope(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/financial-permissions.php');
        $this->assertStringContainsString("lib/coach_scope.php", $src);
        $this->assertStringContainsString('getCoachTeamIds(', $src);
    }
}
