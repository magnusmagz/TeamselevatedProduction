<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * A parent's profile edit has to reach the record the club reads.
 *
 * `users` holds the login; `guardians` holds what the Crew page, every send and
 * every export use. `/parent/settings` wrote only the first, so a parent could
 * change their email and the club would keep the old one indefinitely.
 *
 * The hazard in fixing it is household addresses. Six production addresses are
 * held by two guardians each (John & Jane Jones, Morgan & Zach Powell, and four
 * more). Matching a user to their guardian row on email alone would rewrite BOTH
 * people's contact details when one of them changed theirs.
 */
class GuardianSyncTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE guardians (
                id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT
            );
        ");
        // A shared-household address, exactly as production holds it.
        $this->pdo->exec("INSERT INTO guardians (id, first_name, last_name, email, mobile_phone) VALUES
            (1, 'John', 'Jones', 'thejones@example.com', '555-0001'),
            (2, 'Jane', 'Jones', 'thejones@example.com', '555-0002'),
            (3, 'Solo', 'Parent', 'solo@example.com',    '555-0003')");
    }

    private function guardian(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM guardians WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function testMatchesTheRightPersonOnASharedAddress(): void
    {
        $rows = te_find_guardian_rows_for_user($this->pdo, [
            'email' => 'thejones@example.com', 'first_name' => 'John', 'last_name' => 'Jones',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['id']);
    }

    /** THE REGRESSION THIS GUARDS. Jane's record must not move when John edits his. */
    public function testUpdatingOneHouseholdMemberLeavesTheOtherAlone(): void
    {
        $sync = te_sync_guardian_contact(
            $this->pdo,
            ['email' => 'thejones@example.com', 'first_name' => 'John', 'last_name' => 'Jones'],
            ['email' => 'john.jones@newmail.com']
        );

        $this->assertSame(1, $sync['updated']);
        $this->assertSame([1], $sync['guardian_ids']);
        $this->assertTrue($sync['shared_email'], 'the old address was shared — worth recording');

        $this->assertSame('john.jones@newmail.com', $this->guardian(1)['email']);
        $this->assertSame('thejones@example.com', $this->guardian(2)['email'], "Jane's email must not move");
    }

    public function testPhoneMapsToMobilePhone(): void
    {
        te_sync_guardian_contact(
            $this->pdo,
            ['email' => 'solo@example.com', 'first_name' => 'Solo', 'last_name' => 'Parent'],
            ['phone' => '555-9999']
        );

        $this->assertSame('555-9999', $this->guardian(3)['mobile_phone']);
    }

    /** Only the submitted keys are written — a partial save cannot blank a field. */
    public function testOmittedFieldsAreLeftAlone(): void
    {
        te_sync_guardian_contact(
            $this->pdo,
            ['email' => 'solo@example.com', 'first_name' => 'Solo', 'last_name' => 'Parent'],
            ['email' => 'new@example.com']
        );

        $g = $this->guardian(3);
        $this->assertSame('new@example.com', $g['email']);
        $this->assertSame('555-0003', $g['mobile_phone']);
        $this->assertSame('Solo', $g['first_name']);
    }

    public function testRenameFollowsTheUser(): void
    {
        te_sync_guardian_contact(
            $this->pdo,
            ['email' => 'solo@example.com', 'first_name' => 'Solo', 'last_name' => 'Parent'],
            ['first_name' => 'Solomon', 'last_name' => 'Parenti']
        );

        $g = $this->guardian(3);
        $this->assertSame('Solomon', $g['first_name']);
        $this->assertSame('Parenti', $g['last_name']);
    }

    /**
     * A user with no matching guardian row is not an error — staff-only accounts
     * have none. It must report the miss so the audit entry records that the club
     * still holds nothing for them.
     */
    public function testNoMatchIsReportedNotThrown(): void
    {
        $sync = te_sync_guardian_contact(
            $this->pdo,
            ['email' => 'nobody@example.com', 'first_name' => 'No', 'last_name' => 'Body'],
            ['email' => 'x@example.com']
        );

        $this->assertSame(0, $sync['matched']);
        $this->assertSame(0, $sync['updated']);
        $this->assertSame([], $sync['guardian_ids']);
    }

    /** Matching is case- and whitespace-insensitive; stored data is inconsistent. */
    public function testMatchingToleratesCaseAndWhitespace(): void
    {
        $rows = te_find_guardian_rows_for_user($this->pdo, [
            'email' => '  TheJones@Example.COM ', 'first_name' => ' john ', 'last_name' => 'JONES',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['id']);
    }

    /** A blank email cannot identify anybody — 25 guardians have email = ''. */
    public function testBlankEmailMatchesNobody(): void
    {
        $this->assertSame([], te_find_guardian_rows_for_user($this->pdo, [
            'email' => '', 'first_name' => 'John', 'last_name' => 'Jones',
        ]));
    }

    /** The endpoint must audit the sync — that is how a dev team finds it later. */
    public function testProfileEndpointAuditsTheSync(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/user-profile.php');

        $this->assertStringContainsString('te_sync_guardian_contact(', $src);
        $this->assertStringContainsString('profile_guardian_synced', $src);
        $this->assertStringContainsString('profile_guardian_sync_no_match', $src);
        $this->assertStringContainsString('old_email_shared_with_others', $src);
    }
}
