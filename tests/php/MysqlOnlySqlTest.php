<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MySQL-only SQL functions do not exist in Postgres, and the failure is a 500.
 *
 * `legacy/programs-gateway.php` ordered by
 * `FIELD(p.season_type, 'Spring', 'Summer', …)`. Postgres has no `FIELD()`, so
 * the Programs list threw 42883 for EVERY user — not a scoping bug, not
 * user-specific, just a query that could never have run. It was found on
 * 2026-08-04 only because it happened to sit behind the same page as a real
 * scoping bug.
 *
 * The same substitution had already been made once, in `api/athletes-profile.php`
 * ("FIELD() is MySQL and Postgres rejects it; CASE is the portable equivalent") —
 * fixed in one file, missed in the other. That is exactly what a scan catches and
 * a code review does not.
 *
 * This scans SQL-ish string content only and keeps the function list SHORT. The
 * lesson recorded for `QueriedTablesExistTest` applies: a test that produces
 * false positives gets deleted, so it is better to catch four real things than
 * to flag forty maybes.
 */
class MysqlOnlySqlTest extends TestCase
{
    /**
     * Functions MySQL has and Postgres does not. `NOW()` is deliberately absent —
     * both have it. `CONCAT()` is absent too: Postgres has it since 9.1.
     * `CURDATE()` was added 2026-09-02: Postgres spells it `CURRENT_DATE`, and
     * `models/` was not scanned at all until then, so `models/Team.php` wrote
     * `CURDATE()` into two team_members statements unnoticed.
     */
    private const MYSQL_ONLY = [
        'FIELD('        => 'no Postgres equivalent — use CASE … WHEN … THEN n END',
        'GROUP_CONCAT(' => 'use string_agg(expr, sep)',
        'IFNULL('       => 'use COALESCE()',
        'DATE_FORMAT('  => 'use to_char()',
        'STR_TO_DATE('  => 'use to_date() / to_timestamp()',
        'DATEDIFF('     => 'subtract dates directly, or use AGE()',
        'CURDATE('      => 'use CURRENT_DATE',
    ];

    /**
     * Files with no callers. Same rationale and same entries as
     * SchemaConformanceTest::KNOWN_DEAD — kept in step deliberately.
     */
    private const KNOWN_DEAD = [
        'controllers/EmailController.php' => 'MySQL-era orphan: GROUP_CONCAT, zero callers',
        'models/Coach.php'                => 'writes tables that do not exist',
        'api/practices.php'               => 'writes to `events` (now calendar_events); no callers',
    ];

    private function runtimeFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (['api', 'legacy', 'services', 'lib', 'controllers', 'workers', 'models'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
                    if (array_key_exists($rel, self::KNOWN_DEAD)) {
                        continue;
                    }
                    $out[$rel] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    public function testNoMysqlOnlyFunctionsInLiveQueries(): void
    {
        $findings = [];

        foreach ($this->runtimeFiles() as $rel => $abs) {
            $src = file_get_contents($abs);

            foreach (self::MYSQL_ONLY as $fn => $fix) {
                // Word boundary before the name so `SOMEFIELD(` and
                // `->groupConcat(` do not match.
                $pattern = '/(?<![A-Za-z0-9_>$])' . preg_quote($fn, '/') . '/i';
                if (!preg_match($pattern, $src)) {
                    continue;
                }

                $lines = explode("\n", $src);
                foreach ($lines as $n => $line) {
                    if (!preg_match($pattern, $line)) {
                        continue;
                    }

                    // Comments explaining this very substitution are not findings.
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                        || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    // A PHP method may legitimately be called field() —
                    // services/ImportStrategy.php has one. Skip declarations and
                    // method calls outright.
                    if (preg_match('/function\s+' . preg_quote(rtrim($fn, '('), '/') . '\s*\(/i', $line)) {
                        continue;
                    }

                    // SCAN SQL, NOT SOURCE. Require a SQL keyword within a few
                    // lines either side, so only text that is actually a query
                    // counts. The QueriedTablesExistTest lesson: a checker that
                    // cries wolf gets deleted.
                    $from = max(0, $n - 8);
                    $window = implode("\n", array_slice($lines, $from, 17));
                    if (!preg_match('/\b(SELECT|FROM|WHERE|ORDER\s+BY|GROUP\s+BY|JOIN|INSERT|UPDATE)\b/i', $window)) {
                        continue;
                    }

                    $findings[] = sprintf('%s:%d uses %s — %s', $rel, $n + 1, $fn, $fix);
                }
            }
        }

        $this->assertSame(
            [],
            $findings,
            "MySQL-only SQL found. Postgres throws 42883 on these, which surfaces as a 500:\n"
            . implode("\n", $findings)
        );
    }

    /** The replacement in programs-gateway must actually be there. */
    public function testProgramsGatewayOrdersSeasonsWithCase(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/legacy/programs-gateway.php');

        $this->assertStringContainsString("CASE p.season_type", $src);
        $this->assertStringContainsString("WHEN 'Spring' THEN 1", $src);
        $this->assertStringContainsString("WHEN 'Year-Round' THEN 5", $src);
    }
}
