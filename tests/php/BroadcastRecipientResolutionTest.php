<?php

use PHPUnit\Framework\TestCase;

// Load the gateway for its resolution helpers only — no dispatch, no Neon connect.
define('TE_COMMUNICATIONS_LIB_ONLY', true);
require_once __DIR__ . '/../../api/communications-gateway.php';

/**
 * resolveBroadcastRecipients — who a broadcast actually reaches.
 *
 * Fixture columns mirror tests/fixtures/production-schema.json. That is not a
 * style preference: MergeFieldServiceTest invented an `events` table production
 * did not have, and the suite stayed green for months while every {{event_*}} tag
 * resolved to nothing in prod. A fixture that does not mirror the snapshot is
 * worse than no fixture.
 */
class BroadcastRecipientResolutionTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 32;
    private const TEAM = 1;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT, club_id INTEGER, deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, personal_email TEXT, mobile_phone TEXT,
                sms_opt_out INTEGER DEFAULT 0, receive_invites INTEGER DEFAULT 1);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
        ");
        $this->seed();
    }

    private function seed(): void
    {
        $p = $this->pdo;

        $p->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at)
            VALUES (1, 'Eagles U14', 32, 10, NULL), (2, 'Hawks U12', 32, NULL, NULL)");

        // 1 rostered. 2 registered but on no roster — reachable club-wide, invisible
        // to a team send, which is the whole reason club-wide exists.
        // 3 inactive, 4 soft-deleted, 5 rostered but no phone, 9 different club.
        $p->exec("INSERT INTO athletes (id, first_name, last_name, email, phone, club_id, deleted_at, active_status) VALUES
            (1, 'Rachel',     'Jones',  'rachel@example.com',  '360-555-0101', 32, NULL,         1),
            (2, 'Unrostered', 'Newkid', 'newkid@example.com',  '360-555-0102', 32, NULL,         1),
            (3, 'Inactive',   'Off',    'off@example.com',     '360-555-0103', 32, NULL,         0),
            (4, 'Deleted',    'Gone',   'gone@example.com',    '360-555-0104', 32, '2026-01-01', 1),
            (5, 'NoPhone',    'Nolan',  'nophone@example.com', NULL,       32, NULL,         1),
            (9, 'Other',      'Club',   'otherclub@example.com','360-555-0109', 51, NULL,        1)");

        $p->exec("INSERT INTO team_members (id, team_id, athlete_id, user_id, role, status) VALUES
            (1, 1, 1, NULL, 'player', 'active'),
            (2, 1, 3, NULL, 'player', 'active'),
            (3, 1, 4, NULL, 'player', 'active'),
            (4, 1, 5, NULL, 'player', 'active'),
            (5, 1, NULL, 11, 'assistant_coach', 'active')");

        // Guardian 1 is linked to the SAME athlete twice-over via two teams below,
        // and guardian 2 shares a household. Guardian 3 has no phone. Guardian 4 is
        // on the unrostered athlete. Guardian 5 has receive_invites = 0 (email gate).
        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, personal_email, mobile_phone, sms_opt_out, receive_invites) VALUES
            (1, 'John',    'Jones', 'thejones@example.com', NULL,                 '360-555-0201', 0, 1),
            (2, 'Jane',    'Jones', 'thejones@example.com', 'jane.alt@example.com','360-555-0202', 0, 1),
            (3, 'NoPhone', 'Nunez', 'nunez@example.com',    NULL,                 NULL,       0, 1),
            (4, 'Unros',   'Parent','unros@example.com',    NULL,                 '360-555-0204', 0, 1),
            (5, 'NoInvite','Nix',   'noinvite@example.com', NULL,                 '360-555-0205', 0, 0),
            (9, 'Other',   'Guard', 'otherguard@example.com',NULL,                '360-555-0209', 0, 1)");

        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 1), (2, 1, 2), (3, 1, 3), (4, 2, 4), (5, 1, 5), (9, 9, 9)");

        // 10 = primary coach of team 1. 11 = assistant on team 1. 12 = club admin,
        // reachable club-wide but on no roster. 13 = revoked. 19 = other club.
        $p->exec("INSERT INTO users (id, first_name, last_name, email, phone) VALUES
            (10, 'Coach',   'Lee',    'coach@example.com',    '360-555-0301'),
            (11, 'Asst',    'Ramirez','asst@example.com',     '360-555-0302'),
            (12, 'Admin',   'Maggie', 'admin@example.com',    '360-555-0303'),
            (13, 'Revoked', 'Ray',    'revoked@example.com',  '360-555-0304'),
            (19, 'Other',   'Coach',  'othercoach@example.com','360-555-0319')");

        $p->exec("INSERT INTO user_club_access (id, user_id, club_profile_id, role, active) VALUES
            (1, 10, 32, 'coach', 1),
            (2, 11, 32, 'coach', 1),
            (3, 12, 32, 'club_admin', 1),
            (4, 13, 32, 'coach', 0),
            (9, 19, 51, 'coach', 1)");
    }

    private function resolve(
        array $types,
        string $channel = 'sms',
        array $excludeIds = [],
        string $scope = 'teams',
        array $teamIds = [self::TEAM]
    ): array {
        return resolveBroadcastRecipients(
            $this->pdo,
            $teamIds,
            $types,
            $excludeIds,
            $channel,
            $scope,
            self::CLUB
        );
    }

    private function namesOf(array $recipients): array
    {
        $names = array_map(fn($r) => $r['name'], $recipients);
        sort($names);
        return $names;
    }

    // ── A1 ────────────────────────────────────────────────────────────────────
    public function testResolvesAllThreeTypesForATeam(): void
    {
        $r = $this->resolve(['athlete', 'guardian', 'coach']);
        $types = array_unique(array_map(fn($x) => $x['type'], $r));
        sort($types);

        $this->assertSame(['athlete', 'coach', 'guardian'], $types);
        foreach ($r as $person) {
            $this->assertNotEmpty($person['phone'], "{$person['name']} resolved without a phone");
        }
    }

    // ── A2 ────────────────────────────────────────────────────────────────────
    /**
     * The plural forms are what recipient-search's resolve-group takes. Passing
     * them here is silent: HTTP 200, total_recipients 0, nothing sent. This test
     * exists so that failure mode can never ship unnoticed.
     */
    public function testPluralRecipientTypesResolveNobody(): void
    {
        $this->assertSame([], $this->resolve(['athletes', 'guardians', 'coaches']));
        $this->assertNotEmpty($this->resolve(['athlete', 'guardian', 'coach']));
    }

    // ── A3 / A4 ───────────────────────────────────────────────────────────────
    public function testExcludesInactiveSoftDeletedAndContactlessAthletes(): void
    {
        $names = $this->namesOf($this->resolve(['athlete']));

        $this->assertContains('Rachel Jones', $names);
        $this->assertNotContains('Inactive Off', $names, 'active_status = 0 must not receive');
        $this->assertNotContains('NoPhone Nolan', $names, 'no phone must not resolve for SMS');
        // Team path has no deleted_at filter of its own; the roster join is what
        // keeps a soft-deleted athlete out only if their membership went too. Assert
        // the club path (which does filter) rather than claiming the team path does.
        $clubNames = $this->namesOf($this->resolve(['athlete'], 'sms', [], 'club', []));
        $this->assertNotContains('Deleted Gone', $clubNames, 'deleted_at must not receive club-wide');
    }

    public function testOtherClubsAreNeverReached(): void
    {
        $names = $this->namesOf($this->resolve(['athlete', 'guardian', 'coach'], 'sms', [], 'club', []));

        $this->assertNotContains('Other Club', $names);
        $this->assertNotContains('Other Guard', $names);
        $this->assertNotContains('Other Coach', $names);
    }

    // ── A5 ────────────────────────────────────────────────────────────────────
    public function testGuardianOnTwoRosteredAthletesAppearsOnce(): void
    {
        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, email, phone, club_id, deleted_at, active_status)
            VALUES (6, 'Second', 'Jones', 'second@example.com', '360-555-0106', 32, NULL, 1)");
        $this->pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, user_id, role, status)
            VALUES (6, 1, 6, NULL, 'player', 'active')");
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (6, 6, 1)");

        $johns = array_filter($this->resolve(['guardian']), fn($r) => $r['name'] === 'John Jones');

        $this->assertCount(1, $johns, 'a guardian of two athletes must get ONE message');
    }

    public function testDedupeIsByNormalizedPhoneNotRawString(): void
    {
        // Same human, entered two ways. The raw-string dedupe this replaced sent twice.
        $this->pdo->exec("UPDATE guardians SET mobile_phone = '(555) 020-1000' WHERE id = 1");
        $this->pdo->exec("UPDATE guardians SET mobile_phone = '5550201000'     WHERE id = 2");

        $r = $this->resolve(['guardian']);
        $phones = array_map(fn($x) => te_normalize_sms_phone($x['phone']), $r);

        $this->assertSame(count($phones), count(array_unique($phones)));
        $this->assertCount(1, array_filter($phones, fn($p) => $p === '+15550201000'));
    }

    // ── A6 ────────────────────────────────────────────────────────────────────
    public function testTypedExclusionRemovesOnlyThatPerson(): void
    {
        $before = $this->namesOf($this->resolve(['guardian']));
        $this->assertContains('John Jones', $before);

        $after = $this->namesOf($this->resolve(['guardian'], 'sms', ['guardian:1']));
        $this->assertNotContains('John Jones', $after);
        $this->assertContains('Jane Jones', $after);
    }

    /**
     * Athlete 1 and guardian 1 are different people who share an id. A typed
     * exclusion must not confuse them — the untyped form (still accepted for
     * backward compatibility) does, which is why the UI sends typed keys.
     */
    public function testTypedExclusionDoesNotCollideAcrossTypes(): void
    {
        $r = $this->resolve(['athlete', 'guardian'], 'sms', ['guardian:1']);
        $names = $this->namesOf($r);

        $this->assertNotContains('John Jones', $names, 'guardian 1 excluded');
        $this->assertContains('Rachel Jones', $names, 'athlete 1 shares the id but not the exclusion');
    }

    // ── A7 ────────────────────────────────────────────────────────────────────
    public function testEmailAndSmsUseDifferentColumnsAndPersonalEmailRules(): void
    {
        $emails = array_map(fn($r) => $r['email'], $this->resolve(['guardian'], 'email'));

        // Household shares thejones@example.com, so it collapses to one send — but
        // Jane's distinct personal_email is a second reachable address.
        $this->assertContains('thejones@example.com', $emails);
        $this->assertContains('jane.alt@example.com', $emails);

        // receive_invites = 0 gates email only.
        $this->assertNotContains('noinvite@example.com', $emails);
        $smsNames = $this->namesOf($this->resolve(['guardian']));
        $this->assertContains('NoInvite Nix', $smsNames, 'receive_invites must not gate SMS');

        // No phone → absent from SMS, present in email.
        $this->assertContains('nunez@example.com', $emails);
        $this->assertNotContains('NoPhone Nunez', $smsNames);
    }

    // ── Club-wide scope ───────────────────────────────────────────────────────
    public function testClubWideReachesTheUnrosteredAndTeamSendDoesNot(): void
    {
        $teamNames = $this->namesOf($this->resolve(['athlete'], 'sms', [], 'teams'));
        $clubNames = $this->namesOf($this->resolve(['athlete'], 'sms', [], 'club', []));

        $this->assertNotContains('Unrostered Newkid', $teamNames);
        $this->assertContains('Unrostered Newkid', $clubNames, 'the entire point of club-wide');
        $this->assertContains('Rachel Jones', $clubNames);
    }

    public function testClubWideCoachesComeFromUserClubAccessAndHonorRevocation(): void
    {
        $names = $this->namesOf($this->resolve(['coach'], 'sms', [], 'club', []));

        $this->assertContains('Coach Lee', $names);
        $this->assertContains('Admin Maggie', $names, 'club_admin is reachable club-wide though on no roster');
        $this->assertNotContains('Revoked Ray', $names, 'user_club_access.active = 0');
    }

    public function testClubWideRespectsRecipientTypeSelection(): void
    {
        $athletesOnly = $this->resolve(['athlete'], 'sms', [], 'club', []);
        $types = array_unique(array_map(fn($r) => $r['type'], $athletesOnly));

        // The 'all' / 'all_crew' special-group ids cannot express this, which is why
        // club-wide lives in resolveBroadcastRecipients instead of routing through them.
        $this->assertSame(['athlete'], array_values($types));
    }

    public function testClubWideRequiresAClubId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        resolveBroadcastRecipients($this->pdo, [], ['athlete'], [], 'sms', 'club', null);
    }

    public function testTeamScopeWithNoTeamsResolvesNobodyRatherThanEveryone(): void
    {
        // A malformed request must not silently become a club-wide blast.
        $this->assertSame([], $this->resolve(['athlete', 'guardian', 'coach'], 'sms', [], 'teams', []));
    }
}
