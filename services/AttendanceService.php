<?php
/**
 * AttendanceService — per-athlete event attendance persistence + aggregation.
 *
 * Extracted from api/event-attendance.php so the save and summary logic is
 * unit-testable against an in-memory SQLite fixture (the same pattern used by
 * AthleteScopeTest). All DB access is injected via $pdo; no global state.
 *
 * Status values (product spec): present / absent / late / excused.
 *
 * Portability note: the upsert is done as an explicit SELECT-then-UPDATE/INSERT
 * rather than Postgres `INSERT ... ON CONFLICT`, and the summary uses portable
 * SUM(CASE WHEN ...) instead of Postgres `COUNT(*) FILTER (WHERE ...)`, so the
 * exact same code path runs on SQLite (tests) and PostgreSQL (prod).
 */
class AttendanceService
{
    /** Valid attendance statuses per product spec. */
    public const VALID_STATUSES = ['present', 'absent', 'late', 'excused'];

    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Normalize an incoming status, defaulting unknown values to 'present'.
     */
    public function normalizeStatus($status): string
    {
        return in_array($status, self::VALID_STATUSES, true) ? $status : 'present';
    }

    /**
     * Upsert a batch of attendance records for one event.
     *
     * @param int   $eventId
     * @param array $records   list of ['athlete_id'=>int, 'status'=>string, 'notes'=>?string]
     * @param int|null $markedBy  user id recording the attendance (nullable)
     * @return int  number of records written
     */
    public function saveAttendance(int $eventId, array $records, ?int $markedBy = null): int
    {
        $selectStmt = $this->pdo->prepare(
            "SELECT id FROM event_attendance WHERE event_id = :event_id AND athlete_id = :athlete_id"
        );
        $updateStmt = $this->pdo->prepare(
            "UPDATE event_attendance
                SET status = :status,
                    notes = :notes,
                    marked_by = :marked_by,
                    marked_at = CURRENT_TIMESTAMP
              WHERE event_id = :event_id AND athlete_id = :athlete_id"
        );
        $insertStmt = $this->pdo->prepare(
            "INSERT INTO event_attendance (event_id, athlete_id, status, notes, marked_by, marked_at)
             VALUES (:event_id, :athlete_id, :status, :notes, :marked_by, CURRENT_TIMESTAMP)"
        );

        $saved = 0;
        foreach ($records as $record) {
            $athleteId = $record['athlete_id'] ?? null;
            if (!$athleteId) {
                continue;
            }
            $status = $this->normalizeStatus($record['status'] ?? 'present');
            $notes = $record['notes'] ?? null;

            $selectStmt->execute(['event_id' => $eventId, 'athlete_id' => $athleteId]);
            $exists = $selectStmt->fetchColumn();

            if ($exists !== false) {
                $updateStmt->execute([
                    'status' => $status,
                    'notes' => $notes,
                    'marked_by' => $markedBy,
                    'event_id' => $eventId,
                    'athlete_id' => $athleteId,
                ]);
            } else {
                $insertStmt->execute([
                    'event_id' => $eventId,
                    'athlete_id' => $athleteId,
                    'status' => $status,
                    'notes' => $notes,
                    'marked_by' => $markedBy,
                ]);
            }
            $saved++;
        }

        return $saved;
    }

    /**
     * Aggregate attendance counts for one event across all 4 statuses.
     * Returns marked-status counts plus the total roster size when provided.
     *
     * @param int      $eventId
     * @param int|null $rosterTotal  total roster size (for not_marked); optional
     * @return array{present:int,absent:int,late:int,excused:int,total:int,not_marked?:int}
     */
    public function getSummary(int $eventId, ?int $rosterTotal = null): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN status = 'absent'  THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN status = 'late'    THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) AS excused,
                COUNT(*) AS marked
             FROM event_attendance
             WHERE event_id = :event_id"
        );
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $present = (int) ($row['present'] ?? 0);
        $absent = (int) ($row['absent'] ?? 0);
        $late = (int) ($row['late'] ?? 0);
        $excused = (int) ($row['excused'] ?? 0);
        $marked = (int) ($row['marked'] ?? 0);

        $summary = [
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            // total = full roster when known, else fall back to marked count
            'total' => $rosterTotal !== null ? $rosterTotal : $marked,
        ];

        if ($rosterTotal !== null) {
            $summary['not_marked'] = max(0, $rosterTotal - $marked);
        }

        return $summary;
    }

    /**
     * Existing attendance records for an event, indexed by athlete_id.
     *
     * @return array<int,array{athlete_id:int,status:string,notes:?string}>
     */
    public function getRecordsByAthlete(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT athlete_id, status, notes, marked_at, marked_by
               FROM event_attendance
              WHERE event_id = :event_id"
        );
        $stmt->execute(['event_id' => $eventId]);
        $byAthlete = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $byAthlete[$record['athlete_id']] = $record;
        }
        return $byAthlete;
    }
}
