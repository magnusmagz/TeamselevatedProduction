<?php

namespace TeamsElevated\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

if (!defined('TE_CLUB_PUBLIC_LIB_ONLY')) {
    define('TE_CLUB_PUBLIC_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/club-public-gateway.php';

/**
 * The public contact form on /club/<slug>.
 *
 * Properties that matter, in order: the message is STORED before any mail is
 * attempted; the rate limit FAILS CLOSED; the honeypot answers silently; the
 * visitor goes in Reply-To and never in From; the response never names an
 * admin; the send is behind its own switch.
 */
class ClubContactMessageTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE club_contact_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER, name TEXT, email TEXT,
                phone TEXT, message TEXT, ip_address TEXT, user_agent TEXT, status TEXT DEFAULT 'new', sent_to TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                active INTEGER, revoked_at TEXT);
            INSERT INTO users VALUES (1, 'Ada', 'Admin', 'ada@cku.org');
            INSERT INTO users VALUES (2, 'Rev', 'Oked', 'rev@cku.org');
            INSERT INTO users VALUES (3, 'Cara', 'Coach', 'cara@cku.org');
            INSERT INTO users VALUES (4, 'No', 'Mail', '');
            INSERT INTO user_club_access VALUES (1, 1, 51, 'club_admin', 1, NULL);
            INSERT INTO user_club_access VALUES (2, 2, 51, 'club_admin', 1, '2026-08-01');
            INSERT INTO user_club_access VALUES (3, 3, 51, 'coach', 1, NULL);
            INSERT INTO user_club_access VALUES (4, 4, 51, 'club_admin', 1, NULL);
            INSERT INTO user_club_access VALUES (5, 1, 52, 'club_admin', 1, NULL);
        ");
    }

    public function testValidationAcceptsAGoodSubmissionAndTruncatesTheMessage(): void
    {
        $v = te_club_contact_validate([
            'name' => '  Jane Doe ', 'email' => 'jane@example.com', 'phone' => '', 'message' => str_repeat('x', 3000),
        ]);
        $this->assertTrue($v['ok']);
        $this->assertSame('Jane Doe', $v['values']['name']);
        $this->assertNull($v['values']['phone']);
        $this->assertSame(2000, mb_strlen($v['values']['message']));
    }

    /** @dataProvider badSubmissions */
    public function testValidationRefusesWithTheFieldNamed(array $body, string $field): void
    {
        $v = te_club_contact_validate($body);
        $this->assertFalse($v['ok']);
        $this->assertSame($field, $v['field']);
        $this->assertNotSame('', $v['error']);
    }

    public static function badSubmissions(): array
    {
        $good = ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'Hello'];
        return [
            'no name'     => [['email' => 'jane@example.com', 'message' => 'Hi'], 'name'],
            'long name'   => [['name' => str_repeat('n', 101)] + $good, 'name'],
            'bad email'   => [['email' => 'not-an-email'] + $good, 'email'],
            'no message'  => [['message' => '   '] + $good, 'message'],
            'long phone'  => [['phone' => str_repeat('1', 41)] + $good, 'phone'],
        ];
    }

    public function testAdminRecipientsAreActiveUnrevokedAdminsWithAnAddress(): void
    {
        $r = te_club_admin_recipients($this->pdo, 51);
        $this->assertSame(['ada@cku.org'], array_column($r, 'email'), 'revoked admin, coach and address-less admin are excluded');
    }

    public function testStoreWritesTheRowBeforeAnyMailAndMarkRecordsTheOutcome(): void
    {
        $id = te_club_contact_store($this->pdo, 51, [
            'name' => 'Jane', 'email' => 'jane@example.com', 'phone' => null, 'message' => 'Hello',
        ], '203.0.113.9', str_repeat('UA', 300));
        $row = $this->pdo->query('SELECT * FROM club_contact_messages')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('new', $row['status']);
        $this->assertSame(255, strlen($row['user_agent']));

        te_club_contact_mark($this->pdo, $id, 'sent', ['ada@cku.org']);
        $row = $this->pdo->query('SELECT status, sent_to FROM club_contact_messages')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('sent', $row['status']);
        $this->assertSame(['ada@cku.org'], json_decode($row['sent_to'], true));
    }

    public function testRateLimitFailsClosedWithoutAnIpOrWhenTheCountCannotRun(): void
    {
        $this->assertTrue(te_club_contact_is_rate_limited($this->pdo, null));
        $this->assertTrue(te_club_contact_is_rate_limited($this->pdo, ''));
        // SQLite has no INTERVAL: the count throws, and a limiter that cannot
        // count must refuse — this form causes outbound mail to real people.
        $this->assertTrue(te_club_contact_is_rate_limited($this->pdo, '203.0.113.9'));
    }

    public function testRateLimitCountsRowsByIpInTheLastHour(): void
    {
        $pdo = new class('sqlite::memory:') extends PDO {
            public function prepare($sql, $options = []): \PDOStatement|false
            {
                // Postgres' interval arithmetic, translated for the fixture.
                $sql = str_replace("NOW() - INTERVAL '1 hour'", "datetime('now', '-1 hour')", $sql);
                return parent::prepare($sql, $options);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE club_contact_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, club_id INTEGER, name TEXT,
            email TEXT, phone TEXT, message TEXT, ip_address TEXT, user_agent TEXT, status TEXT, sent_to TEXT, created_at TEXT)");
        for ($i = 0; $i < TE_CLUB_CONTACT_RATE_LIMIT - 1; $i++) {
            $pdo->exec("INSERT INTO club_contact_messages (club_id, name, email, message, ip_address, created_at)
                        VALUES (51, 'n', 'e@x.com', 'm', '203.0.113.9', datetime('now'))");
        }
        $this->assertFalse(te_club_contact_is_rate_limited($pdo, '203.0.113.9'), 'one under the cap');
        $pdo->exec("INSERT INTO club_contact_messages (club_id, name, email, message, ip_address, created_at)
                    VALUES (51, 'n', 'e@x.com', 'm', '203.0.113.9', datetime('now'))");
        $this->assertTrue(te_club_contact_is_rate_limited($pdo, '203.0.113.9'), 'at the cap');
        $this->assertFalse(te_club_contact_is_rate_limited($pdo, '198.51.100.1'), 'another visitor is unaffected');
        $pdo->exec("UPDATE club_contact_messages SET created_at = datetime('now', '-2 hours')");
        $this->assertFalse(te_club_contact_is_rate_limited($pdo, '203.0.113.9'), 'old rows age out');
    }

    /** Parse the handler: the properties that live in the ORDER of the code. */
    public function testHandlerStoresThenCommitsThenMailsAndNeverEchoesAdmins(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/club-public-gateway.php');
        $case = substr($src, strpos($src, "case 'contact':"));
        $case = substr($case, 0, strpos($case, 'default:'));

        $honeypot = strpos($case, "website_url");
        $limit = strpos($case, 'te_club_contact_is_rate_limited(');
        $validate = strpos($case, 'te_club_contact_validate(');
        $store = strpos($case, 'te_club_contact_store(');
        $commit = strpos($case, '$pdo->commit()');
        $mail = strpos($case, 'sendClubContactMessage(');
        $mark = strpos($case, 'te_club_contact_mark(');
        foreach (compact('honeypot', 'limit', 'validate', 'store', 'commit', 'mail', 'mark') as $k => $v) {
            $this->assertNotFalse($v, "$k is missing from the contact handler");
        }
        $this->assertLessThan($limit, $honeypot, 'a bot is answered before it can consume rate-limit budget');
        $this->assertLessThan($validate, $limit);
        $this->assertLessThan($store, $validate);
        $this->assertLessThan($commit, $store);
        $this->assertLessThan($mail, $commit, 'the row is committed BEFORE any mail is attempted');
        $this->assertLessThan($mark, $mail);

        $this->assertStringContainsString('->replyTo($values[\'email\']', $case, 'the visitor is Reply-To');
        $this->assertStringContainsString('->forClub($pdo, $clubId)', $case, 'the admin is mailed as the club');
        $this->assertStringNotContainsString("'sent_to' =>", $case, 'the response must not list who was mailed');
        $this->assertStringNotContainsString('$admins', substr($case, strrpos($case, 'echo json_encode')), 'admins never reach the response');
        $this->assertStringContainsString("te_feature_disabled_response('PUBLIC_CLUB_CONTACT')", $case);
    }

    public function testTheVisitorIsNeverTheFromAddress(): void
    {
        $src = file_get_contents(__DIR__ . '/../../lib/Email.php');
        $method = substr($src, strpos($src, 'public function replyTo('));
        $method = substr($method, 0, strpos($method, 'public function', 10));
        $this->assertStringNotContainsString('fromEmail', $method, 'replyTo() must not touch From');
        $this->assertStringContainsString("'reply_to'", $src, 'SendGrid payload carries reply_to');
    }
}
