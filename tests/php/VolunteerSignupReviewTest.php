<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit tests for volunteer signup review + compliance aggregation (CA-114 / CA-115).
 *
 * api/volunteer-gateway.php is a procedural gateway that emits HTTP headers on
 * include, so it can't be required directly in a unit test. These tests re-implement
 * the gateway's decision logic (applySignupDecision) and compliance aggregation with
 * the SAME predicates the gateway uses, against an in-memory SQLite PDO. No production
 * database is touched.
 *
 * SQLite note: Postgres `COUNT(*) FILTER (WHERE ...)` is expressed here with the
 * equivalent `SUM(CASE WHEN ... THEN 1 ELSE 0 END)` — semantically identical for the
 * counts the compliance dashboard relies on.
 *
 * Fixture (club 100):
 *   Team 10 — 'Team Ten'
 *   Team 11 — 'Team Eleven'
 *   Users 50 (cleared), 51 (pending), 52 (no bg record)
 */
class VolunteerSignupReviewTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE teams (
                id INTEGER PRIMARY KEY,
                name TEXT,
                club_id INTEGER,
                age_group TEXT,
                division TEXT,
                deleted_at TEXT
            );
            CREATE TABLE volunteer_signups (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                user_id INTEGER,
                requested_at TEXT,
                status TEXT DEFAULT 'pending',
                reviewed_by INTEGER,
                reviewed_at TEXT,
                notes TEXT
            );
            CREATE TABLE team_volunteers (
                id INTEGER PRIMARY KEY,
                team_id INTEGER,
                user_id INTEGER,
                volunteer_role TEXT,
                start_date TEXT,
                end_date TEXT,
                background_check_status TEXT,
                background_check_date TEXT,
                notes TEXT,
                assigned_by INTEGER,
                status TEXT DEFAULT 'active',
                self_signup INTEGER DEFAULT 0
            );
        ");
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, name, club_id, age_group, division, deleted_at) VALUES
            (10, 'Team Ten', 100, 'U12', 'A', NULL),
            (11, 'Team Eleven', 100, 'U14', 'B', NULL)");

        // User 50 already has a cleared volunteer record on team 10 (so their bg = cleared).
        $this->pdo->exec("INSERT INTO team_volunteers
            (id, team_id, user_id, volunteer_role, background_check_status, background_check_date, status)
            VALUES (1, 10, 50, 'volunteer', 'cleared', '2026-01-01', 'active')");
        // User 51 has only a pending bg record.
        $this->pdo->exec("INSERT INTO team_volunteers
            (id, team_id, user_id, volunteer_role, background_check_status, background_check_date, status)
            VALUES (2, 10, 51, 'volunteer', 'pending', '2026-01-01', 'active')");

        // Pending signups (user 50 cleared, user 51 pending, user 52 no record) for team 11.
        $this->pdo->exec("INSERT INTO volunteer_signups (id, team_id, user_id, requested_at, status, notes) VALUES
            (100, 11, 50, '2026-05-01 10:00:00', 'pending', 'Glad to help'),
            (101, 11, 51, '2026-05-02 10:00:00', 'pending', NULL),
            (102, 11, 52, '2026-05-03 10:00:00', 'pending', NULL)");
    }

    // ---- mirrors of gateway helpers ----

    /** Mirrors getUserBackgroundCheckStatus() in the gateway (team_volunteers branch). */
    private function bgStatus(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT background_check_status FROM team_volunteers
             WHERE user_id = ? AND background_check_status = 'cleared' LIMIT 1"
        );
        $stmt->execute([$userId]);
        if ($stmt->fetch()) return 'cleared';

        $stmt = $this->pdo->prepare(
            "SELECT background_check_status FROM team_volunteers
             WHERE user_id = ? AND background_check_status = 'pending' LIMIT 1"
        );
        $stmt->execute([$userId]);
        if ($stmt->fetch()) return 'pending';

        return 'none';
    }

    /** Mirrors applySignupDecision() in the gateway. */
    private function applyDecision(int $signupId, string $decision, int $reviewerId, ?string $notes = null): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM volunteer_signups WHERE id = ? AND status = 'pending'");
        $stmt->execute([$signupId]);
        $signup = $stmt->fetch();
        if (!$signup) return ['ok' => false, 'background_check_status' => 'none', 'reason' => 'not_found'];

        if ($notes !== null && $notes !== '') {
            $u = $this->pdo->prepare("UPDATE volunteer_signups SET status = ?, reviewed_by = ?, reviewed_at = '2026-05-10', notes = ? WHERE id = ?");
            $u->execute([$decision, $reviewerId, $notes, $signupId]);
        } else {
            $u = $this->pdo->prepare("UPDATE volunteer_signups SET status = ?, reviewed_by = ?, reviewed_at = '2026-05-10' WHERE id = ?");
            $u->execute([$decision, $reviewerId, $signupId]);
        }

        if ($decision === 'approved') {
            $bg = $this->bgStatus((int)$signup['user_id']);
            if ($bg !== 'cleared') {
                return ['ok' => false, 'background_check_status' => $bg];
            }
            $c = $this->pdo->prepare("SELECT id FROM team_volunteers WHERE team_id = ? AND user_id = ?");
            $c->execute([$signup['team_id'], $signup['user_id']]);
            if (!$c->fetch()) {
                $ins = $this->pdo->prepare("INSERT INTO team_volunteers
                    (team_id, user_id, volunteer_role, start_date, background_check_status,
                     background_check_date, assigned_by, status, self_signup)
                    VALUES (?, ?, 'volunteer', '2026-05-10', ?, '2026-05-10', ?, 'active', 1)");
                $ins->execute([$signup['team_id'], $signup['user_id'], $bg, $reviewerId]);
            }
            return ['ok' => true, 'background_check_status' => $bg];
        }
        return ['ok' => true, 'background_check_status' => $this->bgStatus((int)$signup['user_id'])];
    }

    private function statusOf(int $signupId): string
    {
        $stmt = $this->pdo->prepare("SELECT status FROM volunteer_signups WHERE id = ?");
        $stmt->execute([$signupId]);
        return (string)$stmt->fetchColumn();
    }

    private function notesOf(int $signupId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT notes FROM volunteer_signups WHERE id = ?");
        $stmt->execute([$signupId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    // ============================================================
    // CA-115: single approve / reject status transitions
    // ============================================================

    public function testApproveClearedSignupSetsApprovedAndCreatesVolunteer(): void
    {
        $res = $this->applyDecision(100, 'approved', 999); // user 50 is cleared
        $this->assertTrue($res['ok']);
        $this->assertSame('approved', $this->statusOf(100));

        // A team_volunteers row now exists for user 50 on team 11.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM team_volunteers WHERE team_id = 11 AND user_id = 50");
        $stmt->execute();
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testApproveBlockedWhenBackgroundCheckNotCleared(): void
    {
        $res = $this->applyDecision(101, 'approved', 999); // user 51 is pending
        $this->assertFalse($res['ok']);
        $this->assertSame('pending', $res['background_check_status']);
        // Caller rolls back on ok=false; no volunteer row created.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM team_volunteers WHERE team_id = 11 AND user_id = 51");
        $stmt->execute();
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testRejectSetsRejectedStatus(): void
    {
        $res = $this->applyDecision(102, 'rejected', 999);
        $this->assertTrue($res['ok']);
        $this->assertSame('rejected', $this->statusOf(102));
    }

    public function testRejectWithReasonStoresNotes(): void
    {
        $this->applyDecision(102, 'rejected', 999, 'Not enough capacity');
        $this->assertSame('Not enough capacity', $this->notesOf(102));
    }

    public function testApproveWithoutNotesPreservesApplicantNotes(): void
    {
        // Signup 100 has applicant notes 'Glad to help'; approving with no review note
        // must NOT wipe them (regression guard for the original overwrite bug).
        $this->applyDecision(100, 'approved', 999);
        $this->assertSame('Glad to help', $this->notesOf(100));
    }

    public function testAlreadyReviewedSignupCannotBeReReviewed(): void
    {
        $this->applyDecision(102, 'rejected', 999);
        $res = $this->applyDecision(102, 'approved', 999); // no longer pending
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['reason']);
        $this->assertSame('rejected', $this->statusOf(102));
    }

    // ============================================================
    // CA-115: bulk review semantics
    // ============================================================

    public function testBulkApprovePartitionsClearedFromBlocked(): void
    {
        // Bulk approve 100 (cleared), 101 (pending), 102 (none).
        $approved = 0; $skipped = [];
        foreach ([100, 101, 102] as $sid) {
            $res = $this->applyDecision($sid, 'approved', 999);
            if ($res['ok']) $approved++;
            else $skipped[] = $sid;
        }
        $this->assertSame(1, $approved); // only user 50
        $this->assertSame([101, 102], $skipped);
        $this->assertSame('approved', $this->statusOf(100));
    }

    public function testBulkRejectRejectsAllPending(): void
    {
        $rejected = 0;
        foreach ([100, 101, 102] as $sid) {
            $res = $this->applyDecision($sid, 'rejected', 999);
            if ($res['ok']) $rejected++;
        }
        $this->assertSame(3, $rejected);
        $this->assertSame('rejected', $this->statusOf(100));
        $this->assertSame('rejected', $this->statusOf(101));
        $this->assertSame('rejected', $this->statusOf(102));
    }

    // ============================================================
    // CA-114: compliance aggregation (per-team breakdown)
    // ============================================================

    public function testPerTeamComplianceBreakdown(): void
    {
        // Mirrors the gateway's per-team breakdown query (FILTER -> SUM(CASE)).
        $stmt = $this->pdo->prepare("
            SELECT t.id as team_id, t.name as team_name,
                   COUNT(tv.id) as volunteer_count,
                   SUM(CASE WHEN tv.background_check_status = 'cleared' THEN 1 ELSE 0 END) as cleared,
                   SUM(CASE WHEN tv.background_check_status = 'pending' THEN 1 ELSE 0 END) as pending_bg,
                   SUM(CASE WHEN tv.background_check_status = 'expired' THEN 1 ELSE 0 END) as expired_bg
            FROM teams t
            LEFT JOIN team_volunteers tv ON t.id = tv.team_id AND tv.status = 'active'
            WHERE t.club_id = ? AND t.deleted_at IS NULL
            GROUP BY t.id, t.name
            ORDER BY t.name
        ");
        $stmt->execute([100]);
        $rows = $stmt->fetchAll();

        $byId = [];
        foreach ($rows as $r) {
            $cnt = (int)$r['volunteer_count'];
            $r['compliance_rate'] = $cnt > 0 ? round(((int)$r['cleared'] / $cnt) * 100, 1) : 100;
            $byId[(int)$r['team_id']] = $r;
        }

        // Team 10 has 2 active volunteers: 1 cleared, 1 pending -> 50% compliance.
        $this->assertSame(2, (int)$byId[10]['volunteer_count']);
        $this->assertSame(1, (int)$byId[10]['cleared']);
        $this->assertSame(1, (int)$byId[10]['pending_bg']);
        $this->assertEquals(50.0, $byId[10]['compliance_rate']);

        // Team 11 has no volunteers yet -> 0 count, 100% by the gateway's convention.
        $this->assertSame(0, (int)$byId[11]['volunteer_count']);
        $this->assertEquals(100, $byId[11]['compliance_rate']);
    }

    public function testApprovingFeedsPerTeamBreakdown(): void
    {
        // After approving cleared user 50 onto team 11, team 11 shows 1 cleared volunteer.
        $this->applyDecision(100, 'approved', 999);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(tv.id) as volunteer_count,
                   SUM(CASE WHEN tv.background_check_status = 'cleared' THEN 1 ELSE 0 END) as cleared
            FROM team_volunteers tv
            WHERE tv.team_id = 11 AND tv.status = 'active'
        ");
        $stmt->execute();
        $row = $stmt->fetch();
        $this->assertSame(1, (int)$row['volunteer_count']);
        $this->assertSame(1, (int)$row['cleared']);
    }
}
