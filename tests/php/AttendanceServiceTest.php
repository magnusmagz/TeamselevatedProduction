<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AttendanceService;

/**
 * Unit tests for AttendanceService (CA-16).
 *
 * Runs against an in-memory SQLite DB — never touches the production Neon
 * database. The service uses portable SQL (explicit SELECT-then-UPSERT and
 * SUM(CASE WHEN ...) aggregation) so it behaves identically on SQLite and
 * PostgreSQL.
 *
 * These tests verify the two things CA-16 reported broken:
 *   1. Saving attendance per athlete (present/absent/late/excused) persists.
 *   2. The summary aggregation reflects what was saved.
 */
class AttendanceServiceTest extends TestCase
{
    private PDO $pdo;
    private AttendanceService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Mirror the prod event_attendance table (migration 037) closely enough
        // for the service's queries. SQLite has no SERIAL/CHECK enforcement, but
        // the service normalizes invalid statuses before writing.
        $this->pdo->exec("
            CREATE TABLE event_attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id INTEGER NOT NULL,
                athlete_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'present',
                notes TEXT,
                marked_by INTEGER,
                marked_at TEXT DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (event_id, athlete_id)
            );
        ");

        $this->service = new AttendanceService($this->pdo);
    }

    public function testSavePersistsAllFourStatuses(): void
    {
        $saved = $this->service->saveAttendance(1, [
            ['athlete_id' => 10, 'status' => 'present'],
            ['athlete_id' => 11, 'status' => 'absent'],
            ['athlete_id' => 12, 'status' => 'late'],
            ['athlete_id' => 13, 'status' => 'excused'],
        ], 50);

        $this->assertSame(4, $saved);

        $records = $this->service->getRecordsByAthlete(1);
        $this->assertSame('present', $records[10]['status']);
        $this->assertSame('absent', $records[11]['status']);
        $this->assertSame('late', $records[12]['status']);
        $this->assertSame('excused', $records[13]['status']);
    }

    public function testSummaryAggregatesAllStatuses(): void
    {
        $this->service->saveAttendance(1, [
            ['athlete_id' => 10, 'status' => 'present'],
            ['athlete_id' => 11, 'status' => 'present'],
            ['athlete_id' => 12, 'status' => 'absent'],
            ['athlete_id' => 13, 'status' => 'late'],
            ['athlete_id' => 14, 'status' => 'excused'],
        ]);

        // Roster of 6 -> one athlete (15) is unmarked.
        $summary = $this->service->getSummary(1, 6);

        $this->assertSame(2, $summary['present']);
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(1, $summary['late']);
        $this->assertSame(1, $summary['excused']);
        $this->assertSame(6, $summary['total']);
        $this->assertSame(1, $summary['not_marked']);
    }

    public function testSaveIsIdempotentUpsertOnEventAthlete(): void
    {
        // First save marks athlete 10 present.
        $this->service->saveAttendance(1, [['athlete_id' => 10, 'status' => 'present']]);
        // Re-saving the same athlete updates rather than duplicating.
        $this->service->saveAttendance(1, [['athlete_id' => 10, 'status' => 'absent']]);

        $rowCount = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM event_attendance WHERE event_id = 1 AND athlete_id = 10")
            ->fetchColumn();
        $this->assertSame(1, $rowCount, 'Re-saving must upsert, not insert a duplicate row');

        $records = $this->service->getRecordsByAthlete(1);
        $this->assertSame('absent', $records[10]['status'], 'Latest status should win');
    }

    public function testInvalidStatusDefaultsToPresent(): void
    {
        $this->service->saveAttendance(1, [['athlete_id' => 10, 'status' => 'bogus']]);
        $records = $this->service->getRecordsByAthlete(1);
        $this->assertSame('present', $records[10]['status']);
    }

    public function testSummaryWithNoRecordsIsAllZero(): void
    {
        $summary = $this->service->getSummary(999, 5);
        $this->assertSame(0, $summary['present']);
        $this->assertSame(0, $summary['absent']);
        $this->assertSame(0, $summary['late']);
        $this->assertSame(0, $summary['excused']);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(5, $summary['not_marked']);
    }

    public function testSummaryWithoutRosterTotalFallsBackToMarkedCount(): void
    {
        $this->service->saveAttendance(2, [
            ['athlete_id' => 20, 'status' => 'present'],
            ['athlete_id' => 21, 'status' => 'late'],
        ]);

        $summary = $this->service->getSummary(2); // no roster total passed
        $this->assertSame(2, $summary['total']);
        $this->assertArrayNotHasKey('not_marked', $summary);
    }

    public function testRecordsAreScopedByEvent(): void
    {
        $this->service->saveAttendance(1, [['athlete_id' => 10, 'status' => 'present']]);
        $this->service->saveAttendance(2, [['athlete_id' => 10, 'status' => 'absent']]);

        $event1 = $this->service->getRecordsByAthlete(1);
        $event2 = $this->service->getRecordsByAthlete(2);

        $this->assertSame('present', $event1[10]['status']);
        $this->assertSame('absent', $event2[10]['status']);
    }

    public function testNotesArePersisted(): void
    {
        $this->service->saveAttendance(1, [
            ['athlete_id' => 10, 'status' => 'excused', 'notes' => 'Family trip'],
        ]);
        $records = $this->service->getRecordsByAthlete(1);
        $this->assertSame('Family trip', $records[10]['notes']);
    }
}
