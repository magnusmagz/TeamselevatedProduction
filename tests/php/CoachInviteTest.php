<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/coach_invite.php';

/**
 * lib/coach_invite.php — a per-person coach invite with a real accepted timestamp
 * (GOTR G6).
 *
 * Replaces the shared literal password: every coach made through the Coaches
 * page or an import gets NO password and a single-use, 7-day `:coach_invite`
 * token instead. Redemption writes the password and `used_at` — that row is the
 * "accepted" fact the funnel counts.
 *
 * What is pinned here, in order of how much the alternative would cost:
 *
 * - The token is never handed back to a caller. The email is the channel; the
 *   sender re-reads the freshest unused token itself.
 * - An address that already has an account is ATTACHED, never duplicated
 *   (users.email is UNIQUE) and never failed. With a password it is
 *   `already_active` and gets no invite.
 * - A revoked coach access is not silently re-granted by an invite.
 * - The three-answer ladder (used / expired / unknown) — same shape as
 *   lib/parent_invite_token.php, for the same 2026-08-03 reason.
 * - No file anywhere carries `password123` any more.
 */
class CoachInviteTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT,
                password_hash TEXT, role TEXT, auth_provider TEXT, phone TEXT, last_login_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT,
                granted_at TEXT DEFAULT CURRENT_TIMESTAMP, granted_by INTEGER, revoked_at TEXT, revoked_by INTEGER,
                active BOOLEAN DEFAULT 1, UNIQUE (user_id, club_profile_id, role)
            );
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, expires_at TEXT, used_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP, invitation_id INTEGER, return_to TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
            CREATE TABLE club_profile (id INTEGER PRIMARY KEY, name TEXT, org_unit_id INTEGER);
            INSERT INTO club_profile (id, name) VALUES (100, 'GOTR Kansas');
            INSERT INTO users (id, email, first_name, last_name, password_hash) VALUES
                (7, 'active@gotr.org', 'Ann', 'Active', 'hash'),
                (8, 'shell@gotr.org', 'Sam', 'Shell', NULL),
                (9, 'gone@gotr.org', 'Gina', 'Gone', NULL);
            INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
                (9, 100, 'coach', 0, '2026-08-01 00:00:00');
        ");
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        putenv('TE_FEATURE_COACH_INVITE_EMAIL');
        unset($_ENV['TE_FEATURE_COACH_INVITE_EMAIL']);
        putenv('APP_URL=https://app.example.test');
        $_ENV['APP_URL'] = 'https://app.example.test';
    }

    private function person(string $email, string $first = 'Pat', string $last = 'Coach'): array
    {
        return ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '5551001000'];
    }

    private function tokens(string $email): array
    {
        $s = $this->pdo->prepare('SELECT * FROM magic_link_tokens WHERE email = ? ORDER BY id');
        $s->execute([$email . ':coach_invite']);
        return $s->fetchAll();
    }

    // ---------------------------------------------------------------- ensure

    public function testANewCoachGetsNoPasswordAndOneSevenDayToken(): void
    {
        $r = te_coach_invite_ensure_user_and_token($this->pdo, $this->person('New.Coach@GOTR.org'), 100, 1);

        $this->assertSame('invited', $r['status']);
        $this->assertArrayNotHasKey('token', $r, 'the token is never handed back — the email is the channel');

        $u = $this->pdo->query("SELECT * FROM users WHERE email = 'new.coach@gotr.org'")->fetch();
        $this->assertNotFalse($u, 'email is stored lowercased');
        $this->assertNull($u['password_hash']);
        $this->assertSame('coach', $u['role']);

        $access = $this->pdo->query("SELECT * FROM user_club_access WHERE user_id = {$u['id']}")->fetchAll();
        $this->assertCount(1, $access);
        $this->assertSame('coach', $access[0]['role']);
        $this->assertEquals(100, $access[0]['club_profile_id']);

        $t = $this->tokens('new.coach@gotr.org');
        $this->assertCount(1, $t);
        $this->assertNull($t[0]['used_at']);
        $days = (strtotime($t[0]['expires_at']) - time()) / 86400;
        $this->assertEqualsWithDelta(7, $days, 0.01, 'a coach invite lives 7 days, like a parent invite');
    }

    public function testAnAddressWithAPasswordIsAttachedAndNotInvited(): void
    {
        $r = te_coach_invite_ensure_user_and_token($this->pdo, $this->person('active@gotr.org'), 100, 1);

        $this->assertSame('already_active', $r['status']);
        $this->assertSame(7, $r['user_id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn() - 2, 'no duplicate user');
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT count(*) FROM user_club_access WHERE user_id = 7 AND club_profile_id = 100 AND role = 'coach' AND active = 1"
        )->fetchColumn(), 'club access is attached to the existing account');
        $this->assertSame([], $this->tokens('active@gotr.org'), 'an account that can already sign in gets no invite');
        $this->assertSame('hash', $this->pdo->query('SELECT password_hash FROM users WHERE id = 7')->fetchColumn(),
            'the existing password is never touched');
    }

    public function testAPasswordlessAccountIsReusedAndInvited(): void
    {
        $r = te_coach_invite_ensure_user_and_token($this->pdo, $this->person('shell@gotr.org'), 100, 1);
        $this->assertSame('invited', $r['status']);
        $this->assertSame(8, $r['user_id']);
        $this->assertCount(1, $this->tokens('shell@gotr.org'));
        $this->assertSame('Sam', $this->pdo->query('SELECT first_name FROM users WHERE id = 8')->fetchColumn(),
            'an existing profile is not overwritten by the CSV');
    }

    public function testAReInviteSpendsThePriorUnusedToken(): void
    {
        te_coach_invite_ensure_user_and_token($this->pdo, $this->person('shell@gotr.org'), 100, 1);
        te_coach_invite_ensure_user_and_token($this->pdo, $this->person('shell@gotr.org'), 100, 1);
        $t = $this->tokens('shell@gotr.org');
        $this->assertCount(2, $t);
        $this->assertNotNull($t[0]['used_at'], 'only the freshest link works');
        $this->assertNull($t[1]['used_at']);
    }

    public function testARevokedAccessIsNotReGrantedByAnInvite(): void
    {
        $r = te_coach_invite_ensure_user_and_token($this->pdo, $this->person('gone@gotr.org'), 100, 1);
        $this->assertSame('access_revoked', $r['status']);
        $row = $this->pdo->query("SELECT active, revoked_at FROM user_club_access WHERE user_id = 9")->fetch();
        $this->assertEquals(0, $row['active']);
        $this->assertNotNull($row['revoked_at']);
        $this->assertSame([], $this->tokens('gone@gotr.org'));
    }

    public function testABlankOrInvalidEmailIsRefused(): void
    {
        $r = te_coach_invite_ensure_user_and_token($this->pdo, $this->person(''), 100, 1);
        $this->assertSame('error', $r['status']);
        $r = te_coach_invite_ensure_user_and_token($this->pdo, $this->person('not-an-email'), 100, 1);
        $this->assertSame('error', $r['status']);
        $this->assertSame(3, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
    }

    // ---------------------------------------------------------------- ladder

    public function testTheLadderChecksUsedBeforeExpired(): void
    {
        $this->assertSame(TE_INVITE_TOKEN_NOT_FOUND, te_classify_coach_invite_token(false));
        $this->assertSame(TE_INVITE_TOKEN_USED, te_classify_coach_invite_token(
            ['used_at' => '2026-08-01 00:00:00', 'expires_at' => '2026-07-01 00:00:00'], strtotime('2026-09-01')));
        $this->assertSame(TE_INVITE_TOKEN_EXPIRED, te_classify_coach_invite_token(
            ['used_at' => null, 'expires_at' => '2026-07-01 00:00:00'], strtotime('2026-09-01')));
        $this->assertSame(TE_INVITE_TOKEN_VALID, te_classify_coach_invite_token(
            ['used_at' => null, 'expires_at' => '2026-12-01 00:00:00'], strtotime('2026-09-01')));
    }

    // ---------------------------------------------------------------- redeem

    private function freshToken(string $email): string
    {
        te_coach_invite_ensure_user_and_token($this->pdo, $this->person($email), 100, 1);
        $t = $this->tokens($email);
        return $t[count($t) - 1]['token'];
    }

    public function testRedeemingSetsThePasswordAndSpendsTheToken(): void
    {
        $token = $this->freshToken('shell@gotr.org');

        $r = te_coach_invite_redeem($this->pdo, $token, 'Str0ngPassword');
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(8, $r['user_id']);

        $u = $this->pdo->query('SELECT password_hash, auth_provider FROM users WHERE id = 8')->fetch();
        $this->assertTrue(password_verify('Str0ngPassword', (string) $u['password_hash']));
        $this->assertSame('password', $u['auth_provider']);
        $this->assertNotNull($this->tokens('shell@gotr.org')[0]['used_at'], 'used_at is the accepted fact');

        $again = te_coach_invite_redeem($this->pdo, $token, 'Str0ngPassword');
        $this->assertFalse($again['success']);
        $this->assertSame(TE_INVITE_TOKEN_USED, $again['reason']);
    }

    public function testRedeemRefusesAnUnknownTokenAndAWeakPassword(): void
    {
        $r = te_coach_invite_redeem($this->pdo, 'nope', 'Str0ngPassword');
        $this->assertFalse($r['success']);
        $this->assertSame(TE_INVITE_TOKEN_NOT_FOUND, $r['reason']);

        $token = $this->freshToken('shell@gotr.org');
        $r = te_coach_invite_redeem($this->pdo, $token, 'short');
        $this->assertFalse($r['success']);
        $this->assertSame('weak_password', $r['reason']);
        $this->assertNull($this->tokens('shell@gotr.org')[0]['used_at'], 'a refused attempt does not spend the link');
    }

    public function testRedeemLeavesTheTokenUnspentWhenTheAccountIsMissing(): void
    {
        $token = $this->freshToken('shell@gotr.org');
        $this->pdo->exec('DELETE FROM users WHERE id = 8');
        $r = te_coach_invite_redeem($this->pdo, $token, 'Str0ngPassword');
        $this->assertFalse($r['success']);
        $this->assertSame('account_missing', $r['reason']);
        $this->assertNull($this->tokens('shell@gotr.org')[0]['used_at']);
    }

    // ---------------------------------------------------------------- send

    public function testSendUsesTheFreshestUnusedTokenAndTheRedemptionRoute(): void
    {
        te_coach_invite_ensure_user_and_token($this->pdo, $this->person('shell@gotr.org'), 100, 1);
        $calls = [];
        $sender = function (string $to, string $name, string $link) use (&$calls): bool {
            $calls[] = [$to, $name, $link];
            return true;
        };

        $r = te_coach_invite_send($this->pdo, 8, 100, $sender);
        $this->assertTrue($r['sent'], json_encode($r));
        $this->assertCount(1, $calls);
        [$to, $name, $link] = $calls[0];
        $this->assertSame('shell@gotr.org', $to);
        $this->assertSame('Sam Shell', $name);
        $token = $this->tokens('shell@gotr.org')[0]['token'];
        $this->assertSame('https://app.example.test/accept-coach-invite?token=' . $token, $link);
    }

    public function testSendIsAKillSwitchAndNeverReportsSuccessWhenOff(): void
    {
        te_coach_invite_ensure_user_and_token($this->pdo, $this->person('shell@gotr.org'), 100, 1);
        putenv('TE_FEATURE_COACH_INVITE_EMAIL=off');
        $_ENV['TE_FEATURE_COACH_INVITE_EMAIL'] = 'off';
        $called = false;
        $r = te_coach_invite_send($this->pdo, 8, 100, function () use (&$called) { $called = true; return true; });
        $this->assertFalse($called);
        $this->assertFalse($r['sent']);
        $this->assertSame('COACH_INVITE_EMAIL', $r['feature_disabled']);
    }

    public function testSendSkipsAnAccountThatCanAlreadySignIn(): void
    {
        $called = false;
        $r = te_coach_invite_send($this->pdo, 7, 100, function () use (&$called) { $called = true; return true; });
        $this->assertFalse($called);
        $this->assertFalse($r['sent']);
        $this->assertSame('already_active', $r['reason']);
    }

    public function testSendMintsAFreshTokenWhenTheOnlyOneHasExpired(): void
    {
        te_coach_invite_ensure_user_and_token($this->pdo, $this->person('shell@gotr.org'), 100, 1);
        $this->pdo->exec("UPDATE magic_link_tokens SET expires_at = '2020-01-01 00:00:00'");
        $links = [];
        $r = te_coach_invite_send($this->pdo, 8, 100, function ($to, $name, $link) use (&$links) { $links[] = $link; return true; });
        $this->assertTrue($r['sent']);
        $t = $this->tokens('shell@gotr.org');
        $this->assertCount(2, $t, 'an expired link is replaced, not re-sent');
        $this->assertStringContainsString($t[1]['token'], $links[0]);
    }

    // ---------------------------------------------------------------- scans

    public function testNoSourceFileCarriesTheSharedLiteralPassword(): void
    {
        $root = realpath(__DIR__ . '/../../');
        $files = [
            'legacy/coaches-gateway.php',
            'frontend/src/components/CoachManagement.tsx',
            'services/CoachImportStrategy.php',
            'lib/coach_invite.php',
        ];
        foreach ($files as $rel) {
            $src = file_get_contents("$root/$rel");
            $this->assertStringNotContainsString('password123', $src, "$rel still carries the shared literal password");
        }
    }

    public function testTheCoachesGatewayCreatesThroughTheInviteAndWritesNoPassword(): void
    {
        $src = file_get_contents(__DIR__ . '/../../legacy/coaches-gateway.php');
        $start = strpos($src, "case 'create':");
        $end = strpos($src, "case 'update':", $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $create = substr($src, $start, $end - $start);

        $this->assertStringContainsString('te_coach_invite_ensure_user_and_token(', $create);
        $this->assertStringNotContainsString('password_hash(', $create, 'a coach made on the Coaches page gets no password');
        $this->assertStringNotContainsString("'token'", $create, 'the token never reaches the admin who pressed the button');
        $this->assertStringContainsString('te_coach_invite_send(', $create, 'the page sends the invite inline');
    }

    public function testTheRedemptionEndpointIsPublicAndNeverAuthenticatesWithDecode(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/coach-invite.php');
        $this->assertStringContainsString('te_coach_invite_redeem(', $src);
        $this->assertStringNotContainsString('JWT::decode(', $src, 'JWT::decode() is not an auth gate');
    }
}
