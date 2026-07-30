<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Crew-editable jersey size (api/athlete-jersey-size.php).
 *
 * Two things are worth locking down here, and neither is "does an UPDATE run".
 *
 * 1. REFUSING WHAT IT CANNOT READ. Everywhere else jersey size rides along with a
 *    bigger save, so te_normalize_jersey_size()'s leniency is right: one bad size
 *    must not sink an otherwise valid athlete edit. On a single-field endpoint
 *    that same leniency would answer 200 to "set this to Large" and store NULL —
 *    a parent is told their child's size is on file when it is not, and the club
 *    orders nothing. te_classify_jersey_size_submission() is the seam that keeps
 *    the two apart, so the distinction is tested at that seam.
 *
 * 2. THAT THERE IS ONLY ONE CELL. The staff athlete form and the parent portal
 *    are not two stores that need syncing — migration 054 deliberately put size
 *    on `athletes` (not per-membership on team_members), so both surfaces write
 *    the same column and last write wins. The round-trip test states that as a
 *    fact so a future change that gives the portal its own copy fails here.
 *
 * As with RegistrationJerseySizeTest, the endpoint is a procedural script reading
 * php://input, so the SQL it runs is mirrored against an in-memory SQLite fixture
 * carrying the real CHECK constraint rather than booting the script.
 */
class ParentJerseySizeUpdateTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirrors migration 054's constraint. If an unresolved value ever reaches
        // the UPDATE, these tests fail with a CHECK violation rather than quietly
        // passing — which is the whole point of putting the constraint here.
        $this->pdo->exec("
            CREATE TABLE athletes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT, last_name TEXT,
                active_status INTEGER DEFAULT 1, deleted_at TEXT,
                updated_at TEXT,
                jersey_size TEXT CHECK (jersey_size IS NULL OR jersey_size IN (
                    'YXS','YS','YM','YL','YXL','AXS','AS','AM','AL','AXL','A2XL','A3XL'
                ))
            );
        ");
        $this->pdo->exec(
            "INSERT INTO athletes (id, first_name, last_name, jersey_size)
             VALUES (1, 'Rachel', 'Jones', NULL)"
        );
    }

    /** Mirror of the UPDATE in api/athlete-jersey-size.php. */
    private function portalWrite(?string $code): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE athletes SET jersey_size = ?
             WHERE id = ? AND active_status = 1 AND deleted_at IS NULL"
        );
        $stmt->execute([$code, 1]);
    }

    /** Mirror of the jersey_size branch of the PUT in legacy/athletes-gateway.php. */
    private function staffWrite($submitted): void
    {
        $stmt = $this->pdo->prepare("UPDATE athletes SET jersey_size = ? WHERE id = ?");
        $stmt->execute([te_normalize_jersey_size($submitted), 1]);
    }

    private function storedSize(): ?string
    {
        return $this->pdo->query("SELECT jersey_size FROM athletes WHERE id = 1")
            ->fetch()['jersey_size'];
    }

    /**
     * A code or a label resolves and is stored. Labels matter because the same
     * endpoint is reachable from anywhere the option text is what got submitted.
     */
    public function testResolvableSubmissionsAreStored(): void
    {
        foreach (['YM' => 'YM', 'Youth Medium (10-12)' => 'YM', 'adult 2xl' => 'A2XL'] as $sent => $expected) {
            $decision = te_classify_jersey_size_submission($sent);

            $this->assertSame(TE_JERSEY_SUBMISSION_SET, $decision['action'], "sent: $sent");
            $this->assertSame($expected, $decision['code'], "sent: $sent");

            $this->portalWrite($decision['code']);
            $this->assertSame($expected, $this->storedSize(), "sent: $sent");
        }
    }

    /**
     * A blank is a real answer — "nobody has asked the family yet" — and clears
     * the column. It must not be confused with the refusal case below.
     */
    public function testBlankClearsTheSize(): void
    {
        $this->portalWrite('YL');
        $this->assertSame('YL', $this->storedSize());

        foreach (['', '   ', null] as $blank) {
            $this->portalWrite('YL');

            $decision = te_classify_jersey_size_submission($blank);
            $this->assertSame(TE_JERSEY_SUBMISSION_CLEAR, $decision['action']);

            $this->portalWrite($decision['code']);
            $this->assertNull($this->storedSize());
        }
    }

    /**
     * THE REGRESSION THIS FILE EXISTS FOR. An unreadable size is refused (the
     * endpoint answers 422), never silently written as NULL.
     *
     * 'M' and 'Large' are in the list on purpose: they are ambiguous between
     * Youth and Adult, so they are a sender mistake worth reporting rather than
     * an empty field. Guessing one is how a club orders adult kit for a nine
     * year old.
     */
    public function testUnreadableSizeIsRefusedNotSilentlyCleared(): void
    {
        $this->portalWrite('YM');

        foreach (['M', 'Large', 'XXS', 'Y2XL', 'banana'] as $bogus) {
            $decision = te_classify_jersey_size_submission($bogus);

            $this->assertSame(
                TE_JERSEY_SUBMISSION_INVALID,
                $decision['action'],
                "'$bogus' must be refused, not treated as a deliberate clear"
            );

            // The endpoint returns 422 at this point and never reaches its UPDATE,
            // so the size already on file is left alone.
            $this->assertSame('YM', $this->storedSize());
        }
    }

    /**
     * Staff and crew edit one cell, so each side reads the other's last write.
     * There is no second copy to reconcile — see the class docblock.
     */
    public function testStaffAndCrewEditTheSameColumn(): void
    {
        $this->staffWrite('Youth Small (6-8)');
        $this->assertSame('YS', $this->storedSize());

        // Crew correct it in the portal; staff see the correction.
        $this->portalWrite(te_classify_jersey_size_submission('YL')['code']);
        $this->assertSame('YL', $this->storedSize());

        // Staff correct it back; the portal sees that.
        $this->staffWrite('AM');
        $this->assertSame('AM', $this->storedSize());
    }

    /** A soft-deleted athlete is invisible elsewhere; keep them unwritable too. */
    public function testSoftDeletedAthleteIsNotWritable(): void
    {
        $this->portalWrite('YM');
        $this->pdo->exec("UPDATE athletes SET deleted_at = '2026-07-30' WHERE id = 1");

        $this->portalWrite('AL');

        $this->assertSame('YM', $this->storedSize());
    }
}
