<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locks the `action=overview` response shape to the OverviewStats interface that
 * consumes it, across the PHP/TypeScript boundary.
 *
 * WHY THIS EXISTS
 * The gateway returned its counters flat at the top level (`delivered`, `opened`,
 * `clicked`) while EmailReporting.tsx read `data.stats` and expected `total_*`,
 * `delivery_rate`, `total_pending` and `prev_*`. `data.stats` was therefore always
 * undefined, `overview` stayed null, and every tile on the Email Reporting page
 * rendered the "No overview data available" empty state — for months, while email
 * metrics landed in `communication_log` perfectly well. Found 2026-07-30 because
 * the boxes were visibly empty, not because anything failed.
 *
 * Nothing caught it: EmailReporting.test.tsx mocks `{ success, stats: {...} }`, the
 * shape the frontend *wants*, so the frontend suite passed against a response the
 * backend never produced. That is the same failure mode as MergeFieldServiceTest
 * creating its own `events` table (see CLAUDE.md, "How the phantom columns got
 * there"): a test that asserts against a fixture nobody validated against reality.
 *
 * A mock can only prove the frontend parses what it was handed. This test proves
 * the backend actually emits it. There is no codegen step in this project, so the
 * two definitions cannot be deduplicated — they are asserted equal instead.
 */
class AnalyticsOverviewContractTest extends TestCase
{
    private const TS_PATH  = __DIR__ . '/../../frontend/src/pages/EmailReporting.tsx';
    private const PHP_PATH = __DIR__ . '/../../api/analytics-gateway.php';

    /** Field names declared by the OverviewStats interface in the .tsx source. */
    private function tsOverviewFields(): array
    {
        $src = file_get_contents(self::TS_PATH);
        $this->assertNotFalse($src, 'EmailReporting.tsx must be readable');

        $this->assertSame(
            1,
            preg_match('/interface\s+OverviewStats\s*\{(.*?)\}/s', $src, $m),
            'could not find the OverviewStats interface in EmailReporting.tsx'
        );

        preg_match_all('/^\s*(\w+)\s*:/m', $m[1], $fields);
        $this->assertNotEmpty($fields[1], 'OverviewStats parsed but declared no fields');

        return $fields[1];
    }

    /** Keys the gateway actually writes into the stats payload. */
    private function phpStatsKeys(): array
    {
        $src = file_get_contents(self::PHP_PATH);
        $this->assertNotFalse($src, 'analytics-gateway.php must be readable');

        // Both the array literal in overviewAggregate ('key' => ...) and the
        // assignments that follow it ($stats['key'] = ...).
        preg_match_all("/'(\w+)'\s*=>/", $src, $literal);
        preg_match_all("/\\\$stats\['(\w+)'\]\s*=/", $src, $assigned);

        return array_unique(array_merge($literal[1], $assigned[1]));
    }

    /**
     * Every field the dashboard reads must be one the gateway sends. The reverse
     * is not required — the gateway may return extras (bounce_rate,
     * unsubscribe_rate) that no tile renders yet.
     */
    public function testGatewaySuppliesEveryFieldTheDashboardReads(): void
    {
        $required = $this->tsOverviewFields();
        $supplied = $this->phpStatsKeys();

        $missing = array_values(array_diff($required, $supplied));

        $this->assertSame(
            [],
            $missing,
            "analytics-gateway.php does not supply these OverviewStats fields: "
            . implode(', ', $missing)
            . ". Every one of them renders a tile on the Email Reporting page, and an "
            . "absent field means that tile shows nothing (or crashes on .toFixed of "
            . "undefined). Add it to overviewAggregate() or remove it from the interface."
        );
    }

    /**
     * The payload must be nested under `stats`. This is the specific mistake that
     * blanked the page: the frontend does `setOverview(data.stats)`, so a flat
     * top-level response sets `overview` to undefined and the whole grid falls
     * through to its empty state without any error surfacing.
     */
    public function testOverviewResponseIsNestedUnderStats(): void
    {
        $src = file_get_contents(self::PHP_PATH);

        $this->assertMatchesRegularExpression(
            "/json_encode\(\s*\[\s*'success'\s*=>\s*true\s*,\s*'stats'\s*=>/",
            $src,
            "handleOverview must emit ['success' => true, 'stats' => \$stats]. "
            . "EmailReporting.tsx reads data.stats; a flat response silently blanks "
            . "every tile on the page."
        );

        $this->assertStringContainsString(
            'setOverview(data.stats)',
            file_get_contents(self::TS_PATH),
            'the frontend still reads data.stats — if that changed, update this test '
            . 'and handleOverview together'
        );
    }

    /**
     * `communication_log` has no `team_id` column. Selecting a team used to emit
     * `AND cl.team_id = ?`, a hard SQL error that 500'd the overview — so the team
     * filter didn't just fail to filter, it blanked the page.
     */
    public function testNoPhantomTeamIdColumnOnCommunicationLog(): void
    {
        $src = file_get_contents(self::PHP_PATH);

        // Strip comments first: the fix documents the old broken SQL in prose.
        $code = preg_replace('#//.*$#m', '', $src);
        $code = preg_replace('#/\*.*?\*/#s', '', $code);

        $this->assertDoesNotMatchRegularExpression(
            '/\bcl\.team_id\b/',
            $code,
            'communication_log has no team_id column — reach the team through '
            . 'athlete_id / athlete_guardians, as buildTeamFilter() does.'
        );
    }

    /**
     * The team filter must not use a JOIN against team_members. An athlete on two
     * teams (or a guardian with two athletes) would multiply rows and inflate every
     * COUNT(*) on the page — wrong numbers being worse than obviously-empty ones.
     */
    public function testTeamFilterUsesExistsNotJoin(): void
    {
        $src = file_get_contents(self::PHP_PATH);

        $this->assertSame(
            1,
            preg_match('/function buildTeamFilter\(\).*?\n\}/s', $src, $m),
            'buildTeamFilter() not found'
        );

        $this->assertStringContainsString('EXISTS', $m[0]);
        $this->assertStringNotContainsStringIgnoringCase(
            'JOIN team_members',
            preg_replace('/JOIN team_members _tf_tm2/', '', $m[0]),
            'the guardian branch may join inside its EXISTS subquery, but the outer '
            . 'filter must not join team_members — that multiplies log rows.'
        );
    }
}
