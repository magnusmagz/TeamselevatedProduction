<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * `users.email` is UNIQUE, so there is exactly one account per address — and
 * until 2026-07-30 te_create_athlete() created the athlete's linked user row on
 * whatever email the athlete form carried. For a youth athlete that is the
 * PARENT's email, so the child's shell account holds the parent's address.
 *
 * parentInvite_ensureUserAndToken() looks a user up by the guardian's email and
 * would therefore find the child. Reusing that row hands the parent a login named
 * after their kid, with users.role='player', still pointed at by athletes.user_id
 * — one account for two people. (Before the password cleanup the same collision
 * failed more quietly: the shell carried a 'defaultpass' hash, so the function
 * returned 'already_active' and the invite was never sent at all. That is what
 * made 14 Central Kansas guardians display as active in the Crew page.)
 *
 * A second users row cannot be created for the address, so the fix repairs the
 * linkage: detach the athlete, rename the row to the guardian. These tests pin
 * both that behavior and the boundary that keeps it safe — a row with a password
 * is someone's real account and must never be reclaimed.
 *
 * Runs entirely against in-memory SQLite — never touches production Neon.
 */
class ParentInviteReclaimTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Postgres NOW(); SQLite has no such function (same shim as AuditLoggerTest).
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);

        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE,
                first_name TEXT, last_name TEXT, password_hash TEXT,
                role TEXT, auth_provider TEXT, created_at TEXT, updated_at TEXT
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, user_id INTEGER
            );
            CREATE TABLE user_club_access (
                id INTEGER PRIMARY KEY, user_id INTEGER, club_profile_id INTEGER,
                role TEXT, active INTEGER, granted_at TEXT, revoked_at TEXT, revoked_by INTEGER,
                UNIQUE (user_id, club_profile_id, role)
            );
            CREATE TABLE magic_link_tokens (
                id INTEGER PRIMARY KEY, email TEXT, token TEXT,
                expires_at TEXT, used_at TEXT, created_at TEXT
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, resource_type TEXT,
                resource_id INTEGER, ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
            );
        ");

        // Kelsey Hart, guardian. Her son Emmett's shell account holds her email.
        $this->pdo->exec("
            INSERT INTO guardians (id, email, first_name, last_name)
            VALUES (1, 'kelseyadams64@hotmail.com', 'Kelsey', 'Hart');
        ");

        require_once __DIR__ . '/../../lib/ParentInvite.php';
    }

    private function seedAthleteShell(?string $passwordHash = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (id, email, first_name, last_name, password_hash, role, auth_provider)
            VALUES (169, 'kelseyadams64@hotmail.com', 'Emmett', 'Hart', ?, 'player', 'magic_link')
        ");
        $stmt->execute([$passwordHash]);

        $this->pdo->exec("
            INSERT INTO athletes (id, first_name, last_name, user_id)
            VALUES (433, 'Emmett', 'Hart', 169);
        ");
    }

    public function testAthleteShellIsReclaimedForTheGuardian(): void
    {
        $this->seedAthleteShell();

        $result = parentInvite_ensureUserAndToken($this->pdo, 1, 51);

        $this->assertSame('invited', $result['status']);
        $this->assertSame(169, $result['user_id'], 'the address has only one possible account');

        $user = $this->pdo->query('SELECT * FROM users WHERE id = 169')->fetch();
        $this->assertSame('Kelsey', $user['first_name'], 'the account must belong to the guardian');
        $this->assertSame('Hart', $user['last_name']);
        $this->assertSame('parent', $user['role']);

        $athlete = $this->pdo->query('SELECT user_id FROM athletes WHERE id = 433')->fetch();
        $this->assertNull($athlete['user_id'], 'the athlete must be detached, not left sharing the login');
    }

    /**
     * The boundary that makes the reclaim safe. A row with a password is a real
     * account someone uses; the function must return already_active long before
     * any repair logic, so a live login can never be renamed out from under its
     * owner. If this ever fails, the reclaim has become an account-takeover.
     */
    public function testRowWithAPasswordIsNeverReclaimed(): void
    {
        $this->seedAthleteShell(password_hash('a-real-password', PASSWORD_DEFAULT));

        $result = parentInvite_ensureUserAndToken($this->pdo, 1, 51);

        $this->assertSame('already_active', $result['status']);

        $user = $this->pdo->query('SELECT * FROM users WHERE id = 169')->fetch();
        $this->assertSame('Emmett', $user['first_name'], 'a live account must not be renamed');
        $this->assertSame('player', $user['role']);

        $athlete = $this->pdo->query('SELECT user_id FROM athletes WHERE id = 433')->fetch();
        $this->assertSame(169, (int)$athlete['user_id'], 'a live account must not be detached');
    }

    /** The ordinary path: nobody holds the address, so a fresh parent shell is created. */
    public function testNoExistingUserCreatesAParentAccount(): void
    {
        $result = parentInvite_ensureUserAndToken($this->pdo, 1, 51);

        $this->assertSame('invited', $result['status']);

        $user = $this->pdo->query('SELECT * FROM users WHERE email = \'kelseyadams64@hotmail.com\'')->fetch();
        $this->assertSame('Kelsey', $user['first_name']);
        $this->assertSame('parent', $user['role']);
        $this->assertNull($user['password_hash']);
    }

    /**
     * A password-less account that no athlete points at is a normal parent shell
     * from an earlier invite. It must be reused as-is, not "repaired".
     */
    public function testPasswordlessNonAthleteAccountIsReusedUntouched(): void
    {
        $this->pdo->exec("
            INSERT INTO users (id, email, first_name, last_name, role, auth_provider)
            VALUES (200, 'kelseyadams64@hotmail.com', 'Kelsey', 'Hart', 'parent', 'invitation');
        ");

        $result = parentInvite_ensureUserAndToken($this->pdo, 1, 51);

        $this->assertSame('invited', $result['status']);
        $this->assertSame(200, $result['user_id']);
        $this->assertSame(
            0,
            (int)$this->pdo->query("SELECT count(*) FROM audit_log WHERE action = 'parent_invite_reclaimed_athlete_shell'")->fetchColumn(),
            'nothing was squatting, so nothing should be logged as reclaimed'
        );
    }

    /** The repair is an identity mutation, so it must leave an audit trail. */
    public function testReclaimIsAudited(): void
    {
        $this->seedAthleteShell();

        parentInvite_ensureUserAndToken($this->pdo, 1, 51);

        $row = $this->pdo->query("
            SELECT * FROM audit_log WHERE action = 'parent_invite_reclaimed_athlete_shell'
        ")->fetch();

        $this->assertNotFalse($row, 'the reclaim must be audited');
        $this->assertSame(169, (int)$row['resource_id']);

        $details = json_decode($row['details'], true);
        $this->assertSame([433], $details['detached_athlete_ids']);
        $this->assertSame(1, $details['guardian_id']);
    }

    /** The invite itself must still work end to end after a reclaim. */
    public function testReclaimStillGrantsParentAccessAndMintsAToken(): void
    {
        $this->seedAthleteShell();

        $result = parentInvite_ensureUserAndToken($this->pdo, 1, 51);

        $access = $this->pdo->query('SELECT * FROM user_club_access WHERE user_id = 169')->fetch();
        $this->assertSame('parent', $access['role']);
        $this->assertSame(51, (int)$access['club_profile_id']);

        $token = $this->pdo->query("
            SELECT * FROM magic_link_tokens
            WHERE email = 'kelseyadams64@hotmail.com:parent_invite'
        ")->fetch();
        $this->assertNotFalse($token);
        $this->assertSame($result['token'], $token['token']);
    }
}
