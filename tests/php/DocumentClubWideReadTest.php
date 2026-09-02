<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use AuthMiddleware;

/**
 * A document assigned club-wide is for that club's MEMBERS. te_document_user_can_read
 * used to grant it to any authenticated user of any club by id (2026-09-02).
 * Mutation: remove the membership clause from the 'club' branch.
 */
class DocumentClubWideReadTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/guardian_identity.php';
        require_once __DIR__ . '/../../lib/club_standing.php';
        require_once __DIR__ . '/../../lib/document_scope.php';
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE document_assignments (document_id INTEGER, target_type TEXT, target_id INTEGER)");
        $this->pdo->exec("CREATE TABLE user_club_access (user_id INTEGER, club_profile_id INTEGER, active INTEGER, revoked_at TEXT)");
        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)");
        $this->pdo->exec("CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT)");
        $this->pdo->exec("CREATE TABLE user_guardians (user_id INTEGER, guardian_id INTEGER)");
        $this->pdo->exec("CREATE TABLE athlete_guardians (athlete_id INTEGER, guardian_id INTEGER)");
        $this->pdo->exec("CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER)");
        $this->pdo->exec("CREATE TABLE teams (id INTEGER PRIMARY KEY, primary_coach_id INTEGER)");
        $this->pdo->exec("CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER)");
        $this->pdo->exec("INSERT INTO document_assignments VALUES (10, 'club', 51)");
        $this->pdo->exec("INSERT INTO users VALUES (1, 'coach51@x.com'), (2, 'stranger@x.com'), (3, 'parent51@x.com')");
        $this->pdo->exec("INSERT INTO user_club_access VALUES (1, 51, 1, NULL), (2, 32, 1, NULL)");
        $this->pdo->exec("INSERT INTO guardians VALUES (7, 'parent51@x.com')");
        $this->pdo->exec("INSERT INTO athlete_guardians VALUES (400, 7)");
        $this->pdo->exec("INSERT INTO athletes VALUES (400, 51)");
    }

    private function auth(int $userId): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => $userId, 'system_role' => 'user', 'roles' => []]);
    }

    private function doc(): array
    {
        return ['id' => 10, 'club_profile_id' => 51];
    }

    public function testAClubStaffMemberReadsAClubWideDocument(): void
    {
        $this->assertTrue(te_document_user_can_read($this->pdo, $this->auth(1), $this->doc()));
    }

    public function testAFamilyInTheClubReadsAClubWideDocument(): void
    {
        $this->assertTrue(te_document_user_can_read($this->pdo, $this->auth(3), $this->doc()));
    }

    public function testASignedInUserFromAnotherClubCannot(): void
    {
        $this->assertFalse(te_document_user_can_read($this->pdo, $this->auth(2), $this->doc()));
    }
}
