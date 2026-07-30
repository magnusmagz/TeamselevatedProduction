<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_COMMUNICATIONS_LIB_ONLY')) {
    define('TE_COMMUNICATIONS_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/communications-gateway.php';

/**
 * Minimal stand-in for AuthMiddleware. Only the three methods
 * broadcastAuthError touches.
 */
class FakeBroadcastAuth
{
    public function __construct(
        private int $userId,
        private array $accessibleClubs,
        private array $adminClubs
    ) {}

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function canAccessClub($clubProfileId): bool
    {
        return in_array((int) $clubProfileId, $this->accessibleClubs, true);
    }

    public function hasRole($role, $clubProfileId, $level): bool
    {
        return $role === 'club_admin' && in_array((int) $clubProfileId, $this->adminClubs, true);
    }
}

/**
 * Who is allowed to broadcast to whom.
 *
 * CLAUDE.md: "Enforce permissions server-side on all send and reporting
 * endpoints — never trust the frontend." The frontend hides the club-wide radio
 * from coaches; this is the half that matters.
 *
 * broadcastAuthError is shared by handleSendBroadcast and handlePreviewBroadcast
 * precisely so these answers cannot drift apart. A preview that is more
 * permissive than the send shows a count for a blast that then 403s.
 */
class BroadcastScopeTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 32;
    private const OTHER_CLUB = 51;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER,
                user_id INTEGER, athlete_id INTEGER, role TEXT, status TEXT);
        ");

        // Team 1: coach 10 is primary. Team 2: coach 10 is assistant.
        // Team 3: coach 10 has no relationship. Team 9: different club.
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at) VALUES
            (1, 'Eagles', 32, 10, NULL),
            (2, 'Hawks',  32, 99, NULL),
            (3, 'Owls',   32, 99, NULL),
            (9, 'Others', 51, 19, NULL)");

        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status) VALUES
            (1, 2, 10, NULL, 'assistant_coach', 'active')");
    }

    private function coach(): FakeBroadcastAuth
    {
        return new FakeBroadcastAuth(10, [self::CLUB], []);
    }

    private function admin(): FakeBroadcastAuth
    {
        return new FakeBroadcastAuth(11, [self::CLUB], [self::CLUB]);
    }

    private function check($auth, bool $clubWide, array $teamIds, $club = self::CLUB): ?array
    {
        return broadcastAuthError($auth, $this->pdo, $club, $clubWide, $teamIds);
    }

    // ── D1 ────────────────────────────────────────────────────────────────────
    public function testCoachMayBroadcastToTheirOwnTeams(): void
    {
        $this->assertNull($this->check($this->coach(), false, [1]), 'primary coach');
        $this->assertNull($this->check($this->coach(), false, [2]), 'assistant coach');
        $this->assertNull($this->check($this->coach(), false, [1, 2]), 'both');
    }

    // ── D2 ────────────────────────────────────────────────────────────────────
    public function testCoachIsRefusedWhenAnyTeamIsOutsideTheirScope(): void
    {
        $err = $this->check($this->coach(), false, [1, 3]);

        // All-or-nothing: the refusal must cover the whole request, not quietly
        // send to the subset the coach does own.
        $this->assertNotNull($err);
        $this->assertSame(403, $err['code']);
        $this->assertStringContainsString('do not have access', $err['error']);
    }

    public function testCoachIsRefusedForATeamInAnotherClub(): void
    {
        $this->assertNotNull($this->check($this->coach(), false, [9]));
    }

    // ── D3 / club-wide gate ───────────────────────────────────────────────────
    public function testCoachMayNotBroadcastClubWide(): void
    {
        $err = $this->check($this->coach(), true, []);

        $this->assertNotNull($err, 'club-wide is an escalation past the team picker');
        $this->assertSame(403, $err['code']);
        $this->assertStringContainsString('Only club admins', $err['error']);
    }

    // ── D4 ────────────────────────────────────────────────────────────────────
    public function testClubAdminMayBroadcastToAnyTeamInTheClubAndClubWide(): void
    {
        $this->assertNull($this->check($this->admin(), false, [1, 2, 3]));
        $this->assertNull($this->check($this->admin(), true, []));
    }

    // ── D5 ────────────────────────────────────────────────────────────────────
    public function testNobodyMayTouchAClubTheyCannotAccess(): void
    {
        foreach (['coach' => $this->coach(), 'admin' => $this->admin()] as $label => $auth) {
            $err = $this->check($auth, false, [9], self::OTHER_CLUB);
            $this->assertNotNull($err, "{$label} must be refused another club");
            $this->assertSame(403, $err['code']);
            $this->assertStringContainsString('Access denied', $err['error']);

            $wide = $this->check($auth, true, [], self::OTHER_CLUB);
            $this->assertNotNull($wide, "{$label} must be refused another club, club-wide");
        }
    }

    /**
     * Club access is checked before the admin short-circuit. A club_admin of club
     * 32 must not inherit anything in club 51 by virtue of being an admin somewhere.
     */
    public function testAdminRightsDoNotLeakAcrossClubs(): void
    {
        $crossClubAdmin = new FakeBroadcastAuth(11, [self::CLUB], [self::CLUB, self::OTHER_CLUB]);

        $err = $this->check($crossClubAdmin, true, [], self::OTHER_CLUB);

        $this->assertNotNull($err);
        $this->assertStringContainsString('Access denied', $err['error']);
    }

    public function testEmptyTeamListForACoachIsVacuouslyAllowedAndResolvesNobody(): void
    {
        // The handlers reject empty team_ids with a 400 before reaching auth, and
        // resolveBroadcastRecipients returns [] for it. Pinned so that neither
        // layer can start treating "no teams" as "all teams".
        $this->assertNull($this->check($this->coach(), false, []));
        $this->assertSame([], resolveBroadcastRecipients($this->pdo, [], ['athlete'], [], 'sms', 'teams', self::CLUB));
    }
}
