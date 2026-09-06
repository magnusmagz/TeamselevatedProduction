<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;

require_once __DIR__ . '/../../lib/lineups.php';

/**
 * Lineup builder (CKU R67, slice 8.5, migration 096).
 *
 * Runs the real request handlers in lib/lineups.php against SQLite. Each
 * handler returns {status, body} rather than emitting, so a 403 is asserted
 * here as a number, not inferred from a grep of the gateway.
 *
 * Fixture (club 100 + an unrelated club 200):
 *   Team 10 U12 Blue  (9v9)  primary coach 50; athletes 1–9 active, 10 injured,
 *                            11 suspended, 12 inactive
 *   Team 11 U10 White (7v7)  assistant coach 51; athlete 13
 *   Team 12 U14 Blue (11v11) coach 52
 *   Team 13 no age group     coach 50
 *   Team 20 club 200
 *   Event 500 game 2026-09-01 team 10  (played)
 *   Event 501 game 2026-09-12 team 10  (next); attendance: 3 absent, 4 excused
 *   Event 502 practice        team 10
 *   Event 503 game 2026-09-06 teams 10 + 11 (intra-club)
 *   Event 504 game            team 20, club 200
 *   Club admin 60. Parent 80 (guardian of athlete 1). Parent 81 (guardian of
 *   athlete 13, team 11). Unrelated 70.
 */
class LineupTest extends TestCase
{
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
            CREATE TABLE lineups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                club_id INTEGER, team_id INTEGER NOT NULL, calendar_event_id INTEGER,
                name TEXT NOT NULL DEFAULT 'Default', formation TEXT NOT NULL, field_size TEXT NOT NULL,
                published_at TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT
            );
            CREATE TABLE lineup_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lineup_id INTEGER NOT NULL, athlete_id INTEGER NOT NULL, slot TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0, captain INTEGER NOT NULL DEFAULT 0, note TEXT,
                UNIQUE (lineup_id, athlete_id)
            );
        ");
        return $pdo;
    }

    private function basePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, age_group TEXT,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT, jersey_number INTEGER, primary_position TEXT,
                positions TEXT, leave_date TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                club_id INTEGER, user_id INTEGER, deleted_at TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER,
                relationship TEXT, is_primary INTEGER);
            CREATE TABLE user_guardians (id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER);
            CREATE TABLE calendar_events (id INTEGER PRIMARY KEY, club_id INTEGER, name TEXT,
                type TEXT, event_date TEXT, start_time TEXT, opponent_name TEXT, status TEXT);
            CREATE TABLE calendar_event_teams (id INTEGER PRIMARY KEY, event_id INTEGER, team_id INTEGER);
            CREATE TABLE event_attendance (id INTEGER PRIMARY KEY, event_id INTEGER, athlete_id INTEGER,
                status TEXT, marked_by INTEGER, marked_at TEXT, notes TEXT);
        ");

        $pdo->exec("INSERT INTO teams (id, name, club_id, age_group, primary_coach_id, deleted_at) VALUES
            (10, 'U12 Blue', 100, 'U12', 50, NULL),
            (11, 'U10 White', 100, 'U-10', NULL, NULL),
            (12, 'U14 Blue', 100, 'U14', 52, NULL),
            (13, 'Open', 100, NULL, 50, NULL),
            (20, 'Other Club U12', 200, 'U12', 90, NULL)");

        $pdo->exec("INSERT INTO athletes (id, first_name, last_name, club_id) VALUES
            (1, 'Ana', 'Keeper', 100), (2, 'Ben', 'Back', 100), (3, 'Cal', 'Centre', 100),
            (4, 'Dee', 'Defender', 100), (5, 'Eli', 'Mid', 100), (6, 'Fay', 'Midfield', 100),
            (7, 'Gus', 'Wing', 100), (8, 'Hal', 'Striker', 100), (9, 'Ivy', 'Forward', 100),
            (10, 'Jon', 'Injured', 100), (11, 'Kim', 'Suspended', 100), (12, 'Lou', 'Left', 100),
            (13, 'Max', 'Younger', 100), (14, 'Ned', 'Nobody', 100)");

        $rows = [
            [1, 10, 1, 'active', 1, 'Goalkeeper'], [2, 10, 2, 'active', 2, 'Left Back'],
            [3, 10, 3, 'active', 4, 'Center Back'], [4, 10, 4, 'active', 5, 'Right Back'],
            [5, 10, 5, 'active', 6, 'Central Midfielder'], [6, 10, 6, 'active', 8, 'Central Midfielder'],
            [7, 10, 7, 'active', 7, 'Left Wing'], [8, 10, 8, 'active', 9, 'Striker'],
            [9, 10, 9, 'active', 10, 'Forward'], [10, 10, 10, 'injured', 11, 'Center Back'],
            [11, 10, 11, 'suspended', 12, 'Striker'], [12, 10, 12, 'inactive', 13, 'Left Back'],
            [13, 11, 13, 'active', 3, 'Striker'],
        ];
        $ins = $pdo->prepare('INSERT INTO team_members (id, team_id, athlete_id, role, status, jersey_number, primary_position) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $ins->execute([$r[0], $r[1], $r[2], 'player', $r[3], $r[4], $r[5]]);
        }
        $pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (100, 11, 51, NULL, 'assistant_coach', 'active')");

        $pdo->exec("INSERT INTO users (id, email, first_name, last_name) VALUES
            (50, 'coach50@club.test', 'Cora', 'Coach'), (51, 'coach51@club.test', 'Sam', 'Second'),
            (52, 'coach52@club.test', 'Tom', 'Third'), (60, 'admin@club.test', 'Ada', 'Admin'),
            (70, 'nobody@example.com', 'No', 'Body'), (80, 'parent@family.test', 'Pat', 'Parent'),
            (81, 'parent81@family.test', 'Pam', 'Parent'), (90, 'coach90@other.test', 'Otto', 'Other')");

        $pdo->exec("INSERT INTO guardians (id, first_name, last_name, email) VALUES
            (800, 'Pat', 'Parent', 'parent@family.test'), (801, 'Pam', 'Parent', 'parent81@family.test')");
        $pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship) VALUES
            (1, 1, 800, 'Parent'), (2, 13, 801, 'Parent')");

        $pdo->exec("INSERT INTO calendar_events (id, club_id, name, type, event_date, start_time, opponent_name, status) VALUES
            (500, 100, 'League match', 'game', '2026-09-01', '10:00', 'Rivals FC', 'scheduled'),
            (501, 100, 'League match', 'game', '2026-09-12', '10:00', 'Salina', 'scheduled'),
            (502, 100, 'Tuesday practice', 'practice', '2026-09-08', '18:00', NULL, 'scheduled'),
            (503, 100, 'Blue v White', 'game', '2026-09-06', '09:00', NULL, 'scheduled'),
            (504, 200, 'Other league', 'game', '2026-09-01', '10:00', 'Elsewhere', 'scheduled')");
        $pdo->exec("INSERT INTO calendar_event_teams (id, event_id, team_id) VALUES
            (1, 500, 10), (2, 501, 10), (3, 502, 10), (4, 503, 10), (5, 503, 11), (6, 504, 20)");
        $pdo->exec("INSERT INTO event_attendance (id, event_id, athlete_id, status) VALUES
            (1, 501, 3, 'absent'), (2, 501, 4, 'excused'), (3, 501, 5, 'present')");

        return $pdo;
    }

    // ---------------------------------------------------------------- actors

    private function coach(int $id): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $id, 'email' => "coach{$id}@club.test",
            'roles' => [['role' => 'coach', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function clubAdmin(int $clubId = 100): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60, 'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    private function parent(int $id = 80): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $id, 'email' => $id === 80 ? 'parent@family.test' : 'parent81@family.test',
            'roles' => [['role' => 'parent', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function unrelated(): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 70, 'email' => 'nobody@example.com', 'roles' => []]);
    }

    /** A full, valid 3-3-2 for team 10 (9v9): 1 GK + 8 field + 9 on the bench. */
    private function nineVNine(array $over = []): array
    {
        $slots = [
            ['athlete_id' => 1, 'slot' => 'GK'],
            ['athlete_id' => 2, 'slot' => 'D1'], ['athlete_id' => 3, 'slot' => 'D2'], ['athlete_id' => 4, 'slot' => 'D3'],
            ['athlete_id' => 5, 'slot' => 'M1'], ['athlete_id' => 6, 'slot' => 'M2'], ['athlete_id' => 7, 'slot' => 'M3'],
            ['athlete_id' => 8, 'slot' => 'F1', 'captain' => true], ['athlete_id' => 9, 'slot' => 'F2'],
        ];
        return array_merge(['formation' => '3-3-2', 'slots' => $slots], $over);
    }

    private function roster(): array
    {
        return te_lineup_roster($this->pdo, 10);
    }

    // ------------------------------------------------------ the table probe

    public function testTheProbeSaysWhenTheMigrationIsMissing(): void
    {
        $this->assertTrue(te_lineups_tables_present($this->pdo));
        $this->assertFalse(te_lineups_tables_present($this->basePdo()));

        $r = te_lineup_get($this->basePdo(), $this->coach(50), 10, 501);
        $this->assertSame(503, $r['status']);
        $this->assertFalse($r['body']['available']);
        $this->assertStringContainsString('not been applied', $r['body']['error']);
    }

    // ------------------------------------------------------ field size

    public function testFieldSizeComesFromTheTeamsAgeGroup(): void
    {
        $this->assertSame('9v9', te_lineup_resolve_field_size(te_lineup_team($this->pdo, 10), null));
        $this->assertSame('7v7', te_lineup_resolve_field_size(te_lineup_team($this->pdo, 11), null),
            'U-10 spelling goes through te_normalize_age_group');
        $this->assertSame('11v11', te_lineup_resolve_field_size(te_lineup_team($this->pdo, 12), null));
        $this->assertSame('11v11', te_lineup_resolve_field_size(te_lineup_team($this->pdo, 13), null),
            'no readable age group falls back to 11v11, never to an error');
        $this->assertSame('7v7', te_lineup_resolve_field_size(te_lineup_team($this->pdo, 13), '7v7'),
            'an explicit size is honoured');
        $this->assertSame('9v9', te_lineup_resolve_field_size(te_lineup_team($this->pdo, 10), 'nonsense'));
    }

    public function testFieldSizeIsCopiedOntoTheLineupAtCreation(): void
    {
        $r = te_lineup_save($this->pdo, $this->coach(50), 10, 501, $this->nineVNine(), 50);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame('9v9', $r['body']['lineup']['field_size']);

        // A later age-group edit does not re-shape the saved lineup.
        $this->pdo->exec("UPDATE teams SET age_group = 'U14' WHERE id = 10");
        $again = te_lineup_get($this->pdo, $this->coach(50), 10, 501);
        $this->assertSame('9v9', $again['body']['lineup']['field_size']);
    }

    // ------------------------------------------------------ the roster

    public function testTheRosterIsSortedByPrimaryPositionThenJerseyAndExcludesInactive(): void
    {
        $roster = $this->roster();
        $ids = array_column($roster, 'athlete_id');
        $this->assertNotContains(12, $ids, 'an inactive member is not on the sheet');
        $this->assertNotContains(13, $ids);
        $this->assertSame(1, $ids[0], 'goalkeeper first');
        $this->assertSame([3, 10, 2], [$ids[1], $ids[2], $ids[3]], 'two centre backs, jersey 4 before jersey 11, then the left back');
        $this->assertSame([8, 11, 9], array_slice($ids, -3), 'strikers by jersey, then forward');
    }

    // ------------------------------------------------------ validation matrix

    private function assertRefused(array $body, string $needle): void
    {
        $r = te_lineup_validate($body, '9v9', $this->roster());
        $this->assertNotNull($r['error'], 'expected a refusal: ' . $needle);
        $this->assertStringContainsString($needle, $r['error']);
    }

    public function testAValidLineupPassesWithNoWarnings(): void
    {
        $r = te_lineup_validate($this->nineVNine(), '9v9', $this->roster());
        $this->assertNull($r['error']);
        $this->assertSame('3-3-2', $r['formation']);
        $this->assertCount(9, $r['slots']);
        $this->assertSame([], $r['warnings']);
        $this->assertTrue($r['slots'][7]['captain']);
    }

    public function testMissingFormationTakesTheDefaultForTheSize(): void
    {
        $r = te_lineup_validate(['slots' => []], '9v9', $this->roster());
        $this->assertNull($r['error']);
        $this->assertSame('3-3-2', $r['formation']);
    }

    public function testAFormationMustBelongToTheFieldSize(): void
    {
        $this->assertRefused($this->nineVNine(['formation' => '4-3-3']), 'not a 9v9 formation');
        $this->assertRefused($this->nineVNine(['formation' => 'banana']), 'not a 9v9 formation');
    }

    public function testASlotMustBeInTheFormation(): void
    {
        $body = $this->nineVNine();
        $body['slots'][8]['slot'] = 'F3';
        $this->assertRefused($body, 'not a slot in 3-3-2');
        $body['slots'][8]['slot'] = 'LB';
        $this->assertRefused($body, 'not a slot in 3-3-2');
    }

    public function testOnFieldCountCannotExceedTheFieldSize(): void
    {
        // 9v9 lineup validated as if the team were 4v4: the count guard fires
        // before any slot-code check could explain it away.
        $r = te_lineup_validate(['formation' => '2-2', 'slots' => $this->nineVNine()['slots']], '4v4', $this->roster());
        $this->assertStringContainsString('4 players on the field', (string) $r['error']);
    }

    public function testNoDuplicateAthlete(): void
    {
        $body = $this->nineVNine();
        $body['slots'][] = ['athlete_id' => 9, 'slot' => 'BENCH'];
        $this->assertRefused($body, 'more than once');
    }

    public function testNoDuplicateFieldSlot(): void
    {
        $body = $this->nineVNine();
        $body['slots'][8]['slot'] = 'F1';
        $this->assertRefused($body, 'F1 is used twice');
    }

    public function testTheBenchMayHoldManyPlayers(): void
    {
        $body = $this->nineVNine();
        $body['slots'][] = ['athlete_id' => 10, 'slot' => 'BENCH', 'sort_order' => 1];
        $body['slots'][] = ['athlete_id' => 11, 'slot' => 'BENCH', 'sort_order' => 2];
        $r = te_lineup_validate($body, '9v9', $this->roster());
        $this->assertNull($r['error']);
        $this->assertCount(11, $r['slots']);
    }

    public function testEveryAthleteMustBeAnActiveRosterMember(): void
    {
        $body = $this->nineVNine();
        $body['slots'][0]['athlete_id'] = 13;
        $this->assertRefused($body, 'not on this roster');
        $body['slots'][0]['athlete_id'] = 14;
        $this->assertRefused($body, 'not on this roster');
        $body['slots'][0]['athlete_id'] = 12;
        $this->assertRefused($body, 'not on this roster');
    }

    public function testInjuredOrSuspendedOnTheBenchIsAWarningNeverADrop(): void
    {
        $body = $this->nineVNine();
        $body['slots'][] = ['athlete_id' => 10, 'slot' => 'BENCH'];
        $body['slots'][] = ['athlete_id' => 11, 'slot' => 'BENCH'];
        $r = te_lineup_validate($body, '9v9', $this->roster());
        $this->assertNull($r['error']);
        $this->assertCount(11, $r['slots'], 'both are kept');
        $this->assertCount(2, $r['warnings']);
        $this->assertStringContainsString('Jon Injured', $r['warnings'][0]);
        $this->assertStringContainsString('injured', $r['warnings'][0]);
        $this->assertStringContainsString('suspended', $r['warnings'][1]);
    }

    public function testInjuredOrSuspendedOnTheFieldIsRefused(): void
    {
        $body = $this->nineVNine();
        $body['slots'][0]['athlete_id'] = 10;
        $this->assertRefused($body, 'marked injured');
        $body['slots'][0]['athlete_id'] = 11;
        $this->assertRefused($body, 'marked suspended');
    }

    public function testNotesAreShortAndSlotsAreWellFormed(): void
    {
        $body = $this->nineVNine();
        $body['slots'][0]['note'] = str_repeat('x', 201);
        $this->assertRefused($body, 'note');
        $this->assertRefused(['formation' => '3-3-2', 'slots' => 'nope'], 'slots must be a list');
        $this->assertRefused(['formation' => '3-3-2', 'slots' => [['slot' => 'GK']]], 'athlete_id');
    }

    // ------------------------------------------------------ save / get

    public function testSaveIsAFullReplaceInsideOneRowPerTeamAndGame(): void
    {
        $first = te_lineup_save($this->pdo, $this->coach(50), 10, 501, $this->nineVNine(), 50);
        $this->assertSame(200, $first['status'], json_encode($first['body']));
        $id = $first['body']['lineup']['id'];
        $this->assertSame('lineup_saved', $first['audit']['action']);
        $this->assertSame($id, $first['audit']['resource_id']);

        $smaller = ['formation' => '3-2-3', 'slots' => [['athlete_id' => 1, 'slot' => 'GK'], ['athlete_id' => 9, 'slot' => 'BENCH']]];
        $second = te_lineup_save($this->pdo, $this->coach(50), 10, 501, $smaller, 50);
        $this->assertSame(200, $second['status']);
        $this->assertSame($id, $second['body']['lineup']['id'], 'same row, replaced');
        $this->assertSame('3-2-3', $second['body']['lineup']['formation']);
        $this->assertCount(2, $second['body']['lineup']['slots']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM lineups')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testSaveReturnsWarningsAlongsideSuccess(): void
    {
        $body = $this->nineVNine();
        $body['slots'][] = ['athlete_id' => 10, 'slot' => 'BENCH'];
        $r = te_lineup_save($this->pdo, $this->coach(50), 10, 501, $body, 50);
        $this->assertSame(200, $r['status']);
        $this->assertCount(1, $r['body']['warnings']);
    }

    public function testAnInvalidSaveIs422AndWritesNothing(): void
    {
        $r = te_lineup_save($this->pdo, $this->coach(50), 10, 501, $this->nineVNine(['formation' => '4-4-2']), 50);
        $this->assertSame(422, $r['status']);
        $this->assertNull($r['audit']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM lineups')->fetchColumn());
    }

    public function testSaveNeedsAGameNotAPractice(): void
    {
        $r = te_lineup_save($this->pdo, $this->coach(50), 10, 502, $this->nineVNine(), 50);
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('game', $r['body']['error']);

        $r = te_lineup_save($this->pdo, $this->coach(50), 10, 504, $this->nineVNine(), 50);
        $this->assertSame(404, $r['status'], 'an event this team is not on reads as not found for this team');
    }

    public function testGetReturnsTheRosterAttendanceAndFormationsForTheScreen(): void
    {
        te_lineup_save($this->pdo, $this->coach(50), 10, 501, $this->nineVNine(), 50);
        $r = te_lineup_get($this->pdo, $this->coach(50), 10, 501);
        $this->assertSame(200, $r['status']);
        $b = $r['body'];
        $this->assertTrue($b['can_edit']);
        $this->assertFalse($b['is_template']);
        $this->assertSame(11, count($b['roster']));
        $this->assertSame(['3' => 'absent', '4' => 'excused', '5' => 'present'], $b['attendance']);
        $this->assertSame(['3-3-2', '3-2-3', '2-3-3', '3-4-1'], $b['formations']);
        $this->assertSame('Salina', $b['event']['opponent_name']);
        $this->assertSame('2026-09-12', $b['event']['event_date']);
        $this->assertFalse($b['has_template']);
        $this->assertSame(9, count($b['lineup']['slots']));
        $this->assertNull($b['lineup']['published_at']);
    }

    // ------------------------------------------------------ template

    public function testTheTemplateIsTheNullEventRowAndGetFallsBackToIt(): void
    {
        $t = te_lineup_save($this->pdo, $this->coach(50), 10, null, $this->nineVNine(['name' => 'Default']), 50);
        $this->assertSame(200, $t['status'], json_encode($t['body']));
        $this->assertNull($t['body']['lineup']['calendar_event_id']);
        $this->assertTrue($t['body']['lineup']['is_template']);

        // Nothing saved for 501 yet: the template comes back, flagged, so the
        // screen can say "starting from your default".
        $r = te_lineup_get($this->pdo, $this->coach(50), 10, 501);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['body']['is_template']);
        $this->assertTrue($r['body']['has_template']);
        $this->assertSame('3-3-2', $r['body']['lineup']['formation']);

        // Saving the template again edits the same row.
        te_lineup_save($this->pdo, $this->coach(50), 10, null, ['formation' => '3-2-3', 'slots' => [['athlete_id' => 1, 'slot' => 'GK']]], 50);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM lineups')->fetchColumn());

        // Asking for the template directly.
        $r = te_lineup_get($this->pdo, $this->coach(50), 10, null);
        $this->assertSame('3-2-3', $r['body']['lineup']['formation']);
        $this->assertNull($r['body']['event']);
    }

    public function testNoLineupAndNoTemplateIsAnEmptyStartNotAnError(): void
    {
        $r = te_lineup_get($this->pdo, $this->coach(50), 10, 501);
        $this->assertSame(200, $r['status']);
        $this->assertNull($r['body']['lineup']);
        $this->assertFalse($r['body']['is_template']);
        $this->assertSame('9v9', $r['body']['team']['field_size']);
    }

    // ------------------------------------------------------ copy-from

    public function testCopyFromTemplateAndFromLastGame(): void
    {
        te_lineup_save($this->pdo, $this->coach(50), 10, null, $this->nineVNine(['formation' => '3-4-1', 'slots' => [['athlete_id' => 1, 'slot' => 'GK']]]), 50);
        te_lineup_save($this->pdo, $this->coach(50), 10, 500, $this->nineVNine(), 50);

        $r = te_lineup_copy_from($this->pdo, $this->coach(50), 10, 501, 'template', 50);
        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame('3-4-1', $r['body']['lineup']['formation']);
        $this->assertSame(501, $r['body']['lineup']['calendar_event_id']);
        $this->assertSame('lineup_saved', $r['audit']['action']);
        $this->assertSame('template', $r['audit']['details']['copied_from']);

        $r = te_lineup_copy_from($this->pdo, $this->coach(50), 10, 501, 'last', 50);
        $this->assertSame(200, $r['status']);
        $this->assertSame('3-3-2', $r['body']['lineup']['formation']);
        $this->assertCount(9, $r['body']['lineup']['slots']);
        $this->assertSame(500, $r['body']['copied_from_event']['id']);

        // An explicit source event.
        $r = te_lineup_copy_from($this->pdo, $this->coach(50), 10, 501, '500', 50);
        $this->assertSame(200, $r['status']);
    }

    public function testCopyFromRefusesAMissingSourceAndAnotherTeamsLineup(): void
    {
        $r = te_lineup_copy_from($this->pdo, $this->coach(50), 10, 501, 'template', 50);
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('default', $r['body']['error']);

        $r = te_lineup_copy_from($this->pdo, $this->coach(50), 10, 501, 'last', 50);
        $this->assertSame(404, $r['status']);

        // Team 11 saves for 503; team 10's coach cannot copy it into team 10.
        te_lineup_save($this->pdo, $this->coach(51), 11, 503, ['formation' => '2-3-1', 'slots' => [['athlete_id' => 13, 'slot' => 'F1']]], 51);
        $r = te_lineup_copy_from($this->pdo, $this->coach(50), 10, 501, '503', 50);
        $this->assertSame(404, $r['status']);
    }

    public function testLastGameIsTheMostRecentGameBeforeThisOneWithALineup(): void
    {
        te_lineup_save($this->pdo, $this->coach(50), 10, 500, $this->nineVNine(), 50);
        te_lineup_save($this->pdo, $this->coach(50), 10, 503, ['formation' => '2-3-3', 'slots' => [['athlete_id' => 1, 'slot' => 'GK']]], 50);
        $this->assertSame(503, te_lineup_last_game_event_id($this->pdo, 10, '2026-09-12', 501));
        $this->assertSame(500, te_lineup_last_game_event_id($this->pdo, 10, '2026-09-06', 503),
            'the game itself is excluded');
        $this->assertNull(te_lineup_last_game_event_id($this->pdo, 10, '2026-09-01', 500));
    }

    // ------------------------------------------------------ standing

    public function testACoachOfAnotherTeamInTheClubIs403(): void
    {
        $this->assertSame(403, te_lineup_get($this->pdo, $this->coach(52), 10, 501)['status']);
        $this->assertSame(403, te_lineup_save($this->pdo, $this->coach(52), 10, 501, $this->nineVNine(), 52)['status']);
        $this->assertSame(403, te_lineup_save($this->pdo, $this->coach(52), 10, null, $this->nineVNine(), 52)['status']);
        $this->assertSame(403, te_lineup_get($this->pdo, $this->unrelated(), 10, 501)['status']);
        $this->assertSame(403, te_lineup_get($this->pdo, $this->clubAdmin(200), 10, 501)['status']);
    }

    public function testTheTemplateIsGatedOnTeamStaffStandingAndAnEventOnEventStanding(): void
    {
        $this->assertSame(200, te_lineup_save($this->pdo, $this->clubAdmin(), 10, null, $this->nineVNine(), 60)['status']);
        // Assistant coach 51 is staff on team 11 (getCoachTeamIds semantics).
        $this->assertSame(200, te_lineup_save($this->pdo, $this->coach(51), 11, null, ['formation' => '2-3-1', 'slots' => []], 51)['status']);
        $this->assertSame(200, te_lineup_save($this->pdo, $this->coach(51), 11, 503, ['formation' => '2-3-1', 'slots' => []], 51)['status']);
        $this->assertSame(403, te_lineup_save($this->pdo, $this->coach(51), 10, null, $this->nineVNine(), 51)['status']);
    }

    public function testAParentIs403UntilPublishedThenSeesTheReducedShape(): void
    {
        $body = $this->nineVNine();
        $body['slots'][0]['note'] = 'left foot';
        $body['slots'][] = ['athlete_id' => 10, 'slot' => 'BENCH', 'sort_order' => 2, 'note' => 'first sub'];
        $body['slots'][] = ['athlete_id' => 11, 'slot' => 'BENCH', 'sort_order' => 1];
        te_lineup_save($this->pdo, $this->coach(50), 10, 501, $body, 50);

        $r = te_lineup_get($this->pdo, $this->parent(), 10, 501);
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('not been published', $r['body']['error']);

        $p = te_lineup_publish($this->pdo, $this->coach(50), 10, 501, true, 50);
        $this->assertSame(200, $p['status'], json_encode($p['body']));
        $this->assertNotNull($p['body']['lineup']['published_at']);
        $this->assertSame('lineup_published', $p['audit']['action']);

        $r = te_lineup_get($this->pdo, $this->parent(), 10, 501);
        $this->assertSame(200, $r['status']);
        $b = $r['body'];
        $this->assertFalse($b['can_edit']);
        $this->assertSame([1], $b['my_athlete_ids']);
        $this->assertSame('3-3-2', $b['lineup']['formation']);
        $this->assertCount(9, $b['lineup']['slots']);
        $this->assertSame('Ana Keeper', $b['lineup']['slots'][0]['name']);
        $this->assertCount(2, $b['lineup']['bench']);
        $this->assertSame(['Jon Injured', 'Kim Suspended'], array_column($b['lineup']['bench'], 'name'),
            'bench by name, never by the coach\'s sub order');
        $this->assertStringNotContainsString('note', json_encode($b));
        $this->assertStringNotContainsString('sort_order', json_encode($b));
        $this->assertArrayNotHasKey('roster', $b);
        $this->assertArrayNotHasKey('attendance', $b);

        // Unpublish closes the door again.
        $u = te_lineup_publish($this->pdo, $this->coach(50), 10, 501, false, 50);
        $this->assertSame('lineup_unpublished', $u['audit']['action']);
        $this->assertSame(403, te_lineup_get($this->pdo, $this->parent(), 10, 501)['status']);
    }

    public function testAParentOfAnotherTeamIs403EvenWhenPublished(): void
    {
        te_lineup_save($this->pdo, $this->coach(50), 10, 501, $this->nineVNine(), 50);
        te_lineup_publish($this->pdo, $this->coach(50), 10, 501, true, 50);
        $this->assertSame(403, te_lineup_get($this->pdo, $this->parent(81), 10, 501)['status']);
        $this->assertSame(403, te_lineup_get($this->pdo, $this->parent(), 10, null)['status'],
            'the template is never published');
    }

    public function testAParentCannotPublishOrSave(): void
    {
        te_lineup_save($this->pdo, $this->coach(50), 10, 501, $this->nineVNine(), 50);
        $this->assertSame(403, te_lineup_publish($this->pdo, $this->parent(), 10, 501, true, 80)['status']);
        $this->assertSame(403, te_lineup_save($this->pdo, $this->parent(), 10, 501, $this->nineVNine(), 80)['status']);
    }

    public function testPublishNeedsASavedGameLineup(): void
    {
        $this->assertSame(404, te_lineup_publish($this->pdo, $this->coach(50), 10, 501, true, 50)['status']);
        te_lineup_save($this->pdo, $this->coach(50), 10, null, $this->nineVNine(), 50);
        $this->assertSame(422, te_lineup_publish($this->pdo, $this->coach(50), 10, null, true, 50)['status'],
            'a template cannot be published');
    }

    // ------------------------------------------------------ the games list

    public function testTheGamesListCarriesLineupStatusAndIsStaffOnly(): void
    {
        te_lineup_save($this->pdo, $this->coach(50), 10, 500, $this->nineVNine(), 50);
        te_lineup_publish($this->pdo, $this->coach(50), 10, 500, true, 50);
        $r = te_lineup_games($this->pdo, $this->coach(50), 10);
        $this->assertSame(200, $r['status']);
        $ids = array_column($r['body']['games'], 'id');
        $this->assertSame([500, 503, 501], $ids, 'games only, by date');
        $this->assertTrue($r['body']['games'][0]['has_lineup']);
        $this->assertTrue($r['body']['games'][0]['published']);
        $this->assertFalse($r['body']['games'][2]['has_lineup']);

        $this->assertSame(403, te_lineup_games($this->pdo, $this->parent(), 10)['status']);
    }

    // ------------------------------------------------------ the gateway

    public function testTheGatewayAuditsAndDoesNotDecide(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/lineups.php');
        $this->assertStringContainsString('AuthMiddleware::requireAuth()', $src);
        $this->assertStringContainsString('AuditLogger::log(', $src);
        $this->assertStringNotContainsString('JWT::decode', $src);
        $this->assertStringNotContainsString('INSERT INTO', $src, 'SQL lives in the lib');
        foreach (['te_lineup_get(', 'te_lineup_save(', 'te_lineup_copy_from(', 'te_lineup_publish(', 'te_lineup_games('] as $fn) {
            $this->assertStringContainsString($fn, $src);
        }
    }
}
