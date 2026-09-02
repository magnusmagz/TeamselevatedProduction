<?php

namespace TeamsElevated\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * THERE IS NO PRIMARY GUARDIAN. Crew members are equal.
 *
 * Product rule, reaffirmed by Maggie 2026-09-02. `athlete_guardians.is_primary`
 * stays in Neon because the schema is additive-only, but as of that date it is a
 * legacy column: nothing writes it, nothing filters on it, nothing orders by it,
 * and no surface shows it.
 *
 * This replaces `PrimaryGuardianWriteTest`, which pinned the OPPOSITE rule — that
 * exactly one link per athlete carried the flag and that every ordering put it
 * first. Deleting that test without adding this one would have left the concept
 * free to grow back the next time someone needed "the parent to contact".
 *
 * Three of these are SCANS rather than unit tests, deliberately. The flag was
 * never wrong in one place; it was spread across 7 writers, 15 query sites and 4
 * components, and the recurring failure in this repo is fixing one and missing
 * the rest (`ParentPortalChildScopeTest`, `MysqlOnlySqlTest`, `sameUser.test.ts`
 * all exist for the same reason).
 *
 * ⚠️ The scans read SQL, not prose. Comments explaining the removal mention
 * `ag.is_primary` on purpose and must not be findings — see the note on
 * `QueriedTablesExistTest`: a checker that cries wolf gets deleted.
 */
class NoPrimaryGuardianTest extends TestCase
{
    private function runtimeFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (['api', 'legacy', 'services', 'lib', 'controllers', 'workers', 'models', 'registration', 'scripts'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[ltrim(str_replace($root, '', $f->getPathname()), '/')] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    /**
     * Drop every comment so a line explaining the removal cannot be read as a
     * reintroduction. SQL `--`, and PHP `//`, `#` and block comments.
     */
    private function stripComments(string $src): string
    {
        $src = preg_replace('#/\*.*?\*/#s', ' ', $src);
        $out = [];
        foreach (explode("\n", $src) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
                continue;
            }
            // An inline `-- …` tail inside a SQL heredoc.
            $line = preg_replace('/\s--\s.*$/', '', $line);
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * No writer may put `is_primary` into an athlete_guardians INSERT or UPDATE.
     *
     * Seven did on 2026-09-01: legacy/guardian-gateway.php (POST insert, POST
     * update and PUT), controllers/AthleteController.php (create, addGuardian,
     * updateGuardianRelationship), api/athletes.php, registrations-api.php and
     * services/AthleteImportStrategy.php. Leaving the column out of the statement
     * is what makes it legacy — the database default applies and nobody decides.
     *
     * ⚠️ Omitting the key from a REQUEST body is not sufficient on its own and
     * this test exists to say so. The guardian gateway used to coerce a missing
     * `is_primary_contact` to `'false'` and write it, so a payload that said
     * nothing still overwrote the column. The column had to leave the SQL.
     */
    public function testNoRuntimeFileWritesIsPrimaryOnAthleteGuardians(): void
    {
        $findings = [];

        foreach ($this->runtimeFiles() as $rel => $abs) {
            $src = $this->stripComments(file_get_contents($abs));

            // INSERT INTO athlete_guardians ( … is_primary … )
            if (preg_match_all('/INSERT\s+INTO\s+athlete_guardians\s*\(([^)]*)\)/is', $src, $m)) {
                foreach ($m[1] as $columns) {
                    if (preg_match('/\bis_primary\b/i', $columns)) {
                        $findings[] = "$rel: INSERT INTO athlete_guardians names is_primary";
                    }
                }
            }

            // UPDATE athlete_guardians … SET … is_primary =
            if (preg_match_all('/UPDATE\s+athlete_guardians\b(.*?)(?:WHERE|;|"|\')/is', $src, $m)) {
                foreach ($m[1] as $body) {
                    if (preg_match('/\bis_primary\s*=/i', $body)) {
                        $findings[] = "$rel: UPDATE athlete_guardians sets is_primary";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $findings,
            "There is no primary guardian (2026-09-02): crew members are equal and\n"
            . "athlete_guardians.is_primary is a legacy column nothing writes.\n"
            . "Leave it out of the statement; do not write a false either.\n  "
            . implode("\n  ", $findings)
        );
    }

    /**
     * No READER may depend on the flag either, and that is the half with teeth.
     *
     * Once nothing writes the column, `LEFT JOIN athlete_guardians ag ON …
     * AND ag.is_primary = true` matches nothing for every family created from
     * then on — and because it is a LEFT join the query still succeeds and
     * returns NULL contact details. Silent. Eleven billing and reporting sites
     * had exactly that join (invoices, outstanding balances, transaction report,
     * roster fee status, payment receipts / reminders / failures); each now picks
     * the first crew member by link id through a LATERAL.
     *
     * An `ORDER BY ag.is_primary DESC` is the same defect wearing a smaller hat:
     * it silently re-ranks a family the moment the data stops being maintained.
     */
    public function testNoRuntimeQueryReadsIsPrimaryOnAthleteGuardians(): void
    {
        $findings = [];

        foreach ($this->runtimeFiles() as $rel => $abs) {
            $src = $this->stripComments(file_get_contents($abs));

            foreach (explode("\n", $src) as $n => $line) {
                // `ag.` is the alias every one of these queries used; the fully
                // qualified form is spelled out too. A bare `is_primary` is NOT
                // matched — `insurance_policies.is_primary` is a different
                // column on a different table and is none of this test's
                // business.
                if (preg_match('/\b(ag|athlete_guardians)\.is_primary\b/i', $line)) {
                    $findings[] = "$rel:" . ($n + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $findings,
            "Nothing may read athlete_guardians.is_primary. A join on it stops\n"
            . "matching once nothing writes it, and a LEFT JOIN turns that into a\n"
            . "blank contact rather than an error. Pick the first crew member by\n"
            . "ag.id, or take all of them.\n  "
            . implode("\n  ", $findings)
        );
    }

    /**
     * The API contract does not carry the concept either.
     *
     * `is_primary_contact` was the aliased, API-facing spelling (see the
     * athlete_guardians note in CLAUDE.md). Four gateways aliased the column into
     * it, and four React components rendered a PRIMARY badge off the result. The
     * alias is what let the concept survive a rename of the column.
     */
    public function testNoRuntimeFileAliasesIsPrimaryIntoTheApiContract(): void
    {
        $findings = [];

        foreach ($this->runtimeFiles() as $rel => $abs) {
            $src = $this->stripComments(file_get_contents($abs));
            if (preg_match('/is_primary\s+AS\s+is_primary_contact/i', $src)) {
                $findings[] = $rel;
            }
        }

        $this->assertSame([], $findings, implode(', ', $findings)
            . ' still aliases is_primary into the API as is_primary_contact');
    }

    /**
     * Both athlete-list endpoints return the whole family.
     *
     * They keep `primary_guardian_name/email/phone` for one release so an older
     * deployed bundle does not blank its Crew column mid-deploy — those keys are
     * now just the FIRST crew member by link id, which is why the LATERAL has no
     * `is_primary` predicate. The list the frontend actually reads is `guardians`.
     */
    public function testBothAthleteListsAttachEveryCrewMember(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'legacy/athletes-gateway.php',
            'controllers/AthleteController.php',
        ] as $rel) {
            $src = file_get_contents($root . '/' . $rel);

            $this->assertStringContainsString(
                'te_attach_crew_to_athletes',
                $src,
                "$rel must attach the full crew list to its athlete list — a single "
                . 'chosen guardian is the thing being removed'
            );
            $this->assertStringContainsString(
                "require_once __DIR__ . '/../lib/athlete_crew.php'",
                $src,
                "$rel must require lib/athlete_crew.php"
            );
        }
    }

    /**
     * The crew list itself: every guardian, in link order, keyed by athlete.
     */
    public function testCrewForAthletesReturnsEveryGuardianInLinkOrder(): void
    {
        $pdo = $this->fixture();
        require_once dirname(__DIR__, 2) . '/lib/athlete_crew.php';

        $crew = te_crew_for_athletes($pdo, [1, 2, 3]);

        // Athlete 1: two guardians. Neither outranks the other, and BOTH come
        // back — a family with two parents is not represented by one of them.
        $this->assertCount(2, $crew[1]);
        $this->assertSame(['Alex Rivera', 'Bianca Rivera'], array_column($crew[1], 'name'));
        $this->assertSame('alex@example.com', $crew[1][0]['email']);
        $this->assertSame('Father', $crew[1][0]['relationship']);

        // Athlete 2: three. The one the old code would have elected (link id 30,
        // the only row with is_primary set) does not lead the list — ordering is
        // by link id and nothing else.
        $this->assertSame(
            ['Cara Stone', 'Dee Stone', 'Eve Stone'],
            array_column($crew[2], 'name')
        );

        // Athlete 3 has no crew and is simply absent from the keyed result.
        $this->assertArrayNotHasKey(3, $crew);
    }

    /**
     * An athlete with no crew still gets the key, holding an empty list.
     *
     * An absent key and an empty array read the same in JavaScript right up until
     * something does `.length` on undefined. "No crew on file" is a real answer
     * the list screen has to be able to draw.
     */
    public function testAthletesWithNoCrewGetAnEmptyListNotAMissingKey(): void
    {
        $pdo = $this->fixture();
        require_once dirname(__DIR__, 2) . '/lib/athlete_crew.php';

        $athletes = [['id' => 1], ['id' => 3]];
        te_attach_crew_to_athletes($pdo, $athletes);

        $this->assertCount(2, $athletes[0]['guardians']);
        $this->assertArrayHasKey('guardians', $athletes[1]);
        $this->assertSame([], $athletes[1]['guardians']);
    }

    /**
     * The duplicate-primaries report is gone, because the question it answered
     * ("which athletes have more than one primary?") no longer has meaning.
     */
    public function testTheDuplicatePrimariesReportIsGone(): void
    {
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2) . '/scripts/report-duplicate-primaries.php',
            'scripts/report-duplicate-primaries.php reports on a concept the product no longer has'
        );
    }

    private function fixture(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER,
                relationship TEXT, is_primary INTEGER
            );
        ");

        $pdo->exec("
            INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
              (10, 'Alex',   'Rivera', 'alex@example.com',   '5125550100'),
              (11, 'Bianca', 'Rivera', 'bianca@example.com', '5125550101'),
              (12, 'Cara',   'Stone',  'cara@example.com',   '5125550102'),
              (13, 'Dee',    'Stone',  'dee@example.com',    '5125550103'),
              (14, 'Eve',    'Stone',  'eve@example.com',    '5125550104');
        ");

        // Link ids ascend in attach order. Athlete 2's link 30 carries a stale
        // is_primary — legacy data that must not change the answer.
        $pdo->exec("
            INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary) VALUES
              (20, 1, 10, 'Father', NULL),
              (21, 1, 11, 'Mother', NULL),
              (28, 2, 12, 'Mother', NULL),
              (29, 2, 13, 'Father', NULL),
              (30, 2, 14, 'Guardian', 1);
        ");

        return $pdo;
    }
}
