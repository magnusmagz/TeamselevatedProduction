<?php

use PHPUnit\Framework\TestCase;

if (!defined('TE_RECIPIENT_SEARCH_LIB_ONLY')) {
    define('TE_RECIPIENT_SEARCH_LIB_ONLY', true);
}
require_once __DIR__ . '/../../api/recipient-search-gateway.php';

/**
 * Auth double that can hold the coach role, which FakeSearchAuth cannot —
 * it answers true only for club_admin, and the whole bug here is about a coach.
 */
class FakeChatAuth
{
    /** @param string[] $roles */
    public function __construct(private array $roles = [], private bool $superAdmin = false) {}
    public function isSuperAdmin(): bool { return $this->superAdmin; }
    public function canAccessClub($clubProfileId): bool { return true; }
    public function hasRole($role, $clubProfileId = null, $level = null): bool
    {
        return in_array($role, $this->roles, true);
    }
}

/**
 * The chat recipient typeahead, for a coach who has no team yet.
 *
 * Reported 2026-08-14: a CKU coach typing "ley" could not find Leya, their club
 * admin, and could not find other coaches either.
 *
 * `$isCoach` was derived from `!empty($coachTeamIds)`, so a coach with no team
 * assigned fell through to the parent branch, matched no athletes, and received
 * an empty list — HTTP 200, no error, nobody to talk to. Nine such accounts were
 * live, four at CKU. Broken since the typeahead shipped (08396c6, 2026-05-05);
 * it only became visible once coaches stopped being shown every team in the club.
 *
 * A coach is a coach because of their ROLE. Team assignment decides which
 * FAMILIES they can reach, never whether they are staff.
 */
class ChatSearchCoachScopeTest extends TestCase
{
    private PDO $pdo;

    private const CLUB = 51;
    private const TEAMLESS_COACH = 158;   // Morgan Long — role coach, no team
    private const ROSTERED_COACH = 161;   // Kyle Smith — primary coach of team 1
    private const ADMIN = 300;            // Leya, club admin

    protected function setUp(): void
    {
        $this->pdo = new IlikeTranslatingPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT);
            CREATE TABLE user_club_access (id INTEGER PRIMARY KEY, user_id INTEGER,
                club_profile_id INTEGER, role TEXT, active INTEGER);
            CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, age_group TEXT, club_id INTEGER,
                primary_coach_id INTEGER, deleted_at TEXT);
            CREATE TABLE team_members (id INTEGER PRIMARY KEY, team_id INTEGER, athlete_id INTEGER,
                user_id INTEGER, role TEXT, status TEXT);
            CREATE TABLE athletes (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                club_id INTEGER, deleted_at TEXT, active_status INTEGER);
            CREATE TABLE guardians (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT);
            CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY, athlete_id INTEGER, guardian_id INTEGER);
        ");

        $p = $this->pdo;
        $p->exec("INSERT INTO users (id,first_name,last_name,email) VALUES
            (158,'Morgan','Long','morgan@example.com'),
            (161,'Kyle','Smith','kyle@example.com'),
            (300,'Leya','Devora','leya@example.com'),
            (400,'Pat','Parent','pat@example.com'),
            (500,'Otherclub','Coach','other@example.com')");

        $p->exec("INSERT INTO user_club_access (id,user_id,club_profile_id,role,active) VALUES
            (1,158,51,'coach',1),
            (2,161,51,'coach',1),
            (3,300,51,'club_admin',1),
            (4,400,51,'parent',1),
            (5,500,32,'coach',1)");

        // One team, coached by Kyle. Morgan coaches nothing — the reported case.
        $p->exec("INSERT INTO teams (id,name,age_group,club_id,primary_coach_id,deleted_at)
                  VALUES (1,'7th-8th Team 1','7th-8th',51,161,NULL)");

        // Pat is the guardian of an athlete on Kyle's team.
        $p->exec("INSERT INTO athletes (id,first_name,last_name,club_id,deleted_at,active_status)
                  VALUES (900,'Kid','Parent',51,NULL,1)");
        $p->exec("INSERT INTO guardians (id,first_name,last_name,email) VALUES (1,'Pat','Parent','pat@example.com')");
        $p->exec("INSERT INTO athlete_guardians (id,athlete_id,guardian_id) VALUES (1,900,1)");
        $p->exec("INSERT INTO team_members (id,team_id,athlete_id,user_id,role,status)
                  VALUES (1,1,900,NULL,'player','active')");
    }

    private function chatSearch(string $q, int $userId, array $roles): array
    {
        $_GET = ['q' => $q, 'club_profile_id' => self::CLUB];
        ob_start();
        try {
            handleChatSearch($this->pdo, new FakeChatAuth($roles), $userId);
        } finally {
            $out = ob_get_clean();
        }
        return json_decode($out, true) ?: [];
    }

    private function names(array $res): array
    {
        $n = array_map(fn($r) => $r['display_name'], $res['people'] ?? []);
        sort($n);
        return $n;
    }

    /** The reported bug, exactly: typing "ley" as a team-less coach. */
    public function testTeamlessCoachFindsTheClubAdmin(): void
    {
        $names = $this->names($this->chatSearch('ley', self::TEAMLESS_COACH, ['coach']));

        $this->assertContains('Leya Devora', $names, 'a coach with no team must still reach their club admin');
    }

    public function testTeamlessCoachFindsOtherCoaches(): void
    {
        $names = $this->names($this->chatSearch('', self::TEAMLESS_COACH, ['coach']));

        $this->assertContains('Kyle Smith', $names, 'coaches are colleagues regardless of team assignment');
    }

    /**
     * The distinction the fix preserves: role decides whether you are staff,
     * team assignment decides which FAMILIES you can reach. Morgan coaches
     * nobody, so no crew.
     */
    public function testTeamlessCoachStillReachesNoFamilies(): void
    {
        $names = $this->names($this->chatSearch('', self::TEAMLESS_COACH, ['coach']));

        $this->assertNotContains('Pat Parent', $names);
    }

    public function testACoachWithATeamStillReachesThatTeamsCrew(): void
    {
        $names = $this->names($this->chatSearch('', self::ROSTERED_COACH, ['coach']));

        $this->assertContains('Pat Parent', $names, 'the guardian branch must survive the refactor');
        $this->assertContains('Leya Devora', $names);
    }

    /** Club boundary is unchanged — the extra branches are club-scoped. */
    public function testOtherClubsStayUnreachable(): void
    {
        foreach ([self::TEAMLESS_COACH, self::ROSTERED_COACH] as $uid) {
            $names = $this->names($this->chatSearch('', $uid, ['coach']));
            $this->assertNotContains('Otherclub Coach', $names);
        }
    }

    /**
     * `array_fill(0, 0, '?')` yields `IN ()`, a syntax error rather than an empty
     * result, so the guard is what stops the whole search 500ing.
     */
    public function testTeamlessCoachGetsNoTeamGroupsAndNoError(): void
    {
        $res = $this->chatSearch('', self::TEAMLESS_COACH, ['coach']);

        $this->assertTrue($res['success'] ?? false);
        $this->assertSame([], $res['team_groups'] ?? null);
    }

    public function testACoachWithATeamStillSeesItAsAGroup(): void
    {
        $res = $this->chatSearch('', self::ROSTERED_COACH, ['coach']);

        $this->assertSame(['7th-8th Team 1'], array_column($res['team_groups'] ?? [], 'name'));
    }

    /** Admins are unaffected: whole club, all teams. */
    public function testAdminStillSeesEveryoneAndEveryTeam(): void
    {
        $res = $this->chatSearch('', self::ADMIN, ['club_admin']);
        $names = $this->names($res);

        $this->assertContains('Pat Parent', $names);
        $this->assertContains('Kyle Smith', $names);
        $this->assertContains('Morgan Long', $names);
        $this->assertCount(1, $res['team_groups'] ?? []);
    }
}
