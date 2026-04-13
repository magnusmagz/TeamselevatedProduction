<?php
/**
 * AthleteImportService
 *
 * Processes a queued athlete-import job: parses the CSV stored on the
 * import_jobs row, upserts athletes + guardians + relationships, optionally
 * adds athletes to a team's roster, and records per-row errors.
 */

class AthleteImportService {
    private $pdo;

    private static $REQUIRED_HEADERS = [
        'athlete_first_name',
        'athlete_last_name',
        'athlete_dob',
        'athlete_gender',
        'guardian1_first_name',
        'guardian1_last_name',
        'guardian1_email',
        'guardian1_mobile',
    ];

    private static $ALLOWED_GENDERS = ['Male', 'Female', 'Non-binary', 'Prefer not to say'];
    private static $ALLOWED_RELATIONSHIPS = ['Parent', 'Guardian', 'Emergency Contact', 'Other'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function processJob(array $payload) {
        $jobId = (int) ($payload['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('athlete_import job missing job_id');
        }

        $job = $this->loadJob($jobId);
        if (!$job) {
            throw new RuntimeException("import_jobs row {$jobId} not found");
        }

        $this->markStarted($jobId);

        try {
            $rows = $this->parseCsv($job['csv_content']);

            if (empty($rows)) {
                $this->markFinished($jobId, 'completed');
                return;
            }

            $headers = array_keys($rows[0]);
            $missing = array_diff(self::$REQUIRED_HEADERS, $headers);
            if (!empty($missing)) {
                $this->recordError($jobId, 0, null, 'Missing required columns: ' . implode(', ', $missing));
                $this->markFinished($jobId, 'failed');
                return;
            }

            $created = 0; $updated = 0; $skipped = 0; $errors = 0;

            foreach ($rows as $idx => $row) {
                $rowNumber = $idx + 2;
                try {
                    $result = $this->processRow(
                        $row,
                        (int) $job['club_profile_id'],
                        $job['team_id'] !== null ? (int) $job['team_id'] : null,
                        (int) $job['user_id']
                    );
                    if ($result === 'created') $created++;
                    elseif ($result === 'updated') $updated++;
                    elseif ($result === 'skipped') $skipped++;
                } catch (Exception $e) {
                    $errors++;
                    $this->recordError($jobId, $rowNumber, $row, $e->getMessage());
                }

                $this->updateProgress($jobId, $idx + 1, $created, $updated, $skipped, $errors);
            }

            $this->markFinished($jobId, 'completed');
        } catch (Exception $e) {
            $this->recordError($jobId, 0, null, 'Fatal: ' . $e->getMessage());
            $this->markFinished($jobId, 'failed');
            throw $e;
        }
    }

    private function loadJob(int $jobId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM import_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function markStarted(int $jobId): void {
        $this->pdo->prepare("UPDATE import_jobs SET status = 'processing', started_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);
    }

    private function markFinished(int $jobId, string $status): void {
        $this->pdo->prepare("UPDATE import_jobs SET status = :status, finished_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId, 'status' => $status]);
    }

    private function updateProgress(int $jobId, int $processed, int $created, int $updated, int $skipped, int $errors): void {
        $this->pdo->prepare('
            UPDATE import_jobs
            SET processed_rows = :p, created_count = :c, updated_count = :u, skipped_count = :s, error_count = :e
            WHERE id = :id
        ')->execute([
            'id' => $jobId, 'p' => $processed, 'c' => $created, 'u' => $updated, 's' => $skipped, 'e' => $errors,
        ]);
    }

    private function recordError(int $jobId, int $rowNumber, ?array $rowJson, string $message): void {
        $this->pdo->prepare('
            INSERT INTO import_job_errors (job_id, row_number, row_json, error_message)
            VALUES (:job, :row, :json, :msg)
        ')->execute([
            'job'  => $jobId,
            'row'  => $rowNumber,
            'json' => $rowJson !== null ? json_encode($rowJson) : null,
            'msg'  => $message,
        ]);
    }

    private function parseCsv(string $content): array {
        $lines = preg_split("/\r\n|\n|\r/", trim($content));
        if (count($lines) < 2) return [];

        $headers = str_getcsv(array_shift($lines), ',', '"', '\\');
        $headers = array_map('trim', $headers);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line, ',', '"', '\\');
            $values = array_pad($values, count($headers), '');
            $rows[] = array_combine($headers, array_map('trim', $values));
        }
        return $rows;
    }

    private function processRow(array $row, int $clubId, ?int $teamId, int $createdBy): string {
        $athleteFirst = $row['athlete_first_name'] ?? '';
        $athleteLast  = $row['athlete_last_name'] ?? '';
        $athleteDob   = $row['athlete_dob'] ?? '';
        $athleteGender = $row['athlete_gender'] ?? '';

        if ($athleteFirst === '' || $athleteLast === '' || $athleteDob === '') {
            throw new RuntimeException('Missing athlete first_name, last_name, or dob');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $athleteDob)) {
            throw new RuntimeException("Invalid athlete_dob '{$athleteDob}' — must be YYYY-MM-DD");
        }
        if (!in_array($athleteGender, self::$ALLOWED_GENDERS, true)) {
            throw new RuntimeException("Invalid athlete_gender '{$athleteGender}' — must be one of: " . implode(', ', self::$ALLOWED_GENDERS));
        }

        $this->pdo->beginTransaction();
        try {
            $athleteResult = $this->upsertAthlete($row, $clubId, $createdBy);
            $athleteId = $athleteResult['id'];
            $rowOutcome = $athleteResult['outcome'];

            for ($n = 1; $n <= 2; $n++) {
                $gFirst = $row["guardian{$n}_first_name"] ?? '';
                $gLast  = $row["guardian{$n}_last_name"] ?? '';
                $gEmail = $row["guardian{$n}_email"] ?? '';
                if ($gFirst === '' && $gLast === '' && $gEmail === '') continue;
                if ($gFirst === '' || $gLast === '' || $gEmail === '') {
                    throw new RuntimeException("guardian{$n} requires first_name, last_name, and email");
                }

                $guardianId = $this->upsertGuardian([
                    'first_name'   => $gFirst,
                    'last_name'    => $gLast,
                    'email'        => $gEmail,
                    'mobile_phone' => $row["guardian{$n}_mobile"] ?? '',
                ]);

                $relationship = $row["guardian{$n}_relationship"] ?? 'Guardian';
                if (!in_array($relationship, self::$ALLOWED_RELATIONSHIPS, true)) {
                    $relationship = 'Guardian';
                }
                $isPrimary = $this->parseBool($row["guardian{$n}_is_primary"] ?? ($n === 1 ? 'true' : 'false'));

                $this->upsertAthleteGuardianLink($athleteId, $guardianId, $relationship, $isPrimary);
            }

            if ($teamId !== null) {
                $this->ensureTeamMembership($athleteId, $teamId);
            }

            $this->pdo->commit();
            return $rowOutcome;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function upsertAthlete(array $row, int $clubId, int $createdBy): array {
        $stmt = $this->pdo->prepare('
            SELECT id FROM athletes
            WHERE first_name = :first AND last_name = :last AND date_of_birth = :dob AND club_id = :club
        ');
        $stmt->execute([
            'first' => $row['athlete_first_name'],
            'last'  => $row['athlete_last_name'],
            'dob'   => $row['athlete_dob'],
            'club'  => $clubId,
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return ['id' => (int) $existing['id'], 'outcome' => 'skipped'];
        }

        $insert = $this->pdo->prepare('
            INSERT INTO athletes
                (first_name, last_name, date_of_birth, gender, grade_level, school_name, club_id, created_by, active_status)
            VALUES
                (:first, :last, :dob, :gender, :grade, :school, :club, :created_by, true)
            RETURNING id
        ');
        $insert->execute([
            'first'      => $row['athlete_first_name'],
            'last'       => $row['athlete_last_name'],
            'dob'        => $row['athlete_dob'],
            'gender'     => $row['athlete_gender'],
            'grade'      => $this->intOrNull($row['athlete_grade_level'] ?? ''),
            'school'     => $this->strOrNull($row['athlete_school'] ?? ''),
            'club'       => $clubId,
            'created_by' => $createdBy,
        ]);
        return ['id' => (int) $insert->fetchColumn(), 'outcome' => 'created'];
    }

    private function upsertGuardian(array $g): int {
        $stmt = $this->pdo->prepare('
            SELECT id FROM guardians
            WHERE email = :email AND first_name = :first AND last_name = :last
            LIMIT 1
        ');
        $stmt->execute([
            'email' => $g['email'],
            'first' => $g['first_name'],
            'last'  => $g['last_name'],
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return (int) $existing['id'];

        $mobile = $g['mobile_phone'] !== '' ? $g['mobile_phone'] : 'unknown';
        $insert = $this->pdo->prepare('
            INSERT INTO guardians (first_name, last_name, email, mobile_phone)
            VALUES (:first, :last, :email, :mobile)
            RETURNING id
        ');
        $insert->execute([
            'first'  => $g['first_name'],
            'last'   => $g['last_name'],
            'email'  => $g['email'],
            'mobile' => $mobile,
        ]);
        return (int) $insert->fetchColumn();
    }

    private function upsertAthleteGuardianLink(int $athleteId, int $guardianId, string $relationship, bool $isPrimary): void {
        $stmt = $this->pdo->prepare('
            SELECT id FROM athlete_guardians WHERE athlete_id = :a AND guardian_id = :g LIMIT 1
        ');
        $stmt->execute(['a' => $athleteId, 'g' => $guardianId]);
        if ($stmt->fetch()) return;

        $this->pdo->prepare('
            INSERT INTO athlete_guardians (athlete_id, guardian_id, relationship, is_primary)
            VALUES (:a, :g, :rel, :primary)
        ')->execute([
            'a' => $athleteId,
            'g' => $guardianId,
            'rel' => $relationship,
            'primary' => $isPrimary ? 'true' : 'false',
        ]);
    }

    private function ensureTeamMembership(int $athleteId, int $teamId): void {
        $stmt = $this->pdo->prepare('
            SELECT id FROM team_members WHERE team_id = :t AND athlete_id = :a LIMIT 1
        ');
        $stmt->execute(['t' => $teamId, 'a' => $athleteId]);
        if ($stmt->fetch()) return;

        $this->pdo->prepare("
            INSERT INTO team_members (team_id, athlete_id, role, status, join_date)
            VALUES (:t, :a, 'player', 'active', CURRENT_DATE)
        ")->execute(['t' => $teamId, 'a' => $athleteId]);
    }

    private function parseBool($v): bool {
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['t', 'true', '1', 'yes', 'y'], true);
    }

    private function intOrNull($v): ?int {
        $s = trim((string) $v);
        return $s === '' ? null : (int) $s;
    }

    private function strOrNull($v): ?string {
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
