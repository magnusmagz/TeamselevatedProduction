<?php

use PHPUnit\Framework\TestCase;

/**
 * `/api/teams` (TeamController@index → Team::getTeams) returned HTTP 500 for every
 * caller from the MySQL→Postgres move until 2026-07-31, and nobody noticed because
 * the main Teams screen uses legacy/teams-gateway.php instead. The one live caller
 * is VolunteerSignupRequests.tsx, where the team list just came back empty.
 *
 * Three separate MySQL-isms were in the same function. This test pins all three.
 *
 * A parse test rather than an execution test on purpose: the query is Postgres-only
 * (ILIKE, CONCAT, a correlated subquery), so a SQLite fixture would have to be a
 * different query — and a fixture that does not mirror production is the exact trap
 * that let MergeFieldService query a dropped table for months while its suite stayed
 * green.
 */
class TeamListQueryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        // Comments are STRIPPED before every assertion. The comments in getTeams
        // quote the code they replaced ("it used to be str_replace(...)"), so a
        // naive file_get_contents makes each of these tests fail against its own
        // explanation — and, worse, would pass again the moment someone deleted the
        // comment. Assert on code.
        $this->src = self::codeOnly(file_get_contents(__DIR__ . '/../../models/Team.php'));
    }

    private static function codeOnly(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    /** Only getTeams — other methods on this model legitimately build SQL their own way. */
    private function getTeamsBody(): string
    {
        $start = strpos($this->src, 'function getTeams');
        $this->assertNotFalse($start, 'Team::getTeams no longer exists.');
        $next = strpos($this->src, 'public function', $start + 10);
        return substr($this->src, $start, $next === false ? null : $next - $start);
    }

    /**
     * The 500. `str_replace('SELECT t.*', 'SELECT COUNT(*)', $sql)` rewrote only the
     * first line of the select list, leaving CONCAT(u.first_name, …), s.name and
     * f.name beside an aggregate with no GROUP BY — SQLSTATE 42803.
     */
    public function testTheCountQueryIsNotDerivedByRewritingTheSelectList(): void
    {
        $this->assertStringNotContainsString(
            "str_replace('SELECT t.*'",
            $this->getTeamsBody(),
            'The count query must be built from a shared WHERE fragment, not by '
            . 'string-replacing part of the select list.'
        );
    }

    public function testTheCountQuerySelectsOnlyTheAggregate(): void
    {
        $this->assertMatchesRegularExpression(
            '/prepare\(\s*"SELECT COUNT\(\*\) FROM teams t"\s*\.\s*\$where\s*\)/',
            $this->getTeamsBody(),
            'The count must be a bare COUNT(*) over the same WHERE, with no other '
            . 'columns in the select list.'
        );
    }

    /**
     * ORDER BY cannot be bound, so it is interpolated — and it was interpolated
     * straight from $_GET via TeamController::index.
     */
    public function testTheSortColumnComesFromAFixedList(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$sortable\s*=\s*\[/',
            $this->getTeamsBody(),
            'sort_by must be checked against a whitelist before interpolation.'
        );
        $this->assertMatchesRegularExpression(
            '/in_array\(\s*\$filters\[.sort_by.\]\s*\?\?\s*..\s*,\s*\$sortable\s*,\s*true\s*\)/',
            $this->getTeamsBody(),
            'sort_by must be validated with a strict in_array against $sortable.'
        );
    }

    public function testTheSortDirectionCannotBeArbitraryText(): void
    {
        $this->assertMatchesRegularExpression(
            "/===\s*'desc'\s*\?\s*'DESC'\s*:\s*'ASC'/",
            $this->getTeamsBody(),
            'sort_order must collapse to exactly DESC or ASC.'
        );
    }

    /** No path may paste a request value into the statement. */
    public function testNoRawRequestValueReachesTheOrderByClause(): void
    {
        $this->assertStringNotContainsString(
            'ORDER BY t.$sortBy $sortOrder',
            $this->getTeamsBody(),
            'Interpolating the unvalidated variables directly is the original bug.'
        );
        $this->assertStringNotContainsString(
            '$_GET', $this->getTeamsBody(),
            'The model must not read the request directly; the controller passes filters in.'
        );
    }

    /**
     * MySQL's LIKE is case-insensitive by default; Postgres' is not. Searching
     * "Thunder" for a team stored as "thunder" silently returned nothing.
     */
    public function testTeamSearchIsCaseInsensitive(): void
    {
        $this->assertStringContainsString('t.name ILIKE :search', $this->getTeamsBody());
        $this->assertStringNotContainsString('t.name LIKE :search', $this->getTeamsBody());
    }

    /** Every filter must land on the shared fragment, or the two queries disagree. */
    public function testEveryFilterAppendsToTheSharedWhereFragment(): void
    {
        foreach (['search', 'season_id', 'age_group', 'division'] as $filter) {
            $this->assertMatchesRegularExpression(
                '/\$where \.= " AND t\.' . preg_quote($filter === 'search' ? 'name' : $filter, '/') . '/',
                $this->getTeamsBody(),
                "The '{$filter}' filter must append to \$where so the count and the "
                . 'page query stay in agreement.'
            );
        }
        $this->assertStringNotContainsString(
            '$sql .= " AND',
            $this->getTeamsBody(),
            'A filter appended to $sql alone would be applied to the rows but not '
            . 'the count, so pagination would report a total the list never reaches.'
        );
    }
}
