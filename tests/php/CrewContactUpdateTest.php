<?php

use PHPUnit\Framework\TestCase;

/**
 * Editing a crew member's own contact details from the club-wide Crew page.
 *
 * Until 2026-07-30 nothing in the product could change a guardian's name, email
 * or phone — `legacy/guardian-gateway.php` PUT only touched the relationship row,
 * and its POST branch found the guardian and moved on. That is fixed, but it is
 * athlete-scoped and resolves through an `athlete_guardians` link id the Crew
 * page does not have.
 *
 * This endpoint writes `guardians` once, club-scoped, because a person's phone
 * number belongs to the PERSON: a guardian with three athletes has one number,
 * and editing it from any of those rows must mean the same thing.
 *
 * These tests exercise the rules through the same SQL the endpoint runs. The
 * handler itself reads php://input and echoes, which is not reachable in-process;
 * what is worth pinning is the scope predicate and the NOT NULL contract, because
 * both fail destructively.
 */
class CrewContactUpdateTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 51;
    private const OTHER_CLUB = 32;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirrors live Neon: all four contact columns are NOT NULL.
        $this->pdo->exec("
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                mobile_phone TEXT NOT NULL
            );
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                club_id INTEGER, deleted_at TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE magic_link_tokens (id INTEGER PRIMARY KEY, email TEXT);
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO athletes (id, first_name, last_name, club_id, deleted_at) VALUES
            (1,'Jayce','Darrington',51,NULL),
            (2,'Idalie','Ramirez',51,NULL),
            (3,'Sibling','Darrington',51,NULL),
            (9,'Other','Kid',32,NULL),
            (4,'Gone','Kid',51,'2026-01-01')");

        $p->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1,'Cathy','Rice','crice70@yahoo.com','9165170661'),
            (2,'Multi','Parent','multi@example.com','7855550002'),
            (3,'Other','Club','otherclub@example.com','7855550003'),
            (4,'Ghost','Parent','ghost@example.com','7855550004')");

        // Guardian 2 has two athletes in the club — the one-person-one-number case.
        // Guardian 3 is in another club. Guardian 4's only athlete is soft-deleted.
        $p->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES
            (1,1,1), (2,2,2), (3,3,2), (9,9,3), (4,4,4)");

        $p->exec("INSERT INTO magic_link_tokens (id, email) VALUES
            (1,'crice70@yahoo.com:parent_invite')");
    }

    /** The endpoint's scope predicate, verbatim. */
    private function inScope(int $guardianId, int $clubId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT g.id FROM guardians g
            WHERE g.id = ?
              AND EXISTS (
                SELECT 1 FROM athlete_guardians ag
                JOIN athletes a ON a.id = ag.athlete_id
                WHERE ag.guardian_id = g.id AND a.club_id = ? AND a.deleted_at IS NULL
              )
            LIMIT 1
        ");
        $stmt->execute([$guardianId, $clubId]);
        return (bool) $stmt->fetchColumn();
    }

    private function update(int $id, array $f): void
    {
        $this->pdo->prepare(
            'UPDATE guardians SET first_name=?, last_name=?, email=?, mobile_phone=? WHERE id=?'
        )->execute([$f['first_name'], $f['last_name'], $f['email'], $f['mobile_phone'], $id]);
    }

    private function row(int $id): array
    {
        $s = $this->pdo->prepare('SELECT * FROM guardians WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    // ── Scope ────────────────────────────────────────────────────────────────
    public function testAGuardianInTheClubIsEditable(): void
    {
        $this->assertTrue($this->inScope(1, self::CLUB));
    }

    /**
     * Without this predicate, a club admin could edit ANY guardian on the platform
     * by guessing an id — the guardian id alone carries no club.
     */
    public function testAnotherClubsGuardianIsNotEditable(): void
    {
        $this->assertFalse($this->inScope(3, self::CLUB));
        $this->assertTrue($this->inScope(3, self::OTHER_CLUB), 'but their own club can');
    }

    public function testAGuardianWhoseOnlyAthleteIsDeletedIsNotEditable(): void
    {
        // They are no longer crew of an active athlete, so they are not on the
        // Crew page and must not be reachable through it either.
        $this->assertFalse($this->inScope(4, self::CLUB));
    }

    public function testAGuardianOfTwoAthletesResolvesOnce(): void
    {
        // Two links, one person — the scope check must not depend on which athlete
        // you happened to open them from.
        $this->assertTrue($this->inScope(2, self::CLUB));
    }

    // ── Writing ──────────────────────────────────────────────────────────────
    public function testEditingUpdatesThePersonNotTheRelationship(): void
    {
        $this->update(2, [
            'first_name' => 'Multi', 'last_name' => 'Parent',
            'email' => 'new@example.com', 'mobile_phone' => '7855559999',
        ]);

        $g = $this->row(2);
        $this->assertSame('new@example.com', $g['email']);
        $this->assertSame('7855559999', $g['mobile_phone']);

        // One row, so both athletes see the change. No per-link duplication.
        $count = $this->pdo->query("SELECT count(*) FROM guardians WHERE email='new@example.com'")->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    /**
     * All four columns are NOT NULL in Neon and 25 rows already carry ''. Writing
     * null throws; blank must therefore be an empty string.
     */
    public function testBlankContactIsStoredAsEmptyStringNotNull(): void
    {
        $this->update(1, [
            'first_name' => 'Cathy', 'last_name' => 'Rice',
            'email' => '', 'mobile_phone' => '',
        ]);

        $g = $this->row(1);
        $this->assertSame('', $g['email']);
        $this->assertSame('', $g['mobile_phone']);
    }

    public function testNullContactIsRejectedByTheDatabase(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->prepare('UPDATE guardians SET email = NULL WHERE id = ?')->execute([1]);
    }

    // ── The invite warning ───────────────────────────────────────────────────
    /**
     * Portal status is inferred by matching guardians.email to a users row, so a
     * new address silently detaches an invite sent to the old one. The admin is
     * warned rather than left to discover it from the status column later.
     */
    public function testChangingAnEmailThatHadAnInviteIsDetectable(): void
    {
        $before = $this->row(1)['email'];
        $stmt = $this->pdo->prepare('SELECT 1 FROM magic_link_tokens WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($before)) . ':parent_invite']);

        $this->assertTrue((bool) $stmt->fetchColumn(), 'Cathy had an invite on the old address');
    }

    public function testChangingAnEmailWithNoInviteWarnsNothing(): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM magic_link_tokens WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($this->row(2)['email']) . ':parent_invite']);

        $this->assertFalse((bool) $stmt->fetchColumn());
    }
}
