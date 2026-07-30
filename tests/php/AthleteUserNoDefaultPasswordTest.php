<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Regression guard for the 2026-07-30 "crew says invited when they weren't" bug.
 *
 * te_create_athlete() used to seed the athlete's linked users row with
 * password_hash('defaultpass', PASSWORD_DEFAULT) — a constant literal in the source.
 * That broke two things at once:
 *
 *   1. Security. handlePasswordLogin does a plain password_verify with no extra gate,
 *      so every one of those rows was a live account whose password was published in
 *      the repo. A youth athlete's form carries the PARENT's email, so the credential
 *      landed on a real adult's address — 14 of them in club 51 before the fix.
 *
 *   2. Correctness. handleClubParents / handleParentPortalStatus define portal state as
 *      "a users row for this email has a password_hash", joined on email ALONE. The
 *      child's auto-created account therefore made the parent read as "active", i.e.
 *      as having accepted an invite nobody ever sent them.
 *
 * Migration 056 cleared the 31 affected production rows. This test stops the generating
 * bug from being reintroduced: an auto-created player user must have NO password. Portal
 * access for athletes goes through the invite / magic-link path, same as guardians.
 *
 * Runs entirely against in-memory SQLite — never touches production Neon.
 */
class AthleteUserNoDefaultPasswordTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors the live Neon shape for the columns te_create_athlete touches
        // (tests/fixtures/production-schema.json is authoritative).
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT UNIQUE, password_hash TEXT, role TEXT,
                auth_provider TEXT DEFAULT 'magic_link', last_login_at TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY, first_name TEXT, middle_initial TEXT,
                last_name TEXT, preferred_name TEXT, date_of_birth TEXT, gender TEXT,
                home_address_line1 TEXT, city TEXT, state TEXT, zip_code TEXT,
                school_name TEXT, grade_level TEXT, jersey_size TEXT,
                user_id INTEGER, club_id INTEGER, active_status INTEGER
            );
        ");

        require_once __DIR__ . '/../../lib/jersey_size.php';
        require_once __DIR__ . '/../../lib/athlete_writes.php';
    }

    public function testAutoCreatedPlayerUserHasNoPassword(): void
    {
        $created = te_create_athlete($this->pdo, [
            'first_name' => 'Emmett',
            'last_name'  => 'Hart',
            'email'      => 'parent@example.com',
            'club_id'    => 51,
        ]);

        $this->assertNotNull($created['user_id'], 'an email should still link a user row');

        $stmt = $this->pdo->prepare('SELECT role, password_hash FROM users WHERE id = ?');
        $stmt->execute([$created['user_id']]);
        $user = $stmt->fetch();

        $this->assertSame('player', $user['role']);
        $this->assertNull(
            $user['password_hash'],
            'Auto-created athlete accounts must have no password. A non-null hash here is '
            . 'both a live credential on the parent\'s email address and the thing that '
            . 'makes the crew page report an invite that was never sent.'
        );
    }

    /**
     * The specific value that shipped. Named explicitly so a reintroduction fails with
     * an obvious message rather than a generic null assertion.
     */
    public function testDefaultpassIsNeverAccepted(): void
    {
        $created = te_create_athlete($this->pdo, [
            'first_name' => 'Sebastian',
            'last_name'  => 'Luns',
            'email'      => 'another-parent@example.com',
        ]);

        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$created['user_id']]);
        $hash = $stmt->fetchColumn();

        $this->assertFalse(
            is_string($hash) && $hash !== '' && password_verify('defaultpass', $hash),
            "The literal 'defaultpass' is back in the athlete-create path. See migration "
            . '056_clear_default_player_passwords.sql for why that is not survivable.'
        );
    }

    /**
     * The find-or-create half must keep working: a second athlete on the same email
     * reuses the existing user rather than tripping users_email_key. That was an
     * earlier prod crash, and the fix must not regress it.
     */
    public function testSecondAthleteOnSameEmailReusesUser(): void
    {
        $a = te_create_athlete($this->pdo, [
            'first_name' => 'Sibling', 'last_name' => 'One', 'email' => 'shared@example.com',
        ]);
        $b = te_create_athlete($this->pdo, [
            'first_name' => 'Sibling', 'last_name' => 'Two', 'email' => 'shared@example.com',
        ]);

        $this->assertSame($a['user_id'], $b['user_id']);
        $this->assertNotSame($a['athlete_id'], $b['athlete_id']);
    }

    /**
     * No email means no user row at all — not a row with a blank email, which would
     * collide on the second athlete.
     */
    public function testNoEmailCreatesNoUser(): void
    {
        $created = te_create_athlete($this->pdo, [
            'first_name' => 'No', 'last_name' => 'Email',
        ]);

        $this->assertNull($created['user_id']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
    }
}
