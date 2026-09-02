<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * api/invitations-gateway.php authenticated but did not AUTHORIZE (found 2026-08-17,
 * fixed 2026-09-02).
 *
 * `send` and `create-link` required a token and then checked nothing else: `clubId`
 * comes from the request body and the caller's standing in that club was never
 * verified. Any signed-in user — parent, player, volunteer — could mint a
 * `club_admin` invitation link (90 days, unlimited uses) for ANY club and redeem it.
 * Combined with the JWT::decode() hole (ForgedTokenAuthGateTest) that was "anyone at
 * all", not "any signed-in user".
 *
 * The gate is te_is_club_admin(), never AuthMiddleware::canAccessClub(): the latter is
 * club MEMBERSHIP and a `parent` row satisfies it. Same lesson as handleClubParents.
 *
 * Parse-based, because the predicate was never wrong — which one got called was.
 */
class InvitationsAuthorizationTest extends TestCase
{
    private const FILE = __DIR__ . '/../../api/invitations-gateway.php';

    private function code(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(self::FILE)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private function functionBody(string $name): string
    {
        $code = $this->code();
        $start = strpos($code, "function $name(");
        $this->assertNotFalse($start, "function $name not found");
        $next = preg_match('/\nfunction \w+\(/', $code, $m, PREG_OFFSET_CAPTURE, $start + 10)
            ? $m[0][1] : strlen($code);
        return substr($code, $start, $next - $start);
    }

    /** @dataProvider inviteWriters */
    public function testInviteWritersGateOnClubAdminStanding(string $fn): void
    {
        $body = $this->functionBody($fn);
        $this->assertStringContainsString('te_is_club_admin(', $body,
            "$fn must check the caller is a club admin of the club in the request body");
        $this->assertStringContainsString('http_response_code(403)', $body);
        $this->assertStringNotContainsString('canAccessClub(', $body,
            'canAccessClub is membership, not staff standing — a parent passes it');
    }

    public static function inviteWriters(): array
    {
        return [['handleSendInvitations'], ['handleCreateLink']];
    }

    public function testTheGateReceivesTheAuthenticatedMiddlewareNotJustAUserId(): void
    {
        $code = $this->code();
        $this->assertMatchesRegularExpression('/function handleSendInvitations\(\$conn, \$input, \$userId, \$auth\)/', $code);
        $this->assertMatchesRegularExpression('/function handleCreateLink\(\$conn, \$input, \$userId, \$auth\)/', $code);
        $this->assertStringContainsString('$auth = AuthMiddleware::requireAuth();', $code);
    }

    public function testTheGateRunsBeforeAnyWrite(): void
    {
        foreach (['handleSendInvitations', 'handleCreateLink'] as $fn) {
            $body = $this->functionBody($fn);
            $gate = strpos($body, 'te_is_club_admin(');
            $write = strpos($body, 'INSERT INTO');
            $this->assertNotFalse($write, "$fn should INSERT");
            $this->assertLessThan($write, $gate, "$fn must authorize before it writes");
        }
    }

    public function testTheOldUnverifiedHelperIsGone(): void
    {
        $this->assertStringNotContainsString('function getAuthenticatedUser(', $this->code());
    }
}
