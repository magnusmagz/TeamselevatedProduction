<?php

use PHPUnit\Framework\TestCase;

/**
 * AuditLogger against an in-memory SQLite audit_log.
 *
 * The contract that matters most is the negative one: auditing must never break
 * the operation it records. Several tests here assert that a broken audit path
 * returns false rather than throwing.
 */
class AuditLoggerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // NOW() is Postgres/MySQL; SQLite needs a shim for the same SQL to run.
        $this->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $this->pdo->exec('CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER, action TEXT, resource_type TEXT, resource_id INTEGER,
            ip_address TEXT, user_agent TEXT, details TEXT, created_at TEXT
        )');
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testWritesAnAuditRow(): void
    {
        $ok = AuditLogger::log($this->pdo, 118, 'view_medical', 'athlete_medical', 457, ['athlete_id' => 457]);

        $this->assertTrue($ok);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('118', (string) $rows[0]['user_id']);
        $this->assertSame('view_medical', $rows[0]['action']);
        $this->assertSame('athlete_medical', $rows[0]['resource_type']);
        $this->assertSame('457', (string) $rows[0]['resource_id']);
        $this->assertSame(['athlete_id' => 457], json_decode($rows[0]['details'], true));
        $this->assertNotEmpty($rows[0]['created_at']);
    }

    public function testUnauthenticatedActorIsRecordedAsNull(): void
    {
        // A guardian confirming consent from an emailed link is not logged in.
        AuditLogger::log($this->pdo, null, 'consent_email_confirmed', 'consent_records', 5);
        $this->assertNull($this->rows()[0]['user_id']);
    }

    public function testUserIdZeroBecomesNullRatherThanUserZero(): void
    {
        AuditLogger::log($this->pdo, 0, 'login_failure', 'users', null);
        $this->assertNull($this->rows()[0]['user_id'], 'user 0 does not exist; store null');
    }

    public function testPrefersForwardedForOverProxyAddress(): void
    {
        // Behind Heroku's router REMOTE_ADDR is the proxy, not the caller.
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.1.2.3';

        AuditLogger::log($this->pdo, 1, 'login_success', 'users', 1);
        $this->assertSame('203.0.113.9', $this->rows()[0]['ip_address']);
    }

    public function testFallsBackToRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        AuditLogger::log($this->pdo, 1, 'login_success', 'users', 1);
        $this->assertSame('198.51.100.7', $this->rows()[0]['ip_address']);
    }

    public function testTruncatesAbsurdUserAgent(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 5000);
        AuditLogger::log($this->pdo, 1, 'view_athlete', 'athletes', 1);
        $this->assertSame(500, strlen($this->rows()[0]['user_agent']));
    }

    public function testEmptyDetailsStoredAsNullNotEmptyJson(): void
    {
        AuditLogger::log($this->pdo, 1, 'view_athlete', 'athletes', 1);
        $this->assertNull($this->rows()[0]['details']);
    }

    /**
     * The whole point of the class: a broken audit path must not take the
     * operation down with it.
     */
    public function testMissingTableReturnsFalseInsteadOfThrowing(): void
    {
        $broken = new PDO('sqlite::memory:');
        $broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // No audit_log table at all.

        $result = AuditLogger::log($broken, 1, 'view_medical', 'athlete_medical', 1);

        $this->assertFalse($result, 'must report failure, not raise');
    }

    public function testNonUtf8DetailsDoNotThrow(): void
    {
        // json_encode returns false on invalid UTF-8; that must not become a fatal.
        $result = AuditLogger::log($this->pdo, 1, 'view_medical', 'athlete_medical', 1, [
            'note' => "\xB1\x31 invalid",
        ]);
        $this->assertIsBool($result);
    }
}
