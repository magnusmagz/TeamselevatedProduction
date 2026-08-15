<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Athletes have NO login identity (decided 2026-08-15).
 *
 * This file previously guarded a narrower rule — that the athlete's auto-created
 * `users` row carried no password (the 2026-07-30 fix, migration 056, after 31
 * accounts shipped with `password_hash('defaultpass')` published in the source).
 * That closed the password door and left the magic-link one open, because
 * `send-magic-link` resolves purely by email and a passwordless account is still
 * an account.
 *
 * The account itself was the bug. A youth athlete's form carries the PARENT's
 * email, and `users.email` is unique, so the child's row OWNED that address and
 * the parent had none of their own. Signing in with their own email logged the
 * parent in AS THEIR CHILD, into a row with no club roles — which routed them to
 * the staff app. That is what CKU parents reported as "I saw the coach's portal".
 *
 * So te_create_athlete now creates no user row at all, and links to no existing
 * one. Nothing depends on these rows: `user_club_access` holds zero `player`
 * entries, and the chat server's lib/participants.js already documents that
 * `athletes.user_id` mostly points at a guardian and must never be read as "this
 * account is the child".
 *
 * If athletes are given accounts later, the identity must be the athlete's OWN
 * address and must be created deliberately — never as a side effect of saving a
 * roster record.
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
                email TEXT, phone TEXT,
                user_id INTEGER, club_id INTEGER, active_status INTEGER
            );
        ");

        require_once __DIR__ . '/../../lib/jersey_size.php';
        require_once __DIR__ . '/../../lib/athlete_writes.php';
    }

    /** The rule, stated plainly. */
    public function testCreatingAnAthleteWithAnEmailCreatesNoAccount(): void
    {
        $created = te_create_athlete($this->pdo, [
            'first_name' => 'Emmett',
            'last_name'  => 'Hart',
            'email'      => 'parent@example.com',
            'club_id'    => 51,
        ]);

        $this->assertNull($created['user_id'], 'athletes get no login identity');

        // The email is not discarded — it lands on the athlete row, where the form
        // reads it back from. Previously it was write-only into the users table.
        $stmt = $this->pdo->prepare('SELECT email FROM athletes WHERE id = ?');
        $stmt->execute([$created['athlete_id']]);
        $this->assertSame('parent@example.com', $stmt->fetchColumn());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn(),
            'The email on a youth athlete form is the PARENT\'s. Minting a users row on '
            . 'it gives the child that address, and the parent then signs in as their '
            . 'own child.'
        );
    }

    /**
     * Linking to an EXISTING account is worse than creating one: the account on a
     * youth athlete's email belongs to the parent, so athletes.user_id would point
     * at the parent and every "is this account the child" question answers wrong.
     */
    public function testAnExistingAccountOnThatEmailIsNotLinkedEither(): void
    {
        $this->pdo->exec(
            "INSERT INTO users (id, first_name, last_name, email, role)
             VALUES (7, 'Jess', 'Ziegler', 'parent@example.com', 'parent')"
        );

        $created = te_create_athlete($this->pdo, [
            'first_name' => 'Bonnie',
            'last_name'  => 'Ziegler',
            'email'      => 'parent@example.com',
        ]);

        $this->assertNull($created['user_id'], 'must not adopt the parent\'s account as the athlete\'s');

        $stmt = $this->pdo->prepare('SELECT user_id FROM athletes WHERE id = ?');
        $stmt->execute([$created['athlete_id']]);
        $this->assertNull($stmt->fetchColumn());
    }

    /**
     * The literal that shipped. Named explicitly so a reintroduction of the whole
     * account-minting block fails with an obvious message.
     * See migration 056_clear_default_player_passwords.sql.
     */
    public function testDefaultpassIsNeverAccepted(): void
    {
        te_create_athlete($this->pdo, [
            'first_name' => 'Sebastian',
            'last_name'  => 'Luns',
            'email'      => 'another-parent@example.com',
        ]);

        $hashes = $this->pdo->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($hashes as $hash) {
            $this->assertFalse(
                is_string($hash) && $hash !== '' && password_verify('defaultpass', $hash),
                "The literal 'defaultpass' is back in the athlete-create path."
            );
        }
        $this->assertSame([], $hashes, 'no account should exist at all');
    }

    /**
     * Two siblings on one household email was an early users_email_key crash. With
     * no account minted the collision cannot occur — kept as a guard because the
     * shared-email shape is what drove the original find-or-create.
     */
    public function testTwoSiblingsOnOneHouseholdEmailDoNotCollide(): void
    {
        $a = te_create_athlete($this->pdo, [
            'first_name' => 'Sibling', 'last_name' => 'One', 'email' => 'shared@example.com',
        ]);
        $b = te_create_athlete($this->pdo, [
            'first_name' => 'Sibling', 'last_name' => 'Two', 'email' => 'shared@example.com',
        ]);

        $this->assertNull($a['user_id']);
        $this->assertNull($b['user_id']);
        $this->assertNotSame($a['athlete_id'], $b['athlete_id']);
    }

    public function testNoEmailCreatesNoUser(): void
    {
        $created = te_create_athlete($this->pdo, [
            'first_name' => 'No', 'last_name' => 'Email',
        ]);

        $this->assertNull($created['user_id']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM users')->fetchColumn());
    }
}
