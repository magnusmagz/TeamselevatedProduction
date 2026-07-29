<?php
/**
 * Audit trail for sensitive operations.
 *
 * COPPA-COMPLIANCE.md documents this class as deployed (Feb 2026). Like
 * lib/Encryption.php and api/consent.php it is absent from the tree and from git
 * history, so the `audit_log` table has existed with almost nothing writing to it.
 *
 * The one rule that matters: auditing must never break the operation it records.
 * A failed audit write is logged to error_log and swallowed — an outage in the
 * audit path must not stop a guardian confirming consent or a coach reading a
 * roster.
 */

class AuditLogger
{
    /**
     * Record an auditable action.
     *
     * @param PDO         $pdo
     * @param int|null    $userId       Actor. Null for unauthenticated actions
     *                                  (e.g. a guardian clicking an emailed link).
     * @param string      $action       e.g. 'view_medical', 'consent_revoked'.
     * @param string|null $resourceType Table or domain object the action targets.
     * @param int|null    $resourceId
     * @param array       $details      Extra context, stored as JSON.
     * @return bool  Whether the row was written. Callers may ignore it.
     */
    public static function log(
        PDO $pdo,
        ?int $userId,
        string $action,
        ?string $resourceType = null,
        ?int $resourceId = null,
        array $details = []
    ): bool {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log
                    (user_id, action, resource_type, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            return $stmt->execute([
                $userId ?: null,
                $action,
                $resourceType,
                $resourceId,
                self::clientIp(),
                self::userAgent(),
                $details ? json_encode($details) : null,
            ]);
        } catch (Throwable $e) {
            // Deliberately swallowed — see the class docblock.
            error_log('AuditLogger: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Originating client IP.
     *
     * Behind Heroku's router REMOTE_ADDR is the proxy, so prefer the first entry
     * of X-Forwarded-For — the client as seen by the edge. Later entries are
     * appended by intermediate proxies and are not the caller.
     */
    private static function clientIp(): ?string
    {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($fwd) && $fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if ($first !== '') {
                return substr($first, 0, 45); // fits IPv6
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        return $remote ? substr((string) $remote, 0, 45) : null;
    }

    /** Truncated so a hostile or absurd UA cannot blow up the column. */
    private static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua ? substr((string) $ua, 0, 500) : null;
    }
}
