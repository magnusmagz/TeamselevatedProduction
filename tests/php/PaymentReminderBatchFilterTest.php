<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;

/**
 * api/payment-reminders.php batch query: `AND (subq) IS NULL OR (subq) < ...`.
 *
 * AND binds tighter than OR, so the second branch carried no club, status, amount or
 * due-date filter — every athlete_payment in the database with a reminder older than
 * three days, across all clubs. Latent on 2026-08-31 because payment_reminder_log was
 * empty; it would have activated the second time anyone pressed "Send batch reminders".
 *
 * Executed against SQLite with the Postgres-only pieces normalised, because the bug is
 * precedence and only a running query proves precedence. The fixture has one due
 * payment in the caller's club, and one paid-off payment in ANOTHER club whose last
 * reminder is old — the second must never be selected.
 */
class PaymentReminderBatchFilterTest extends TestCase
{
    private const FILE = __DIR__ . '/../../api/payment-reminders.php';

    private function batchSql(): string
    {
        $src = file_get_contents(self::FILE);
        $start = strpos($src, 'SELECT ap.id');
        $this->assertNotFalse($start);
        $start = strrpos(substr($src, 0, $start), '$stmt = $pdo->prepare("');
        $this->assertNotFalse($start);
        $start += strlen('$stmt = $pdo->prepare("');
        $end = strpos($src, '");', $start);
        $sql = substr($src, $start, $end - $start);
        $this->assertStringContainsString('payment_reminder_log', $sql, 'expected the batch reminder query');
        return $sql;
    }

    public function testTheOrGroupIsParenthesised(): void
    {
        $sql = preg_replace('/--[^\n]*/', '', $this->batchSql());
        $this->assertMatchesRegularExpression(
            '/AND\s*\(\s*\(\s*SELECT MAX\(sent_at\)[\s\S]*?\)\s*IS NULL\s*OR\s*\([\s\S]*?INTERVAL \'3 days\'\s*\)/',
            $sql
        );
    }

    public function testAnOldReminderInAnotherClubIsNotSelected(): void
    {
        $sql = preg_replace('/--[^\n]*/', '', $this->batchSql());
        $sql = str_replace('$whereType', '1=1', $sql);
        $sql = str_replace("CURRENT_TIMESTAMP - INTERVAL '3 days'", "datetime('now', '-3 days')", $sql);
        $sql = str_replace(':league_id', '51', $sql);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE programs (id INTEGER PRIMARY KEY, club_id INTEGER)");
        $pdo->exec("CREATE TABLE athlete_payments (id INTEGER PRIMARY KEY, program_id INTEGER, status TEXT, amount_remaining REAL, due_date TEXT, athlete_id INTEGER)");
        $pdo->exec("CREATE TABLE payment_reminder_log (id INTEGER PRIMARY KEY, athlete_payment_id INTEGER, sent_at TEXT)");
        $pdo->exec("INSERT INTO programs VALUES (1, 51), (2, 32)");
        // Due, in our club, never reminded: selected.
        $pdo->exec("INSERT INTO athlete_payments VALUES (10, 1, 'pending', 100, '2026-09-30', 1)");
        // PAID OFF, in ANOTHER club, reminded ten days ago: the unparenthesised OR selected this.
        $pdo->exec("INSERT INTO athlete_payments VALUES (20, 2, 'paid', 0, '2026-01-01', 2)");
        $pdo->exec("INSERT INTO payment_reminder_log VALUES (1, 20, datetime('now', '-10 days'))");

        // The live query joins tables the fixture does not model; strip to the shape under test.
        $sql = preg_replace('/JOIN (athletes|users|guardians)[^\n]*\n/', '', $sql);
        $sql = preg_replace('/SELECT[\s\S]*?FROM athlete_payments ap/', 'SELECT ap.id FROM athlete_payments ap', $sql);

        $ids = $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['10'], array_map('strval', $ids));
    }
}
