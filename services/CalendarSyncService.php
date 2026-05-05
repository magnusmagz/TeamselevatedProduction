<?php

require_once __DIR__ . '/IcsParserService.php';

class CalendarSyncService
{
    private PDO $db;
    private IcsParserService $parser;

    public function __construct(PDO $db, ?IcsParserService $parser = null)
    {
        $this->db = $db;
        $this->parser = $parser ?? new IcsParserService();
    }

    public function syncSubscription(int $subscriptionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM calendar_subscriptions WHERE id = ?');
        $stmt->execute([$subscriptionId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            throw new RuntimeException("Subscription {$subscriptionId} not found");
        }

        if (empty($sub['feed_url'])) {
            throw new RuntimeException("Subscription {$subscriptionId} has no feed URL");
        }

        $icsContent = $this->fetchUrl($sub['feed_url']);

        return $this->importFromContent($icsContent, $subscriptionId, (int)$sub['club_id'], $sub['team_id'] ? (int)$sub['team_id'] : null);
    }

    public function importFromContent(string $icsContent, int $subscriptionId, int $clubId, ?int $teamId): array
    {
        $events = $this->parser->parse($icsContent);

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        $this->db->beginTransaction();

        try {
            $upsertStmt = $this->db->prepare("
                INSERT INTO calendar_events (
                    club_id, name, type, event_date, start_time, end_time,
                    location, description, status, subscription_id, external_uid
                ) VALUES (?, ?, 'event', ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (subscription_id, external_uid)
                    WHERE subscription_id IS NOT NULL AND external_uid IS NOT NULL
                DO UPDATE SET
                    name = EXCLUDED.name,
                    event_date = EXCLUDED.event_date,
                    start_time = EXCLUDED.start_time,
                    end_time = EXCLUDED.end_time,
                    location = EXCLUDED.location,
                    description = EXCLUDED.description,
                    status = EXCLUDED.status
                RETURNING id, (xmax = 0) AS is_insert
            ");

            $teamStmt = null;
            if ($teamId) {
                $teamStmt = $this->db->prepare("
                    INSERT INTO calendar_event_teams (event_id, team_id)
                    VALUES (?, ?)
                    ON CONFLICT DO NOTHING
                ");
            }

            foreach ($events as $event) {
                if (empty($event['date'])) {
                    $skipped++;
                    continue;
                }

                $upsertStmt->execute([
                    $clubId,
                    $event['summary'],
                    $event['date'],
                    $event['start_time'],
                    $event['end_time'],
                    $event['location'],
                    $this->truncate($event['description'], 5000),
                    $event['status'],
                    $subscriptionId,
                    $event['uid'],
                ]);

                $row = $upsertStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if ($row['is_insert']) {
                        $imported++;
                    } else {
                        $updated++;
                    }

                    if ($teamStmt && $row['id']) {
                        $teamStmt->execute([$row['id'], $teamId]);
                    }
                }
            }

            $eventCount = $imported + $updated;
            $this->updateSubscriptionStatus($subscriptionId, 'success', null, $eventCount);

            $this->db->commit();

            return [
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
                'total'    => count($events),
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->updateSubscriptionStatus($subscriptionId, 'error', $e->getMessage(), null);
            throw $e;
        }
    }

    private function fetchUrl(string $url): string
    {
        $url = preg_replace('/^webcal:\/\//', 'https://', $url);

        $context = stream_context_create([
            'http' => [
                'timeout'       => 15,
                'user_agent'    => 'TeamsElevated-CalendarSync/1.0',
                'follow_location' => true,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last();
            throw new RuntimeException("Failed to fetch ICS feed: " . ($error['message'] ?? 'Unknown error'));
        }

        if (empty(trim($content))) {
            throw new RuntimeException("ICS feed returned empty response");
        }

        if (stripos($content, 'BEGIN:VCALENDAR') === false) {
            throw new RuntimeException("Response does not appear to be a valid ICS file");
        }

        return $content;
    }

    private function updateSubscriptionStatus(int $subscriptionId, string $status, ?string $error, ?int $eventCount): void
    {
        $sql = "UPDATE calendar_subscriptions SET last_synced_at = NOW(), last_sync_status = ?, last_sync_error = ?, updated_at = NOW()";
        $params = [$status, $error];

        if ($eventCount !== null) {
            $sql .= ", event_count = ?";
            $params[] = $eventCount;
        }

        $sql .= " WHERE id = ?";
        $params[] = $subscriptionId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function truncate(?string $text, int $maxLength): ?string
    {
        if ($text === null) {
            return null;
        }
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
