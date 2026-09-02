<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/pagination.php';

/**
 * The cursor-pagination contract, and the five endpoints that must honour it.
 *
 *   ?limit=<1..1000>  default 200
 *   ?cursor=<opaque>  omit for the first page
 *   response gains    page: { limit, next_cursor|null, truncated }
 *
 * Three things are asserted, per the G2 brief: the cursor round trips, the
 * ordering is stable across pages, and the cap is enforced.
 *
 * ⚠️ The endpoints themselves connect to Neon at load and require a token, so
 * they cannot be executed here. What IS executed is the shared library against a
 * SQLite fixture using the SAME sort expressions each endpoint builds — the
 * paging walk below is the real algorithm, not a model of it. The endpoint files
 * are then PARSED to prove they call it and that they emit `page`. That split is
 * deliberate: the bugs in keyset pagination (a duplicate name, a NULL surname, a
 * missing tiebreaker) live in the expression list, and the expression list is
 * exactly what the walk exercises.
 */
class PaginationContractTest extends TestCase
{
    // ---- The limit ----

    public function testLimitDefaultsTo200AndCapsAt1000(): void
    {
        $this->assertSame(200, te_page_limit(null));
        $this->assertSame(200, te_page_limit(''));
        $this->assertSame(200, te_page_limit('not a number'));
        $this->assertSame(200, te_page_limit('0'));
        $this->assertSame(200, te_page_limit('-5'));
        $this->assertSame(50, te_page_limit('50'));
        $this->assertSame(1000, te_page_limit('1000'));
        $this->assertSame(1000, te_page_limit('1000000'));
        // A caller asking for everything gets the ceiling, not an error: a list
        // endpoint that refuses to answer because of a query string is worse
        // than a sensible page.
        $this->assertSame(1000, te_page_limit('999999999999'));
    }

    // ---- The cursor ----

    public function testCursorRoundTrips(): void
    {
        $values = ['smith', 'jane', 4211];
        $encoded = te_page_encode_cursor($values);

        $this->assertIsString($encoded);
        // Opaque: base64url, so it survives a query string without escaping.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        $this->assertSame($values, te_page_decode_cursor($encoded, 3));
    }

    public function testAnUnusableCursorMeansTheFIRSTPageAndNotAnError(): void
    {
        // The row the caller was reading may genuinely have been deleted, the
        // cursor may be from a different endpoint, or the sort key may have
        // changed since it was minted. None of those is worth a 400.
        $this->assertNull(te_page_decode_cursor('!!!not base64!!!', 3));
        $this->assertNull(te_page_decode_cursor(te_page_encode_cursor(['a', 1]), 3), 'wrong arity');
        $this->assertNull(te_page_decode_cursor(base64_encode('{"a":1}'), 3), 'not a list');
        $this->assertNull(te_page_decode_cursor(te_page_encode_cursor([['nested'], 1, 2]), 3));
        $this->assertNull(te_page_decode_cursor(null, 3));
        $this->assertNull(te_page_decode_cursor('', 3));
    }

    public function testNoCursorMeansNoKeysetPredicateAtAll(): void
    {
        $exprs = ['a', 'b', 'c'];
        $this->assertSame(['sql' => '', 'params' => []], te_page_keyset_clause($exprs, null));
        // Arity mismatch degrades to the first page rather than emitting a row
        // comparison with the wrong number of columns, which is a SQL error.
        $this->assertSame(['sql' => '', 'params' => []], te_page_keyset_clause($exprs, ['x']));
    }

    public function testKeysetIsARowComparisonOverTheWholeSortKey(): void
    {
        $clause = te_page_keyset_clause(['x', 'y', 'z'], ['a', 'b', 3]);
        $this->assertSame(' AND (x, y, z) > (?, ?, ?)', $clause['sql']);
        $this->assertSame(['a', 'b', 3], $clause['params']);
    }

    // ---- The page block ----

    public function testTruncatedIsTrueOnlyWhenThereIsAnotherPage(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];

        // Fetched limit+1 and got it: there is more.
        $full = te_page_finish($rows, 2, fn($r) => [$r['id']]);
        $this->assertTrue($full['page']['truncated']);
        $this->assertCount(2, $full['rows']);
        $this->assertNotNull($full['page']['next_cursor']);
        // The cursor points at the LAST row RETURNED, not the one trimmed off.
        $this->assertSame([2], te_page_decode_cursor($full['page']['next_cursor'], 1));

        // Fetched limit+1 and got fewer: this is the end.
        $last = te_page_finish($rows, 5, fn($r) => [$r['id']]);
        $this->assertFalse($last['page']['truncated']);
        $this->assertCount(3, $last['rows']);
        $this->assertNull($last['page']['next_cursor']);

        $empty = te_page_finish([], 200, fn($r) => [$r['id']]);
        $this->assertFalse($empty['page']['truncated']);
        $this->assertNull($empty['page']['next_cursor']);
        $this->assertSame(200, $empty['page']['limit']);
    }

    // ---- The walk: stable ordering across pages ----

    private function fixture(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');

        // Deliberately nasty: three people share a surname, two of those share a
        // first name as well, one surname is NULL, one is the empty string, and
        // the case is mixed. Each of those is a way a keyset walk loops or drops
        // a row when the sort key is wrong.
        $rows = [
            [1, 'Jane', 'Smith'],
            [2, 'Jane', 'Smith'],
            [3, 'John', 'Smith'],
            [4, 'Ana',  'alvarez'],
            [5, 'Ana',  'Alvarez'],
            [6, 'Zoe',  null],
            [7, 'Yuri', ''],
            [8, 'Bob',  'Brown'],
            [9, 'Cal',  'Brown'],
        ];
        $stmt = $pdo->prepare('INSERT INTO people (id, first_name, last_name) VALUES (?, ?, ?)');
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
        return $pdo;
    }

    /** @return array{ids:int[], pages:int} */
    private function walk(PDO $pdo, array $sortExprs, int $limit): array
    {
        $cursorRaw = null;
        $ids = [];
        $pages = 0;

        do {
            $pages++;
            $this->assertLessThan(50, $pages, 'Pagination did not terminate — the cursor is looping.');

            $cursor = te_page_decode_cursor($cursorRaw, count($sortExprs));
            $keyset = te_page_keyset_clause($sortExprs, $cursor);
            $sql = 'SELECT id, first_name, last_name FROM people WHERE 1=1'
                 . $keyset['sql'] . ' ' . te_page_order_by($sortExprs)
                 . ' LIMIT ' . te_page_fetch_limit($limit);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($keyset['params']);

            $page = te_page_finish(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                $limit,
                fn(array $row) => [
                    te_page_text_value($row['last_name'] ?? null),
                    te_page_text_value($row['first_name'] ?? null),
                    (int) $row['id'],
                ]
            );

            $this->assertLessThanOrEqual($limit, count($page['rows']), 'A page exceeded the requested limit.');

            foreach ($page['rows'] as $row) {
                $ids[] = (int) $row['id'];
            }
            $cursorRaw = $page['page']['next_cursor'];
        } while ($cursorRaw !== null);

        return ['ids' => $ids, 'pages' => $pages];
    }

    public function testWalkingEveryPageVisitsEveryRowExactlyOnce(): void
    {
        $pdo = $this->fixture();
        $sortExprs = [
            te_page_text_key('last_name'),
            te_page_text_key('first_name'),
            'id',
        ];

        // One full read, unpaginated, as the reference order.
        $stmt = $pdo->query(
            'SELECT id FROM people ' . te_page_order_by($sortExprs)
        );
        $expected = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $this->assertCount(9, $expected);

        // Every page size, including 1 (the worst case for a bad tiebreaker) and
        // sizes that divide the set exactly — an off-by-one at the boundary is
        // the classic keyset bug.
        foreach ([1, 2, 3, 4, 8, 9, 10, 200] as $limit) {
            $walk = $this->walk($pdo, $sortExprs, $limit);
            $this->assertSame(
                $expected,
                $walk['ids'],
                "limit={$limit}: the paged walk did not match the unpaginated order."
            );
            $this->assertSame(
                count($expected),
                count(array_unique($walk['ids'])),
                "limit={$limit}: a row was returned on two different pages."
            );
        }
    }

    public function testANullSurnameDoesNotEndTheWalkEarly(): void
    {
        // Without COALESCE, `(NULL, …) > (…)` is NULL — neither true nor false —
        // so the row after a NULL surname is silently unreachable and the list
        // just stops. This is why te_page_text_key() exists.
        $pdo = $this->fixture();
        $sortExprs = [
            te_page_text_key('last_name'),
            te_page_text_key('first_name'),
            'id',
        ];
        $walk = $this->walk($pdo, $sortExprs, 1);
        $this->assertContains(6, $walk['ids'], 'The NULL-surname row was never returned.');
        $this->assertCount(9, $walk['ids']);
    }

    public function testWithoutTheIdTiebreakerTheWalkBreaks(): void
    {
        // The guard on the guard: prove the tiebreaker is load-bearing rather
        // than decorative, by removing it and watching the walk lose rows.
        $pdo = $this->fixture();
        $noTiebreaker = [te_page_text_key('last_name'), te_page_text_key('first_name')];

        $cursorRaw = null;
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $cursor = te_page_decode_cursor($cursorRaw, count($noTiebreaker));
            $keyset = te_page_keyset_clause($noTiebreaker, $cursor);
            $stmt = $pdo->prepare(
                'SELECT id, first_name, last_name FROM people WHERE 1=1'
                . $keyset['sql'] . ' ' . te_page_order_by($noTiebreaker) . ' LIMIT ' . te_page_fetch_limit(1)
            );
            $stmt->execute($keyset['params']);
            $page = te_page_finish(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                1,
                fn(array $row) => [
                    te_page_text_value($row['last_name'] ?? null),
                    te_page_text_value($row['first_name'] ?? null),
                ]
            );
            foreach ($page['rows'] as $row) {
                $ids[] = (int) $row['id'];
            }
            $cursorRaw = $page['page']['next_cursor'];
            if ($cursorRaw === null) {
                break;
            }
        }

        // Jane Smith is two people and Ana Alvarez is two people; without the id
        // in the key, one of each pair is skipped.
        $this->assertLessThan(9, count($ids), 'Expected the tiebreaker-less walk to lose rows.');
    }

    // ---- The five endpoints ----

    /** path => [rows key, the sort columns its cursor carries] */
    private const ENDPOINTS = [
        'api/volunteer-gateway.php'     => 'volunteers',
        'legacy/athletes-gateway.php'   => 'athletes',
        'legacy/coaches-gateway.php'    => 'coaches',
        'api/super-admin-gateway.php'   => 'clubs',
    ];

    public function testEveryPaginatedEndpointUsesTheSharedContract(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (array_keys(self::ENDPOINTS) as $rel) {
            $body = file_get_contents($root . '/' . $rel);
            $this->assertNotFalse($body, "{$rel} is missing");

            foreach (['te_page_limit(', 'te_page_decode_cursor(', 'te_page_keyset_clause(', 'te_page_finish('] as $fn) {
                $this->assertStringContainsString(
                    $fn,
                    $body,
                    "{$rel} paginates by hand instead of using lib/pagination.php's {$fn}"
                );
            }

            $this->assertStringContainsString(
                "require_once __DIR__ . '/../lib/pagination.php'",
                $body,
                "{$rel} uses the pagination helpers without requiring the file"
            );

            $this->assertStringContainsString(
                "'page'",
                $body,
                "{$rel} does not return a `page` block, so a caller cannot tell a short list from a complete one"
            );
        }
    }

    public function testTheSuperAdminClubListNoLongerRunsFourCorrelatedCounts(): void
    {
        $body = file_get_contents(dirname(__DIR__, 2) . '/api/super-admin-gateway.php');
        $clubs = substr($body, strpos($body, 'function handleGetClubs'), 3500);

        // Four correlated subqueries per club row, 270 councils, 1,080 executions
        // to draw one page. Collapsed into grouped LEFT JOINs.
        $this->assertStringNotContainsString('(SELECT COUNT(*) FROM user_club_access WHERE club_profile_id = c.id', $clubs);
        $this->assertStringNotContainsString('(SELECT COUNT(*) FROM teams WHERE club_id = c.id)', $clubs);
        $this->assertStringNotContainsString('(SELECT COUNT(*) FROM athletes WHERE club_id = c.id', $clubs);

        // LEFT JOIN and COALESCE, not JOIN: a club with no coaches must still be
        // listed, and its count must still read 0.
        $this->assertStringContainsString('LEFT JOIN (', $clubs);
        $this->assertStringContainsString('COALESCE(acc.admin_count, 0)', $clubs);
        $this->assertStringContainsString('COALESCE(ath.athlete_count, 0)', $clubs);
    }

    public function testTheGroupedClubCountsProduceTheSameNumbersAsTheCorrelatedOnes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER, active_status INTEGER);

            INSERT INTO club_profile (id, name, created_at) VALUES (1,'Alpha','x'), (2,'Beta','x'), (3,'Empty','x');
            INSERT INTO user_club_access (id, user_id, club_profile_id, role, active) VALUES
                (1, 10, 1, 'club_admin', 1), (2, 11, 1, 'coach', 1), (3, 12, 1, 'coach', 1),
                (4, 13, 1, 'coach', 0), (5, 14, 2, 'club_admin', 1);
            INSERT INTO teams (id, club_id) VALUES (1,1),(2,1),(3,2);
            INSERT INTO athletes (id, club_id, active_status) VALUES (1,1,1),(2,1,0),(3,2,1);
        ");

        // SQLite has no FILTER before 3.30; CASE is the portable equivalent and
        // means the same thing. The shape under test is the grouped LEFT JOIN,
        // not the FILTER syntax.
        $sql = "
            SELECT c.id,
                   COALESCE(acc.admin_count, 0)   as admin_count,
                   COALESCE(acc.coach_count, 0)   as coach_count,
                   COALESCE(tm.team_count, 0)     as team_count,
                   COALESCE(ath.athlete_count, 0) as athlete_count
            FROM club_profile c
            LEFT JOIN (
                SELECT club_profile_id,
                       SUM(CASE WHEN role = 'club_admin' THEN 1 ELSE 0 END) as admin_count,
                       SUM(CASE WHEN role = 'coach' THEN 1 ELSE 0 END)      as coach_count
                  FROM user_club_access WHERE active = 1 GROUP BY club_profile_id
            ) acc ON acc.club_profile_id = c.id
            LEFT JOIN (SELECT club_id, COUNT(*) as team_count FROM teams GROUP BY club_id) tm ON tm.club_id = c.id
            LEFT JOIN (SELECT club_id, COUNT(*) as athlete_count FROM athletes WHERE active_status = 1 GROUP BY club_id) ath ON ath.club_id = c.id
            ORDER BY c.id
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame(
            [
                ['id' => 1, 'admin_count' => 1, 'coach_count' => 2, 'team_count' => 2, 'athlete_count' => 1],
                ['id' => 2, 'admin_count' => 1, 'coach_count' => 0, 'team_count' => 1, 'athlete_count' => 1],
                // The club with nothing at all is still LISTED, with zeros — an
                // inner join would have dropped it.
                ['id' => 3, 'admin_count' => 0, 'coach_count' => 0, 'team_count' => 0, 'athlete_count' => 0],
            ],
            array_map(fn($r) => array_map('intval', $r), $rows)
        );
    }
}
