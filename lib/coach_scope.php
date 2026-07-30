<?php
/**
 * Which teams a coach may act on within a club.
 *
 * Previously copy-pasted byte-for-byte into both communications-gateway.php and
 * recipient-search-gateway.php. Only one gateway loads per request so the
 * duplication never collided at runtime, but it meant a scoping fix could land in
 * one copy and not the other — and a test that requires both fatals on redeclare.
 *
 * A coach is scoped to a team if they are its primary_coach_id OR hold an active
 * assistant_coach / team_manager membership. Club-level roles come from
 * user_club_access (authoritative), not users.role.
 *
 * NOTE: no deleted_at filter. A soft-deleted team still resolves here, which is
 * the pre-extraction behavior, preserved deliberately — tightening it changes who
 * can send in both gateways at once and belongs in its own change.
 */
if (!function_exists('getCoachTeamIds')) {
    function getCoachTeamIds($connection, $userId, $clubProfileId)
    {
        $stmt = $connection->prepare("
            SELECT DISTINCT t.id FROM teams t
            LEFT JOIN team_members tm ON t.id = tm.team_id AND tm.user_id = ?
                AND tm.role IN ('assistant_coach','team_manager') AND tm.status = 'active'
            WHERE (t.primary_coach_id = ? OR tm.id IS NOT NULL) AND t.club_id = ?
        ");
        $stmt->execute([$userId, $userId, $clubProfileId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
