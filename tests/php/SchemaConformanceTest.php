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
                    $violations[] = "$rel: INSERT INTO `$table` — table does not exist";
                    continue;
                }
                foreach (explode(',', $m[2]) as $raw) {
                    $col = trim($raw, " \t\n\r\"`");
                    // Skip SQL comment lines and anything that isn't a bare identifier.
                    if ($col === '' || !preg_match('/^[a-zA-Z_][\w]*$/', $col)) continue;
                    if (!in_array($col, self::$schema[$table], true)) {
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
                    $violations[] = "$rel: UPDATE `$table` — table does not exist";
                    continue;
                }
                if (!preg_match_all('/([a-zA-Z_][\w]*)\s*=/', $m[2], $cols)) continue;
                foreach ($cols[1] as $col) {
                    if (!in_array($col, self::$schema[$table], true)) {
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
