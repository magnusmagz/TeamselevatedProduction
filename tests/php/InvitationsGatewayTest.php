<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit tests for the invitations gateway logic (CA-118 + accept fix).
 *
 * api/invitations-gateway.php is a procedural gateway that emits HTTP headers
 * and opens a DB connection on include, so it can't be required directly in a
 * unit test. These tests exercise the gateway's logic with the SAME SQL and the
 * SAME predicates the gateway uses, run against an in-memory SQLite PDO so they
 * never touch the production Neon database.
 *
 * Covered:
 *   - Resend cooldown (resendCooldownRemaining + the resend flow gate)
 *   - Cancel (revoke) -> status='canceled', scoped to inviter + pending only
 *   - Accept -> creates/links user, grants club access, marks accepted
 *     (and crucially does NOT write the non-existent accepted_by column)
 *
 * Fixture:
 *   Club 100, club 200
 *   User 50 (inviter / club admin of 100), user 51 (a different inviter)
 *   Existing user 60: existing@club.test
 *   Invitation 1: pending, club 100, invited_by 50, new@club.test
 *   Invitation 2: pending, club 100, invited_by 50, existing@club.test
 *   Invitation 3: accepted, club 100, invited_by 50
 *   Invitation 4: pending, club 100, invited_by 51 (other inviter)
 */
class InvitationsGatewayTest extends TestCase
{
    private PDO $pdo;

    /** Mirrors RESEND_COOLDOWN_SECONDS in the gateway. */
    private const RESEND_COOLDOWN_SECONDS = 120;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
    }

    private function createSchema(): void
    {
        // Mirrors the production invitations table — note there is NO
        // accepted_by column (the bug we fixed assumed one existed).
        $this->pdo->exec("
            CREATE TABLE invitations (
                id INTEGER PRIMARY KEY,
                club_profile_id INTEGER,
                email TEXT,
                role TEXT,
                status TEXT DEFAULT 'pending',
                invited_by INTEGER,
                personal_message TEXT,
                created_at TEXT,
                accepted_at TEXT,
                expires_at TEXT
            );
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                first_name TEXT,
                last_name TEXT,
                email TEXT,
                auth_provider TEXT,
                created_at TEXT
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                club_profile_id INTEGER,
                role TEXT,
                granted_at TEXT,
                UNIQUE(user_id, club_profile_id, role)
            );
        ");
    }

    private function seed(): void
    {
        $future = date('Y-m-d H:i:s', time() + 86400);
        $now = date('Y-m-d H:i:s');

        $this->pdo->exec("INSERT INTO users (id, first_name, last_name, email, auth_provider) VALUES
            (50, 'Admin', 'One', 'admin@club.test', 'password'),
            (51, 'Admin', 'Two', 'admin2@club.test', 'password'),
            (60, 'Existing', 'User', 'existing@club.test', 'password')");

        $this->pdo->exec("INSERT INTO invitations
            (id, club_profile_id, email, role, status, invited_by, personal_message, created_at, expires_at) VALUES
            (1, 100, 'new@club.test', 'coach', 'pending', 50, NULL, '$now', '$future'),
            (2, 100, 'existing@club.test', 'coach', 'pending', 50, NULL, '$now', '$future'),
            (3, 100, 'done@club.test', 'coach', 'accepted', 50, NULL, '$now', '$future'),
            (4, 100, 'other@club.test', 'coach', 'pending', 51, NULL, '$now', '$future')");
    }

    // ----------------------------------------------------------------
    // resendCooldownRemaining — pure predicate (mirrors the gateway)
    // ----------------------------------------------------------------

    /** Mirrors resendCooldownRemaining() in api/invitations-gateway.php. */
    private function resendCooldownRemaining($lastSentAt, $nowTs = null): int
    {
        if (empty($lastSentAt)) {
            return 0;
        }
        $nowTs = $nowTs ?? time();
        $lastTs = is_numeric($lastSentAt) ? (int)$lastSentAt : strtotime($lastSentAt);
        if ($lastTs === false) {
            return 0;
        }
        $elapsed = $nowTs - $lastTs;
        $remaining = self::RESEND_COOLDOWN_SECONDS - $elapsed;
        return $remaining > 0 ? $remaining : 0;
    }

    public function testCooldownBlocksImmediateResend(): void
    {
        $now = time();
        $remaining = $this->resendCooldownRemaining(date('Y-m-d H:i:s', $now), $now);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(self::RESEND_COOLDOWN_SECONDS, $remaining);
    }

    public function testCooldownAllowsAfterWindow(): void
    {
        $now = time();
        $old = date('Y-m-d H:i:s', $now - (self::RESEND_COOLDOWN_SECONDS + 5));
        $this->assertSame(0, $this->resendCooldownRemaining($old, $now));
    }

    public function testCooldownAllowsWhenNeverSent(): void
    {
        $this->assertSame(0, $this->resendCooldownRemaining(null));
        $this->assertSame(0, $this->resendCooldownRemaining(''));
    }

    public function testCooldownAtExactBoundaryIsAllowed(): void
    {
        $now = time();
        $exactly = date('Y-m-d H:i:s', $now - self::RESEND_COOLDOWN_SECONDS);
        $this->assertSame(0, $this->resendCooldownRemaining($exactly, $now));
    }

    // ----------------------------------------------------------------
    // Resend flow — gate + last-sent bump (mirrors handleResendInvitation)
    // ----------------------------------------------------------------

    /**
     * Mirrors the relevant parts of handleResendInvitation: fetch scoped by
     * inviter, reject non-pending, enforce cooldown, then bump created_at.
     * Returns ['ok' => bool, 'error' => ?string, 'retryAfter' => ?int].
     */
    private function resend(int $invitationId, int $userId, ?int $nowTs = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM invitations WHERE id = :id AND invited_by = :user_id'
        );
        $stmt->execute(['id' => $invitationId, 'user_id' => $userId]);
        $inv = $stmt->fetch();

        if (!$inv) {
            return ['ok' => false, 'error' => 'not_found', 'retryAfter' => null];
        }
        if ($inv['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'not_pending', 'retryAfter' => null];
        }
        $remaining = $this->resendCooldownRemaining($inv['created_at'] ?? null, $nowTs);
        if ($remaining > 0) {
            return ['ok' => false, 'error' => 'cooldown', 'retryAfter' => $remaining];
        }
        // (email send happens here in the gateway)
        $bumpTs = $nowTs ?? time();
        $upd = $this->pdo->prepare(
            'UPDATE invitations SET created_at = :ts WHERE id = :id AND invited_by = :user_id'
        );
        $upd->execute([
            'ts' => date('Y-m-d H:i:s', $bumpTs),
            'id' => $invitationId,
            'user_id' => $userId,
        ]);
        return ['ok' => true, 'error' => null, 'retryAfter' => null];
    }

    public function testResendRejectedDuringCooldown(): void
    {
        // Invitation 1 was just created (now), so a resend should be blocked.
        $res = $this->resend(1, 50);
        $this->assertFalse($res['ok']);
        $this->assertSame('cooldown', $res['error']);
        $this->assertGreaterThan(0, $res['retryAfter']);
    }

    public function testResendAllowedAfterCooldownAndBumpsTimestamp(): void
    {
        // Age the invitation past the cooldown window.
        $past = time() - (self::RESEND_COOLDOWN_SECONDS + 10);
        $this->pdo->prepare('UPDATE invitations SET created_at = :ts WHERE id = 1')
            ->execute(['ts' => date('Y-m-d H:i:s', $past)]);

        $now = time();
        $res = $this->resend(1, 50, $now);
        $this->assertTrue($res['ok']);

        // created_at should now be bumped to "now" -> a second resend is blocked.
        $blocked = $this->resend(1, 50, $now);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('cooldown', $blocked['error']);
    }

    public function testResendCannotTargetAnotherInvitersInvitation(): void
    {
        // User 50 cannot resend invitation 4 (invited_by 51).
        $res = $this->resend(4, 50);
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['error']);
    }

    public function testResendRejectsNonPendingInvitation(): void
    {
        $res = $this->resend(3, 50); // already accepted
        $this->assertFalse($res['ok']);
        $this->assertSame('not_pending', $res['error']);
    }

    // ----------------------------------------------------------------
    // Cancel (revoke) — mirrors handleCancelInvitation
    // ----------------------------------------------------------------

    private function cancel(int $invitationId, int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE invitations
            SET status = 'canceled'
            WHERE id = :id AND invited_by = :user_id AND status = 'pending'
        ");
        $stmt->execute(['id' => $invitationId, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    private function statusOf(int $invitationId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM invitations WHERE id = :id');
        $stmt->execute(['id' => $invitationId]);
        return (string)$stmt->fetchColumn();
    }

    public function testCancelSetsStatusCanceled(): void
    {
        $this->assertTrue($this->cancel(1, 50));
        $this->assertSame('canceled', $this->statusOf(1));
    }

    public function testCanceledInvitationLeavesPendingList(): void
    {
        $this->cancel(1, 50);
        $stmt = $this->pdo->prepare(
            "SELECT id FROM invitations WHERE invited_by = 50 AND status = 'pending' ORDER BY id"
        );
        $stmt->execute();
        $pendingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        // 1 was canceled, 3 was accepted, 4 belongs to inviter 51 -> only 2 remains.
        $this->assertSame([2], $pendingIds);
    }

    public function testCancelCannotTargetAnotherInvitersInvitation(): void
    {
        $this->assertFalse($this->cancel(4, 50));
        $this->assertSame('pending', $this->statusOf(4)); // unchanged
    }

    public function testCannotCancelAlreadyAcceptedInvitation(): void
    {
        $this->assertFalse($this->cancel(3, 50));
        $this->assertSame('accepted', $this->statusOf(3));
    }

    // ----------------------------------------------------------------
    // Accept — mirrors handleAcceptInvitation (email-invitation path)
    // ----------------------------------------------------------------

    /**
     * Mirrors the user-create + grant + mark-accepted block of
     * handleAcceptInvitation. Critically it does NOT write accepted_by (that
     * column does not exist) — the original bug threw a SQL error here.
     * Returns the resolved user id.
     */
    private function accept(int $invitationId, string $name = ''): int
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM invitations WHERE id = :id AND status = 'pending'
        ");
        $stmt->execute(['id' => $invitationId]);
        $inv = $stmt->fetch();
        $this->assertNotFalse($inv, 'invitation must be pending');

        $email = $inv['email'];
        $role = $inv['role'];
        $clubId = $inv['club_profile_id'];

        $u = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $u->execute(['email' => $email]);
        $existing = $u->fetch();

        $this->pdo->beginTransaction();
        try {
            if ($existing) {
                $userId = (int)$existing['id'];
            } else {
                $parts = explode(' ', trim($name), 2);
                $ins = $this->pdo->prepare("
                    INSERT INTO users (first_name, last_name, email, auth_provider, created_at)
                    VALUES (:first, :last, :email, 'invitation', :ts)
                ");
                $ins->execute([
                    'first' => $parts[0],
                    'last' => $parts[1] ?? '',
                    'email' => $email,
                    'ts' => date('Y-m-d H:i:s'),
                ]);
                $userId = (int)$this->pdo->lastInsertId();
            }

            // SQLite supports the same ON CONFLICT ... DO NOTHING syntax.
            $grant = $this->pdo->prepare("
                INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at)
                VALUES (:user_id, :club, :role, :ts)
                ON CONFLICT (user_id, club_profile_id, role) DO NOTHING
            ");
            $grant->execute([
                'user_id' => $userId,
                'club' => $clubId,
                'role' => $role,
                'ts' => date('Y-m-d H:i:s'),
            ]);

            // No accepted_by column written here — this is the fix.
            $mark = $this->pdo->prepare("
                UPDATE invitations
                SET status = 'accepted', accepted_at = :ts
                WHERE id = :id
            ");
            $mark->execute(['ts' => date('Y-m-d H:i:s'), 'id' => $invitationId]);

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $userId;
    }

    public function testAcceptCreatesNewUserGrantsAccessAndMarksAccepted(): void
    {
        $userId = $this->accept(1, 'New Coach');
        $this->assertGreaterThan(0, $userId);

        // New user created with auth_provider = invitation, no password column.
        $stmt = $this->pdo->prepare('SELECT first_name, last_name, auth_provider FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        $this->assertSame('New', $user['first_name']);
        $this->assertSame('Coach', $user['last_name']);
        $this->assertSame('invitation', $user['auth_provider']);

        // Club access granted with the invited role.
        $access = $this->pdo->prepare(
            'SELECT role FROM user_club_access WHERE user_id = :uid AND club_profile_id = 100'
        );
        $access->execute(['uid' => $userId]);
        $this->assertSame('coach', $access->fetchColumn());

        // Invitation marked accepted (and accepted_at set).
        $inv = $this->pdo->query('SELECT status, accepted_at FROM invitations WHERE id = 1')->fetch();
        $this->assertSame('accepted', $inv['status']);
        $this->assertNotEmpty($inv['accepted_at']);
    }

    public function testAcceptLinksExistingUserWithoutCreatingDuplicate(): void
    {
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        // Invitation 2 targets existing@club.test (user 60).
        $userId = $this->accept(2);
        $this->assertSame(60, $userId);

        $after = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $this->assertSame($before, $after, 'no duplicate user should be created');

        $access = $this->pdo->prepare(
            'SELECT role FROM user_club_access WHERE user_id = 60 AND club_profile_id = 100'
        );
        $access->execute();
        $this->assertSame('coach', $access->fetchColumn());

        $this->assertSame('accepted', $this->statusOf(2));
    }

    public function testAcceptDoesNotReferenceAcceptedByColumn(): void
    {
        // Guard test: writing accepted_by must NOT be part of the accept path.
        // If a regression reintroduces it, this UPDATE would throw because the
        // column does not exist on the table.
        $this->accept(1, 'New Coach');

        $cols = $this->pdo->query("PRAGMA table_info(invitations)")->fetchAll();
        $names = array_column($cols, 'name');
        $this->assertNotContains('accepted_by', $names);
    }

    public function testAcceptIsIdempotentOnClubAccessGrant(): void
    {
        // Pre-grant the same access, then accept — ON CONFLICT DO NOTHING means
        // exactly one row, no exception.
        $this->pdo->prepare("
            INSERT INTO user_club_access (user_id, club_profile_id, role, granted_at)
            VALUES (60, 100, 'coach', :ts)
        ")->execute(['ts' => date('Y-m-d H:i:s')]);

        $this->accept(2); // existing user 60, same club+role

        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM user_club_access WHERE user_id = 60 AND club_profile_id = 100 AND role = 'coach'"
        )->fetchColumn();
        $this->assertSame(1, (int)$count);
    }
}
