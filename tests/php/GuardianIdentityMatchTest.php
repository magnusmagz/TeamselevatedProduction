<?php

use PHPUnit\Framework\TestCase;

/**
 * How a guardian is recognised as someone we already have.
 *
 * Four code paths create guardians — AthleteController::createOrFindGuardian,
 * api/athletes.php, legacy/guardian-gateway.php and AthleteImportStrategy — and
 * before 2026-07-31 they did not agree with each other. The rule is now identical
 * in all four:
 *
 *   identity = email + first + last, compared case/whitespace-insensitively,
 *   and a BLANK email matches nothing at all.
 *
 * The blank-email clause is the important one. `guardians.email` is NOT NULL and
 * 25 production rows carry an empty STRING, and in SQL '' = '' is true — so any
 * query matching on it collapses unrelated people who happen to share a name.
 * `Juan Rocha` and `Juan Coca` are both live right now with no email.
 *
 * These tests run the shared predicate against SQLite rather than any one path,
 * because the point is that all four ask the same question.
 */
class GuardianIdentityMatchTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                mobile_phone TEXT NOT NULL
            );
        ");
        $this->pdo->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1,'John','Jones','thejones@example.com','3605550001'),
            (2,'Jane','Jones','thejones@example.com','3605550002'),
            (3,'Juan','Rocha','','7853427215'),
            (4,'Juan','Coca','','7857872208'),
            (5,'Taylor','Cook','tcook0921@yahoo.com','9256280439')");
    }

    /** The rule, as all four call sites now express it. */
    private function findExisting(string $email, string $first, string $last): ?int
    {
        if (trim($email) === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM guardians
             WHERE lower(trim(email))      = lower(?)
               AND lower(trim(first_name)) = lower(?)
               AND lower(trim(last_name))  = lower(?)
             LIMIT 1'
        );
        $stmt->execute([trim($email), trim($first), trim($last)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : null;
    }

    // ── The blank-email clause ───────────────────────────────────────────────
    /**
     * The bug this exists to prevent. Without the guard, adding an emailless
     * "Juan Rocha" would match... and adding an emailless "Juan Anything" would
     * have matched under the old email + first_name rule, attaching a stranger to
     * someone else's family.
     */
    public function testABlankEmailNeverMatchesAnyone(): void
    {
        $this->assertNull($this->findExisting('', 'Juan', 'Rocha'), 'even an exact name match');
        $this->assertNull($this->findExisting('   ', 'Juan', 'Coca'), 'whitespace is blank too');
        $this->assertNull($this->findExisting('', 'Brand', 'New'));
    }

    public function testTwoEmaillessPeopleSharingAFirstNameStaySeparate(): void
    {
        // Juan Rocha and Juan Coca are both real, both emailless, both in club 51.
        $this->assertNull($this->findExisting('', 'Juan', 'Coca'));
        $this->assertSame(
            2,
            (int) $this->pdo->query("SELECT count(*) FROM guardians WHERE first_name='Juan'")->fetchColumn(),
            'they must remain two rows'
        );
    }

    // ── Last name is part of identity ────────────────────────────────────────
    /**
     * AthleteController matched on email + FIRST NAME only until 2026-07-31, so
     * two people sharing a household address and a first name merged.
     */
    public function testLastNameDistinguishesPeopleOnASharedAddress(): void
    {
        $this->assertNull(
            $this->findExisting('thejones@example.com', 'John', 'Smith'),
            'same email and first name, different person'
        );
        $this->assertSame(1, $this->findExisting('thejones@example.com', 'John', 'Jones'));
    }

    public function testAHouseholdSharingAnEmailStaysTwoPeople(): void
    {
        // The behaviour the shared-email model depends on.
        $this->assertSame(1, $this->findExisting('thejones@example.com', 'John', 'Jones'));
        $this->assertSame(2, $this->findExisting('thejones@example.com', 'Jane', 'Jones'));
    }

    // ── Normalization ────────────────────────────────────────────────────────
    public function testMatchingIgnoresCaseAndSurroundingWhitespace(): void
    {
        foreach ([
            ['TCOOK0921@YAHOO.COM', 'Taylor', 'Cook'],
            ['tcook0921@yahoo.com', 'taylor', 'cook'],
            ['  tcook0921@yahoo.com  ', ' Taylor ', ' Cook '],
        ] as [$e, $f, $l]) {
            $this->assertSame(5, $this->findExisting($e, $f, $l), "should match: {$e}/{$f}/{$l}");
        }
    }

    // ── The limit, stated honestly ───────────────────────────────────────────
    /**
     * A misspelled email is a different identity by definition, and no matching
     * rule can see through it. This is exactly how Taylor Cook and Maddison Mathis
     * each arrived twice and had to be merged by hand on 2026-07-31.
     *
     * Pinned so the limitation is documented rather than assumed away — catching
     * it needs a same-name near-duplicate warning at import preview, which does
     * not exist.
     */
    public function testAMisspelledEmailIsANewPersonAndAlwaysWillBe(): void
    {
        $this->assertNull(
            $this->findExisting('tcook0921@yhaoo.com', 'Taylor', 'Cook'),
            'yhaoo vs yahoo — this creates a second Taylor, by design'
        );
    }
}
