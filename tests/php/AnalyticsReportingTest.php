<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Unit tests for the analytics-gateway reporting queries (CA-60 + CA-61).
 *
 * api/analytics-gateway.php is a procedural gateway that emits HTTP headers on
 * include, so it can't be required in a unit test. These tests exercise the two
 * pieces of logic the fixes depend on, implemented here with the SAME SQL and
 * the SAME derivation the gateway uses (against in-memory SQLite — never the
 * production Neon DB):
 *
 *   CA-60 — per-recipient breakdown: open/click booleans + first-seen
 *           timestamps are derived from email_events (NOT the bogus
 *           "communication_events" table the gateway used to query).
 *   CA-61 — top clicked links: aggregation over email_links joined to
 *           communication_log, with the inclusive date upper bound and the
 *           HAVING SUM(click_count) > 0 filter.
 *
 * Fixture (club 32):
 *   communication_log:
 *     1  individual email,  delivered, sent 2026-05-10
 *     2  broadcast 900 row, delivered, sent 2026-05-11
 *     3  broadcast 900 row, bounced,   sent 2026-05-11
 *   email_events:
 *     log 1: open, click
 *     log 2: open
 *     log 3: (none)
 *   email_links:
 *     log 1 -> /schedule click_count 5
 *     log 2 -> /schedule click_count 3
 *     log 1 -> /roster   click_count 0   (never clicked)
 */
class AnalyticsReportingTest extends TestCase
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
            CREATE TABLE communication_log (
                id INTEGER PRIMARY KEY,
                club_profile_id INTEGER,
                channel TEXT,
                broadcast_campaign_id INTEGER,
                recipient_name TEXT,
                recipient_email TEXT,
                recipient_phone TEXT,
                status TEXT,
                open_count INTEGER DEFAULT 0,
                click_count INTEGER DEFAULT 0,
                created_at TEXT
            );
            CREATE TABLE email_events (
                id INTEGER PRIMARY KEY,
                communication_log_id INTEGER,
                event_type TEXT,
                event_data TEXT,
                occurred_at TEXT
            );
            CREATE TABLE email_links (
                id INTEGER PRIMARY KEY,
                communication_log_id INTEGER,
                link_id TEXT,
                original_url TEXT,
                click_count INTEGER DEFAULT 0
            );
        ");
    }

    private function seed(): void
    {
        $this->pdo->exec("INSERT INTO communication_log
            (id, club_profile_id, channel, broadcast_campaign_id, recipient_name, recipient_email, status, open_count, click_count, created_at) VALUES
            (1, 32, 'email', NULL, 'Solo Sender', 'solo@example.com', 'delivered', 1, 1, '2026-05-10 09:00:00'),
            (2, 32, 'email', 900,  'Jane Doe',    'jane@example.com', 'delivered', 1, 0, '2026-05-11 09:00:00'),
            (3, 32, 'email', 900,  'Bob Lee',     'bob@example.com',  'bounced',   0, 0, '2026-05-11 09:00:00')");

        $this->pdo->exec("INSERT INTO email_events (communication_log_id, event_type, occurred_at) VALUES
            (1, 'open',  '2026-05-10 09:05:00'),
            (1, 'click', '2026-05-10 09:06:00'),
            (2, 'open',  '2026-05-11 10:00:00')");

        $this->pdo->exec("INSERT INTO email_links (communication_log_id, link_id, original_url, click_count) VALUES
            (1, 'a', 'https://club.example.com/schedule', 5),
            (2, 'b', 'https://club.example.com/schedule', 3),
            (1, 'c', 'https://club.example.com/roster',   0)");
    }

    // -----------------------------------------------------------------------
    // CA-60 — per-recipient open/click derivation from email_events.
    // Mirrors the firstEventTime() + per-recipient enrichment in the gateway.
    // -----------------------------------------------------------------------

    /** Mirrors the email_events fetch in handleSingleReport(). */
    private function fetchEvents(int $logId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT event_type, occurred_at, event_data
            FROM email_events
            WHERE communication_log_id = ?
            ORDER BY occurred_at ASC
        ");
        $stmt->execute([$logId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Mirrors firstEventTime() in api/analytics-gateway.php. */
    private function firstEventTime(array $events, string $type): ?string
    {
        foreach ($events as $evt) {
            if (($evt['event_type'] ?? null) === $type) {
                return $evt['occurred_at'];
            }
        }
        return null;
    }

    public function testSingleReportEventsQueryUsesEmailEventsTable(): void
    {
        // The bug was querying a non-existent "communication_events" table; the
        // real table is email_events. This must return rows without error.
        $events = $this->fetchEvents(1);
        $this->assertCount(2, $events);
        $this->assertSame('open', $events[0]['event_type']);
        $this->assertSame('click', $events[1]['event_type']);
    }

    public function testSingleRecipientOpenedAndClickedDerivedFromEvents(): void
    {
        $events    = $this->fetchEvents(1);
        $openedAt  = $this->firstEventTime($events, 'open');
        $clickedAt = $this->firstEventTime($events, 'click');

        $this->assertSame('2026-05-10 09:05:00', $openedAt);
        $this->assertSame('2026-05-10 09:06:00', $clickedAt);
        $this->assertTrue($openedAt !== null);   // opened
        $this->assertTrue($clickedAt !== null);  // clicked
    }

    public function testRecipientWithNoEventsIsNotOpenedOrClicked(): void
    {
        $events = $this->fetchEvents(3); // Bob Lee, bounced, no events
        $this->assertSame([], $events);
        $this->assertNull($this->firstEventTime($events, 'open'));
        $this->assertNull($this->firstEventTime($events, 'click'));
    }

    /** Mirrors the broadcast per-recipient event-time map in handleBroadcastReport(). */
    private function broadcastRecipientBreakdown(int $broadcastId): array
    {
        $rStmt = $this->pdo->prepare("
            SELECT id, recipient_name, recipient_email, status, open_count, click_count
            FROM communication_log
            WHERE broadcast_campaign_id = ?
            ORDER BY recipient_name
        ");
        $rStmt->execute([$broadcastId]);
        $rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        $logIds = array_column($rows, 'id');
        $openTimes = [];
        $clickTimes = [];
        if (!empty($logIds)) {
            $ph = implode(',', array_fill(0, count($logIds), '?'));
            $eStmt = $this->pdo->prepare("
                SELECT communication_log_id, event_type, MIN(occurred_at) as first_at
                FROM email_events
                WHERE communication_log_id IN ($ph)
                  AND event_type IN ('open', 'click')
                GROUP BY communication_log_id, event_type
            ");
            $eStmt->execute($logIds);
            foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $evt) {
                $lid = (int)$evt['communication_log_id'];
                if ($evt['event_type'] === 'open') {
                    $openTimes[$lid] = $evt['first_at'];
                } else {
                    $clickTimes[$lid] = $evt['first_at'];
                }
            }
        }

        return array_map(function ($r) use ($openTimes, $clickTimes) {
            $lid = (int)$r['id'];
            $openedAt  = $openTimes[$lid] ?? null;
            $clickedAt = $clickTimes[$lid] ?? null;
            return [
                'name'       => $r['recipient_name'],
                'status'     => $r['status'],
                'opened'     => $openedAt !== null || (int)$r['open_count'] > 0,
                'clicked'    => $clickedAt !== null || (int)$r['click_count'] > 0,
                'opened_at'  => $openedAt,
                'clicked_at' => $clickedAt,
            ];
        }, $rows);
    }

    public function testBroadcastPerRecipientBreakdown(): void
    {
        $recipients = $this->broadcastRecipientBreakdown(900);
        $this->assertCount(2, $recipients);

        // Ordered by recipient_name: Bob Lee, Jane Doe.
        $bob  = $recipients[0];
        $jane = $recipients[1];

        $this->assertSame('Bob Lee', $bob['name']);
        $this->assertFalse($bob['opened']);
        $this->assertFalse($bob['clicked']);
        $this->assertNull($bob['opened_at']);

        $this->assertSame('Jane Doe', $jane['name']);
        $this->assertTrue($jane['opened']);
        $this->assertSame('2026-05-11 10:00:00', $jane['opened_at']);
        $this->assertFalse($jane['clicked']); // no click event, click_count 0
    }

    // -----------------------------------------------------------------------
    // CA-61 — top clicked links aggregation + inclusive date bound + HAVING.
    // Mirrors handleLinkAnalytics() incl. the buildDateFilter() upper bound.
    // -----------------------------------------------------------------------

    /**
     * Mirrors handleLinkAnalytics(). SQLite has no `::date + interval` syntax,
     * so the inclusive upper bound is expressed here as `< date(?, '+1 day')`,
     * which is the exact same semantics the gateway's
     * `created_at < (?::date + interval '1 day')` produces on Postgres.
     */
    private function topClickedLinks(int $clubId, ?string $dateFrom, ?string $dateTo): array
    {
        $where = '';
        $params = [$clubId];
        if ($dateFrom) {
            $where .= " AND cl.created_at >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND cl.created_at < date(?, '+1 day')";
            $params[] = $dateTo;
        }

        $sql = "
            SELECT el.original_url,
                   SUM(el.click_count) as total_clicks,
                   COUNT(DISTINCT el.communication_log_id) as emails_containing
            FROM email_links el
            JOIN communication_log cl ON el.communication_log_id = cl.id
            WHERE cl.club_profile_id = ?
            {$where}
            GROUP BY el.original_url
            HAVING SUM(el.click_count) > 0
            ORDER BY total_clicks DESC
            LIMIT 20
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testTopLinksAggregatesClicksAcrossSends(): void
    {
        $links = $this->topClickedLinks(32, null, null);
        // /schedule = 5 + 3 = 8 across 2 emails. /roster has 0 clicks -> excluded.
        $this->assertCount(1, $links);
        $this->assertSame('https://club.example.com/schedule', $links[0]['original_url']);
        $this->assertSame(8, (int)$links[0]['total_clicks']);
        $this->assertSame(2, (int)$links[0]['emails_containing']);
    }

    public function testZeroClickLinksAreExcluded(): void
    {
        $links = $this->topClickedLinks(32, null, null);
        $urls = array_column($links, 'original_url');
        $this->assertNotContains('https://club.example.com/roster', $urls);
    }

    public function testInclusiveDateUpperBoundIncludesFinalDaySends(): void
    {
        // date_to = 2026-05-11. The 05-11 send (log 2, 3 clicks to /schedule)
        // must be INCLUDED. With the old `created_at <= '2026-05-11'` bound it
        // coerced to 00:00:00 and dropped the 09:00 send entirely.
        $links = $this->topClickedLinks(32, '2026-05-10', '2026-05-11');
        $this->assertCount(1, $links);
        $this->assertSame(8, (int)$links[0]['total_clicks']); // 5 (05-10) + 3 (05-11)
    }

    public function testDateRangeExcludesSendsOutsideWindow(): void
    {
        // Only 2026-05-10: just log 1's /schedule click (5).
        $links = $this->topClickedLinks(32, '2026-05-10', '2026-05-10');
        $this->assertCount(1, $links);
        $this->assertSame(5, (int)$links[0]['total_clicks']);
    }

    public function testClubScopingFiltersOtherClubs(): void
    {
        $links = $this->topClickedLinks(99, null, null); // no data for club 99
        $this->assertSame([], $links);
    }
}
