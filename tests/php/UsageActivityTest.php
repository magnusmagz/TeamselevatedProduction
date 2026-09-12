<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/usage_activity.php';

/**
 * lib/usage_activity.php against SQLite: who is bucketed where, that the same
 * rule sizes both sides of every rate, and that DAU / WAU / MAU count a person
 * once per club however many roles they hold.
 */
class UsageActivityTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, system_role TEXT DEFAULT \'user\', last_login_at TEXT)');
        $this->pdo->exec('CREATE TABLE user_club_access (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, club_profile_id INTEGER, role TEXT, active INTEGER DEFAULT 1, revoked_at TEXT)');
        $this->pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, club_id INTEGER, primary_coach_id INTEGER, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE team_members (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, user_id INTEGER, role TEXT, status TEXT DEFAULT \'active\')');
        $this->pdo->exec('CREATE TABLE guardians (id INTEGER PRIMARY KEY, email TEXT NOT NULL DEFAULT \'\')');
        $this->pdo->exec('CREATE TABLE user_guardians (user_id INTEGER, guardian_id INTEGER)');
        $this->pdo->exec('CREATE TABLE athletes (id INTEGER PRIMARY KEY, club_id INTEGER, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE athlete_guardians (id INTEGER PRIMARY KEY AUTOINCREMENT, athlete_id INTEGER, guardian_id INTEGER)');
        $this->pdo->exec('CREATE TABLE user_activity_daily (
            user_id INTEGER NOT NULL, club_id INTEGER NOT NULL, role TEXT NOT NULL, activity_date TEXT NOT NULL,
            surface TEXT NOT NULL DEFAULT \'staff\', first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL,
            PRIMARY KEY (user_id, club_id, role, activity_date))');

        // 1: club admin of 51 AND coach of a team in 51 (dual role via team, no coach uca row)
        // 2: parent of an athlete in 51 by user_guardians link
        // 3: parent by email match only (Emily Govier shape: capital letter)
        // 4: coach+parent in 51 — coach by uca, parent by guardian chain
        // 5: revoked club_admin of 51 — holds nothing
        // 6: super admin, no club row
        // 7: assistant coach on a soft-deleted team — holds nothing
        // 8: parent of a soft-deleted athlete only — holds nothing
        $this->pdo->exec("INSERT INTO users (id, email, system_role) VALUES
            (1,'admin@x.com','user'),(2,'p2@x.com','user'),(3,'Emily@x.com','user'),(4,'cp@x.com','user'),
            (5,'rev@x.com','user'),(6,'maggie@x.com','super_admin'),(7,'dead@x.com','user'),(8,'gone@x.com','user')");
        $this->pdo->exec("INSERT INTO user_club_access (user_id, club_profile_id, role, active, revoked_at) VALUES
            (1,51,'club_admin',1,NULL),(4,51,'coach',1,NULL),(5,51,'club_admin',1,'2026-01-01'),(2,51,'parent',1,NULL)");
        $this->pdo->exec("INSERT INTO teams (id, club_id, primary_coach_id, deleted_at) VALUES (10,51,1,NULL),(11,51,NULL,'2026-01-01')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, role, status) VALUES (11,7,'assistant_coach','active')");
        $this->pdo->exec("INSERT INTO guardians (id, email) VALUES (100,'other@x.com'),(101,'emily@x.com'),(102,'cp@x.com'),(103,'gone@x.com')");
        $this->pdo->exec("INSERT INTO user_guardians (user_id, guardian_id) VALUES (2,100)");
        $this->pdo->exec("INSERT INTO athletes (id, club_id, deleted_at) VALUES (500,51,NULL),(501,51,NULL),(502,51,'2026-01-01')");
        $this->pdo->exec("INSERT INTO athlete_guardians (athlete_id, guardian_id) VALUES (500,100),(500,101),(501,102),(502,103)");
    }

    public function testBucketsEvaluateEachRoleIndependently(): void
    {
        $this->assertSame([['club_id' => 51, 'role' => 'club_admin'], ['club_id' => 51, 'role' => 'coach']], te_usage_buckets_for_user($this->pdo, 1));
        $this->assertSame([['club_id' => 51, 'role' => 'parent']], te_usage_buckets_for_user($this->pdo, 2));
        $this->assertSame([['club_id' => 51, 'role' => 'parent']], te_usage_buckets_for_user($this->pdo, 3), 'email match is case-insensitive');
        $this->assertSame([['club_id' => 51, 'role' => 'coach'], ['club_id' => 51, 'role' => 'parent']], te_usage_buckets_for_user($this->pdo, 4));
        $this->assertSame([['club_id' => 0, 'role' => 'super_admin']], te_usage_buckets_for_user($this->pdo, 6));
    }

    public function testRevokedDeletedAndGoneHoldNothing(): void
    {
        $this->assertSame([], te_usage_buckets_for_user($this->pdo, 5), 'revoked role');
        $this->assertSame([], te_usage_buckets_for_user($this->pdo, 7), 'soft-deleted team');
        $this->assertSame([], te_usage_buckets_for_user($this->pdo, 8), 'soft-deleted athlete');
        $this->assertSame([], te_usage_buckets_for_user($this->pdo, 0));
    }

    public function testHoldersUseTheSameRuleAsTheBuckets(): void
    {
        $holders = te_usage_holders($this->pdo);
        $this->assertSame(['0|super_admin' => 1, '51|club_admin' => 1, '51|coach' => 2, '51|parent' => 3], $holders);
        $this->assertSame([0 => 1, 51 => 4], te_usage_holders_by_club($this->pdo), 'users 1 and 4 counted once each for the club');
    }

    public function testRecordIsIdempotentAndWritesOneRowPerBucket(): void
    {
        $w = te_usage_record($this->pdo, 4, '2026-09-12', 'staff', '2026-09-12 10:00:00');
        $this->assertCount(2, $w);
        te_usage_record($this->pdo, 4, '2026-09-12', 'parent', '2026-09-12 18:00:00');
        $rows = $this->pdo->query("SELECT role, surface, first_seen_at, last_seen_at FROM user_activity_daily WHERE user_id = 4 ORDER BY role")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-12 10:00:00', $rows[0]['first_seen_at']);
        $this->assertSame('2026-09-12 18:00:00', $rows[0]['last_seen_at']);
        $this->assertSame('staff', $rows[0]['surface'], 'first surface of the day is kept');
        $this->assertSame([], te_usage_record($this->pdo, 5, '2026-09-12'), 'no role, no row');
    }

    public function testSummaryCountsAPersonOncePerClubAcrossRoles(): void
    {
        te_usage_record($this->pdo, 1, '2026-09-12'); // admin + coach today
        te_usage_record($this->pdo, 4, '2026-09-10'); // coach + parent, this week
        te_usage_record($this->pdo, 2, '2026-08-20'); // parent, this month only
        te_usage_record($this->pdo, 3, '2026-07-01'); // outside the window
        te_usage_record($this->pdo, 6, '2026-09-12');

        $s = te_usage_summary($this->pdo, '2026-09-12');
        $this->assertSame('2026-09-06', $s['week_start']);
        $this->assertSame('2026-08-14', $s['month_start']);
        $byKey = [];
        foreach ($s['rows'] as $r) { $byKey[$r['club_id'] . '|' . $r['role']] = $r; }
        $this->assertSame(['holders' => 1, 'dau' => 1, 'wau' => 1, 'mau' => 1], array_intersect_key($byKey['51|club_admin'], array_flip(['holders','dau','wau','mau'])));
        $this->assertSame(['holders' => 2, 'dau' => 1, 'wau' => 2, 'mau' => 2], array_intersect_key($byKey['51|coach'], array_flip(['holders','dau','wau','mau'])));
        $this->assertSame(['holders' => 3, 'dau' => 0, 'wau' => 1, 'mau' => 2], array_intersect_key($byKey['51|parent'], array_flip(['holders','dau','wau','mau'])));
        $this->assertSame(['holders' => 1, 'dau' => 1, 'wau' => 1, 'mau' => 1], array_intersect_key($byKey['0|super_admin'], array_flip(['holders','dau','wau','mau'])));

        $clubs = [];
        foreach ($s['clubs'] as $c) { $clubs[$c['club_id']] = $c; }
        $this->assertSame(4, $clubs[51]['holders']);
        $this->assertSame(1, $clubs[51]['dau'], 'user 1 is admin and coach but one person');
        $this->assertSame(2, $clubs[51]['wau']);
        $this->assertSame(3, $clubs[51]['mau']);
    }

    public function testEveryRoleWithHoldersAppearsEvenWithNoActivity(): void
    {
        $s = te_usage_summary($this->pdo, '2026-09-12');
        $keys = array_map(fn($r) => $r['club_id'] . '|' . $r['role'], $s['rows']);
        $this->assertSame(['0|super_admin', '51|club_admin', '51|coach', '51|parent'], $keys);
        foreach ($s['rows'] as $r) { $this->assertSame(0, $r['mau']); }
    }

    public function testWeeklyTrendWindowsAndCounts(): void
    {
        te_usage_record($this->pdo, 1, '2026-09-12');
        te_usage_record($this->pdo, 4, '2026-09-12');
        te_usage_record($this->pdo, 4, '2026-09-01');
        te_usage_record($this->pdo, 2, '2026-08-30');
        $t = te_usage_weekly_trend($this->pdo, '2026-09-12', 3);
        $this->assertSame([['start' => '2026-08-23', 'end' => '2026-08-29'], ['start' => '2026-08-30', 'end' => '2026-09-05'], ['start' => '2026-09-06', 'end' => '2026-09-12']], $t['weeks']);
        $this->assertSame([0, 1, 2], $t['series']['51|coach']);
        $this->assertSame([0, 2, 1], $t['series']['51|parent']);
        $this->assertSame([0, 2, 2], $t['series']['51|*']);
    }

    public function testClientDateIsTrustedOnlyWithinADay(): void
    {
        $this->assertSame('2026-09-11', te_usage_resolve_activity_date('2026-09-11', '2026-09-12'));
        $this->assertSame('2026-09-13', te_usage_resolve_activity_date('2026-09-13', '2026-09-12'));
        $this->assertSame('2026-09-12', te_usage_resolve_activity_date('2026-09-01', '2026-09-12'));
        $this->assertSame('2026-09-12', te_usage_resolve_activity_date('garbage', '2026-09-12'));
        $this->assertSame('2026-09-12', te_usage_resolve_activity_date(null, '2026-09-12'));
    }

    public function testTablePresenceProbe(): void
    {
        $this->assertTrue(te_usage_table_present($this->pdo));
        $this->pdo->exec('DROP TABLE user_activity_daily');
        $this->assertFalse(te_usage_table_present($this->pdo));
    }
}
