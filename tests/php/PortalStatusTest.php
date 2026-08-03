<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/portal_status.php';

/**
 * lib/portal_status.php replaced an inference that was wrong three ways. Each of
 * those is pinned here, because each shipped and each looked fine on screen.
 */
class PortalStatusTest extends TestCase
{
    private function row(array $over = []): array
    {
        return array_merge([
            'first_login_at'    => null,
            'last_login_at'     => null,
            'invited_at'        => null,
            'invite_used_at'    => null,
            'invite_expires_at' => null,
            'has_password'      => false,
            'accounts_on_email' => 1,
            'athlete_shells'    => 0,
            'other_roles'       => null,
        ], $over);
    }

    // ── 1. A password is not a login ─────────────────────────────────────────

    /**
     * The Samantha Archer case. Her coach account had a password, so the old CASE
     * said 'active'; she had never signed in and had never been invited.
     */
    public function testAPasswordAloneIsNotReportedAsBeingOnThePlatform(): void
    {
        $s = te_portal_status($this->row(['has_password' => true]), 'coach@example.com', 'crew');
        $this->assertSame('account_never_used', $s['status']);
        $this->assertNull($s['first_login_at']);
    }

    public function testARealLoginIsReportedAsActive(): void
    {
        $s = te_portal_status($this->row(['first_login_at' => '2026-08-02 15:51:48']), 'a@b.com');
        $this->assertSame('active', $s['status']);
        $this->assertSame('2026-08-02 15:51:48', $s['first_login_at']);
    }

    // ── 2. An expired invite must not decay into "never invited" ─────────────

    /**
     * 64 Central Kansas invites expire on 2026-08-07. Under the old CASE all 64
     * would have flipped to 'not_invited' overnight, turning "send a reminder"
     * into "make first contact" for families who had already been written to.
     */
    public function testALapsedInviteIsDistinctFromNeverHavingBeenInvited(): void
    {
        // Relative to now, not a literal date: pinning "expired" to a calendar date
        // means the test passes only until that date arrives. The first draft of
        // this test used 2026-08-07 — the real Central Kansas expiry — and failed
        // because that day had not come yet.
        $expired = te_portal_status($this->row([
            'invited_at'        => '2026-07-01 09:00:00',
            'invite_expires_at' => date('Y-m-d H:i:s', time() - 86400),
        ]), 'a@b.com');
        $this->assertSame('invite_expired', $expired['status'], 'a lapsed invite must stay visible');

        $never = te_portal_status($this->row(), 'a@b.com');
        $this->assertSame('not_invited', $never['status']);

        $this->assertNotSame($expired['status'], $never['status']);
    }

    public function testALiveInviteIsInvited(): void
    {
        $s = te_portal_status($this->row([
            'invited_at'        => '2026-07-31 22:43:00',
            'invite_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]), 'a@b.com');
        $this->assertSame('invited', $s['status']);
    }

    /** The invite date survives into the response — it is what the UI shows. */
    public function testTheInviteDateIsCarriedThrough(): void
    {
        $s = te_portal_status($this->row([
            'invited_at'        => '2026-07-31 22:43:00',
            'invite_expires_at' => date('Y-m-d H:i:s', time() - 86400),
        ]), 'a@b.com');
        $this->assertSame('2026-07-31 22:43:00', $s['invited_at']);
    }

    // ── 3. Email-alone matching is disclosed, not hidden ─────────────────────

    public function testASecondAccountOnTheSameAddressIsFlagged(): void
    {
        $s = te_portal_status($this->row([
            'first_login_at'    => '2026-08-01 10:00:00',
            'accounts_on_email' => 2,
        ]), 'shared@example.com');
        $this->assertTrue($s['shared_account']);
        $this->assertStringContainsString('2 accounts', $s['shared_reason']);
    }

    public function testAnAthleteShellOnTheAccountIsFlagged(): void
    {
        $s = te_portal_status($this->row([
            'first_login_at' => '2026-08-01 10:00:00',
            'athlete_shells' => 1,
        ]), 'a@b.com');
        $this->assertTrue($s['shared_account']);
    }

    /**
     * A coach role explains a login that is NOT the parent's — for crew that is a
     * caveat. For the coach list it is simply what they are, so it must not be
     * flagged there or every row on that page carries a warning and none mean
     * anything.
     */
    public function testAStaffRoleIsACaveatForCrewButNotForCoaches(): void
    {
        $row = $this->row(['first_login_at' => '2026-08-01 10:00:00', 'other_roles' => 'coach']);

        $this->assertTrue(te_portal_status($row, 'a@b.com', 'crew')['shared_account']);
        $this->assertFalse(te_portal_status($row, 'a@b.com', 'coach')['shared_account']);
    }

    public function testAnOrdinaryParentIsNotFlagged(): void
    {
        $s = te_portal_status($this->row(['first_login_at' => '2026-08-01 10:00:00']), 'a@b.com');
        $this->assertFalse($s['shared_account']);
        $this->assertNull($s['shared_reason']);
    }

    // ── Evidence precedence ──────────────────────────────────────────────────

    /** No address means they cannot be invited at all — it outranks everything. */
    public function testNoEmailWins(): void
    {
        $this->assertSame('no_email', te_portal_status($this->row([
            'has_password' => true, 'first_login_at' => '2026-08-01 10:00:00',
        ]), '')['status']);
        $this->assertSame('no_email', te_portal_status($this->row(), '   ')['status']);
    }

    /** Having used the invite but never returned is its own state. */
    public function testSetAPasswordButNeverSignedIn(): void
    {
        $s = te_portal_status($this->row([
            'invited_at'     => '2026-07-31 22:43:00',
            'invite_used_at' => '2026-08-01 02:27:18',
            'has_password'   => true,
        ]), 'a@b.com');
        $this->assertSame('account_never_used', $s['status']);
    }

    // ── The SQL fragment ─────────────────────────────────────────────────────

    /**
     * audit_log holds BOTH 'user' (68 rows, to 2026-07-29) and 'users' (123 rows,
     * since). Matching one loses six people's first-login date.
     */
    public function testBothAuditResourceTypeSpellingsAreMatched(): void
    {
        $sql = te_portal_status_columns('g.email');
        $this->assertStringContainsString("IN ('user', 'users')", $sql);
    }

    /**
     * 15 users have last_login_at and no audit row. Without the COALESCE fallback
     * they would all report as never having signed in.
     */
    public function testLastLoginIsTheFallbackWhenNoAuditRowExists(): void
    {
        $sql = te_portal_status_columns('g.email', 'u');
        $this->assertMatchesRegularExpression('/COALESCE\(\s*\(SELECT min\(al\.created_at\)/', $sql);
        $this->assertStringContainsString('u.last_login_at' . "\n" . '        ) AS first_login_at', $sql);
    }

    /** The alias must be honoured, or the fragment silently reads the wrong table. */
    public function testTheUserAliasIsApplied(): void
    {
        $sql = te_portal_status_columns('x.email', 'usr');
        $this->assertStringContainsString('usr.last_login_at', $sql);
        $this->assertStringNotContainsString('u.last_login_at', $sql);
    }
}
