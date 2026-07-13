<?php

namespace TeamsElevated\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use AuditLog;

class AuditLogTest extends TestCase {

    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER, action TEXT NOT NULL, resource_type TEXT, resource_id INTEGER,
                ip_address TEXT, user_agent TEXT, details TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function testRecordWritesRowWithDetails(): void {
        $ok = AuditLog::record($this->pdo, 42, 'payment.refund_requested', 'payment_transaction', 7, [
            'amount' => 25.00, 'reason' => 'duplicate charge',
        ]);

        $this->assertTrue($ok);
        $row = $this->pdo->query("SELECT * FROM audit_log")->fetch();
        $this->assertEquals(42, $row['user_id']);
        $this->assertSame('payment.refund_requested', $row['action']);
        $this->assertSame('payment_transaction', $row['resource_type']);
        $this->assertEquals(7, $row['resource_id']);
        $details = json_decode($row['details'], true);
        $this->assertSame('duplicate charge', $details['reason']);
        $this->assertEquals(25.00, $details['amount']);
    }

    public function testRecordFailureIsNonFatal(): void {
        $this->pdo->exec("DROP TABLE audit_log");
        $ok = AuditLog::record($this->pdo, 1, 'x', 'y', null);
        $this->assertFalse($ok); // logged, swallowed — never blocks the action
    }
}
