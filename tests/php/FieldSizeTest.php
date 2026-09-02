<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;

require_once __DIR__ . '/../../lib/team_roster_scope.php';
require_once __DIR__ . '/../../lib/field_size.php';

/**
 * Field size (CKU R73, slice 6.3) — the age-group mapping, the format
 * tolerance, and what a team's picker is handed.
 *
 * legacy/fields-gateway.php emits headers and exits, so the endpoint itself
 * cannot be required. Everything it decides lives in two libs that CAN be:
 * te_fields_for_team() and te_team_view_standing(). These run the real
 * functions against SQLite.
 *
 * Fixture — club 100 has two venues and five fields of assorted sizes; club 200
 * has one field, which must never appear in club 100's answer.
 *   Team 10  'U12'   club 100  primary_coach_id 50
 *   Team 11  '9U'    club 100
 *   Team 12  NULL    club 100   (no age group on file)
 *   Team 20          club 200
 */
class FieldSizeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // SQLite has no information_schema, so the real probe answers false
        // here — correctly, but that is the DEGRADED path. Every test below
        // except testTheWholeFeatureDegradesWhenTheColumnIsAbsent is about the
        // migrated schema, so say so explicitly.
        te_field_size_probe_override(true);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                age_group TEXT, primary_coach_id INTEGER, deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT, leave_date TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                club_id INTEGER, user_id INTEGER, deleted_at TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER,
                relationship TEXT, is_primary INTEGER
            );
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT
            );
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER
            );
            CREATE TABLE venues (
                id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER
            );
            CREATE TABLE fields (
                id INTEGER PRIMARY KEY, venue_id INTEGER, name TEXT,
                field_type TEXT, surface_type TEXT, field_size TEXT, active INTEGER
            );
        ");

        $this->pdo->exec("INSERT INTO teams (id, name, club_id, age_group, primary_coach_id) VALUES
            (10, 'Sharks U12', 100, 'U12', 50),
            (11, 'Minnows',    100, '9U',  NULL),
            (12, 'Unlabelled', 100, NULL,  NULL),
            (20, 'Other Club', 200, 'U12', NULL)");

        $this->pdo->exec("INSERT INTO venues (id, name, club_id) VALUES
            (1, 'Ashford Park', 100),
            (2, 'Beech Fields', 100),
            (9, 'Far Away',     200)");

        $this->pdo->exec("INSERT INTO users (id, first_name, last_name, email) VALUES
            (50, 'Cora', 'Coach', 'coach50@club.test'),
            (60, 'Adam', 'Admin', 'admin@club.test')");

        $this->pdo->exec("INSERT INTO fields (id, venue_id, name, field_type, surface_type, field_size, active) VALUES
            (1, 1, 'Pitch 1', 'Soccer', 'Grass', '9v9',   1),
            (2, 1, 'Pitch 2', 'Soccer', 'Turf',  '11v11', 1),
            (3, 2, 'North',   'Soccer', 'Grass', NULL,    1),
            (4, 2, 'South',   'Soccer', 'Grass', '9v9',   1),
            (5, 2, 'Closed',  'Soccer', 'Grass', '9v9',   0),
            (6, 9, 'Distant', 'Soccer', 'Grass', '9v9',   1)");
    }

    protected function tearDown(): void
    {
        te_field_size_probe_override(null);
    }

    // ---- The mapping ----

    public function testTheAgeGroupToSizeMapping(): void
    {
        $expected = [
            'U6' => '4v4', 'U8' => '4v4',
            'U9' => '7v7', 'U10' => '7v7',
            'U11' => '9v9', 'U12' => '9v9',
            'U13' => '11v11', 'U14' => '11v11', 'U19' => '11v11',
        ];
        foreach ($expected as $group => $size) {
            $this->assertSame($size, te_field_size_for_age_group($group), "$group should be $size");
        }
    }

    /** The boundaries are where a one-off would land, so name them. */
    public function testTheBoundariesBetweenFormats(): void
    {
        $this->assertSame('4v4', te_field_size_for_age_group('U8'));
        $this->assertSame('7v7', te_field_size_for_age_group('U9'));
        $this->assertSame('7v7', te_field_size_for_age_group('U10'));
        $this->assertSame('9v9', te_field_size_for_age_group('U11'));
        $this->assertSame('9v9', te_field_size_for_age_group('U12'));
        $this->assertSame('11v11', te_field_size_for_age_group('U13'));
    }

    /** `teams.age_group` is free text, so every live spelling must resolve. */
    public function testFormatTolerance(): void
    {
        foreach (['U12', 'u12', 'U-12', 'U 12', 'Under12', '12U', '12u', '12-U'] as $spelling) {
            $this->assertSame(12, te_age_group_number($spelling), "could not read `$spelling`");
            $this->assertSame('9v9', te_field_size_for_age_group($spelling), "wrong size for `$spelling`");
        }
    }

    /**
     * The spelling is parsed by te_normalize_age_group() in lib/age_rule.php and
     * nowhere else, so this file inherits its refusals — including the
     * deliberate one for an ambiguous label. Pinned because a second parser here
     * is exactly the copied-age-logic mistake the codebase has already made four
     * times.
     */
    public function testTheSpellingParserIsTheSharedOne(): void
    {
        $this->assertNull(te_age_group_number('U10/U11'), 'an ambiguous label must not resolve to one half');
        $this->assertNull(te_age_group_number('Open'));
        $this->assertNull(te_age_group_number('U12 Boys'), 'age_rule.php refuses a compound label, and so must this');
        $this->assertNull(te_age_group_number('12'), 'a bare number is not a U-group spelling');

        $src = file_get_contents(__DIR__ . '/../../lib/field_size.php');
        $this->assertStringContainsString('te_normalize_age_group(', $src);
        $this->assertStringNotContainsString('preg_match', $src,
            'no second U-group parser may live in field_size.php');
    }

    public function testTheCanonicalLabel(): void
    {
        $this->assertSame('U12', te_age_group_label('12U'));
        $this->assertSame('U9', te_age_group_label('U-9'));
        $this->assertNull(te_age_group_label(''));
    }

    /**
     * A missing or unreadable age group answers null — "no opinion" — never a
     * size. A team with nothing on file must see its club's fields exactly as
     * it does today.
     */
    public function testAnUnreadableAgeGroupHasNoOpinion(): void
    {
        foreach ([null, '', '   ', 'Recreational', 'Adult', 'Boys'] as $group) {
            $this->assertNull(te_field_size_for_age_group($group), 'expected null for ' . var_export($group, true));
        }
    }

    /**
     * A birth year is not a U-group. '2012 Boys' must not silently map a whole
     * club's teams onto 11v11.
     */
    public function testABirthYearIsNotAnAgeGroup(): void
    {
        $this->assertNull(te_age_group_number('2012'));
        $this->assertNull(te_age_group_number('2012 Boys'));
        $this->assertNull(te_field_size_for_age_group('2014'));
        $this->assertNull(te_field_size_for_age_group('U2012'), 'the 4-25 clamp, not the spelling, refuses this one');
    }

    // ---- Submitted values ----

    /**
     * The facility form submits '' for a field nobody has sized. Writing that
     * raw raises 23514 against the CHECK constraint and rolls back the whole
     * facility save — the same failure jersey_size had.
     */
    public function testAnEmptySubmissionNormalisesToNull(): void
    {
        $this->assertNull(te_normalize_field_size(''));
        $this->assertNull(te_normalize_field_size(null));
        $this->assertNull(te_normalize_field_size('   '));
    }

    public function testASubmittedSizeIsTolerantButBounded(): void
    {
        $this->assertSame('7v7', te_normalize_field_size('7v7'));
        $this->assertSame('7v7', te_normalize_field_size('7V7'));
        $this->assertSame('7v7', te_normalize_field_size(' 7 v 7 '));
        $this->assertSame('11v11', te_normalize_field_size('11vs11'));
        // Not a value the CHECK constraint accepts, so it must not be stored.
        $this->assertNull(te_normalize_field_size('5v5'));
        $this->assertNull(te_normalize_field_size('full size'));
    }

    // ---- for-team ----

    public function testAMatchingFieldIsFlaggedTrueAndAnUnsizedOneNull(): void
    {
        $result = te_fields_for_team($this->pdo, 10);

        $this->assertSame('9v9', $result['recommended_size']);
        $this->assertSame('U12', $result['age_group_label']);

        $byId = [];
        foreach ($result['fields'] as $f) { $byId[$f['id']] = $f; }

        $this->assertTrue($byId[1]['size_match'], 'a 9v9 field fits a U12 team');
        $this->assertTrue($byId[4]['size_match']);
        $this->assertNull($byId[3]['size_match'], 'an unsized field has no opinion, it is not a mismatch');
        $this->assertFalse($byId[2]['size_match'], 'an 11v11 field does not fit a U12 team');
    }

    /**
     * The unsized field is the whole reason nothing is filtered: on the day 088
     * is applied, EVERY row is NULL. A picker that hid them would show an empty
     * list to every club.
     */
    public function testAnUnsizedFieldIsNeverHidden(): void
    {
        $ids = array_column(te_fields_for_team($this->pdo, 10)['fields'], 'id');
        $this->assertContains(3, $ids);
    }

    /** Mismatches are returned too — the UI warns, it does not block. */
    public function testAMismatchedFieldIsStillOffered(): void
    {
        $ids = array_column(te_fields_for_team($this->pdo, 10)['fields'], 'id');
        $this->assertContains(2, $ids, 'an 11v11 field must still be selectable for a U12 team');
    }

    public function testFitsComeFirstThenUnsizedThenTheWrongSizes(): void
    {
        $matches = array_column(te_fields_for_team($this->pdo, 10)['fields'], 'size_match');
        $this->assertSame([true, true, null, false], $matches);
    }

    public function testAnotherClubsFieldsAreExcluded(): void
    {
        $ids = array_column(te_fields_for_team($this->pdo, 10)['fields'], 'id');
        $this->assertNotContains(6, $ids, "club 200's field must not reach club 100's team");
    }

    public function testAnInactiveFieldIsExcluded(): void
    {
        $ids = array_column(te_fields_for_team($this->pdo, 10)['fields'], 'id');
        $this->assertNotContains(5, $ids);
    }

    /** '9U' is U9, which plays 7v7 — and this club has no 7v7 pitch. */
    public function testATrailingUFormatResolvesThroughTheWholeQuery(): void
    {
        $result = te_fields_for_team($this->pdo, 11);
        $this->assertSame('7v7', $result['recommended_size']);
        $this->assertSame([], array_filter(array_column($result['fields'], 'size_match')));
    }

    /**
     * A team with no age group gets every field with no verdict on any of them.
     * "I cannot tell" must not render as "all wrong".
     */
    public function testATeamWithNoAgeGroupGetsNoVerdicts(): void
    {
        $result = te_fields_for_team($this->pdo, 12);
        $this->assertNull($result['recommended_size']);
        $this->assertCount(4, $result['fields']);
        foreach ($result['fields'] as $f) {
            $this->assertNull($f['size_match']);
        }
    }

    public function testAMissingTeamAnswersAnEmptyListRatherThanThrowing(): void
    {
        $result = te_fields_for_team($this->pdo, 999);
        $this->assertSame([], $result['fields']);
    }

    // ---- Column-absent tolerance ----

    /**
     * `main` is shared and deploys are by push, so this code runs in production
     * before migration 088 does. Everything must still answer — with no sizes
     * and no verdicts — rather than 42703 the facility and scheduling screens.
     */
    public function testTheWholeFeatureDegradesWhenTheColumnIsAbsent(): void
    {
        te_field_size_probe_override(false);

        $result = te_fields_for_team($this->pdo, 10);

        $this->assertFalse($result['sizing_available']);
        $this->assertNull($result['recommended_size'], 'no column means no recommendation to make');
        $this->assertCount(4, $result['fields'], 'the club still sees all of its fields');
        foreach ($result['fields'] as $f) {
            $this->assertNull($f['field_size']);
            $this->assertNull($f['size_match']);
        }
    }

    /**
     * The probe is what makes that safe, and it must answer false rather than
     * throw when information_schema is not there at all (SQLite).
     */
    public function testTheProbeAnswersFalseRatherThanThrowing(): void
    {
        te_field_size_probe_override(null);
        $this->assertFalse(te_field_size_column_present($this->pdo));
    }

    // ---- Access ----

    private function coachOf(int $userId): AuthMiddleware
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

    public function testTheClubsOwnAdminMayAskAboutItsTeam(): void
    {
        $this->assertSame(TE_TEAM_ROSTER_OK, te_team_view_standing($this->pdo, $this->clubAdmin(100), 10));
    }

    public function testAnotherClubsAdminMayNot(): void
    {
        $this->assertSame(TE_TEAM_ROSTER_DENIED, te_team_view_standing($this->pdo, $this->clubAdmin(200), 10));
    }

    public function testTheTeamsCoachMay(): void
    {
        $this->assertSame(TE_TEAM_ROSTER_OK, te_team_view_standing($this->pdo, $this->coachOf(50), 10));
    }

    public function testAMissingTeamIsNotFoundRatherThanDenied(): void
    {
        $this->assertSame(TE_TEAM_ROSTER_NOT_FOUND, te_team_view_standing($this->pdo, $this->clubAdmin(100), 999));
    }

    /**
     * The endpoint must gate on the VIEW predicate, not the staff one — a
     * parent on the team legitimately sees which pitch their child plays on.
     * Pinned by parsing the gateway, because the bug is never in the predicate,
     * it is in which one gets called.
     */
    public function testTheGatewayGatesOnTheViewPredicate(): void
    {
        $src = file_get_contents(__DIR__ . '/../../legacy/fields-gateway.php');
        $this->assertStringContainsString('te_team_view_standing(', $src);
        $this->assertStringNotContainsString('te_team_roster_staff_standing(', $src);
    }

    /**
     * Both writers must normalise before binding, or a blank select from the
     * facility form rolls back the whole save against the CHECK constraint.
     */
    public function testTheFacilityWriterNormalisesTheSubmittedSize(): void
    {
        $src = file_get_contents(__DIR__ . '/../../legacy/venues-gateway.php');
        $this->assertSame(2, substr_count($src, 'te_normalize_field_size('),
            'both the create and the update branch of venues-gateway write fields');
        $this->assertSame(2, substr_count($src, 'te_field_size_available('),
            'and both must probe before naming the column in SQL');
    }
}
