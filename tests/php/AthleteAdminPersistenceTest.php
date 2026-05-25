<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteScope;

/**
 * CA-20 / CA-21 persistence + scope tests.
 *
 * The athlete-edit (legacy/athletes-gateway.php PUT) and guardian-link
 * (legacy/guardian-gateway.php POST) endpoints are procedural scripts that read
 * superglobals + php://input, so they can't be invoked as functions in a unit
 * test. Instead we exercise:
 *
 *   1. The exact persistence SQL those endpoints run (athlete UPDATE, and the
 *      find-or-create-guardian + link-with-primary-demotion flow) against an
 *      in-memory SQLite fixture, asserting the writes persist and re-read.
 *   2. AthleteScope::userCanAccessAthlete — the gate both endpoints call before
 *      writing — proving a club admin in-scope is allowed and an out-of-scope
 *      user is denied.
 *
 * Key behaviors locked in:
 *   - Editing name / DOB persists and re-reads (CA-20).
 *   - A guardian is linked with relationship + is_primary (CA-21).
 *   - Guardian lookup matches on email + first + last (composite), NOT email
 *     alone, so two people sharing one household email stay distinct (CA-21 /
 *     household shared-email model).
 *   - Setting a new primary demotes the previous primary (exactly one primary).
 *
 * Never touches production Neon.
 */
class AthleteAdminPersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT
            );
            CREATE TABLE team_members (
                id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                athlete_id INTEGER, role TEXT, status TEXT
            );
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT, last_name TEXT, date_of_birth TEXT,
                gender TEXT, club_id INTEGER
            );
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT, last_name TEXT, email TEXT, mobile_phone TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                athlete_id INTEGER, guardian_id INTEGER, relationship TEXT,
                is_primary INTEGER, can_pickup INTEGER, emergency_contact INTEGER
            );
        ");

        // Athlete 1 belongs to club 100 (admin's club).
        $this->pdo->exec("INSERT INTO athletes (id, first_name, last_name, date_of_birth, gender, club_id)
            VALUES (1, 'Sam', 'Stone', '2012-05-01', 'Male', 100)");
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id, deleted_at)
            VALUES (10, 'Team A', 100, 50, NULL)");
        $this->pdo->exec("INSERT INTO team_members (id, team_id, user_id, athlete_id, role, status)
            VALUES (1, 10, NULL, 1, 'player', 'active')");
    }

    private function clubAdmin(int $clubId): AuthMiddleware
    {
        return AuthMiddleware::fromContext([
            'user_id' => 60,
            'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => $clubId]],
        ]);
    }

    private function unrelated(): AuthMiddleware
    {
        return AuthMiddleware::fromContext(['user_id' => 999, 'email' => 'nobody@example.com', 'roles' => []]);
    }

    /**
     * Mirror of legacy/athletes-gateway.php PUT field-mapping persistence.
     */
    private function updateAthlete(int $id, array $input): void
    {
        $mapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'date_of_birth' => 'date_of_birth',
            'gender' => 'gender',
        ];
        $fields = [];
        $values = [];
        foreach ($mapping as $inKey => $col) {
            if (isset($input[$inKey])) {
                $fields[] = "$col = ?";
                $values[] = $input[$inKey];
            }
        }
        $values[] = $id;
        $stmt = $this->pdo->prepare("UPDATE athletes SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
    }

    /**
     * Mirror of legacy/guardian-gateway.php POST: composite find-or-create +
     * link with primary demotion.
     */
    private function linkGuardian(int $athleteId, array $g): int
    {
        $find = $this->pdo->prepare(
            "SELECT id FROM guardians WHERE email = ? AND first_name = ? AND last_name = ?"
        );
        $find->execute([$g['email'], $g['first_name'], $g['last_name']]);
        $row = $find->fetch();

        if ($row) {
            $guardianId = (int) $row['id'];
        } else {
            $ins = $this->pdo->prepare(
                "INSERT INTO guardians (first_name, last_name, email, mobile_phone) VALUES (?, ?, ?, ?)"
            );
            $ins->execute([$g['first_name'], $g['last_name'], $g['email'], $g['mobile_phone'] ?? null]);
            $guardianId = (int) $this->pdo->lastInsertId();
        }

        $isPrimary = !empty($g['is_primary_contact']) ? 1 : 0;
        if ($isPrimary) {
            $this->pdo->prepare(
                "UPDATE athlete_guardians SET is_primary = 0 WHERE athlete_id = ? AND is_primary = 1"
            )->execute([$athleteId]);
        }

        $existing = $this->pdo->prepare(
            "SELECT id FROM athlete_guardians WHERE athlete_id = ? AND guardian_id = ?"
        );
        $existing->execute([$athleteId, $guardianId]);
        $link = $existing->fetch();

        if ($link) {
            $this->pdo->prepare(
                "UPDATE athlete_guardians SET relationship = ?, is_primary = ? WHERE id = ?"
            )->execute([$g['relationship_type'] ?? 'Guardian', $isPrimary, $link['id']]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO athlete_guardians (athlete_id, guardian_id, relationship, is_primary, can_pickup, emergency_contact)
                 VALUES (?, ?, ?, ?, 1, 0)"
            )->execute([$athleteId, $guardianId, $g['relationship_type'] ?? 'Guardian', $isPrimary]);
        }

        return $guardianId;
    }

    // ---- CA-20: edit persistence + scope ----

    public function testClubAdminIsInScopeToEdit(): void
    {
        $this->assertTrue(
            AthleteScope::userCanAccessAthlete($this->pdo, $this->clubAdmin(100), 1)
        );
    }

    public function testOutOfScopeUserCannotEdit(): void
    {
        $this->assertFalse(
            AthleteScope::userCanAccessAthlete($this->pdo, $this->unrelated(), 1)
        );
    }

    public function testEditNameAndDobPersistsAndRereads(): void
    {
        $this->updateAthlete(1, [
            'first_name' => 'Samuel',
            'last_name' => 'Stonebridge',
            'date_of_birth' => '2011-09-09',
        ]);

        $row = $this->pdo->query("SELECT first_name, last_name, date_of_birth, gender FROM athletes WHERE id = 1")->fetch();
        $this->assertSame('Samuel', $row['first_name']);
        $this->assertSame('Stonebridge', $row['last_name']);
        $this->assertSame('2011-09-09', $row['date_of_birth']);
        // Untouched field is preserved (PUT only updates supplied fields).
        $this->assertSame('Male', $row['gender']);
    }

    // ---- CA-21: guardian link persistence, composite match, primary ----

    public function testGuardianLinkedWithRelationshipAndPrimary(): void
    {
        $gid = $this->linkGuardian(1, [
            'first_name' => 'John', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550001',
            'relationship_type' => 'Father', 'is_primary_contact' => true,
        ]);

        $link = $this->pdo->query(
            "SELECT relationship, is_primary FROM athlete_guardians WHERE athlete_id = 1 AND guardian_id = $gid"
        )->fetch();
        $this->assertSame('Father', $link['relationship']);
        $this->assertSame(1, (int) $link['is_primary']);
    }

    public function testSharedEmailKeepsTwoDistinctGuardians(): void
    {
        // John and Jane share thejones@gmail.com — they must remain two rows.
        $john = $this->linkGuardian(1, [
            'first_name' => 'John', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550001',
            'relationship_type' => 'Father', 'is_primary_contact' => true,
        ]);
        $jane = $this->linkGuardian(1, [
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550002',
            'relationship_type' => 'Mother', 'is_primary_contact' => false,
        ]);

        $this->assertNotSame($john, $jane, 'Composite match must not merge the two guardians');

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) AS c FROM guardians WHERE email = 'thejones@gmail.com'"
        )->fetch()['c'];
        $this->assertSame(2, $count);

        $links = (int) $this->pdo->query(
            "SELECT COUNT(*) AS c FROM athlete_guardians WHERE athlete_id = 1"
        )->fetch()['c'];
        $this->assertSame(2, $links);
    }

    public function testSettingNewPrimaryDemotesPrevious(): void
    {
        $john = $this->linkGuardian(1, [
            'first_name' => 'John', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550001',
            'relationship_type' => 'Father', 'is_primary_contact' => true,
        ]);
        // Jane added as the new primary -> John must be demoted.
        $this->linkGuardian(1, [
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550002',
            'relationship_type' => 'Mother', 'is_primary_contact' => true,
        ]);

        $primaries = (int) $this->pdo->query(
            "SELECT COUNT(*) AS c FROM athlete_guardians WHERE athlete_id = 1 AND is_primary = 1"
        )->fetch()['c'];
        $this->assertSame(1, $primaries, 'Exactly one primary guardian must remain');

        $johnPrimary = (int) $this->pdo->query(
            "SELECT is_primary FROM athlete_guardians WHERE athlete_id = 1 AND guardian_id = $john"
        )->fetch()['is_primary'];
        $this->assertSame(0, $johnPrimary);
    }

    public function testRelinkingSameGuardianDoesNotDuplicate(): void
    {
        $g = [
            'first_name' => 'John', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550001',
            'relationship_type' => 'Father', 'is_primary_contact' => true,
        ];
        $this->linkGuardian(1, $g);
        $this->linkGuardian(1, $g);

        $links = (int) $this->pdo->query(
            "SELECT COUNT(*) AS c FROM athlete_guardians WHERE athlete_id = 1"
        )->fetch()['c'];
        $this->assertSame(1, $links);
    }
}
