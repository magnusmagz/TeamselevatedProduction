<?php
require_once __DIR__ . '/ImportStrategy.php';
require_once __DIR__ . '/AthleteImportStrategy.php';
require_once __DIR__ . '/FacilityImportStrategy.php';

/**
 * ImportJobProcessor — the entity-agnostic outer loop for bulk imports.
 *
 * Loads an import_jobs row, parses its csv_content, iterates rows, delegates
 * per-row upsert work to the registered ImportStrategy for the job's
 * entity_type, and updates progress/error counters in the import_jobs table.
 *
 * Used by:
 *   - workers/queue-worker.php (Redis queue dispatch)
 *   - api/imports-gateway.php (to resolve strategies for preview/upload)
 */

class ImportJobProcessor {
    private $pdo;
    /** @var array<string, ImportStrategy> */
    private $strategies = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function register(ImportStrategy $strategy): void {
        $this->strategies[$strategy->getEntityType()] = $strategy;
    }

    public function getStrategy(string $entityType): ?ImportStrategy {
        return $this->strategies[$entityType] ?? null;
    }

    /** @return string[] */
    public function getEntityTypes(): array {
        return array_keys($this->strategies);
    }

    /**
     * Build a processor with all currently-supported strategies registered.
     * Adding a new entity type = one line here.
     */
    public static function buildDefault(PDO $pdo): self {
        $processor = new self($pdo);
        $processor->register(new AthleteImportStrategy());
        $processor->register(new FacilityImportStrategy());
        // Future: volunteers, coaches, users, teams
        return $processor;
    }

    /**
     * Process a Redis job payload. Payload must contain { job_id }.
     */
    public function processJob(array $payload): void {
        $jobId = (int) ($payload['job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('import job missing job_id');
        }

        $job = $this->loadJob($jobId);
        if (!$job) {
            throw new RuntimeException("import_jobs row {$jobId} not found");
        }

        $entityType = $job['entity_type'];
        $strategy = $this->getStrategy($entityType);
        if (!$strategy) {
            $this->recordError($jobId, 0, null, "No import strategy registered for entity_type '{$entityType}'");
            $this->markFinished($jobId, 'failed');
            throw new RuntimeException("No strategy for entity_type '{$entityType}'");
        }

        $this->markStarted($jobId);

        try {
            $rows = $this->parseCsv($job['csv_content']);
            if (empty($rows)) {
                $this->markFinished($jobId, 'completed');
                return;
            }

            $mapping = is_string($job['column_mapping'] ?? null)
                ? json_decode($job['column_mapping'], true)
                : ($job['column_mapping'] ?? []);
            if (!is_array($mapping)) $mapping = [];

            $context = [
                'pdo'     => $this->pdo,
                'club_id' => (int) $job['club_profile_id'],
                'team_id' => $job['team_id'] !== null ? (int) $job['team_id'] : null,
                'user_id' => (int) $job['user_id'],
            ];

            $created = 0; $updated = 0; $skipped = 0; $errors = 0;

            foreach ($rows as $idx => $row) {
                $rowNumber = $idx + 2; // +1 for header row, +1 for 1-based
                try {
                    $result = $strategy->processRow($row, $mapping, $context);
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

    // ─────────────────────────────────────────────────────────────

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

        $headers = array_map('trim', str_getcsv(array_shift($lines), ',', '"', '\\'));

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line, ',', '"', '\\');
            $values = array_pad($values, count($headers), '');
            $rows[] = array_combine($headers, array_map('trim', $values));
        }
        return $rows;
    }
}
