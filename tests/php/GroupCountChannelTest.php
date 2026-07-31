<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_RECIPIENT_SEARCH_LIB_ONLY')) {
    define('TE_RECIPIENT_SEARCH_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/recipient-search-gateway.php';

/**
 * Group counts in the compose picker.
 *
 * They used to be the deduplicated EMAIL count regardless of what you were
 * composing, so SMS showed "All Crew (124)" — everyone with an email address,
 * including people with no mobile who could never receive a text. The resolve
 * step dropped them correctly, so the picker promised one number and the send
 * delivered another, with nothing on screen explaining the gap.
 *
 * Three counts, each measured directly, because the arithmetic between them does
 * NOT close:
 *
 *   people    — distinct humans in the group
 *   missing   — humans with no address on this channel
 *   reachable — distinct ADDRESSES, i.e. messages actually sent
 *
 * A household sharing one mobile is 2 people, 0 missing, 1 message. Deriving
 * `missing` by subtraction would report that household as someone lacking a
 * phone number — a wrong accusation about real families, which is why these are
 * counted separately and tested separately.
 */
class GroupCountChannelTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 51;

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
            CREATE TABLE email_suppressions (id INTEGER PRIMARY KEY, club_profile_id INTEGER,
                email TEXT, phone TEXT, channel TEXT, reason TEXT, scope TEXT, team_id INTEGER);
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO athletes (id,first_name,last_name,email,phone,club_id,deleted_at,active_status)
                  VALUES (1,'Kid','One',NULL,NULL,51,NULL,1)");

        // 1 + 2 are a household sharing one mobile, different emails.
        // 3 has an email but NO phone — reachable by email, not by SMS.
        // 4 has a phone but no email — the mirror case.
        $p->exec("INSERT INTO guardians (id,first_name,last_name,email,mobile_phone) VALUES
            (1,'John','House','john@example.com','3605550100'),
            (2,'Jane','House','jane@example.com','3605550100'),
            (3,'NoPhone','Nunez','nunez@example.com',NULL),
            (4,'NoMail','Ngata',NULL,'3605550400')");
        $p->exec("INSERT INTO athlete_guardians (id,athlete_id,guardian_id)
                  VALUES (1,1,1),(2,1,2),(3,1,3),(4,1,4)");

        // 2 coaches with phones, 1 without, 1 revoked (must never count).
        $p->exec("INSERT INTO users (id,first_name,last_name,email,phone,password_hash) VALUES
            (10,'Coach','Lee','lee@example.com','3605550010',''),
            (11,'Coach','Ray','ray@example.com','3605550011',''),
            (12,'Coach','NoPhone','np@example.com',NULL,''),
            (13,'Coach','Gone','gone@example.com','3605550013','')");
        $p->exec("INSERT INTO user_club_access (id,user_id,club_profile_id,role,active) VALUES
            (1,10,51,'coach',1),(2,11,51,'club_admin',1),(3,12,51,'coach',1),(4,13,51,'coach',0)");
    }

    private function group(string $id, string $channel): array
    {
        foreach (getSpecialGroups($this->pdo, self::CLUB, $channel) as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        $this->fail("group {$id} not offered");
    }

    // ── The reported problem ─────────────────────────────────────────────────
    public function testCrewCountsDifferByChannel(): void
    {
        $sms   = $this->group('all_crew', 'sms');
        $email = $this->group('all_crew', 'email');

        // SMS: 1+2 share one number, 4 has one, 3 has none  → 2 addresses.
        $this->assertSame(2, $sms['recipient_count']);
        // Email: 1, 2, 3 have addresses, 4 has none         → 3 addresses.
        $this->assertSame(3, $email['recipient_count']);
        $this->assertNotSame(
            $sms['recipient_count'],
            $email['recipient_count'],
            'an email-based count on the SMS picker is the original bug'
        );
    }

    public function testMissingIsCountedNotDerivedBySubtraction(): void
    {
        $sms = $this->group('all_crew', 'sms');

        $this->assertSame(4, $sms['people_count']);
        // ONLY guardian 3 lacks a phone. The sharing household must not be
        // mistaken for people missing a number, which subtraction would do:
        // 4 people - 2 addresses = 2, and 2 is the wrong answer.
        $this->assertSame(1, $sms['missing_contact_count']);
        $this->assertNotSame(
            $sms['people_count'] - $sms['recipient_count'],
            $sms['missing_contact_count'],
            'this fixture exists precisely so subtraction gives the wrong number'
        );
    }

    public function testLabelMatchesTheChannel(): void
    {
        $this->assertSame('phone number', $this->group('all_crew', 'sms')['missing_contact_label']);
        $this->assertSame('email address', $this->group('all_crew', 'email')['missing_contact_label']);
    }

    // ── The new group ────────────────────────────────────────────────────────
    public function testAllCoachesGroupIsOffered(): void
    {
        $g = $this->group('all_coaches', 'sms');

        $this->assertSame('All Coaches', $g['name']);
        $this->assertSame('special', $g['group_type']);
    }

    public function testAllCoachesCountsExcludeRevokedAccess(): void
    {
        $g = $this->group('all_coaches', 'sms');

        // 10, 11, 12 active; 13 revoked and must not appear anywhere.
        $this->assertSame(3, $g['people_count']);
        $this->assertSame(2, $g['recipient_count'], 'only 10 and 11 have phones');
        $this->assertSame(1, $g['missing_contact_count'], 'coach 12 has no phone');
    }

    public function testAllCoachesResolvesToCoachesOnlyNoFamilies(): void
    {
        ob_start();
        try {
            resolveSpecialGroup($this->pdo, self::CLUB, 'all_coaches', 'sms', []);
        } finally {
            $out = ob_get_clean();
        }
        $res = json_decode($out, true);

        $types = array_unique(array_column($res['recipients'], 'type'));
        $this->assertSame(['coach'], array_values($types), 'All Coaches must not include crew or athletes');
        $this->assertCount(2, $res['recipients'], 'the phoneless coach is excluded for SMS');
    }

    public function testAllCrewResolvesToGuardiansOnly(): void
    {
        ob_start();
        try {
            resolveSpecialGroup($this->pdo, self::CLUB, 'all_crew', 'sms', []);
        } finally {
            $out = ob_get_clean();
        }
        $res = json_decode($out, true);

        $types = array_unique(array_column($res['recipients'], 'type'));
        $this->assertSame(['guardian'], array_values($types), 'All Crew must never include coaches');
        // Household dedupes to one, guardian 3 has no phone, guardian 4 has one.
        $this->assertCount(2, $res['recipients']);
        $this->assertSame(1, $res['missing_contact_count']);
    }
}
