<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../lib/athlete_medical.php';

/**
 * te_normalize_athlete_medical_values() — the one definition of a value the
 * athlete_medical columns will accept.
 *
 * Regression: saving an athlete whose medical record already existed but who had
 * no physical date on file failed with
 *   SQLSTATE[22007] Invalid datetime format: 7 ERROR: invalid input syntax for type date: ""
 * and the form reported "medical information could not be saved". AthleteForm
 * initialises every medical date to '', legacy/medical-gateway.php bound it with
 * isset() (true for ''), and it went straight into a DATE column.
 *
 * The numeric half of the same bug had been patched in the browser — AthleteForm
 * converts height/weight to null — which is why the date half survived. The guard
 * belongs on the server, where every caller passes through it.
 */
class AthleteMedicalValuesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirrors the live athlete_medical columns under test. SQLite will not
        // reject '' in a DATE column the way Postgres does, so the assertions below
        // check the normalized VALUE rather than relying on the driver to complain.
        $this->pdo->exec(
            'CREATE TABLE athlete_medical (
                athlete_id INTEGER PRIMARY KEY,
                last_physical_date DATE,
                physical_expiry_date DATE,
                last_concussion_date DATE,
                return_to_play_date DATE,
                height_inches NUMERIC,
                weight_lbs NUMERIC,
                has_asthma BOOLEAN,
                has_epipen BOOLEAN,
                emergency_treatment_consent BOOLEAN
            )'
        );
    }

    /** The exact payload AthleteForm sends for an athlete with no dates on file. */
    public function testEmptyDatesBecomeNull(): void
    {
        $out = te_normalize_athlete_medical_values([
            'last_physical_date' => '',
            'physical_expiry_date' => '',
            'last_concussion_date' => '',
            'return_to_play_date' => '',
        ]);

        $this->assertNull($out['last_physical_date']);
        $this->assertNull($out['physical_expiry_date']);
        $this->assertNull($out['last_concussion_date']);
        $this->assertNull($out['return_to_play_date']);
    }

    public function testWhitespaceOnlyDateBecomesNull(): void
    {
        $out = te_normalize_athlete_medical_values(['last_physical_date' => '   ']);
        $this->assertNull($out['last_physical_date']);
    }

    public function testRealDatesAreLeftAlone(): void
    {
        $out = te_normalize_athlete_medical_values([
            'last_physical_date' => '2026-03-14',
            'height_inches' => '62.5',
        ]);

        $this->assertSame('2026-03-14', $out['last_physical_date']);
        $this->assertSame('62.5', $out['height_inches']);
    }

    /** Empty numerics raise 22P02 — same class, same fix. */
    public function testEmptyNumericsBecomeNull(): void
    {
        $out = te_normalize_athlete_medical_values(['height_inches' => '', 'weight_lbs' => '']);

        $this->assertNull($out['height_inches']);
        $this->assertNull($out['weight_lbs']);
    }

    /** PDO binds PHP false as '', which Postgres rejects for a boolean. */
    public function testBooleansBecomeTrueFalseStrings(): void
    {
        $out = te_normalize_athlete_medical_values([
            'has_asthma' => false,
            'has_epipen' => true,
            'emergency_treatment_consent' => 1,
        ]);

        $this->assertSame('false', $out['has_asthma']);
        $this->assertSame('true', $out['has_epipen']);
        $this->assertSame('true', $out['emergency_treatment_consent']);
    }

    /** 'false' and '0' are non-empty strings — truthy to PHP, false to a human. */
    public function testStringyFalseIsFalse(): void
    {
        $out = te_normalize_athlete_medical_values([
            'has_asthma' => 'false',
            'has_epipen' => '0',
            'emergency_treatment_consent' => '',
        ]);

        $this->assertSame('false', $out['has_asthma']);
        $this->assertSame('false', $out['has_epipen']);
        $this->assertSame('false', $out['emergency_treatment_consent']);
    }

    /** A partial update must stay partial — absent keys are not invented. */
    public function testAbsentKeysStayAbsent(): void
    {
        $out = te_normalize_athlete_medical_values(['last_physical_date' => '2026-03-14']);

        $this->assertArrayNotHasKey('has_asthma', $out);
        $this->assertArrayNotHasKey('weight_lbs', $out);
        $this->assertArrayNotHasKey('return_to_play_date', $out);
    }

    /**
     * The gateway's partial UPDATE binds every key present after normalization
     * (array_key_exists, not isset) — so clearing a date persists as NULL instead
     * of being silently skipped.
     */
    public function testClearingADatePersistsAsNull(): void
    {
        $this->pdo->exec(
            "INSERT INTO athlete_medical (athlete_id, last_physical_date) VALUES (7, '2025-01-01')"
        );

        $data = te_normalize_athlete_medical_values(['last_physical_date' => '']);
        $fields = [];
        $values = [];
        foreach (['last_physical_date', 'physical_expiry_date'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        $values[] = 7;
        $this->pdo->prepare('UPDATE athlete_medical SET ' . implode(', ', $fields) . ' WHERE athlete_id = ?')
            ->execute($values);

        $stmt = $this->pdo->query('SELECT last_physical_date FROM athlete_medical WHERE athlete_id = 7');
        $this->assertNull($stmt->fetchColumn());
    }
}
