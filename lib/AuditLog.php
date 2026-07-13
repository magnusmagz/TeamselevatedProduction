<?php
/**
 * AuditLog — writes to the audit_log table (who did what, to what, from where).
 *
 * First consumers are the money-adjacent admin actions (refunds, Stripe account
 * onboarding). Recording is BEST-EFFORT: an audit failure is logged but never
 * blocks the action itself — the ledger and Stripe remain the financial source
 * of truth; this table answers "which human asked for it, and why".
 */

class AuditLog {

    /**
     * @param int|null   $userId       acting user (null for system/webhook actions)
     * @param string     $action       e.g. 'payment.refund_requested'
     * @param string     $resourceType e.g. 'payment_transaction'
     * @param int|null   $resourceId
     * @param array      $details      JSON-encoded extras (amount, reason, ...)
     */
    public static function record(PDO $pdo, ?int $userId, string $action,
                                  string $resourceType, ?int $resourceId, array $details = []): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, action, resource_type, resource_id, ip_address, user_agent, details)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $action,
                $resourceType,
                $resourceId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
                $details ? json_encode($details) : null,
            ]);
            return true;
        } catch (Exception $e) {
            error_log('AuditLog::record failed (' . $action . '): ' . $e->getMessage());
            return false;
        }
    }
}
