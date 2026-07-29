<?php

use PHPUnit\Framework\TestCase;

// Load the gateway for its query helpers only — no dispatch, no Neon connect.
define('TE_RECIPIENT_SEARCH_LIB_ONLY', true);
require_once __DIR__ . '/../../api/recipient-search-gateway.php';

/**
 * The club-wide compose groups: "All" and "All Crew".
 *
 * The frontend has always had the group_type:'special' branch; the backend
 * never returned such a group and would 400 on resolve. These cover the backend
 * half.
 *
 * The important design decision under test: membership is club-scoped, NOT
 * "every team's roster" — so an athlete who has been registered but not yet
 * rostered, and their crew, are still reached by a club-wide announcement.
 */
class SpecialRecipientGroupsTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 32;
    private const OTHER_CLUB = 51;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT, club_id INTEGER, deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT, sms_opt_out INTEGER DEFAULT 0);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE email_suppressions (id INTEGER PRIMARY KEY, club_profile_id INTEGER,
                email TEXT, phone TEXT, channel TEXT, reason TEXT, scope TEXT, team_id INTEGER);
        ");
        $this->seed();
    }

    private function seed(): void
    {
        $p = $this->pdo;

        $p->exec("INSERT INTO teams (id, name, club_id, deleted_at) VALUES (1, 'Eagles U14', 32, NULL)");

        // Athlete 1: rostered. Athlete 2: registered but NOT on any team — the
        // case a roster-based 'All' would silently miss.
        $p->exec("INSERT INTO athletes (id, first_name, last_name, email, phone, club_id, deleted_at, active_status)
            VALUES (1, 'Rachel', 'Jones', 'rachel@example.com', '555-0101', 32, NULL, 1),
                   (2, 'Unrostered', 'Newkid', 'newkid@example.com', '555-0102', 32, NULL, 1),
                   (3, 'Deleted', 'Gone', 'gone@example.com', NULL, 32, '2026-01-01', 1),
                   (4, 'Inactive', 'Off', 'off@example.com', NULL, 32, NULL, 0),
                   (9, 'Other', 'Club', 'otherclub@example.com', NULL, 51, NULL, 1)");
        $p->exec("INSERT INTO team_members (id, team_id, athlete_id, role, status) VALUES (1, 1, 1, 'player', 'active')");

        // Guardians 1 and 2 are a household sharing one address (John & Jane).
        // Guardian 3 has no email. Guardian 4 is suppressed. Guardian 9 is another club.
        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1, 'John',  'Jones', 'thejones@example.com', '555-0201'),
            (2, 'Jane',  'Jones', 'THEJONES@example.com', '555-0202'),
            (3, 'NoMail','Nunez', NULL,                   '555-0203'),
            (4, 'Supp',  'Ressed','bounced@example.com',  '555-0204'),
            (9, 'Other', 'Guard', 'otherguard@example.com','555-0209')");
        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 1), (2, 1, 2), (3, 2, 3), (4, 2, 4), (9, 9, 9)");

        // Staff: a coach and a club admin, plus a revoked coach and another club's.
        $p->exec("INSERT INTO users (id, first_name, last_name, email, phone) VALUES
            (10, 'Coach', 'Lee',    'coach@example.com', '555-0301'),
            (11, 'Admin', 'Maggie', 'admin@example.com', '555-0302'),
            (12, 'Revoked','Coach', 'revoked@example.com','555-0303'),
            (19, 'Other', 'Coach',  'othercoach@example.com', NULL)");
        $p->exec("INSERT INTO user_club_access (id, user_id, club_profile_id, role, active) VALUES
            (1, 10, 32, 'coach', 1),
            (2, 11, 32, 'club_admin', 1),
            (3, 12, 32, 'coach', 0),
            (9, 19, 51, 'coach', 1)");

        $p->exec("INSERT INTO email_suppressions (id, club_profile_id, email, phone, channel, reason)
            VALUES (1, 32, 'bounced@example.com', NULL, 'email', 'bounce')");
    }

    /** resolveSpecialGroup echoes JSON like the other gateway actions. */
    private function resolve(string $group, array $excludeLookup = [], string $channel = 'email'): array
    {
        ob_start();
        resolveSpecialGroup($this->pdo, self::CLUB, $group, $channel, $excludeLookup);
        return json_decode(ob_get_clean(), true);
    }

    private function emailsOf(array $res): array
    {
        $e = array_map(fn($r) => strtolower($r['email']), $res['recipients']);
        sort($e);
        return $e;
    }

    public function testGroupListOffersBothGroupsWithDedupedCounts(): void
    {
        $groups = getSpecialGroups($this->pdo, self::CLUB);
        $this->assertCount(2, $groups);
        $this->assertSame(['all', 'all_crew'], array_column($groups, 'id'));
        $this->assertSame(['All', 'All Crew'], array_column($groups, 'name'));
        $this->assertSame(['special', 'special'], array_column($groups, 'group_type'));

        // Crew: thejones (John+Jane share one address) + bounced. NoMail has none.
        $byId = array_column($groups, null, 'id');
        $this->assertSame(2, $byId['all_crew']['recipient_count']);

        // All: the 2 crew addresses + 2 active athletes + coach + club_admin.
        $this->assertSame(6, $byId['all']['recipient_count']);
    }

    public function testAllCrewIsGuardiansOnlyAndCollapsesTheSharedHousehold(): void
    {
        $res = $this->resolve('all_crew');

        $this->assertSame(['bounced@example.com', 'thejones@example.com'], $this->emailsOf($res));
        $this->assertSame(['guardian'], array_unique(array_column($res['recipients'], 'type')));

        // John and Jane share one address: one recipient, not two.
        $this->assertSame(2, $res['total']);
        // Guardian 3 has no email and is reported, not silently dropped.
        $this->assertSame(1, $res['missing_contact_count']);
    }

    public function testAllIncludesAthletesCrewAndStaff(): void
    {
        $res = $this->resolve('all');

        $this->assertSame([
            'admin@example.com',
            'bounced@example.com',
            'coach@example.com',
            'newkid@example.com',
            'rachel@example.com',
            'thejones@example.com',
        ], $this->emailsOf($res));

        $types = array_count_values(array_column($res['recipients'], 'type'));
        $this->assertSame(2, $types['guardian']);
        $this->assertSame(2, $types['athlete']);
        $this->assertSame(2, $types['coach']);
    }

    /** The reason membership is club-scoped rather than roster-scoped. */
    public function testUnrosteredAthleteIsStillReached(): void
    {
        $this->assertContains('newkid@example.com', $this->emailsOf($this->resolve('all')));
    }

    public function testDeletedInactiveRevokedAndOtherClubsAreExcluded(): void
    {
        $emails = $this->emailsOf($this->resolve('all'));

        $this->assertNotContains('gone@example.com', $emails, 'soft-deleted athlete');
        $this->assertNotContains('off@example.com', $emails, 'inactive athlete');
        $this->assertNotContains('revoked@example.com', $emails, 'revoked club access');
        $this->assertNotContains('otherclub@example.com', $emails, 'another club\'s athlete');
        $this->assertNotContains('otherguard@example.com', $emails, 'another club\'s guardian');
        $this->assertNotContains('othercoach@example.com', $emails, 'another club\'s coach');
    }

    /** Suppressed contacts are returned and flagged so the UI can warn — never silently dropped. */
    public function testSuppressedRecipientIsFlaggedNotRemoved(): void
    {
        $res = $this->resolve('all_crew');
        $byEmail = array_column($res['recipients'], null, 'email');

        $this->assertArrayHasKey('bounced@example.com', $byEmail);
        $this->assertTrue($byEmail['bounced@example.com']['suppressed']);
        $this->assertSame('bounce', $byEmail['bounced@example.com']['suppression_reason']);
        $this->assertSame(1, $res['suppressed_count']);

        $this->assertFalse($byEmail['thejones@example.com']['suppressed']);
    }

    public function testExcludedRecipientsAreDropped(): void
    {
        $res = $this->resolve('all_crew', ['guardian:4' => true]);
        $this->assertSame(['thejones@example.com'], $this->emailsOf($res));
    }
}
