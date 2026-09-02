<?php
/**
 * AthleteScope — centralized athlete access-control scoping.
 *
 * Decides which athletes a given authenticated user may read, enforcing:
 *   - club_admin: athletes belonging to a club the admin can access
 *   - coach:      athletes on a team the user coaches (primary_coach_id OR
 *                 active assistant_coach/team_manager team_members row)
 *   - guardian:   athletes the user is a guardian of (resolved by user id through
 *                 lib/guardian_identity.php — user_guardians links UNION the
 *                 guardians.email match — linked via athlete_guardians)
 *   - super_admin: everything
 *   - otherwise:  no access
 *
 * All DB access is injected (pass $pdo) so the logic is unit-testable against
 * an in-memory SQLite fixture or a PDO test double. No global state.
 *
 * Notes:
 *  - Athletes have no club_id column; an athlete's club(s) are derived via
 *    team_members.athlete_id -> teams.club_id (mirrors recipient-search-gateway).
 *  - Coach->teams definition mirrors models/Coach.php::getCoachTeams() and
 *    recipient-search-gateway::getCoachTeamIds().
 *  - Guardian detection is te_guardian_ids_for_user() / te_user_is_guardian_of_athlete()
 *    in lib/guardian_identity.php, shared with api/financial-permissions.php.
 *  - PostgreSQL booleans use TRUE/FALSE.
 */

require_once __DIR__ . '/AuthMiddleware.php';
require_once __DIR__ . '/guardian_identity.php';

class AthleteScope {

    /**
     * Team IDs the given user coaches.
     *
     * A coach's teams are those where teams.primary_coach_id = $userId OR the
     * user has an active team_members row with role assistant_coach/team_manager.
     * (Matches models/Coach.php and recipient-search-gateway.php.)
     *
     * @param PDO $pdo
     * @param int $userId
     * @return int[] distinct team IDs
     */
    public static function coachTeamIdsForUser(PDO $pdo, int $userId): array {
        $sql = "
            SELECT DISTINCT t.id
            FROM teams t
            LEFT JOIN team_members tm
                ON tm.team_id = t.id
                AND tm.user_id = :uid_tm
                AND tm.role IN ('assistant_coach', 'team_manager')
                AND tm.status = 'active'
            WHERE (t.primary_coach_id = :uid_primary OR tm.id IS NOT NULL)
              AND t.deleted_at IS NULL
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid_tm' => $userId, ':uid_primary' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Distinct club IDs an athlete belongs to.
     *
     * An athlete's club is derived from BOTH:
     *   - their direct athletes.club_id (set on creation/registration — covers
     *     club athletes who are not yet assigned to any team), and
     *   - their team memberships (team_members.athlete_id -> teams.club_id).
     *
     * Relying on team membership alone hid athletes who belong to a club but
     * have no team yet from the People -> Athletes list and detail view for the
     * club admin (CA-18). Including the direct club_id closes that gap while
     * keeping the team-derived path for athletes whose club_id was never set.
     *
     * @param PDO $pdo
     * @param int $athleteId
     * @return int[] distinct club IDs
     */
    public static function athleteClubIds(PDO $pdo, int $athleteId): array {
        $ids = [];

        // Team-derived clubs.
        $sql = "
            SELECT DISTINCT t.club_id
            FROM team_members tm
            JOIN teams t ON t.id = tm.team_id
            WHERE tm.athlete_id = :aid
              AND t.club_id IS NOT NULL
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':aid' => $athleteId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $clubId) {
            $ids[(int) $clubId] = true;
        }

        // Direct athletes.club_id (guarded: older fixtures may lack the column).
        try {
            $stmt = $pdo->prepare("SELECT club_id FROM athletes WHERE id = :aid AND club_id IS NOT NULL");
            $stmt->execute([':aid' => $athleteId]);
            $direct = $stmt->fetchColumn();
            if ($direct !== false && $direct !== null) {
                $ids[(int) $direct] = true;
            }
        } catch (\PDOException $e) {
            // athletes.club_id not present in this schema — ignore.
        }

        return array_keys($ids);
    }

    /**
     * Is this user a guardian of the given athlete?
     *
     * ⚠️ This takes a USER ID. It used to take the requester's email string, which made
     * identity a string comparison at a security boundary — this predicate gates consent
     * recording, medical edits and jersey writes. An account and its guardian row can
     * legitimately hold different addresses (Allix Boyce: @gmail login, @yahoo guardian
     * row), and when they did, the parent was refused their own child. Changed
     * 2026-09-02 as phase 2 of docs/user-guardians-identity-plan.md; both callers already
     * had the user in hand.
     *
     * The answer itself lives in lib/guardian_identity.php so the resolver, and not this
     * class, is the single definition of "which guardian rows belong to this account".
     *
     * @param PDO $pdo
     * @param int $userId requester's users.id
     * @param int $athleteId
     * @return bool
     */
    public static function isGuardianOfAthlete(PDO $pdo, int $userId, int $athleteId): bool {
        return te_user_is_guardian_of_athlete($pdo, $userId, $athleteId);
    }

    /**
     * Does this user coach a team the athlete is a member of?
     *
     * @param PDO $pdo
     * @param int $userId
     * @param int $athleteId
     * @return bool
     */
    public static function coachesAthlete(PDO $pdo, int $userId, int $athleteId): bool {
        $teamIds = self::coachTeamIdsForUser($pdo, $userId);
        if (empty($teamIds)) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $sql = "
            SELECT 1 FROM team_members tm
            WHERE tm.athlete_id = ? AND tm.team_id IN ($placeholders)
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$athleteId], $teamIds));
        return $stmt->fetch() !== false;
    }

    /**
     * Does this user hold STAFF standing over the athlete — club admin of one of
     * their clubs, a coach of one of their teams, or a super admin?
     *
     * This is userCanAccessAthlete() minus the guardian branch, and the split is
     * the point. "May I see this child" and "may I rewrite this child's record"
     * are different questions, and a guardian is a legitimate yes to the first
     * and not to the second. Reading is a superset of managing, so
     * userCanAccessAthlete() is defined in terms of THIS rather than the two
     * being maintained as parallel lists that can drift.
     *
     * Callers that mutate an athlete want this one. See the PUT and DELETE
     * handlers in legacy/athletes-gateway.php for why.
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth authenticated requester
     * @param int $athleteId
     * @return bool
     */
    public static function staffCanManageAthlete(PDO $pdo, AuthMiddleware $auth, int $athleteId): bool {
        // 1. Super admins can manage everything.
        if ($auth->isSuperAdmin()) {
            return true;
        }

        // 2. Club admin of any club the athlete belongs to. athleteClubIds()
        //    covers both team-derived clubs and the direct athletes.club_id, so a
        //    club athlete with no team yet is still manageable by their admin.
        foreach (self::athleteClubIds($pdo, $athleteId) as $clubId) {
            if ($auth->hasRole('club_admin', $clubId, 'club')) {
                return true;
            }
        }

        // 3. Coach of a team the athlete is on.
        $userId = (int) $auth->getUserId();
        return $userId > 0 && self::coachesAthlete($pdo, $userId, $athleteId);
    }

    /**
     * Core authorization decision: may this authenticated user READ this athlete?
     *
     * Staff standing (see staffCanManageAthlete) OR guardian of the athlete.
     *
     * Read access only. Do NOT reuse this to gate a write — a guardian passes it,
     * which is correct for viewing their own child and wrong for editing them.
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth authenticated requester
     * @param int $athleteId
     * @return bool
     */
    public static function userCanAccessAthlete(PDO $pdo, AuthMiddleware $auth, int $athleteId): bool {
        if (self::staffCanManageAthlete($pdo, $auth, $athleteId)) {
            return true;
        }

        // Guardian: allow if the requester's ACCOUNT is a guardian of the athlete —
        // by recorded link or by the email match, resolved in one place.
        $userId = (int) $auth->getUserId();
        if ($userId > 0 && self::isGuardianOfAthlete($pdo, $userId, $athleteId)) {
            return true;
        }

        return false;
    }

    /**
     * Build a WHERE fragment + params restricting a LIST query to athletes the
     * requester may access. Designed for queries that join athletes `a` to
     * team_members and (optionally) teams.
     *
     * Returns the set of accessible athlete IDs and a ready-made SQL fragment of
     * the form "AND a.id IN (?, ?, ...)" (or "AND 1=0" when nothing accessible,
     * or "" for super_admin = unrestricted).
     *
     * Using an explicit id list keeps the caller's existing query shape intact
     * regardless of how it joins, avoiding accidental row multiplication.
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth
     * @param string $athleteIdColumn column to scope (default 'a.id')
     * @return array{sql:string, params:array, athlete_ids:int[]|null}
     *               athlete_ids is null for super_admin (unrestricted).
     */
    public static function accessibleAthleteFilter(PDO $pdo, AuthMiddleware $auth, string $athleteIdColumn = 'a.id'): array {
        // Super admin: unrestricted.
        if ($auth->isSuperAdmin()) {
            return ['sql' => '', 'params' => [], 'athlete_ids' => null];
        }

        $ids = self::accessibleAthleteIds($pdo, $auth);

        if (empty($ids)) {
            return ['sql' => 'AND 1=0', 'params' => [], 'athlete_ids' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return [
            'sql' => "AND {$athleteIdColumn} IN ({$placeholders})",
            'params' => array_values($ids),
            'athlete_ids' => array_values($ids),
        ];
    }

    /**
     * The full set of athlete IDs the requester may access (non-super-admin).
     *
     * Union of:
     *   - athletes in clubs where requester is club_admin
     *   - athletes on teams the requester coaches
     *   - athletes the requester is a guardian of
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth
     * @return int[] distinct athlete IDs
     */
    public static function accessibleAthleteIds(PDO $pdo, AuthMiddleware $auth): array {
        // Staff standing first; the guardian branch is added below. Same shape as
        // userCanAccessAthlete being defined in terms of staffCanManageAthlete —
        // the staff half lives in exactly one place so the two cannot drift.
        $ids = [];
        foreach (self::staffManageableAthleteIds($pdo, $auth) as $aid) {
            $ids[$aid] = true;
        }

        // Guardian-of athletes. Resolved by user id through lib/guardian_identity.php —
        // recorded links (user_guardians) UNION the email match — so a parent whose
        // account address has drifted from their guardian row keeps their own children.
        $userId = (int) $auth->getUserId();
        foreach (te_athlete_ids_for_user($pdo, $userId) as $aid) {
            $ids[(int) $aid] = true;
        }

        return array_keys($ids);
    }

    /**
     * The athlete IDs the requester may MANAGE as staff — club admin of their
     * club, or coach of their team. No guardian branch.
     *
     * The list counterpart of staffCanManageAthlete(), for the same reason: a
     * staff-only view (who still owes parental consent, say) must not widen to
     * every athlete the caller happens to parent, and must return nothing at all
     * for a caller who is only a parent.
     *
     * Returns an empty array for a requester with no staff standing, INCLUDING a
     * super admin — callers that need "unrestricted" must check isSuperAdmin()
     * themselves, because an empty list and "everything" are opposite answers and
     * conflating them is how a scope check turns into a data leak.
     *
     * @param PDO $pdo
     * @param AuthMiddleware $auth
     * @return int[] distinct athlete IDs
     */
    public static function staffManageableAthleteIds(PDO $pdo, AuthMiddleware $auth): array {
        $ids = [];

        // Club admin clubs (from JWT roles).
        $adminClubIds = self::clubAdminClubIds($auth);
        if (!empty($adminClubIds)) {
            $ph = implode(',', array_fill(0, count($adminClubIds), '?'));

            // (a) Athletes on a team in the admin's club(s).
            $sql = "
                SELECT DISTINCT tm.athlete_id
                FROM team_members tm
                JOIN teams t ON t.id = tm.team_id
                WHERE t.club_id IN ($ph)
                  AND tm.athlete_id IS NOT NULL
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($adminClubIds));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $ids[(int) $aid] = true;
            }

            // (b) Athletes directly linked to the admin's club(s) via
            //     athletes.club_id — covers club athletes with no team yet, so
            //     the People -> Athletes list shows the full club roster
            //     (CA-18). Guarded for fixtures that lack the column.
            try {
                $sql = "SELECT id FROM athletes WHERE club_id IN ($ph)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($adminClubIds));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                    $ids[(int) $aid] = true;
                }
            } catch (\PDOException $e) {
                // athletes.club_id not present in this schema — ignore.
            }
        }

        // Coached teams.
        $userId = (int) $auth->getUserId();
        if ($userId > 0) {
            $teamIds = self::coachTeamIdsForUser($pdo, $userId);
            if (!empty($teamIds)) {
                $ph = implode(',', array_fill(0, count($teamIds), '?'));
                $sql = "
                    SELECT DISTINCT tm.athlete_id
                    FROM team_members tm
                    WHERE tm.team_id IN ($ph)
                      AND tm.athlete_id IS NOT NULL
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($teamIds));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                    $ids[(int) $aid] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Club IDs where the requester holds the club_admin role (from JWT roles).
     *
     * @param AuthMiddleware $auth
     * @return int[]
     */
    public static function clubAdminClubIds(AuthMiddleware $auth): array {
        $clubIds = [];
        $roles = $auth->getRoles();
        $rolesArray = is_array($roles) ? $roles : (array) $roles;
        foreach ($rolesArray as $role) {
            $r = (array) $role;
            if (($r['role'] ?? null) === 'club_admin'
                && ($r['scope_type'] ?? null) === 'club'
                && isset($r['scope_id'])) {
                $clubIds[(int) $r['scope_id']] = true;
            }
        }
        return array_keys($clubIds);
    }
}
