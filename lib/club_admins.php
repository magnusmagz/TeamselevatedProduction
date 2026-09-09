<?php
/**
 * Who are a club's administrators? One query, shared.
 *
 * user_club_access is authoritative for roles (CLAUDE.md), and `revoked_at` is
 * checked alongside `active` because the two can disagree and the revocation is
 * the newer fact — the gap lib/JWT.php had to close on 2026-08-04.
 *
 * Coaches are deliberately excluded: every caller is a club-admin surface
 * (chat moderation alerts, the public contact form) and mailing a coach about
 * something they cannot open — or a message the club did not route to them —
 * is wrong in both directions.
 *
 * Extracted from lib/chat_moderation_alerts.php (te_chat_club_admins) on
 * 2026-09-09 when the public club page's contact form needed the same answer.
 */

if (!function_exists('te_club_admin_recipients')) {
    /**
     * Active, unrevoked club_admins of the club who have an email address.
     *
     * @return array<int,array{id:int,email:string,first_name:string}>
     */
    function te_club_admin_recipients(PDO $pdo, int $clubId): array
    {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.email, u.first_name
              FROM users u
              JOIN user_club_access uca ON uca.user_id = u.id
             WHERE uca.club_profile_id = ?
               AND uca.active = TRUE
               AND uca.revoked_at IS NULL
               AND uca.role IN ('club_admin', 'admin', 'owner')
               AND u.email IS NOT NULL
               AND u.email <> ''
             ORDER BY u.id
        ");
        $stmt->execute([$clubId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
