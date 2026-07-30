<?php

use PHPUnit\Framework\TestCase;

// Load the gateway for its query helpers only — no dispatch, no Neon connect.
define('TE_RECIPIENT_SEARCH_LIB_ONLY', true);
require_once __DIR__ . '/../../api/recipient-search-gateway.php';

/**
 * The club-wide compose groups: "All", "All Crew", and the two parent-portal
 * follow-up groups.
 *
 * The frontend has always had the group_type:'special' branch; the backend
 * never returned such a group and would 400 on resolve. These cover the backend
 * half.
 *
 * Two design decisions under test:
 *  - Membership is club-scoped, NOT "every team's roster", so an athlete who has
 *    registered but is not yet rostered — and their crew — are still reached.
 *  - The portal groups treat an EXPIRED invite as sent. It lapsed unused, which
 *    is precisely who a follow-up is for.
 */
class SpecialRecipientGroupsTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 32;

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
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT, password_hash TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE magic_link_tokens (id INTEGER PRIMARY KEY, email TEXT,
                used_at TEXT, expires_at TEXT, created_at TEXT);
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

        // Athlete 1 rostered; athlete 2 registered but on no team — the case a
        // roster-based 'All' would silently miss.
        $p->exec("INSERT INTO athletes (id, first_name, last_name, email, phone, club_id, deleted_at, active_status)
            VALUES (1, 'Rachel', 'Jones', 'rachel@example.com', '555-0101', 32, NULL, 1),
                   (2, 'Unrostered', 'Newkid', 'newkid@example.com', '555-0102', 32, NULL, 1),
                   (3, 'Deleted', 'Gone', 'gone@example.com', NULL, 32, '2026-01-01', 1),
                   (4, 'Inactive', 'Off', 'off@example.com', NULL, 32, NULL, 0),
                   (9, 'Other', 'Club', 'otherclub@example.com', NULL, 51, NULL, 1)");
        $p->exec("INSERT INTO team_members (id, team_id, athlete_id, role, status) VALUES (1, 1, 1, 'player', 'active')");

        // Crew, one per portal state.
        //   1+2 household sharing one address, never invited
        //   3   no email at all
        //   4   suppressed, never invited
        //   5   already set up (has a password)
        //   6   invited, token still live, never set up
        //   7   invited, token EXPIRED, never set up
        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1, 'John',    'Jones',  'thejones@example.com', '555-0201'),
            (2, 'Jane',    'Jones',  'THEJONES@example.com', '555-0202'),
            (3, 'NoMail',  'Nunez',  NULL,                   '555-0203'),
            (4, 'Supp',    'Ressed', 'bounced@example.com',  '555-0204'),
            (5, 'Setup',   'Sam',    'setup@example.com',    '555-0205'),
            (6, 'Invited', 'Ivy',    'invited@example.com',  '555-0206'),
            (7, 'Expired', 'Ed',     'expired@example.com',  '555-0207'),
            (9, 'Other',   'Guard',  'otherguard@example.com','555-0209')");
        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1, 1, 1), (2, 1, 2), (3, 2, 3), (4, 2, 4),
            (5, 1, 5), (6, 1, 6), (7, 2, 7), (9, 9, 9)");

        // Staff. Parent logins 20-22 have no club access, so they are not staff.
        // 21 and 22 are what ParentInvite creates: a users row with NO password
        // yet — that must not read as "set up".
        $p->exec("INSERT INTO users (id, first_name, last_name, email, phone, password_hash) VALUES
            (10, 'Coach',  'Lee',    'coach@example.com',   '555-0301', 'hash'),
            (11, 'Admin',  'Maggie', 'admin@example.com',   '555-0302', 'hash'),
            (12, 'Revoked','Coach',  'revoked@example.com', '555-0303', 'hash'),
            (19, 'Other',  'Coach',  'othercoach@example.com', NULL,    'hash'),
            (20, 'Setup',  'Sam',    'setup@example.com',   NULL, '\$2y\$10\$realhash'),
            (21, 'Invited','Ivy',    'invited@example.com', NULL, NULL),
            (22, 'Expired','Ed',     'expired@example.com', NULL, '')");
        $p->exec("INSERT INTO user_club_access (id, user_id, club_profile_id, role, active) VALUES
            (1, 10, 32, 'coach', 1),
            (2, 11, 32, 'club_admin', 1),
            (3, 12, 32, 'coach', 0),
            (9, 19, 51, 'coach', 1)");

        $p->exec("INSERT INTO magic_link_tokens (id, email, used_at, expires_at, created_at) VALUES
            (1, 'invited@example.com:parent_invite', NULL, '2099-01-01', '2026-07-01'),
            (2, 'expired@example.com:parent_invite', NULL, '2026-01-01', '2025-12-01')");

        $p->exec("INSERT INTO email_suppressions (id, club_profile_id, email, phone, channel, reason)
            VALUES (1, 32, 'bounced@example.com', NULL, 'email', 'bounce')");
    }

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

    private function counts(): array
    {
        return array_column(getSpecialGroups($this->pdo, self::CLUB), 'recipient_count', 'id');
    }

    public function testGroupListOffersAllFourGroups(): void
    {
        $groups = getSpecialGroups($this->pdo, self::CLUB);

        $this->assertSame(
            ['all', 'all_crew', 'invite_never_sent', 'invite_sent_not_setup'],
            array_column($groups, 'id')
        );
        $this->assertSame(
            ['All', 'All Crew', 'Invite Never Sent', 'Invite Sent, Not Set Up'],
            array_column($groups, 'name')
        );
        $this->assertSame(['special', 'special', 'special', 'special'], array_column($groups, 'group_type'));
    }

    public function testAllCrewCollapsesTheSharedHousehold(): void
    {
        $res = $this->resolve('all_crew');

        $this->assertSame([
            'bounced@example.com',
            'expired@example.com',
            'invited@example.com',
            'setup@example.com',
            'thejones@example.com',
        ], $this->emailsOf($res));

        $this->assertSame(5, $this->counts()['all_crew']);
        $this->assertSame(1, $res['missing_contact_count'], 'the emailless guardian is reported, not hidden');
    }

    public function testAllIncludesAthletesCrewAndStaff(): void
    {
        $res = $this->resolve('all');
        $types = array_count_values(array_column($res['recipients'], 'type'));

        $this->assertSame(5, $types['guardian']);
        $this->assertSame(2, $types['athlete']);
        $this->assertSame(2, $types['coach']);
        $this->assertSame(9, $this->counts()['all']);
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
        $this->assertNotContains('otherclub@example.com', $emails, "another club's athlete");
        $this->assertNotContains('otherguard@example.com', $emails, "another club's guardian");
        $this->assertNotContains('othercoach@example.com', $emails, "another club's coach");
    }

    // ---- parent-portal follow-up groups ----

    public function testInviteNeverSentIsCrewWithNoAccountAndNoInvite(): void
    {
        $res = $this->resolve('invite_never_sent');

        $this->assertSame(['bounced@example.com', 'thejones@example.com'], $this->emailsOf($res));
        $this->assertSame(2, $this->counts()['invite_never_sent']);

        // A guardian with no email cannot be sent a portal invite, so they are
        // not a "never invited" case to chase — excluded outright, not counted
        // as a missing contact.
        $this->assertSame(0, $res['missing_contact_count']);
    }

    public function testInviteSentNotSetUpIncludesTheExpiredInvite(): void
    {
        $res = $this->resolve('invite_sent_not_setup');

        $this->assertSame(['expired@example.com', 'invited@example.com'], $this->emailsOf($res));
        $this->assertSame(2, $this->counts()['invite_sent_not_setup']);
    }

    /** A users row with no password yet is what an invite creates — not "set up". */
    public function testInvitedUserWithoutPasswordIsNotTreatedAsSetUp(): void
    {
        $this->assertContains('invited@example.com', $this->emailsOf($this->resolve('invite_sent_not_setup')));
        $this->assertContains('expired@example.com', $this->emailsOf($this->resolve('invite_sent_not_setup')), 'empty-string hash is not a password');
    }

    public function testSetUpCrewAppearInNeitherPortalGroup(): void
    {
        $this->assertNotContains('setup@example.com', $this->emailsOf($this->resolve('invite_never_sent')));
        $this->assertNotContains('setup@example.com', $this->emailsOf($this->resolve('invite_sent_not_setup')));
        $this->assertContains('setup@example.com', $this->emailsOf($this->resolve('all_crew')));
    }

    /** The two portal groups plus the already-set-up crew must partition All Crew. */
    public function testPortalGroupsPartitionTheCrew(): void
    {
        $c = $this->counts();
        $setUp = 1; // guardian 5

        $this->assertSame(
            $c['all_crew'],
            $c['invite_never_sent'] + $c['invite_sent_not_setup'] + $setUp,
            'every crew member belongs to exactly one portal state'
        );
    }

    // ---- shared recipient handling ----

    public function testSuppressedRecipientIsFlaggedNotRemoved(): void
    {
        $res = $this->resolve('all_crew');
        $byEmail = array_column($res['recipients'], null, 'email');

        $this->assertTrue($byEmail['bounced@example.com']['suppressed']);
        $this->assertSame('bounce', $byEmail['bounced@example.com']['suppression_reason']);
        $this->assertSame(1, $res['suppressed_count']);
        $this->assertFalse($byEmail['thejones@example.com']['suppressed']);
    }

    public function testExcludedRecipientsAreDropped(): void
    {
        $res = $this->resolve('invite_never_sent', ['guardian:4' => true]);
        $this->assertSame(['thejones@example.com'], $this->emailsOf($res));
    }

    /**
     * sms_opt_out rides along on the guardian row instead of costing a query per
     * person, so the STOP flag has to still be honoured on the SMS channel.
     */
    public function testGuardianSmsOptOutIsHonouredOnSmsChannel(): void
    {
        $this->pdo->exec("UPDATE guardians SET sms_opt_out = 1 WHERE id = 1");

        $res = $this->resolve('all_crew', [], 'sms');
        $byPhone = array_column($res['recipients'], null, 'phone');

        $this->assertTrue($byPhone['555-0201']['suppressed'], 'STOP guardian must be flagged');
        $this->assertSame('twilio_stop', $byPhone['555-0201']['suppression_reason']);
        $this->assertFalse($byPhone['555-0202']['suppressed']);
    }
}
