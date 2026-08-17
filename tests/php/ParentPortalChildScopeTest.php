<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "My children" and "athletes whose finances I can see" are different questions.
 *
 * `api/financial-permissions.php` answers the second: a user's own children UNION
 * every athlete on the teams they coach. That union is correct for payment screens
 * and wrong for anything meaning "my family".
 *
 * The parent portal read the union. So a coach who is also a parent got their whole
 * roster wherever the portal asked about their children — and `ConsentGate` asked
 * them to give PARENTAL CONSENT for other people's kids. `consent.php?action=record`
 * correctly refuses a non-guardian with a 422, `handleSubmit` throws on the first
 * failure, and the gate renders instead of the portal — so those accounts could not
 * enter the parent portal at all. Luis Escamilla (user 157, coach of team 79 with 11
 * athletes, father of one of them) pressed Submit five times on 2026-08-17, writing
 * his own son's consent five times over and failing on the first teammate each time.
 *
 * Six coach-parent accounts at club 51 were in that state.
 *
 * The predicate was never wrong. Which one got called was — the same shape as
 * `userCanAccessAthlete` vs `staffCanManageAthlete`, and as `canAccessClub` vs
 * `te_is_club_admin`. So the durable guard is a scan for the wrong caller, not a
 * unit test of the right one.
 */
class ParentPortalChildScopeTest extends TestCase
{
    private const ENDPOINT = __DIR__ . '/../../api/financial-permissions.php';
    private const PORTAL_DIR = __DIR__ . '/../../frontend/src/parent-portal';

    /**
     * Tests are deliberately NOT scanned. They must be free to name the wider list —
     * the coach-parent regression test proves the gate ignores it by passing both,
     * which is only expressible by mentioning it. Scanning them would force that
     * assertion to be deleted, i.e. the checker would remove its own evidence.
     *
     * Same lesson as MysqlOnlySqlTest requiring a SQL keyword near the match, and
     * QueriedTablesExistTest scanning SQL rather than prose: a checker that cries
     * wolf gets deleted.
     */
    private const SKIP_DIR = '__tests__';

    public function testEndpointReturnsAGuardianOnlyChildList(): void
    {
        $src = file_get_contents(self::ENDPOINT);

        $this->assertStringContainsString(
            "'my_children'",
            $src,
            'financial-permissions must expose a guardian-only my_children list; the '
            . 'parent portal has nothing else honest to read.'
        );
        $this->assertStringContainsString("'my_children_ids'", $src);
    }

    /**
     * The coach branch must never widen $myChildren.
     *
     * This is the whole bug in one assertion: the old code had a single list and
     * array_merge'd the coach's roster into it. If a future edit merges into
     * $myChildren, the endpoint starts telling the portal that eleven children are
     * yours again — and every frontend guard below becomes decoration.
     */
    public function testCoachRosterIsNeverMergedIntoMyChildren(): void
    {
        $src = file_get_contents(self::ENDPOINT);

        $this->assertMatchesRegularExpression(
            '/\$myChildren\s*=\s*\$athleteStmt->fetchAll/',
            $src,
            '$myChildren must come from the guardian query.'
        );

        // Every assignment to $myChildren, other than its initialisation.
        preg_match_all('/\$myChildren\s*=\s*([^;]+);/', $src, $m);
        foreach ($m[1] as $rhs) {
            $rhs = trim($rhs);
            if ($rhs === '[]') {
                continue;
            }
            $this->assertStringNotContainsString(
                'array_merge',
                $rhs,
                'A coach roster was merged into $myChildren. Coaching a child is not '
                . 'guardianship of one — merge into $accessibleAthletes instead.'
            );
            $this->assertStringNotContainsString('teamAthleteStmt', $rhs);
        }
    }

    /**
     * No parent-portal file may read the finance-scoped list.
     *
     * Four files did: ConsentGate, useParentAthletes (which feeds the dashboard,
     * athlete detail and medical pages), AthleteDetailPage and MedicalInfoPage.
     * Fixing one and missing three is precisely what happened to `FIELD(` in
     * MysqlOnlySqlTest — a scan catches that, a review does not.
     */
    public function testNoParentPortalFileReadsTheFinanceScopedList(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PORTAL_DIR, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!preg_match('/\.tsx?$/', $file->getFilename())) {
                continue;
            }

            $relative = str_replace(realpath(self::PORTAL_DIR) . '/', '', realpath($file->getPathname()));
            if (str_contains($relative, self::SKIP_DIR . '/')) {
                continue;
            }

            foreach (file($file->getPathname()) as $i => $line) {
                // Comments explain WHY the wider list is wrong here; they are the
                // documentation this test exists to keep true, so skip them.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (preg_match('/\baccessibleAthletes?\b|\baccessibleAthleteIds\b/', $line)) {
                    $offenders[] = $relative . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The parent portal must scope to myChildren / myChildrenIds.\n"
            . "accessibleAthletes includes every athlete on the teams a coach runs, so "
            . "reading it here shows a coach-parent their roster as their family:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * The context must distinguish "field absent" from "field empty".
     *
     * `main` is shared and deploys are by push, so this frontend can be live before
     * the backend that serves my_children. An absent field means an old backend and
     * must fall back to the wider list — visibly wrong for a few minutes. Treating it
     * as "no children" would silently stop prompting EVERY family for consent, which
     * is a compliance gap nobody would notice. `||` collapses the two; `??` does not.
     */
    public function testContextFallsBackWithNullishCoalescing(): void
    {
        $src = file_get_contents(__DIR__ . '/../../frontend/src/contexts/FinancialPermissionsContext.tsx');

        $this->assertMatchesRegularExpression(
            '/data\.my_children\s*\?\?\s*data\.accessible_athletes/',
            $src,
            'my_children must fall back with ?? so that an EMPTY list stays empty.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data\.my_children\s*\|\|\s*data\.accessible_athletes/',
            $src,
            '|| would hand a coach-parent their whole roster whenever they have no '
            . 'children, permanently.'
        );
    }
}
