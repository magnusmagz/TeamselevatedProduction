<?php
/**
 * Email suppression check that respects the unsubscribe SCOPE.
 *
 * Extracted from EmailSendService::isSuppressed so the compliance behavior is
 * unit-testable. Rule:
 *   - A club-wide opt-out (scope='club', or a legacy row with no scope) blocks all
 *     of that club's email.
 *   - A team-scoped opt-out (scope='team', team_id=X) blocks ONLY sends that target
 *     team X — so unsubscribing from one team no longer silently stops all club email.
 *   - A tournament-scoped opt-out is ignored here; it only blocks tournament sends,
 *     which are a separate path.
 */
if (!function_exists('te_email_suppressed')) {
    /**
     * @param PDO    $pdo
     * @param string $email
     * @param int    $clubProfileId
     * @param array  $teamIds  Teams this send targets (empty = general/individual send).
     * @return bool   true if the recipient should be suppressed for this send.
     */
    function te_email_suppressed(PDO $pdo, string $email, int $clubProfileId, array $teamIds = []): bool
    {
        $sql = "SELECT COUNT(*) FROM email_suppressions
                WHERE email = ? AND club_profile_id = ? AND channel = 'email'
                  AND (scope = 'club' OR scope IS NULL";
        $params = [$email, $clubProfileId];

        if (!empty($teamIds)) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $sql .= " OR (scope = 'team' AND team_id IN ($placeholders))";
            $params = array_merge($params, array_map('intval', $teamIds));
        }
        $sql .= ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }
}
