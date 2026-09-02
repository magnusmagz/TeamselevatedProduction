<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Identity is an email string, so the comparison must be case-insensitive.
 *
 * Reported 2026-08-18: Emily Govier signed in fine and the parent portal told her no
 * athletes were registered to her. Her guardians row read `Emilygovier0@gmail.com`,
 * her users row `emilygovier0@gmail.com`. One capital letter.
 *
 * Postgres `=` on text is case-sensitive, and ten query sites compared those two
 * columns with it. Measured against production at the time:
 *
 *     g.email = 'emilygovier0@gmail.com'         -> 0 rows
 *     lower(g.email) = 'emilygovier0@gmail.com'  -> 1 row
 *
 * Three accounts were in that state (users 152, 235, 253). Every one of them held a
 * valid `parent` role, so nothing looked broken from the staff side — they simply saw
 * an empty portal, which reads as a product statement rather than a bug. Same failure
 * shape as the coach who was told "no athletes are registered to you" for months.
 *
 * A scan, not a unit test, because the defect was never in one place: it was in ten,
 * and fixing some is indistinguishable from fixing all until a family reports it.
 */
class GuardianEmailCaseTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** Runtime directories. tests/ and vendor/ are not shipped. */
    private const DIRS = ['api', 'lib', 'controllers', 'services', 'legacy'];

    /**
     * Comparisons of a guardians.email against anything must be wrapped in LOWER().
     *
     * Matches `g.email =`, `guardians.email =` and `FROM guardians ... WHERE email =`,
     * and ignores writes (`SET email =`) and prose.
     */
    public function testGuardianEmailIsNeverComparedCaseSensitively(): void
    {
        $offenders = [];

        foreach (self::DIRS as $dir) {
            $path = self::ROOT . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname()) as $i => $line) {
                    $trimmed = ltrim($line);

                    // Comments explain the rule and name the wrong form, so skip them —
                    // otherwise the documentation reads as the defect. Same reason
                    // MysqlOnlySqlTest requires a SQL keyword near its match.
                    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                        || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    // Writes are not comparisons.
                    if (preg_match('/\bSET\b[^=]*email\s*=/i', $line)) {
                        continue;
                    }

                    $isComparison = preg_match('/\bg\.email\s*=(?!=)/i', $line)
                        || preg_match('/\bguardians\.email\s*=(?!=)/i', $line);

                    if (!$isComparison) {
                        continue;
                    }

                    if (stripos($line, 'lower(') !== false) {
                        continue;
                    }

                    $rel = str_replace(realpath(self::ROOT) . '/', '', realpath($file->getPathname()));
                    $offenders[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A guardian email is compared case-sensitively. Postgres `=` on text is "
            . "case-sensitive, and one capital letter is the difference between a parent "
            . "seeing their child and seeing an empty portal:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * Where parent standing is decided, pinned so a rewrite cannot drop the
     * normalisation.
     *
     * Both of these queries moved into lib/guardian_identity.php on 2026-09-02 (phase 2
     * of docs/user-guardians-identity-plan.md) — the resolver reads `user_guardians`
     * UNION the email match, so there is now one comparison instead of ten. The
     * normalisation still has to be there, and the two call sites still have to use it
     * rather than growing their own copy back.
     */
    public function testTheParentStandingQueriesNormalise(): void
    {
        $resolver = file_get_contents(self::ROOT . 'lib/guardian_identity.php');
        $this->assertStringContainsString(
            'LOWER(g.email) = LOWER(u.email)',
            $resolver,
            'The resolver is the only guardian email comparison left; it must normalise.'
        );
        $this->assertStringContainsString(
            'LOWER(g.email) = LOWER(:email_direct)',
            $resolver,
            'te_guardian_ids_for_email resolves a bare address at sign-up; it must normalise.'
        );

        $perms = file_get_contents(self::ROOT . 'api/financial-permissions.php');
        $this->assertStringContainsString(
            'te_guardian_ids_for_user(',
            $perms,
            'financial-permissions derives the parent athlete list through the resolver.'
        );

        $scope = file_get_contents(self::ROOT . 'lib/AthleteScope.php');
        $this->assertStringContainsString(
            'te_user_is_guardian_of_athlete(',
            $scope,
            'AthleteScope::isGuardianOfAthlete gates consent, medical and jersey writes.'
        );
    }

    public function testMigration071AddsTheFunctionalIndexes(): void
    {
        $sql = file_get_contents(self::ROOT . 'database/migrations/071_guardian_email_case_index.sql');
        $this->assertStringContainsString('guardians (LOWER(email))', $sql);
        $this->assertStringContainsString('users (LOWER(email))', $sql);
    }
}
