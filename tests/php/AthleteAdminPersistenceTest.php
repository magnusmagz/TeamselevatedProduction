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
 *      find-or-create-guardian + link flow) against an in-memory SQLite fixture,
 *      asserting the writes persist and re-read.
 *   2. AthleteScope::userCanAccessAthlete — the gate both endpoints call before
 *      writing — proving a club admin in-scope is allowed and an out-of-scope
 *      user is denied.
 *
 * Key behaviors locked in:
 *   - Editing name / DOB persists and re-reads (CA-20).
 *   - A guardian is linked with a relationship (CA-21).
 *   - Guardian lookup matches on email + first + last (composite), NOT email
 *     alone, so two people sharing one household email stay distinct (CA-21 /
 *     household shared-email model).
 *   - ⚠️ `is_primary` is NOT written. There is no primary guardian in this
 *     product (2026-09-02): crew members are equal, so there is no promotion to
 *     make and nobody to demote. This harness mirrors the gateway, so the
 *     demotion UPDATE that used to live here had to go with it — a simulation
 *     that keeps running logic the product removed is a test asserting fiction.
 *     `NoPrimaryGuardianTest` is what holds the real gateways to it.
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
                gender TEXT, club_id INTEGER,
                -- Mirrors athletes_jersey_size_check from migration 054. The CHECK
                -- has to be in the fixture, not just in Neon: the whole point of
                -- the jersey_size tests below is that an empty or bogus size does
                -- not violate it and take the athlete save down with it.
                jersey_size TEXT CHECK (jersey_size IS NULL OR jersey_size IN (
                    'YXS','YS','YM','YL','YXL','AXS','AS','AM','AL','AXL','A2XL','A3XL'
                ))
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
            CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE user_guardians (
                id INTEGER PRIMARY KEY, user_id INTEGER, guardian_id INTEGER,
                source TEXT, confidence TEXT
            );
        ");

        // Guardian standing is resolved from the ACCOUNT (lib/guardian_identity.php).
        $this->pdo->exec("INSERT INTO users (id, email) VALUES
            (60, 'admin@club.test'), (999, 'nobody@example.com')");

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
            'jersey_size' => 'jersey_size',
        ];
        $fields = [];
        $values = [];
        foreach ($mapping as $inKey => $col) {
            if (isset($input[$inKey])) {
                $fields[] = "$col = ?";
                $values[] = $inKey === 'jersey_size'
                    ? te_normalize_jersey_size($input[$inKey])
                    : $input[$inKey];
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

        $existing = $this->pdo->prepare(
            "SELECT id FROM athlete_guardians WHERE athlete_id = ? AND guardian_id = ?"
        );
        $existing->execute([$athleteId, $guardianId]);
        $link = $existing->fetch();

        if ($link) {
            $this->pdo->prepare(
                "UPDATE athlete_guardians SET relationship = ? WHERE id = ?"
            )->execute([$g['relationship_type'] ?? 'Guardian', $link['id']]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO athlete_guardians (athlete_id, guardian_id, relationship, can_pickup, emergency_contact)
                 VALUES (?, ?, ?, 1, 0)"
            )->execute([$athleteId, $guardianId, $g['relationship_type'] ?? 'Guardian']);
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

    // ---- CA-21: guardian link persistence, composite match ----

    /**
     * The link stores the relationship, and NOTHING about rank. An
     * `is_primary_contact` in the payload is ignored — an older deployed bundle
     * still sends it, and refusing an otherwise valid save over a key that no
     * longer means anything would be worse than dropping it.
     */
    public function testGuardianLinkedWithRelationshipAndNoPrimaryFlag(): void
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
        $this->assertNull($link['is_primary'], 'the column is left alone, not set to a value');
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

    /**
     * Adding a second crew member changes NOTHING about the first.
     *
     * This replaces testSettingNewPrimaryDemotesPrevious. Adding Jane used to
     * demote John — a write to a row the admin was not editing, triggered as a
     * side effect of adding someone else. With crew members equal there is
     * nothing to demote, and the assertion that matters is that John's link is
     * untouched.
     */
    public function testAddingASecondCrewMemberDoesNotTouchTheFirst(): void
    {
        $john = $this->linkGuardian(1, [
            'first_name' => 'John', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550001',
            'relationship_type' => 'Father', 'is_primary_contact' => true,
        ]);
        $this->linkGuardian(1, [
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '5550002',
            'relationship_type' => 'Mother', 'is_primary_contact' => true,
        ]);

        $rows = $this->pdo->query(
            "SELECT guardian_id, relationship, is_primary FROM athlete_guardians
             WHERE athlete_id = 1 ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame($john, (int) $rows[0]['guardian_id']);
        $this->assertSame('Father', $rows[0]['relationship']);
        $this->assertNull($rows[0]['is_primary'], "John's link is untouched by Jane being added");
        $this->assertNull($rows[1]['is_primary']);
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

    private function jerseySizeOf(int $athleteId): ?string
    {
        return $this->pdo->query("SELECT jersey_size FROM athletes WHERE id = $athleteId")
            ->fetch()['jersey_size'];
    }

    public function testJerseySizePersistsAndRereads(): void
    {
        $this->updateAthlete(1, ['jersey_size' => 'YM']);
        $this->assertSame('YM', $this->jerseySizeOf(1));

        // Re-sizing an athlete who outgrew their kit replaces, not appends.
        $this->updateAthlete(1, ['jersey_size' => 'AL']);
        $this->assertSame('AL', $this->jerseySizeOf(1));
    }

    /**
     * The athlete form submits every field it manages on every save, so an
     * athlete with no size on file sends jersey_size:''. Written raw that fails
     * athletes_jersey_size_check and rolls back the entire edit — name change and
     * all — while the UI reports success.
     */
    public function testEmptyJerseySizeStoresNullInsteadOfViolatingCheck(): void
    {
        $this->updateAthlete(1, ['first_name' => 'Samuel', 'jersey_size' => '']);

        $this->assertNull($this->jerseySizeOf(1));
        $this->assertSame('Samuel', $this->pdo->query("SELECT first_name FROM athletes WHERE id = 1")
            ->fetch()['first_name'], 'The rest of the edit must still persist');
    }

    /** Clearing a size back to "unknown" must be possible, not one-way. */
    public function testJerseySizeCanBeClearedOnceSet(): void
    {
        $this->updateAthlete(1, ['jersey_size' => 'YL']);
        $this->assertSame('YL', $this->jerseySizeOf(1));

        $this->updateAthlete(1, ['jersey_size' => '']);
        $this->assertNull($this->jerseySizeOf(1));
    }

    /** A size the frontend never offers must not reach the CHECK constraint. */
    public function testUnknownJerseySizeIsRejectedToNull(): void
    {
        $this->updateAthlete(1, ['jersey_size' => 'Large']);
        $this->assertNull($this->jerseySizeOf(1));

        // A bare 'M' is the exact ambiguity the Y/A prefix exists to prevent —
        // it must not be silently guessed into Youth or Adult Medium.
        $this->updateAthlete(1, ['jersey_size' => 'M']);
        $this->assertNull($this->jerseySizeOf(1));
    }

    public function testJerseySizeIsNormalizedCaseAndVendorAliases(): void
    {
        $this->updateAthlete(1, ['jersey_size' => 'ym']);
        $this->assertSame('YM', $this->jerseySizeOf(1));

        // 'AXXL' is how several vendors spell 2XL; accept it rather than drop it.
        $this->updateAthlete(1, ['jersey_size' => 'AXXL']);
        $this->assertSame('A2XL', $this->jerseySizeOf(1));

        $this->updateAthlete(1, ['jersey_size' => ' AL ']);
        $this->assertSame('AL', $this->jerseySizeOf(1));
    }

    /** An absent key means "leave it alone", not "clear it". */
    public function testOmittedJerseySizeLeavesExistingValueUntouched(): void
    {
        $this->updateAthlete(1, ['jersey_size' => 'YXL']);
        $this->updateAthlete(1, ['first_name' => 'Sammy']);

        $this->assertSame('YXL', $this->jerseySizeOf(1));
    }

    /** Every option the UI offers must satisfy the CHECK constraint. */
    public function testEveryOfferedSizeIsStorable(): void
    {
        foreach (TE_JERSEY_SIZES as $size) {
            $this->updateAthlete(1, ['jersey_size' => $size]);
            $this->assertSame($size, $this->jerseySizeOf(1), "$size must be storable");
        }
        $this->assertCount(12, TE_JERSEY_SIZES);
    }
}
