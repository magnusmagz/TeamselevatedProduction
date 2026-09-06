<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;

require_once __DIR__ . '/../../lib/referee_feedback.php';

/**
 * Referee feedback from the coaches portal (CKU R68, slice 8.6, migration 095).
 *
 * Three questions, answered by three different predicates on purpose:
 *
 *   who may WRITE  — te_event_staff_standing() (super admin, club admin of the
 *                    event's club, coach of a team ON the event), AND the event is
 *                    a game that has already happened (or is today).
 *   which TEAM     — a claim in the request body, checked against the teams on
 *                    the event and, for a coach, the teams they coach.
 *   who may REVIEW — te_is_club_admin() of the club. A coach reads their own rows
 *                    only; a parent reads nothing anywhere.
 *
 * Fixture (club 100 + an unrelated club 200):
 *   Team 10 (primary_coach_id 50), Team 11 (assistant_coach 51), Team 12 (coach 52)
 *   Event 500  game     2026-09-01  teams 10 + 11  vs Rivals FC   (played)
 *   Event 501  game     2026-12-01  team 10                        (future)
 *   Event 502  practice 2026-09-01  team 10                        (not a game)
 *   Event 503  game     2026-09-06  team 10                        (today)
 *   Event 504  game     2026-09-01  team 20, club 200
 *   Club admin 60. Parent 80 (parent role in club 100). Unrelated 70.
 */
class RefereeFeedbackTest extends TestCase
{
    private const TODAY = '2026-09-06';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->migratedPdo();
    }

    // ---------------------------------------------------------------- fixture

    private function migratedPdo(): PDO
    {
        $pdo = $this->basePdo();
        $pdo->exec("
            CREATE TABLE referee_feedback (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                club_id INTEGER NOT NULL,
                calendar_event_id INTEGER NOT NULL,
                team_id INTEGER NOT NULL,
                submitted_by INTEGER NOT NULL,
                referee_name TEXT NOT NULL,
                rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
                categories TEXT,
                comments TEXT,
                incident INTEGER NOT NULL DEFAULT 0,
                created_at TEXT,
                updated_at TEXT,
                UNIQUE (calendar_event_id, submitted_by, referee_name)
            );
        ");
        return $pdo;
    }

    private function unmigratedPdo(): PDO
    {
        return $this->basePdo();
    }

    private function basePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT,
                type TEXT, event_date TEXT, start_time TEXT, opponent_name TEXT, status TEXT);
            CREATE TABLE calendar_event_teams (id INTEGER PRIMARY KEY, event_id INTEGER, team_id INTEGER);
        ");

        $pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES
            (10, 'U12 Blue', 100, 50, NULL),
            (11, 'U12 White', 100, NULL, NULL),
            (12, 'U14 Blue', 100, 52, NULL),
            (20, 'Other Club U12', 200, 90, NULL)");

        $pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (1, 11, 51, NULL, 'assistant_coach', 'active')");

        $pdo->exec("INSERT INTO users (id, email, first_name, last_name) VALUES
            (50, 'coach50@club.test', 'Cora', 'Coach'),
            (51, 'coach51@club.test', 'Sam', 'Second'),
            (52, 'coach52@club.test', 'Tom', 'Third'),
            (60, 'admin@club.test', 'Ada', 'Admin'),
            (70, 'nobody@example.com', 'No', 'Body'),
            (80, 'parent@family.test', 'Pat', 'Parent'),
            (90, 'coach90@other.test', 'Otto', 'Other')");

        $pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, opponent_name, status) VALUES
            (500, 100, 'League match', 'game', '2026-09-01', '10:00', 'Rivals FC', 'scheduled'),
            (501, 100, 'League match', 'game', '2026-12-01', '10:00', 'Future FC', 'scheduled'),
            (502, 100, 'Tuesday practice', 'practice', '2026-09-01', '18:00', NULL, 'scheduled'),
            (503, 100, 'League match', 'game', '2026-09-06', '09:00', 'Today FC', 'scheduled'),
            (504, 200, 'Other league', 'game', '2026-09-01', '10:00', 'Elsewhere', 'scheduled')");

        $pdo->exec("INSERT INTO calendar_event_teams (id, event_id, team_id) VALUES
            (1, 500, 10), (2, 500, 11), (3, 501, 10), (4, 502, 10), (5, 503, 10), (6, 504, 20)");

        return $pdo;
    }

    // ---------------------------------------------------------------- actors

    private function coach(int $id): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $id,
            'email' => "coach{$id}@club.test",
            'roles' => [['role' => 'coach', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function clubAdmin(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60,
            'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function parent(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 80,
            'email' => 'parent@family.test',
            'roles' => [['role' => 'parent', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function unrelated(): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => 'nobody@example.com', 'roles' => []]);
    }

    private function values(array $over = []): array
    {
        return array_merge([
            'referee_name' => 'J. Whistle',
            'rating'       => 4,
            'categories'   => ['control', 'safety'],
            'comments'     => 'Kept the game under control.',
            'incident'     => false,
        ], $over);
    }

    // ------------------------------------------------------- the write gate

    public function testACoachOfATeamOnThePlayedGameMayWrite(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        $this->assertNotNull($event);
        $this->assertNull(te_referee_feedback_rateability($event, self::TODAY));
        $this->assertTrue(te_event_staff_standing($this->pdo, $this->coach(50), 500));
        $this->assertSame([10], te_referee_feedback_writable_team_ids($this->pdo, $this->coach(50), $event));
        $this->assertSame([11], te_referee_feedback_writable_team_ids($this->pdo, $this->coach(51), $event),
            'an assistant coach counts — getCoachTeamIds, never primary_coach_id alone');
    }

    public function testACoachOfAnotherTeamInTheClubHasNoStandingOnTheEvent(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        $this->assertFalse(te_event_staff_standing($this->pdo, $this->coach(52), 500));
        $this->assertSame([], te_referee_feedback_writable_team_ids($this->pdo, $this->coach(52), $event));
    }

    public function testAParentAndAnUnrelatedUserHaveNoStandingAnywhere(): void
    {
        foreach ([500, 501, 502, 503] as $eventId) {
            $this->assertFalse(te_event_staff_standing($this->pdo, $this->parent(), $eventId), "parent on $eventId");
            $this->assertFalse(te_event_staff_standing($this->pdo, $this->unrelated(), $eventId), "unrelated on $eventId");
        }
        $this->assertFalse(te_referee_feedback_can_review_own($this->parent()),
            'a parent must not even reach the "mine" list');
        $this->assertTrue(te_referee_feedback_can_review_own($this->coach(52)));
        $this->assertTrue(te_referee_feedback_can_review_own($this->clubAdmin()));
    }

    public function testAClubAdminMayWriteAgainstAnyTeamOnTheEventButNotAnotherClubs(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        $this->assertSame([10, 11], te_referee_feedback_writable_team_ids($this->pdo, $this->clubAdmin(), $event));

        $this->assertFalse(te_event_staff_standing($this->pdo, $this->clubAdmin(), 504));
    }

    /** A coach cannot rate a game that has not happened. Today counts as played. */
    public function testOnlyAGameThatHasHappenedIsRateable(): void
    {
        $this->assertNull(te_referee_feedback_rateability(te_referee_feedback_event($this->pdo, 500), self::TODAY));
        $this->assertNull(te_referee_feedback_rateability(te_referee_feedback_event($this->pdo, 503), self::TODAY),
            'a game today is rateable — coaches fill this in from the car park');

        $future = te_referee_feedback_rateability(te_referee_feedback_event($this->pdo, 501), self::TODAY);
        $this->assertIsString($future);
        $this->assertStringContainsString('not been played', $future);

        $practice = te_referee_feedback_rateability(te_referee_feedback_event($this->pdo, 502), self::TODAY);
        $this->assertIsString($practice);
        $this->assertStringContainsString('game', $practice);
    }

    /** The comparison is on the STRING, so no timezone can move the boundary. */
    public function testRateabilityComparesTheDateStringNotAParsedDate(): void
    {
        $event = te_referee_feedback_event($this->pdo, 503); // 2026-09-06
        $this->assertNull(te_referee_feedback_rateability($event, '2026-09-06'));
        $this->assertNotNull(te_referee_feedback_rateability($event, '2026-09-05'));
    }

    // ------------------------------------------------------ team resolution

    public function testTheTeamIsResolvedFromTheWritableListNeverTrusted(): void
    {
        $this->assertSame(10, te_referee_feedback_resolve_team([10], null), 'one writable team needs no claim');
        $this->assertSame(10, te_referee_feedback_resolve_team([10, 11], 10));
        $this->assertNull(te_referee_feedback_resolve_team([10, 11], null), 'two teams: the caller must say which');
        $this->assertNull(te_referee_feedback_resolve_team([10], 12), 'a team not on the event is refused');
        $this->assertNull(te_referee_feedback_resolve_team([], 10));
    }

    // ------------------------------------------------------------ validation

    public function testValidationRefusesBadRatingsAndUnknownCategories(): void
    {
        $ok = te_referee_feedback_validate($this->values());
        $this->assertNull($ok['error']);
        $this->assertSame(['control', 'safety'], $ok['values']['categories']);
        $this->assertFalse($ok['values']['incident']);

        $this->assertNotNull(te_referee_feedback_validate($this->values(['rating' => 0]))['error']);
        $this->assertNotNull(te_referee_feedback_validate($this->values(['rating' => 6]))['error']);
        $this->assertNotNull(te_referee_feedback_validate($this->values(['rating' => 'four']))['error']);
        $this->assertNotNull(te_referee_feedback_validate($this->values(['referee_name' => '  ']))['error']);
        $this->assertNotNull(te_referee_feedback_validate($this->values(['categories' => ['control', 'haircut']]))['error']);
        $this->assertNotNull(te_referee_feedback_validate($this->values(['categories' => 'control']))['error'],
            'categories must be a list, not a string');

        $dup = te_referee_feedback_validate($this->values(['categories' => ['safety', 'safety', 'control']]));
        $this->assertSame(['control', 'safety'], $dup['values']['categories'], 'deduplicated and in canonical order');

        $inc = te_referee_feedback_validate($this->values(['incident' => 'true']));
        $this->assertTrue($inc['values']['incident']);
    }

    // ---------------------------------------------------------------- writes

    public function testCreateThenReadBackRoundTripsCategoriesAndIncident(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        $id = te_referee_feedback_create($this->pdo, $event, 10, 50, $this->values(['incident' => true]));
        $this->assertGreaterThan(0, $id);

        $row = te_referee_feedback_find($this->pdo, $id);
        $this->assertSame(100, (int) $row['club_id']);
        $this->assertSame(500, (int) $row['calendar_event_id']);
        $this->assertSame(10, (int) $row['team_id']);
        $this->assertSame(50, (int) $row['submitted_by']);
        $this->assertSame(['control', 'safety'], $row['categories']);
        $this->assertTrue($row['incident']);
        $this->assertSame(4, $row['rating']);

        $mine = te_referee_feedback_for_event($this->pdo, 500, 50);
        $this->assertCount(1, $mine);
        $this->assertSame('J. Whistle', $mine[0]['referee_name']);
        $this->assertSame([], te_referee_feedback_for_event($this->pdo, 500, 51),
            'another coach on the same event does not see this coach\'s row here');
    }

    public function testUpdateRewritesOnlyTheFeedbackFields(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        $id = te_referee_feedback_create($this->pdo, $event, 10, 50, $this->values());

        te_referee_feedback_update($this->pdo, $id, $this->values([
            'referee_name' => 'J. Whistle',
            'rating' => 1,
            'categories' => ['safety'],
            'comments' => 'Missed a dangerous tackle.',
            'incident' => true,
        ]));

        $row = te_referee_feedback_find($this->pdo, $id);
        $this->assertSame(1, $row['rating']);
        $this->assertSame(['safety'], $row['categories']);
        $this->assertTrue($row['incident']);
        $this->assertSame(50, (int) $row['submitted_by'], 'the author never changes on edit');
        $this->assertNotNull($row['updated_at']);
    }

    public function testOneRowPerRefereePerCoachPerGame(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        te_referee_feedback_create($this->pdo, $event, 10, 50, $this->values());

        $this->expectException(\PDOException::class);
        te_referee_feedback_create($this->pdo, $event, 10, 50, $this->values(['rating' => 2]));
    }

    public function testTwoRefereesOnOneGameAreTwoRows(): void
    {
        $event = te_referee_feedback_event($this->pdo, 500);
        te_referee_feedback_create($this->pdo, $event, 10, 50, $this->values(['referee_name' => 'Centre']));
        te_referee_feedback_create($this->pdo, $event, 10, 50, $this->values(['referee_name' => 'AR1']));
        $this->assertCount(2, te_referee_feedback_for_event($this->pdo, 500, 50));
    }

    // ------------------------------------------------------- the admin read

    private function seedForList(): void
    {
        $e500 = te_referee_feedback_event($this->pdo, 500);
        $e503 = te_referee_feedback_event($this->pdo, 503);
        $e504 = te_referee_feedback_event($this->pdo, 504);
        te_referee_feedback_create($this->pdo, $e500, 10, 50, $this->values(['referee_name' => 'J. Whistle', 'rating' => 4]));
        te_referee_feedback_create($this->pdo, $e500, 11, 51, $this->values(['referee_name' => 'J. Whistle', 'rating' => 2, 'incident' => true]));
        te_referee_feedback_create($this->pdo, $e503, 10, 50, $this->values(['referee_name' => 'M. Flag', 'rating' => 5]));
        te_referee_feedback_create($this->pdo, $e504, 20, 90, $this->values(['referee_name' => 'J. Whistle', 'rating' => 1, 'incident' => true]));
    }

    public function testListIsClubScopedAndCarriesEventTeamAndAuthor(): void
    {
        $this->seedForList();
        $rows = te_referee_feedback_list($this->pdo, 100, []);
        $this->assertCount(3, $rows, 'club 200\'s row never appears');

        $first = $rows[0];
        foreach (['event_name', 'event_date', 'opponent_name', 'team_name', 'submitted_by_name', 'categories', 'incident'] as $k) {
            $this->assertArrayHasKey($k, $first);
        }
        $this->assertSame('2026-09-06', $first['event_date'], 'newest game first');
    }

    public function testListFilters(): void
    {
        $this->seedForList();

        $this->assertCount(2, te_referee_feedback_list($this->pdo, 100, ['from' => '2026-09-01', 'to' => '2026-09-01']));
        $this->assertCount(1, te_referee_feedback_list($this->pdo, 100, ['from' => '2026-09-02']));
        $this->assertCount(2, te_referee_feedback_list($this->pdo, 100, ['team_id' => 10]));
        $this->assertCount(1, te_referee_feedback_list($this->pdo, 100, ['incident' => true]));
        $this->assertCount(2, te_referee_feedback_list($this->pdo, 100, ['referee_name' => 'whistle']),
            'name match is case-insensitive and partial');
        $this->assertCount(0, te_referee_feedback_list($this->pdo, 100, ['referee_name' => 'nobody']));
    }

    public function testSummaryIsPerRefereeName(): void
    {
        $this->seedForList();
        $summary = te_referee_feedback_summary(te_referee_feedback_list($this->pdo, 100, []));

        $byName = [];
        foreach ($summary as $s) {
            $byName[$s['referee_name']] = $s;
        }
        $this->assertSame(2, $byName['J. Whistle']['count']);
        $this->assertSame(3.0, $byName['J. Whistle']['average_rating']);
        $this->assertSame(1, $byName['J. Whistle']['incident_count']);
        $this->assertSame(1, $byName['M. Flag']['count']);
        $this->assertSame(5.0, $byName['M. Flag']['average_rating']);
        $this->assertSame(0, $byName['M. Flag']['incident_count']);
    }

    public function testMineIsTheCallersRowsOnly(): void
    {
        $this->seedForList();
        $this->assertCount(2, te_referee_feedback_mine($this->pdo, 50));
        $this->assertCount(1, te_referee_feedback_mine($this->pdo, 51));
        $this->assertCount(0, te_referee_feedback_mine($this->pdo, 60));
    }

    // ---------------------------------------------------------------- export

    public function testExportSheetHasStableHeadersAndReportsTruncation(): void
    {
        $this->seedForList();
        $rows = te_referee_feedback_list($this->pdo, 100, []);
        $sheet = te_referee_feedback_export_sheet($rows);

        $this->assertSame(
            ['Game date', 'Game', 'Opponent', 'Team', 'Referee', 'Rating', 'Categories', 'Incident', 'Comments', 'Submitted by', 'Submitted at'],
            $sheet['headers']
        );
        $this->assertCount(3, $sheet['rows']);
        $this->assertNull(te_referee_feedback_truncation_notice($sheet));
        $this->assertSame('control, safety', $sheet['rows'][0][6]);

        $sheet = te_referee_feedback_export_sheet($rows, 2);
        $this->assertCount(2, $sheet['rows']);
        $notice = te_referee_feedback_truncation_notice($sheet);
        $this->assertSame('1 of 3 feedback rows were left out (the file is capped at 2 rows).', $notice);
    }

    // ---------------------------------------------------- the missing table

    public function testTheProbeSaysNoUntilMigration095IsApplied(): void
    {
        $this->assertTrue(te_referee_feedback_table_present($this->pdo));
        $this->assertFalse(te_referee_feedback_table_present($this->unmigratedPdo()));
    }

    // ------------------------------------------------- the gateway, parsed

    /**
     * The predicate is rarely wrong; which one gets called is. So the gateway is
     * parsed for the calls that matter, the way AthleteWriteScopeTest does.
     */
    public function testTheGatewayCallsTheRightPredicates(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/referee-feedback.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $src);
        $this->assertStringContainsString('te_event_staff_standing(', $src, 'writes gate on event staff standing');
        $this->assertStringContainsString('te_is_club_admin(', $src, 'the club-wide list and export are admin only');
        $this->assertStringContainsString('te_referee_feedback_rateability(', $src, 'a future game must be refused');
        $this->assertStringContainsString("'referee_feedback_submitted'", $src);
        $this->assertStringContainsString("'referee_feedback_updated'", $src);
        $this->assertStringContainsString("'referee_feedback_exported'", $src);
        $this->assertStringContainsString('AuditLogger::log(', $src);
        $this->assertDoesNotMatchRegularExpression('/INSERT\s+INTO\s+audit_log/i', $src, 'audit goes through AuditLogger');
        $this->assertStringNotContainsString('canAccessClub(', $src, 'membership is not staff');
        $this->assertStringNotContainsString('JWT::decode(', $src);
        $this->assertStringContainsString("date('Y-m-d')", $src, 'today is a date-only string');
        $this->assertStringContainsString('"\xEF\xBB\xBF"', $src, 'the CSV opens with a BOM');
        $this->assertStringContainsString('X-Referee-Feedback-Export-Truncated', $src);
    }
}
