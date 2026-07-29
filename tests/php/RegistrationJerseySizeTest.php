<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Jersey size collected through the public registration form.
 *
 * Both public forms (PublicRegistrationForm and PublicTryoutRegistration) POST to
 * registration/registrations-api.php, which creates or matches the athlete inline.
 * registrations-api.php is a procedural script reading php://input, so as with
 * AthleteAdminPersistenceTest we exercise the exact SQL it runs against an
 * in-memory SQLite fixture carrying the real CHECK constraint.
 *
 * The failure mode being guarded: the registration form's select submits its
 * visible LABEL ('Youth Medium (10-12)'), not the code. Writing that raw violates
 * athletes_jersey_size_check and rolls back the entire registration — guardian,
 * athlete, link and all — while the parent sees a generic error. Resolution has to
 * happen before the value reaches SQL.
 */
class RegistrationJerseySizeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors migration 054's constraint — the point of these tests is that a
        // form label or a blank never reaches it unresolved.
        $this->pdo->exec("
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT, last_name TEXT, date_of_birth TEXT,
                gender TEXT, grade_level INTEGER, club_id INTEGER,
                active_status INTEGER DEFAULT 1, deleted_at TEXT,
                jersey_size TEXT CHECK (jersey_size IS NULL OR jersey_size IN (
                    'YXS','YS','YM','YL','YXL','AXS','AS','AM','AL','AXL','A2XL','A3XL'
                ))
            );
        ");
    }

    /** Mirror of the new-athlete INSERT in registrations-api.php. */
    private function registerNewAthlete(array $formData): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO athletes (
                first_name, last_name, date_of_birth, gender,
                grade_level, jersey_size, club_id, active_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $formData['athlete_first'],
            $formData['athlete_last'],
            $formData['athlete_birthday'],
            $formData['athlete_gender'],
            null,
            te_normalize_jersey_size($formData['jersey_size'] ?? null),
            51,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Mirror of the returning-athlete branch in registrations-api.php. */
    private function registerReturningAthlete(int $athleteId, array $formData): void
    {
        $size = te_normalize_jersey_size($formData['jersey_size'] ?? null);
        if ($size !== null) {
            $this->pdo->prepare("UPDATE athletes SET jersey_size = ? WHERE id = ?")
                ->execute([$size, $athleteId]);
        }
    }

    private function sizeOf(int $id): ?string
    {
        return $this->pdo->query("SELECT jersey_size FROM athletes WHERE id = $id")
            ->fetch()['jersey_size'];
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'athlete_first' => 'Rowan',
            'athlete_last' => 'Diaz',
            'athlete_birthday' => '2014-03-09',
            'athlete_gender' => 'Female',
        ], $overrides);
    }

    public function testFormLabelIsStoredAsACode(): void
    {
        $id = $this->registerNewAthlete($this->form(['jersey_size' => 'Youth Medium (10-12)']));
        $this->assertSame('YM', $this->sizeOf($id));
    }

    /**
     * The field is optional, so most submissions arrive with it blank. That must
     * be an ordinary registration, not a rolled-back one.
     */
    public function testBlankSizeStillRegistersTheAthlete(): void
    {
        $id = $this->registerNewAthlete($this->form(['jersey_size' => '']));
        $this->assertNull($this->sizeOf($id));
        $this->assertSame('Rowan', $this->pdo->query("SELECT first_name FROM athletes WHERE id = $id")
            ->fetch()['first_name']);
    }

    /** A form with no jersey_size key at all (a club removed the field). */
    public function testAbsentFieldStillRegistersTheAthlete(): void
    {
        $id = $this->registerNewAthlete($this->form());
        $this->assertNull($this->sizeOf($id));
    }

    /** Kids grow: a re-registration is the freshest size we will ever get. */
    public function testReturningAthleteSizeIsUpdated(): void
    {
        $id = $this->registerNewAthlete($this->form(['jersey_size' => 'Youth Small (6-8)']));
        $this->assertSame('YS', $this->sizeOf($id));

        $this->registerReturningAthlete($id, $this->form(['jersey_size' => 'Youth Large (14-16)']));
        $this->assertSame('YL', $this->sizeOf($id));
    }

    /**
     * ...but a blank on re-registration must not erase a size the club already
     * knows. Silently clearing good data is worse than not collecting it.
     */
    public function testReturningAthleteBlankDoesNotWipeKnownSize(): void
    {
        $id = $this->registerNewAthlete($this->form(['jersey_size' => 'Adult Small']));
        $this->registerReturningAthlete($id, $this->form(['jersey_size' => '']));
        $this->assertSame('AS', $this->sizeOf($id));
    }

    /**
     * Every option seeded onto a registration form must resolve to a storable
     * code. If a label and its map entry ever drift, this is what catches it —
     * otherwise a parent picks a size and it silently lands as NULL.
     */
    public function testEverySeededOptionResolvesAndStores(): void
    {
        $options = te_jersey_size_options();
        $this->assertCount(12, $options);

        foreach ($options as $label) {
            $id = $this->registerNewAthlete($this->form(['jersey_size' => $label]));
            $code = $this->sizeOf($id);
            $this->assertNotNull($code, "Option '$label' must resolve to a code");
            $this->assertContains($code, TE_JERSEY_SIZES, "Option '$label' resolved to '$code'");
        }
    }

    /**
     * Migration 055 hardcodes the option list as a JSON literal (SQL cannot call
     * te_jersey_size_options()). That duplication is the drift risk, so assert the
     * two are identical rather than trusting a comment to keep them in step.
     */
    public function testMigrationOptionListMatchesThePhpList(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/055_registration_jersey_size_field.sql');
        $this->assertNotFalse($sql, 'migration 055 must be readable');

        preg_match('/\'(\[\"Youth.*?\])\'/s', $sql, $m);
        $this->assertNotEmpty($m, 'could not find the options JSON literal in migration 055');

        $fromMigration = json_decode($m[1], true);
        $this->assertSame(
            te_jersey_size_options(),
            $fromMigration,
            'migration 055 option labels have drifted from TE_JERSEY_SIZE_LABELS'
        );
    }
}
