<?php

use PHPUnit\Framework\TestCase;

/**
 * Program display order + archive-without-delete (CKU R89/R90, migration 084).
 *
 * Parse-based on purpose. The defects this guards against are all "which
 * predicate got called" and "which fragment ended up in the SQL", which is
 * exactly the shape that a unit test on the happy path cannot see:
 *
 *  - archive / unarchive / reorder hide or renumber a club's programs for every
 *    person in it, so they must gate on `te_is_club_admin`, never on
 *    `canAccessClub` (club MEMBERSHIP — a `parent` row satisfies it, which is
 *    how `handleClubParents` handed the guardian roster to parents).
 *  - the list must order by `sort_order` with an explicit `NULLS LAST`, or a
 *    club that has never touched the arrows sinks or floats unpredictably.
 *  - the list must exclude archived rows by DEFAULT. An archive that still shows
 *    up is a delete button that lies.
 *  - every read and write of the three new columns must tolerate their absence.
 *    `main` is shared and deploys are by push, so this code can reach production
 *    days before migration 084 is applied by hand, and on Postgres a missing
 *    column is 42703 — a hard error that would empty the Programs page for every
 *    club rather than merely hiding a new feature.
 */
class ProgramsArchiveReorderTest extends TestCase
{
    private const GATEWAY = 'legacy/programs-gateway.php';
    private const LIST_API = 'registration/programs-api.php';
    private const LIB = 'lib/program_ordering.php';
    private const MIGRATION = 'database/migrations/084_programs_order_archive.sql';

    private static function root(): string
    {
        return realpath(__DIR__ . '/../..');
    }

    private static function src(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        if (!is_file($path)) {
            self::fail("missing file: $rel");
        }
        return file_get_contents($path);
    }

    /**
     * Strip comments before matching. Every one of these rules is explained in a
     * comment that necessarily quotes the thing it is about, so matching raw
     * source would let the documentation stand in for the code.
     */
    private static function code(string $rel): string
    {
        $s = self::src($rel);
        $s = preg_replace('!/\*.*?\*/!s', '', $s);
        $s = preg_replace('/^[ \t]*\/\/.*$/m', '', $s);
        $s = preg_replace('/^[ \t]*--.*$/m', '', $s);
        return $s;
    }

    /** The block in the gateway that handles archive / unarchive / reorder. */
    private static function actionBlock(): string
    {
        $code = self::code(self::GATEWAY);
        $start = strpos($code, "in_array(\$action, ['archive', 'unarchive', 'reorder']");
        self::assertNotFalse($start, 'the archive/unarchive/reorder dispatch has gone');
        $end = strpos($code, 'switch ($method)', $start);
        self::assertNotFalse($end, 'could not find the end of the action block');
        return substr($code, $start, $end - $start);
    }

    // ---------------------------------------------------------------- authz

    public function testEveryWriteActionGatesOnClubAdmin(): void
    {
        $block = self::actionBlock();

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($block, 'te_is_club_admin('),
            'archive/unarchive and reorder must each gate on te_is_club_admin()'
        );
    }

    public function testTheWriteActionsNeverGateOnClubMembership(): void
    {
        $block = self::actionBlock();

        $this->assertStringNotContainsString(
            'canAccessClub(',
            $block,
            'canAccessClub() is club MEMBERSHIP — a parent row satisfies it. '
            . 'Archiving and reordering are club-wide staff actions; use te_is_club_admin().'
        );
        $this->assertStringNotContainsString(
            'te_is_club_staff(',
            $block,
            'a coach is team-scoped and must not hide or renumber the club catalogue'
        );
    }

    public function testTheGatewayLoadsTheClubStandingPredicate(): void
    {
        $this->assertStringContainsString(
            "require_once __DIR__ . '/../lib/club_standing.php'",
            self::code(self::GATEWAY),
            'te_is_club_admin() lives in lib/club_standing.php and must be required'
        );
    }

    /**
     * The club is resolved from the PROGRAM, never taken from the request body.
     * Reading it from the body would let an admin of club A name their own club
     * and renumber club B's programs.
     */
    public function testTheClubIsResolvedFromTheProgramNotTheBody(): void
    {
        $block = self::actionBlock();

        $this->assertStringContainsString('pg_programClubId(', $block);
        $this->assertDoesNotMatchRegularExpression(
            '/\$body\[[\'"]club_id[\'"]\]/',
            $block,
            'club_id must not come from the request body'
        );
    }

    public function testReorderRefusesIdsFromAnotherClub(): void
    {
        $lib = self::code(self::LIB);

        $this->assertStringContainsString(
            'foreign_club',
            $lib,
            'te_program_reorder() must report ids that belong to another club'
        );
        $this->assertMatchesRegularExpression(
            '/SELECT id FROM programs WHERE id IN \([^)]*\) AND club_id = \?/',
            $lib,
            'every submitted id must be re-checked against the club before renumbering'
        );

        $block = self::actionBlock();
        $this->assertStringContainsString(
            "'foreign_club'",
            $block,
            'the gateway must turn a foreign-club reorder into a refusal, not a silent partial write'
        );
    }

    // ------------------------------------------------------------- ordering

    public function testTheOrderByCarriesSortOrderWithNullsLast(): void
    {
        $lib = self::code(self::LIB);

        $this->assertMatchesRegularExpression(
            '/sort_order/',
            $lib,
            'te_program_order_by() must order by sort_order'
        );
        $this->assertMatchesRegularExpression(
            '/NULLS LAST/',
            $lib,
            'NULLS LAST is explicit, not left to the Postgres default: a program nobody '
            . 'has moved has sort_order NULL and must sink to the bottom of its section'
        );
    }

    public function testBothListsOrderThroughTheSharedHelper(): void
    {
        foreach ([self::GATEWAY, self::LIST_API] as $rel) {
            $code = self::code($rel);
            $this->assertStringContainsString(
                'te_program_order_by(',
                $code,
                "$rel must build its ORDER BY through the shared helper"
            );
            $this->assertMatchesRegularExpression(
                '/ORDER BY \$orderBy/',
                $code,
                "$rel must use the helper's fragment in the query"
            );
        }
    }

    /**
     * The FIELD() → CASE substitution stays. FIELD() is MySQL; Postgres has no
     * such function and this ORDER BY threw 42883 for every user until
     * 2026-08-04. Adding sort_order in front of it must not resurrect it.
     */
    public function testTheSeasonOrderIsStillPostgresCase(): void
    {
        $code = self::code(self::GATEWAY);
        $this->assertSame(0, preg_match('/\bFIELD\s*\(/i', $code), 'FIELD() is MySQL; Postgres needs CASE');
        $this->assertStringContainsString('CASE p.season_type', $code);
    }

    // -------------------------------------------------------------- archive

    public function testTheDefaultListExcludesArchivedPrograms(): void
    {
        $lib = self::code(self::LIB);

        $this->assertStringContainsString(
            'archived_at',
            $lib,
            'te_program_archive_filter() must filter on archived_at'
        );
        $this->assertMatchesRegularExpression(
            '/archived_at\s*\.?\s*.{0,20}IS NULL|IS NULL/',
            $lib,
            'the default filter is archived_at IS NULL'
        );
        $this->assertStringContainsString(
            "' AND ' . \$col . ' IS NULL'",
            $lib,
            'the filter fragment must be `AND <alias>.archived_at IS NULL`'
        );
    }

    public function testArchivedRowsAreOnlyIncludedOnAnExplicitRequest(): void
    {
        $lib = self::code(self::LIB);

        // The filter is dropped only when the caller asked, or when the column
        // is absent. Anything else must keep archived rows out.
        $this->assertMatchesRegularExpression(
            '/if \(\$includeArchived \|\| !te_program_order_columns_present\(\$pdo\)\) \{\s*return \'\';/',
            $lib,
            'archived rows must be excluded unless include_archived was explicitly requested'
        );

        foreach ([self::GATEWAY, self::LIST_API] as $rel) {
            $this->assertStringContainsString(
                'te_program_include_archived_requested(',
                self::code($rel),
                "$rel must parse include_archived through the shared helper"
            );
            $this->assertStringContainsString(
                'te_program_archive_filter(',
                self::code($rel),
                "$rel must apply the archive filter"
            );
        }
    }

    public function testIncludeArchivedIsValidatedNotTruthy(): void
    {
        $lib = self::code(self::LIB);
        $this->assertMatchesRegularExpression(
            "/\['1', 'true', 'yes', 'on'\]/",
            $lib,
            'include_archived is matched against a list; a typo must not widen the list'
        );
    }

    // ------------------------------------------- tolerance for a missing 084

    public function testEveryColumnTouchIsGuardedOnTheColumnsExisting(): void
    {
        $lib = self::code(self::LIB);

        foreach ([
            'te_program_order_by',
            'te_program_archive_filter',
            'te_program_set_archived',
            'te_program_reorder',
        ] as $fn) {
            $start = strpos($lib, "function $fn(");
            $this->assertNotFalse($start, "$fn() has gone");
            $body = substr($lib, $start, 900);
            $this->assertStringContainsString(
                'te_program_order_columns_present($pdo)',
                $body,
                "$fn() must tolerate migration 084 not being applied yet — a missing "
                . 'column is 42703 on Postgres, which takes the whole list down'
            );
        }
    }

    public function testTheProbeIsOneInformationSchemaQueryPerRequest(): void
    {
        $lib = self::code(self::LIB);
        $this->assertStringContainsString('information_schema.columns', $lib);
        $this->assertStringContainsString('static $present = null;', $lib,
            'the probe is memoised — one query per request, not one per call site');
    }

    public function testAFailedProbeAnswersFalse(): void
    {
        $lib = self::code(self::LIB);
        $this->assertMatchesRegularExpression(
            '/catch \(Throwable \$e\) \{.*?\$present = false;/s',
            $lib,
            'a probe that throws must degrade, never propagate'
        );
    }

    public function testTheGatewayReportsTheMissingSchemaRatherThanFailingSilently(): void
    {
        $block = self::actionBlock();
        $this->assertSame(
            2,
            substr_count($block, "'schema'"),
            'archive/unarchive and reorder must each answer "not available yet" when '
            . 'migration 084 has not been applied, rather than reporting a success that wrote nothing'
        );
        $this->assertStringContainsString('503', $block);
    }

    // ------------------------------------------------------------ migration

    public function testTheMigrationIsAdditiveAndCarriesItsReverse(): void
    {
        $sql = self::src(self::MIGRATION);

        foreach (['sort_order', 'archived_at', 'archived_by'] as $col) {
            $this->assertMatchesRegularExpression(
                '/ALTER TABLE programs ADD COLUMN IF NOT EXISTS ' . $col . '\b/i',
                $sql,
                "084 must add $col"
            );
            $this->assertStringContainsString(
                "DROP COLUMN $col;",
                $sql,
                'the reverse SQL belongs in the header comment'
            );
        }

        // Additive only: nothing existing may be dropped or retyped.
        $executable = preg_replace('/^\s*--.*$/m', '', $sql);
        $this->assertSame(0, preg_match('/\bDROP\s+(COLUMN|TABLE)\b/i', $executable),
            'migration 084 is additive; the DROPs live in the reverse comment only');
        $this->assertSame(0, preg_match('/\bALTER\s+COLUMN\b/i', $executable),
            'migration 084 must not alter an existing column');
    }

    public function testArchivedByReferencesUsers(): void
    {
        $this->assertMatchesRegularExpression(
            '/archived_by\s+INTEGER\s+NULL\s+REFERENCES\s+users\(id\)/i',
            self::src(self::MIGRATION),
            'archived_by records which admin archived it and must be a real users(id)'
        );
    }

    // ---------------------------------------------------------------- audit

    public function testArchivingAndReorderingAreAudited(): void
    {
        $block = self::actionBlock();
        foreach (['program_archived', 'program_unarchived', 'programs_reordered'] as $action) {
            $this->assertStringContainsString(
                "'$action'",
                $block,
                "hiding or renumbering a club's programs must leave an audit row ($action)"
            );
        }
        $this->assertStringContainsString('AuditLogger::log(', $block,
            'audit rows go through AuditLogger, never a raw INSERT');
    }
}
