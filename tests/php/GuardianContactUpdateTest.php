<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Editing a crew member's contact details must persist.
 *
 * Regression: updating a parent's phone number did nothing. legacy/guardian-gateway.php's
 * POST branch matched an existing guardian on email+first+last and then simply took
 * their id — it never wrote the submitted contact fields. The PUT branch only ever
 * touched `athlete_guardians` (relationship, is_primary, can_pickup,
 * emergency_contact). Across the whole backend the only `UPDATE guardians`
 * statements were sms_opt_out and last_contacted, from the Twilio webhook and the
 * send services. So no code path anywhere could change a guardian's name, email or
 * phone, and the request still returned success.
 *
 * These tests exercise the gateway's resolution + update logic against SQLite,
 * mirroring the live columns. They cover the three cases the gateway distinguishes:
 * edit by link id, match by identity, and genuinely new.
 */
class GuardianContactUpdateTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                mobile_phone TEXT NOT NULL,
                work_phone TEXT,
                city TEXT
            );
            CREATE TABLE athlete_guardians (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                athlete_id INTEGER,
                guardian_id INTEGER
            );'
        );
        $this->pdo->exec(
            "INSERT INTO guardians (id, first_name, last_name, email, mobile_phone, work_phone, city)
             VALUES (1, 'Jane', 'Jones', 'thejones@gmail.com', '555-0100', NULL, 'Salina')"
        );
        $this->pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (9, 42, 1)");
    }

    /**
     * The gateway's resolve-then-update logic, kept in step with the POST branch of
     * legacy/guardian-gateway.php.
     */
    private function saveGuardian(array $input, int $athleteId): int
    {
        $existingGuardian = null;

        $linkId = $input['id'] ?? null;
        if ($linkId) {
            $stmt = $this->pdo->prepare(
                'SELECT guardian_id FROM athlete_guardians WHERE id = ? AND athlete_id = ?'
            );
            $stmt->execute([$linkId, $athleteId]);
            $linked = $stmt->fetchColumn();
            if ($linked) {
                $existingGuardian = ['id' => $linked];
            }
        }

        if (!$existingGuardian) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM guardians WHERE email = ? AND first_name = ? AND last_name = ?'
            );
            $stmt->execute([$input['email'], $input['first_name'], $input['last_name']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $existingGuardian = $row ?: null;
        }

        if ($existingGuardian) {
            $guardianId = (int) $existingGuardian['id'];
            $setParts = [];
            $setValues = [];
            foreach (['first_name', 'last_name', 'email', 'mobile_phone', 'work_phone', 'city'] as $f) {
                if (!array_key_exists($f, $input)) {
                    continue;
                }
                $value = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
                if ($value === '' && $f !== 'mobile_phone') {
                    $value = null;
                }
                $setParts[] = "$f = ?";
                $setValues[] = $value;
            }
            if ($setParts) {
                $setValues[] = $guardianId;
                $this->pdo->prepare('UPDATE guardians SET ' . implode(', ', $setParts) . ' WHERE id = ?')
                    ->execute($setValues);
            }
            return $guardianId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO guardians (first_name, last_name, email, mobile_phone, work_phone)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $input['first_name'], $input['last_name'], $input['email'],
            $input['mobile_phone'], $input['work_phone'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function guardian(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM guardians WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function guardianCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM guardians')->fetchColumn();
    }

    /** The reported bug: change the phone, save, and it stays changed. */
    public function testPhoneNumberUpdatePersists(): void
    {
        $id = $this->saveGuardian([
            'id' => 9,
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '555-0199',
        ], 42);

        $this->assertSame(1, $id);
        $this->assertSame('555-0199', $this->guardian(1)['mobile_phone']);
        $this->assertSame(1, $this->guardianCount(), 'must update in place, not insert');
    }

    /** No link id — identity still matches, and the phone still has to stick. */
    public function testPhoneUpdatePersistsWhenMatchedByIdentityAlone(): void
    {
        $id = $this->saveGuardian([
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '555-0177',
        ], 42);

        $this->assertSame(1, $id);
        $this->assertSame('555-0177', $this->guardian(1)['mobile_phone']);
        $this->assertSame(1, $this->guardianCount());
    }

    /**
     * Identity matching cannot handle an edit TO the identity: a rename found
     * nothing, inserted a second guardian and left the first one attached. The
     * link id resolves it to the right row.
     */
    public function testRenameUpdatesInPlaceInsteadOfCreatingADuplicate(): void
    {
        $id = $this->saveGuardian([
            'id' => 9,
            'first_name' => 'Janet', 'last_name' => 'Jones-Smith',
            'email' => 'janet@gmail.com', 'mobile_phone' => '555-0100',
        ], 42);

        $this->assertSame(1, $id);
        $this->assertSame(1, $this->guardianCount());
        $row = $this->guardian(1);
        $this->assertSame('Janet', $row['first_name']);
        $this->assertSame('Jones-Smith', $row['last_name']);
        $this->assertSame('janet@gmail.com', $row['email']);
    }

    /** A partial payload must not blank the fields it never mentions. */
    public function testOmittedFieldsAreLeftAlone(): void
    {
        $this->saveGuardian([
            'id' => 9,
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '555-0111',
        ], 42);

        $this->assertSame('Salina', $this->guardian(1)['city'], 'city was not in the payload');
    }

    /** An empty optional field clears it; empty is "not recorded". */
    public function testEmptyOptionalFieldClearsToNull(): void
    {
        $this->pdo->exec("UPDATE guardians SET work_phone = '555-0900' WHERE id = 1");

        $this->saveGuardian([
            'id' => 9,
            'first_name' => 'Jane', 'last_name' => 'Jones',
            'email' => 'thejones@gmail.com', 'mobile_phone' => '555-0100',
            'work_phone' => '',
        ], 42);

        $this->assertNull($this->guardian(1)['work_phone']);
    }

    /** A link id belonging to a different athlete must not be honoured. */
    public function testLinkIdFromAnotherAthleteIsIgnored(): void
    {
        $before = $this->guardian(1)['mobile_phone'];

        $this->saveGuardian([
            'id' => 9, // link 9 belongs to athlete 42, not 77
            'first_name' => 'Someone', 'last_name' => 'Else',
            'email' => 'someone@example.com', 'mobile_phone' => '555-0000',
        ], 77);

        $this->assertSame($before, $this->guardian(1)['mobile_phone'], 'guardian 1 must be untouched');
        $this->assertSame(2, $this->guardianCount(), 'unmatched identity creates a new guardian');
    }

    /** A genuinely new crew member is still created. */
    public function testNewGuardianIsInserted(): void
    {
        $id = $this->saveGuardian([
            'first_name' => 'Chris', 'last_name' => 'Nolan',
            'email' => 'chris@example.com', 'mobile_phone' => '555-0300',
        ], 42);

        $this->assertGreaterThan(1, $id);
        $this->assertSame(2, $this->guardianCount());
        $this->assertSame('555-0300', $this->guardian($id)['mobile_phone']);
    }
}
