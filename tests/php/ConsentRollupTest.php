<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuthMiddleware;
use AthleteScope;

/**
 * The staff-facing consent roll-up (`api/consent.php?action=summary`).
 *
 * Two separable things are pinned here:
 *
 * 1. THE LADDER. verified / confirmed / signup_only / partial / none are not
 *    cosmetic labels — the gap between "the parent agreed" and "the parent
 *    confirmed by email" is exactly what COPPA's verifiable-consent standard
 *    turns on, and the gap between portal and registration consent is what tells
 *    a club whether the agreement is tied to an account or only to a form.
 *
 * 2. THE SCOPE. A staff-only report must be scoped with staffManageableAthleteIds,
 *    not accessibleAthleteIds. The difference is the guardian branch: keyed on the
 *    wrong one, a parent hitting this endpoint would get a report about their own
 *    child instead of nothing, and a coach who is also a parent would see a child
 *    from outside their teams.
 */
class ConsentRollupTest extends TestCase
{
    // ---- The ladder -------------------------------------------------------

    private function row(string $type, string $source = 'portal', bool $confirmed = false): array
    {
        return [
            'consent_type' => $type,
            'source' => $source,
            'email_confirmed_at' => $confirmed ? '2026-07-31T10:00:00Z' : null,
        ];
    }

    public function testNothingOnFile(): void
    {
        $this->assertSame('none', te_consent_rollup_status([]));
    }

    public function testPartialWhenOnlyOneRequiredTypeIsCovered(): void
    {
        $this->assertSame('partial', te_consent_rollup_status([$this->row('data_collection')]));
    }

    public function testSignupOnlyWhenBothCameFromTheRegistrationForm(): void
    {
        $this->assertSame('signup_only', te_consent_rollup_status([
            $this->row('data_collection', 'registration'),
            $this->row('medical_data', 'registration'),
        ]));
    }

    public function testConfirmedWhenAgreedInThePortalButEmailNotClicked(): void
    {
        $this->assertSame('confirmed', te_consent_rollup_status([
            $this->row('data_collection', 'portal'),
            $this->row('medical_data', 'portal'),
        ]));
    }

    public function testVerifiedOnlyWhenBothTypesAreEmailConfirmed(): void
    {
        // One confirmed, one not, is NOT verified — the weakest link decides.
        $this->assertSame('confirmed', te_consent_rollup_status([
            $this->row('data_collection', 'portal', true),
            $this->row('medical_data', 'portal', false),
        ]));

        $this->assertSame('verified', te_consent_rollup_status([
            $this->row('data_collection', 'portal', true),
            $this->row('medical_data', 'portal', true),
        ]));
    }

    /**
     * A family who agreed at sign-up AND confirmed in the portal is verified —
     * the registration row must not drag the status back down.
     */
    public function testRegistrationRowsDoNotSuppressAVerifiedPortalConsent(): void
    {
        $this->assertSame('verified', te_consent_rollup_status([
            $this->row('data_collection', 'registration'),
            $this->row('medical_data', 'registration'),
            $this->row('data_collection', 'portal', true),
            $this->row('medical_data', 'portal', true),
        ]));
    }

    /**
     * Rows written before migration 063 have no source. That migration backfilled
     * them to 'portal', so defaulting the same way here stops a pre-063 database
     * from reporting real portal consent as signup_only.
     */
    public function testRowsWithNoSourceCountAsPortal(): void
    {
        $this->assertSame('confirmed', te_consent_rollup_status([
            ['consent_type' => 'data_collection', 'email_confirmed_at' => null],
            ['consent_type' => 'medical_data', 'email_confirmed_at' => null],
        ]));
    }

    // ---- The counts -------------------------------------------------------

    public function testOutstandingCountsEverythingNotYetInThePortal(): void
    {
        $counts = te_consent_summary_counts([
            ['status' => 'verified'],
            ['status' => 'confirmed'],
            ['status' => 'signup_only'],
            ['status' => 'partial'],
            ['status' => 'none'],
        ]);

        $this->assertSame(5, $counts['total']);
        $this->assertSame(1, $counts['verified']);
        $this->assertSame(1, $counts['confirmed']);
        // signup_only is outstanding on purpose: real consent, but not tied to an
        // account, which is the whole reason the portal step exists.
        $this->assertSame(3, $counts['outstanding']);
    }

    // ---- The scope --------------------------------------------------------

    private function scopePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, user_id INTEGER,
                                       athlete_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, club_id INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
        ");
        $pdo->exec("INSERT INTO athletes (id, first_name, last_name, club_id) VALUES
            (1,'Anna','Aaron',100), (2,'Ben','Brown',100), (3,'Cara','Cross',101)");
        $pdo->exec("INSERT INTO teams (id, name, club_id, primary_coach_id) VALUES
            (10,'Team A',100,50), (11,'Team B',100,51), (12,'Team C',101,52)");
        $pdo->exec("INSERT INTO team_members (id, team_id, athlete_id, role, status) VALUES
            (1,10,1,'player','active'), (2,11,2,'player','active'), (3,12,3,'player','active')");
        $pdo->exec("INSERT INTO guardians (id, email) VALUES (200,'alice@family-a.com')");
        $pdo->exec("INSERT INTO athlete_guardians (id, athlete_id, guardian_id) VALUES (1,1,200)");
        return $pdo;
    }

    /** THE REGRESSION: a pure guardian gets NOTHING from a staff-only report. */
    public function testAGuardianHasNoStaffManageableAthletes(): void
    {
        $pdo = $this->scopePdo();
        $auth = AuthMiddleware::fromContext([
            'user_id' => 70, 'email' => 'alice@family-a.com', 'roles' => [],
        ]);

        // They can READ their own child...
        $this->assertContains(1, AthleteScope::accessibleAthleteIds($pdo, $auth));
        // ...but hold no staff standing, so the staff report is empty for them.
        $this->assertSame([], AthleteScope::staffManageableAthleteIds($pdo, $auth));
    }

    public function testClubAdminSeesTheirWholeClubAndNoOther(): void
    {
        $pdo = $this->scopePdo();
        $auth = AuthMiddleware::fromContext([
            'user_id' => 60, 'email' => 'admin@club.test',
            'roles' => [['role' => 'club_admin', 'scope_type' => 'club', 'scope_id' => 100]],
        ]);

        $ids = AthleteScope::staffManageableAthleteIds($pdo, $auth);
        sort($ids);
        $this->assertSame([1, 2], $ids);
    }

    public function testCoachSeesOnlyTheirOwnTeam(): void
    {
        $pdo = $this->scopePdo();
        $auth = AuthMiddleware::fromContext([
            'user_id' => 50, 'email' => 'coach50@club.test', 'roles' => [],
        ]);

        $this->assertSame([1], AthleteScope::staffManageableAthleteIds($pdo, $auth));
    }

    /**
     * A coach who is ALSO a parent must not have their own child leak into a
     * staff report scoped to their teams.
     */
    public function testACoachWhoIsAlsoAParentDoesNotGainTheirOwnChild(): void
    {
        $pdo = $this->scopePdo();
        // Coach of team 11 (athlete 2), and guardian of athlete 1.
        $pdo->exec("UPDATE guardians SET email = 'coach51@club.test' WHERE id = 200");
        $auth = AuthMiddleware::fromContext([
            'user_id' => 51, 'email' => 'coach51@club.test', 'roles' => [],
        ]);

        $this->assertSame([2], AthleteScope::staffManageableAthleteIds($pdo, $auth));
        // The read predicate still includes their own child — that is correct there.
        $accessible = AthleteScope::accessibleAthleteIds($pdo, $auth);
        sort($accessible);
        $this->assertSame([1, 2], $accessible);
    }
}
