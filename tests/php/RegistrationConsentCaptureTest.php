<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Consent captured on the public registration form.
 *
 * The bug: PublicRegistrationForm has always sent `consent_data_collection` and
 * `consent_medical_data`, and registrations-api.php never read either. A real
 * parent's real agreement was discarded at the point of collection, so a family
 * that registered and never opened the parent portal had NO consent record at all.
 *
 * Two things here are easy to get wrong and are therefore pinned:
 *
 * 1. THE FLAGS ARE TOP-LEVEL, beside `form_data`, not inside it. Reading them
 *    from form_data finds nothing and records nothing — silently, which is the
 *    exact failure being fixed. `testFlagsAreReadFromTheTopLevel` fails if someone
 *    "tidies" the lookup into form_data.
 * 2. guardian_id is NULL here. The consenting adult has no account at sign-up;
 *    migration 063 relaxed the NOT NULL and added guardian_email/guardian_name to
 *    carry the identity as frozen evidence.
 */
class RegistrationConsentCaptureTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors consent_records after migration 063: guardian_id nullable,
        // source/guardian_email/guardian_name present, consent_type CHECKed.
        $this->pdo->exec("
            CREATE TABLE consent_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                guardian_id INTEGER NULL,
                athlete_id INTEGER NOT NULL,
                consent_type TEXT NOT NULL CHECK (consent_type IN
                    ('data_collection','medical_data','emergency_treatment','tos_privacy')),
                consent_given BOOLEAN NOT NULL DEFAULT 0,
                consented_at TEXT,
                ip_address TEXT,
                user_agent TEXT,
                consent_version TEXT,
                confirmation_token TEXT,
                email_sent_at TEXT,
                email_confirmed_at TEXT,
                revoked_at TEXT,
                source TEXT CHECK (source IS NULL OR source IN ('registration','portal','staff')),
                guardian_email TEXT,
                guardian_name TEXT
            );
        ");
    }

    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM consent_records ORDER BY id')->fetchAll();
    }

    /** The payload shape the public form actually sends. */
    private function payload(bool $data = true, bool $medical = true): array
    {
        return [
            'program_id' => 3,
            'form_data' => [
                'guardian_email' => 'alice@family-a.com',
                'guardian_first' => 'Alice',
                'guardian_last' => 'Aaron',
                'athlete_first' => 'Anna',
            ],
            'consent_data_collection' => $data,
            'consent_medical_data' => $medical,
        ];
    }

    public function testFlagsAreReadFromTheTopLevel(): void
    {
        $this->assertSame(
            ['data_collection', 'medical_data'],
            te_consent_types_from_registration($this->payload())
        );

        // The same flags nested inside form_data must NOT resolve — that is the
        // shape mistake that would silently record nothing.
        $nested = ['form_data' => [
            'consent_data_collection' => true,
            'consent_medical_data' => true,
        ]];
        $this->assertSame([], te_consent_types_from_registration($nested));
    }

    public function testOnlyAffirmativeAnswersAreReturned(): void
    {
        $this->assertSame(['data_collection'], te_consent_types_from_registration($this->payload(true, false)));
        $this->assertSame(['medical_data'], te_consent_types_from_registration($this->payload(false, true)));
        $this->assertSame([], te_consent_types_from_registration($this->payload(false, false)));
    }

    public function testRecordsOneRowPerConsentTypeWithFrozenIdentity(): void
    {
        $written = te_record_registration_consent(
            $this->pdo, 7, 'alice@family-a.com', 'Alice Aaron',
            ['data_collection', 'medical_data'], '203.0.113.9', 'Mozilla/5.0'
        );

        $this->assertSame(2, $written);
        $rows = $this->rows();
        $this->assertCount(2, $rows);

        foreach ($rows as $r) {
            $this->assertNull($r['guardian_id'], 'no account exists at public registration');
            $this->assertSame(7, (int) $r['athlete_id']);
            $this->assertSame('registration', $r['source']);
            $this->assertSame('alice@family-a.com', $r['guardian_email']);
            $this->assertSame('Alice Aaron', $r['guardian_name']);
            $this->assertSame(TE_CONSENT_VERSION, $r['consent_version']);
            $this->assertSame('203.0.113.9', $r['ip_address']);
            $this->assertTrue((bool) $r['consent_given']);
        }

        $this->assertSame(
            ['data_collection', 'medical_data'],
            array_column($rows, 'consent_type')
        );
    }

    /**
     * A family registering a second program for the same child must not pile up
     * duplicate consent rows. The earliest agreement is the meaningful artifact.
     */
    public function testReRegisteringTheSameChildDoesNotDuplicate(): void
    {
        $types = ['data_collection', 'medical_data'];
        te_record_registration_consent($this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', $types);
        $second = te_record_registration_consent($this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', $types);

        $this->assertSame(0, $second);
        $this->assertCount(2, $this->rows());
    }

    /** A sibling is a different child, so they get their own rows. */
    public function testASiblingGetsTheirOwnRecords(): void
    {
        $types = ['data_collection', 'medical_data'];
        te_record_registration_consent($this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', $types);
        te_record_registration_consent($this->pdo, 8, 'alice@family-a.com', 'Alice Aaron', $types);

        $this->assertCount(4, $this->rows());
        $this->assertSame([7, 7, 8, 8], array_map('intval', array_column($this->rows(), 'athlete_id')));
    }

    /** A withdrawn consent must not block a fresh one from being recorded. */
    public function testARevokedConsentDoesNotSuppressANewOne(): void
    {
        te_record_registration_consent($this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', ['data_collection']);
        $this->pdo->exec("UPDATE consent_records SET revoked_at = '2026-07-31' WHERE id = 1");

        $written = te_record_registration_consent(
            $this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', ['data_collection']
        );

        $this->assertSame(1, $written);
        $this->assertCount(2, $this->rows());
    }

    public function testNothingIsWrittenWhenNoConsentWasGiven(): void
    {
        $this->assertSame(
            0,
            te_record_registration_consent($this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', [])
        );
        $this->assertCount(0, $this->rows());
    }

    /**
     * THE DEPLOY-ORDER GUARD. `main` is shared and deploys are by push, so this
     * code can reach production before migration 063 is applied. On Postgres a
     * failed statement poisons the enclosing transaction, so an unguarded insert
     * against a missing column would roll back the FAMILY'S REGISTRATION. The
     * savepoint wrapper must swallow that and let the registration stand.
     */
    public function testAFailedCaptureDoesNotTakeDownTheRegistration(): void
    {
        // Simulate the pre-migration schema: no source/guardian_email columns.
        $this->pdo->exec('DROP TABLE consent_records');
        $this->pdo->exec("
            CREATE TABLE consent_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                guardian_id INTEGER NOT NULL,
                athlete_id INTEGER NOT NULL,
                consent_type TEXT NOT NULL
            );
        ");
        $this->pdo->exec('CREATE TABLE registrations (id INTEGER PRIMARY KEY, athlete_id INTEGER)');

        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO registrations (id, athlete_id) VALUES (1, 7)');

        $written = te_record_registration_consent_safely(
            $this->pdo, 7, 'alice@family-a.com', 'Alice Aaron', ['data_collection']
        );
        $this->pdo->commit();

        $this->assertSame(0, $written, 'capture failed, as expected on the old schema');
        $this->assertCount(
            1,
            $this->pdo->query('SELECT * FROM registrations')->fetchAll(),
            'the registration must survive a consent-capture failure'
        );
    }
}
