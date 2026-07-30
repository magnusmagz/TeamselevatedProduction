<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_RECIPIENT_SEARCH_LIB_ONLY')) {
    define('TE_RECIPIENT_SEARCH_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/recipient-search-gateway.php';

/**
 * SQLite has no ILIKE operator, and the gateway's SQL is Postgres. Rewriting it at
 * prepare() keeps the test exercising the REAL query — joins, filters and all —
 * instead of a hand-copied paraphrase that could drift from production. SQLite's
 * LIKE is already case-insensitive for ASCII, so the semantics match.
 */
class IlikeTranslatingPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_ireplace(' ILIKE ', ' LIKE ', $query), $options);
    }
}

class FakeSearchAuth
{
    public function __construct(private bool $isAdmin, private bool $superAdmin = false) {}
    public function isSuperAdmin(): bool { return $this->superAdmin; }
    public function canAccessClub($clubProfileId): bool { return true; }
    public function hasRole($role, $clubProfileId, $level): bool
    {
        return $role === 'club_admin' && $this->isAdmin;
    }
}

/**
 * The To field must find a family that has registered but is not yet on a roster.
 *
 * It could not, until 2026-07-30. Both search branches INNER JOINed team_members,
 * so a just-created athlete — and their entire crew — were invisible to the
 * typeahead while the club-wide "All" group texted them happily. Two different
 * definitions of who exists in the same product, and the failure was silent:
 * HTTP 200, empty list, nothing in the console.
 *
 * It bites hardest exactly when it matters most — at season start, when everyone
 * has registered and nobody has been placed on a team yet.
 */
class RecipientSearchRosterTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 51;

    protected function setUp(): void
    {
        $this->pdo = new IlikeTranslatingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT, club_id INTEGER, deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, mobile_phone TEXT, sms_opt_out INTEGER DEFAULT 0);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE email_suppressions (id INTEGER PRIMARY KEY, club_profile_id INTEGER,
                email TEXT, phone TEXT, channel TEXT, reason TEXT, scope TEXT, team_id INTEGER);
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                email TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO teams (id,name,club_id,primary_coach_id,deleted_at)
                  VALUES (1,'7th-8th Team 1',51,10,NULL)");

        // 100 rostered, 200 registered-but-unrostered. Same club, same everything else.
        $p->exec("INSERT INTO athletes (id,first_name,last_name,email,phone,club_id,deleted_at,active_status) VALUES
            (100,'Rostered','Testkid',NULL,NULL,51,NULL,1),
            (200,'Unrostered','Testkid',NULL,NULL,51,NULL,1),
            (300,'Deleted','Testkid',NULL,NULL,51,'2026-01-01',1),
            (900,'Other','Testkid',NULL,NULL,32,NULL,1)");
        $p->exec("INSERT INTO team_members (id,team_id,athlete_id,user_id,role,status)
                  VALUES (1,1,100,NULL,'player','active')");

        $p->exec("INSERT INTO guardians (id,first_name,last_name,email,mobile_phone) VALUES
            (441,'Maggie','Test','maggie@example.com','3606317166'),
            (469,'Rostered','Testparent','rp@example.com','3606310000'),
            (999,'Otherclub','Testparent','oc@example.com','3606319999')");
        $p->exec("INSERT INTO athlete_guardians (id,athlete_id,guardian_id) VALUES
            (1,200,441), (2,100,469), (3,900,999)");
    }

    private function search(string $q, bool $admin = true, string $channel = 'sms'): array
    {
        $_GET = [
            'q' => $q,
            'club_profile_id' => self::CLUB,
            'channel' => $channel,
        ];
        ob_start();
        try {
            handleSearch($this->pdo, new FakeSearchAuth($admin), 10);
        } finally {
            // Without this, a query error leaves the buffer open and PHPUnit reports
            // "did not close its own output buffers" instead of the actual failure.
            $out = ob_get_clean();
        }
        return json_decode($out, true) ?: [];
    }

    private function names(array $res): array
    {
        $n = array_map(fn($r) => $r['first_name'] . ' ' . $r['last_name'], $res['results'] ?? []);
        sort($n);
        return $n;
    }

    /** The reported bug, exactly. */
    public function testAGuardianOfAnUnrosteredAthleteIsFound(): void
    {
        $names = $this->names($this->search('test'));

        $this->assertContains('Maggie Test', $names, 'guardian of a not-yet-rostered athlete must be findable');
    }

    public function testRosteredFamiliesStillFound(): void
    {
        $names = $this->names($this->search('test'));

        $this->assertContains('Rostered Testparent', $names);
    }

    public function testOtherClubsAreNotReachable(): void
    {
        $names = $this->names($this->search('test'));

        $this->assertNotContains('Otherclub Testparent', $names);
    }

    public function testSoftDeletedAthletesAreExcluded(): void
    {
        $names = $this->names($this->search('test'));

        $this->assertNotContains('Deleted Testkid', $names);
    }

    /**
     * The scoping half of the fix. A coach's reach is their roster, so dropping the
     * INNER JOIN must not hand them families they have no relationship with — the
     * NULL team_id from the LEFT JOIN cannot satisfy `IN (...)`.
     */
    public function testCoachStillCannotSeeUnrosteredFamilies(): void
    {
        $names = $this->names($this->search('test', admin: false));

        $this->assertNotContains('Maggie Test', $names, 'a coach must not reach an unrostered family');
        $this->assertContains('Rostered Testparent', $names, 'but still reaches their own roster');
    }
}
