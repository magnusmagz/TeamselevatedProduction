<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;

require_once __DIR__ . '/../../lib/team_roster_scope.php';
require_once __DIR__ . '/../../lib/roster_export.php';

/**
 * Roster download — scope and sheet building.
 *
 * The endpoint (api/roster-export.php) emits headers and exits, so it cannot be
 * required in a unit test. Everything it decides lives in two libs that CAN be:
 * te_team_roster_staff_standing() and te_roster_export_sheet(). These run the
 * real functions against SQLite.
 *
 * Fixture — club 100, teams 10 and 11; club 200, team 12:
 *   Team 10  primary_coach_id 50   players: athletes 1 (Ana), 2 (Bo), 3 (Cy)
 *   Team 11  assistant_coach 51    player:  athlete 4
 *   Team 12  club 200              player:  athlete 5
 *   Athlete 1: three crew (primary Nia, then Omar, then Pia)
 *   Athlete 2: one crew, athlete 3: none
 */
class RosterExportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT, jersey_number INTEGER,
                primary_position TEXT, positions TEXT, join_date TEXT, leave_date TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                date_of_birth TEXT, active_status INTEGER, deleted_at TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER,
                relationship TEXT, is_primary INTEGER
            );
        ");

        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES
            (10, 'Sharks U12', 100, 50),
            (11, 'Bolts U10', 100, NULL),
            (12, 'Other Club FC', 200, NULL)");

        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, date_of_birth, active_status, deleted_at) VALUES
            (1, 'Ana',  'Alvarez', '2014-03-05', 1, NULL),
            (2, 'Bo',   'Brooks',  '2013-11-20', 1, NULL),
            (3, 'Cy',   'Chen',    '2014-01-02', 1, NULL),
            (4, 'Dee',  'Diaz',    '2015-06-06', 1, NULL),
            (5, 'Eli',  'Evans',   '2014-09-09', 1, NULL),
            (6, 'Gone', 'Ghost',   '2014-04-04', 1, '2026-01-01'),
            (7, 'Left', 'Leaver',  '2014-05-05', 1, NULL)");

        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status, jersey_number, primary_position, leave_date) VALUES
            (1, 10, NULL, 1, 'player', 'active',   7,    'Forward',    NULL),
            (2, 10, NULL, 2, 'player', 'injured',  NULL, NULL,         NULL),
            (3, 10, NULL, 3, 'player', 'active',   3,    'Goalkeeper', NULL),
            (4, 10, NULL, 6, 'player', 'active',   9,    'Defender',   NULL),
            (5, 10, NULL, 7, 'player', 'active',   11,   'Midfield',   '2026-05-01'),
            (6, 10, 55,  NULL, 'assistant_coach', 'active', NULL, NULL, NULL),
            (7, 11, NULL, 4, 'player', 'active',   1,    'Forward',    NULL),
            (8, 11, 51,  NULL, 'assistant_coach', 'active', NULL, NULL, NULL),
            (9, 12, NULL, 5, 'player', 'active',   2,    'Forward',    NULL)");

        $this->pdo->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1, 'Nia',  'Alvarez', 'nia@example.com',  '555-0101'),
            (2, 'Omar', 'Alvarez', 'omar@example.com', '555-0102'),
            (3, 'Pia',  'Zamora',  'pia@example.com',  '555-0103'),
            (4, 'Rob',  'Brooks',  'rob@example.com',  '555-0104')");

        // Link ids order the crew columns. Link 2 keeps a stale `is_primary = 1`
        // so a regression that starts reading the flag again reorders Ana's
        // columns and fails testCrewColumnsAreFilledInLinkOrderWithNobodyPromoted.
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary) VALUES
            (1, 1, 2, 'Parent', 0),
            (2, 1, 1, 'Parent', 1),
            (3, 1, 3, 'Emergency Contact', 0),
            (4, 2, 4, 'Guardian', 1)");
    }

    // ---- Auth helpers ----

    private function coach(int $userId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => $userId,
            'email' => "coach{$userId}@club.test",
            'roles' => [['role' => 'coach', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function clubAdmin(int $clubId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60,
            'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    private function parent(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 70,
            'email' => 'nia@example.com',
            'roles' => [['role' => 'parent', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);
    }

    private function superAdmin(): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 1,
            'email' => 'super@platform.test',
            'system_role' => 'super_admin',
            'roles' => [],
        ]);
    }

    // ---- Scope ----

    public function testPrimaryCoachMayDownloadTheirOwnTeam(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_OK,
            te_team_roster_staff_standing($this->pdo, $this->coach(50), 10)
        );
    }

    public function testAssistantCoachMayDownloadTheirOwnTeam(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_OK,
            te_team_roster_staff_standing($this->pdo, $this->coach(51), 11)
        );
    }

    /** Coach standing is per team, not per club. */
    public function testCoachMayNotDownloadAnotherTeamInTheirClub(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_DENIED,
            te_team_roster_staff_standing($this->pdo, $this->coach(50), 11)
        );
    }

    public function testClubAdminMayDownloadAnyTeamInTheirClub(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_OK,
            te_team_roster_staff_standing($this->pdo, $this->clubAdmin(100), 11)
        );
    }

    public function testClubAdminMayNotDownloadAnotherClubsTeam(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_DENIED,
            te_team_roster_staff_standing($this->pdo, $this->clubAdmin(100), 12)
        );
    }

    /**
     * The load-bearing one. A guardian of a player on team 10 passes the VIEW
     * predicate the team page uses, and must NOT pass this one: the crew flavour
     * is a contact list for the other families on the team.
     */
    public function testGuardianOnTheTeamMayNotDownloadIt(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_DENIED,
            te_team_roster_staff_standing($this->pdo, $this->parent(), 10)
        );
    }

    public function testSuperAdminMayDownload(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_OK,
            te_team_roster_staff_standing($this->pdo, $this->superAdmin(), 10)
        );
    }

    public function testMissingTeamIsNotFoundNotDenied(): void
    {
        $this->assertSame(
            TE_TEAM_ROSTER_NOT_FOUND,
            te_team_roster_staff_standing($this->pdo, $this->superAdmin(), 999)
        );
    }

    // ---- Sheet: athletes ----

    public function testAthleteSheetHeaderAndOrder(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');

        $this->assertSame(
            ['Jersey #', 'Last Name', 'First Name', 'Date of Birth', 'Age', 'Position', 'Status'],
            $sheet['headers']
        );
        $this->assertSame(
            ['Alvarez', 'Brooks', 'Chen'],
            array_column($sheet['rows'], 1)
        );
    }

    public function testInjuredPlayerIsIncludedWithTheirStatus(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');
        $bo = $sheet['rows'][1];

        $this->assertSame('Brooks', $bo[1]);
        $this->assertSame('injured', $bo[6]);
        $this->assertSame('', $bo[0], 'no jersey number should be blank, not 0');
    }

    public function testSoftDeletedAthleteIsExcluded(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');
        $this->assertNotContains('Ghost', array_column($sheet['rows'], 1));
    }

    public function testFormerMemberIsExcluded(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');
        $this->assertNotContains('Leaver', array_column($sheet['rows'], 1));
    }

    public function testCoachRowIsNotAPlayer(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');
        $this->assertCount(3, $sheet['rows']);
    }

    /** The DOB is emitted exactly as stored — no parsing, so no timezone shift. */
    public function testDateOfBirthIsVerbatim(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');
        $this->assertSame('2014-03-05', $sheet['rows'][0][3]);
    }

    public function testAgeCountsWholeYears(): void
    {
        // Birthday passed this year.
        $this->assertSame(12, te_roster_age('2014-03-05', '2026-08-25'));
        // Birthday not yet reached this year.
        $this->assertSame(11, te_roster_age('2014-11-20', '2026-08-25'));
        // Birthday is today.
        $this->assertSame(12, te_roster_age('2014-08-25', '2026-08-25'));
        // Day before the birthday.
        $this->assertSame(11, te_roster_age('2014-08-26', '2026-08-25'));
    }

    public function testAgeOfAnUnusableDobIsNullNotZero(): void
    {
        $this->assertNull(te_roster_age(null, '2026-08-25'));
        $this->assertNull(te_roster_age('', '2026-08-25'));
        $this->assertNull(te_roster_age('not a date', '2026-08-25'));
    }

    // ---- Sheet: crew ----

    public function testCrewColumnsWidenToTheLargestFamily(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'crew', '2026-08-25');

        // Ana has three crew, so three column groups exist for everyone.
        $this->assertSame(7 + (3 * 4), count($sheet['headers']));
        $this->assertSame('Crew 1 Name', $sheet['headers'][7]);
        $this->assertSame('Crew 3 Phone', $sheet['headers'][18]);
    }

    /**
     * Crew columns are filled in LINK order and nobody is promoted into Crew 1.
     *
     * There is no primary guardian (2026-09-02). Ana's link 1 is Omar and link 2
     * is Nia, and link 2 still carries a stale `is_primary = 1` — so Omar
     * occupying the first column group is what proves the flag is not read. A
     * download outlives the session it came from; a column silently reordered by
     * a flag nobody maintains is a worse answer than a stable one.
     */
    public function testCrewColumnsAreFilledInLinkOrderWithNobodyPromoted(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'crew', '2026-08-25');
        $ana = $sheet['rows'][0];

        $this->assertSame('Omar Alvarez', $ana[7]);
        $this->assertSame('Parent', $ana[8]);
        $this->assertSame('omar@example.com', $ana[9]);
        $this->assertSame('555-0102', $ana[10]);

        $this->assertSame('Nia Alvarez', $ana[11]);
        $this->assertSame('Pia Zamora', $ana[15]);
    }

    public function testAthleteWithNoCrewGetsBlankCellsNotMissingColumns(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'crew', '2026-08-25');
        $cy = $sheet['rows'][2];

        $this->assertSame('Chen', $cy[1]);
        $this->assertCount(count($sheet['headers']), $cy);
        $this->assertSame(['', '', '', ''], array_slice($cy, 7, 4));
    }

    public function testAthleteFlavourCarriesNoCrewColumns(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'athletes', '2026-08-25');

        $this->assertCount(7, $sheet['headers']);
        foreach ($sheet['rows'] as $row) {
            $this->assertCount(7, $row);
        }
        $this->assertStringNotContainsStringIgnoringCase(
            'example.com',
            implode('|', array_merge(...$sheet['rows']))
        );
    }

    // ---- Caps (1000 rows / 25 columns) ----

    public function testColumnCapLimitsCrewGroupsAndSaysSo(): void
    {
        // Give Ana a fifth crew member: 5 groups would be 27 columns.
        for ($i = 5; $i <= 8; $i++) {
            $this->pdo->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone)
                VALUES ({$i}, 'Extra{$i}', 'Alvarez', 'e{$i}@example.com', '555-020{$i}')");
            $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id, relationship, is_primary)
                VALUES (1{$i}, 1, {$i}, 'Other', 0)");
        }

        $sheet = te_roster_export_sheet($this->pdo, 10, 'crew', '2026-08-25');

        $this->assertSame(23, count($sheet['headers']));
        $this->assertLessThanOrEqual(TE_ROSTER_EXPORT_MAX_COLUMNS, count($sheet['headers']));
        $this->assertSame(3, $sheet['omitted_crew_columns']);

        $notice = te_roster_export_truncation_notice($sheet);
        $this->assertNotNull($notice);
        $this->assertStringContainsString('crew member', $notice);
        $this->assertStringContainsString('25 columns', $notice);
    }

    public function testRowCapLimitsPlayersAndSaysSo(): void
    {
        // 1002 players on team 11 (it already has one).
        for ($i = 1000; $i < 2001; $i++) {
            $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, date_of_birth, active_status, deleted_at)
                VALUES ({$i}, 'P{$i}', 'Player', '2014-01-01', 1, NULL)");
            $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, role, status)
                VALUES ({$i}, 11, {$i}, 'player', 'active')");
        }

        $sheet = te_roster_export_sheet($this->pdo, 11, 'athletes', '2026-08-25');

        $this->assertCount(TE_ROSTER_EXPORT_MAX_ROWS, $sheet['rows']);
        $this->assertSame(1002, $sheet['total_players']);
        $this->assertSame(2, $sheet['omitted_rows']);

        $notice = te_roster_export_truncation_notice($sheet);
        $this->assertStringContainsString('2 of 1002 players', $notice);
        $this->assertStringContainsString('1000 rows', $notice);
    }

    /** A file that fits must not claim it was cut. */
    public function testCompleteFileHasNoTruncationNotice(): void
    {
        $sheet = te_roster_export_sheet($this->pdo, 10, 'crew', '2026-08-25');

        $this->assertSame(0, $sheet['omitted_rows']);
        $this->assertSame(0, $sheet['omitted_crew_columns']);
        $this->assertNull(te_roster_export_truncation_notice($sheet));
    }

    // ---- Filename ----

    public function testFilenameIsSlugged(): void
    {
        $this->assertSame(
            'Sharks-U12-roster-2026-08-25.csv',
            te_roster_export_filename('Sharks U12', 'athletes', '2026-08-25')
        );
        $this->assertSame(
            'Sharks-U12-roster-and-crew-2026-08-25.csv',
            te_roster_export_filename('Sharks U12', 'crew', '2026-08-25')
        );
    }

    /**
     * A team name is club-supplied text going into a Content-Disposition header.
     * A newline in it must not be able to inject a response header.
     */
    public function testFilenameCannotInjectAHeader(): void
    {
        $name = te_roster_export_filename("Evil\r\nSet-Cookie: a=b", 'athletes', '2026-08-25');

        $this->assertStringNotContainsString("\r", $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertStringNotContainsString('"', $name);
    }

    public function testFilenameSurvivesATeamNameWithNoUsableCharacters(): void
    {
        $this->assertSame(
            'team-roster-2026-08-25.csv',
            te_roster_export_filename('///', 'athletes', '2026-08-25')
        );
    }
}
